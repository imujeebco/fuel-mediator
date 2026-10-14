<?php

declare(strict_types=1);

namespace App\Odoo\Relay;

use App\Reconciliation\LiveSettlements;
use App\Reconciliation\SettledFills;
use App\Shared\Arr;
use App\Shared\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_values;
use function intdiv;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function number_format;
use function strtotime;

/**
 * Decision D21: tells FuelPay that a fill already paid by another sale record is paid.
 *
 * FuelPay's unpaid queue is rebuilt every 15 s from three Odoo calls, so all three must agree or the row comes back:
 *  - `pos.config.mobile_get_unpaid_fills`: settled fills are removed from `fills`;
 *  - `pos.config.mobile_get_paid_fills`: settled fills are added, under the uuid the device uses for the fill
 *    (FuelPay's deterministic PTS uuid, plus the uuid of Odoo's PENDING row when they differ);
 *  - `pos.config.mobile_is_fill_paid`: `paid` is set to true for a settled pump + transaction number.
 *
 * Decision D28 (live settlement, LiveSettlements) applies the same three adjustments to a PTS fill paid by a manual
 * sale on the same pump, decided from the relay journal and the sales mirror without waiting for the collector.
 *
 * Odoo is not changed: its PENDING row stays, and Odoo's own session close cancels unpaid drafts without payments.
 * Any failure here returns Odoo's answer unchanged (never block or alter a reply on an internal error).
 *
 * @psalm-import-type SettledFill from SettledFills
 */
final class SettledFillFilter
{
    public const METHODS = ['mobile_get_unpaid_fills', 'mobile_get_paid_fills', 'mobile_is_fill_paid'];

    /** Settled fills are considered for the current shift: Odoo's unpaid list looks back at most 720 min. */
    private const LOOKBACK_SECONDS = 13 * 3600;

    public function __construct(
        private readonly SettledFills $settled,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
        private readonly ?LiveSettlements $live = null,
    ) {}

    /**
     * @param array<array-key, mixed> $args execute_kw args: [db, uid, password, model, method, positional, kwargs]
     * @param array<string, mixed> $response Odoo's decoded JSON-RPC response
     *
     * @return array<string, mixed>|null The changed response, or null when nothing changed.
     */
    public function apply(string $modelMethod, array $args, array $response): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        try {
            $raw = $args[5] ?? null;
            $positional = is_array($raw) ? array_values($raw) : [];
            $configId = self::configId($positional[0] ?? null);
            $result = Arr::arrOrNull($response, 'result');
            if ($configId === null || $result === null) {
                return null;
            }
            $stationId = $this->settled->stationForPosConfig($configId);
            if ($stationId === null) {
                return null;
            }
            $since = $this->clock->now()->modify('-' . self::LOOKBACK_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');

            $changed = match ($modelMethod) {
                'mobile_get_unpaid_fills' => $this->filterUnpaid($stationId, $since, $result),
                'mobile_get_paid_fills' => $this->addPaid($stationId, $since, $result),
                'mobile_is_fill_paid' => $this->isPaid($stationId, $since, $positional, $result),
                default => null,
            };
            if ($changed === null) {
                return null;
            }
            $response['result'] = $changed;

            return $response;
        } catch (Throwable $e) {
            $this->logger->error('Settled-fill filter failed; Odoo reply passed through unchanged', ['method' => $modelMethod, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** @param array<array-key, mixed> $result */
    private function filterUnpaid(int $stationId, string $since, array $result): ?array
    {
        $fills = Arr::arrOrNull($result, 'fills');
        if ($fills === null || $fills === []) {
            return null;
        }
        $settled = $this->indexByPumpTx($this->settled->forStation($stationId, $since));
        if ($settled === [] && $this->live === null) {
            return null;
        }
        $kept = [];
        $removed = 0;
        foreach ($fills as $index => $row) {
            $key = is_array($row) ? self::pumpTxKey($row['pump_number'] ?? null, $row['pts_transaction_id'] ?? null) : null;
            if ($key !== null && isset($settled[$key])) {
                $this->settled->recordApplied($stationId, $settled[$key], 'mobile_get_unpaid_fills');
                $removed++;
                continue;
            }
            if ($key !== null && is_array($row) && $this->liveSettle($stationId, $row, self::othersThan($fills, $index), 'mobile_get_unpaid_fills')) {
                $removed++;
                continue;
            }
            $kept[] = $row;
        }
        if ($removed === 0) {
            return null;
        }
        $this->logger->info('Settled fills removed from unpaid list', ['station_id' => $stationId, 'removed' => $removed]);
        $result['fills'] = $kept;

        return $result;
    }

    /** @param array<array-key, mixed> $result */
    private function addPaid(int $stationId, string $since, array $result): ?array
    {
        $settled = $this->settled->forStation($stationId, $since);
        $live = $this->live?->recent($stationId, $since) ?? [];
        if ($settled === [] && $live === []) {
            return null;
        }
        $fills = Arr::arrOrNull($result, 'fills') ?? [];
        $present = [];
        foreach ($fills as $row) {
            $uuid = is_array($row) ? ($row['uuid'] ?? null) : null;
            if (is_string($uuid)) {
                $present[$uuid] = true;
            }
        }
        $added = 0;
        foreach ($settled as $fill) {
            $uuids = [$fill['fill_uuid']];
            if ($fill['pending_uuid'] !== null && $fill['pending_uuid'] !== $fill['fill_uuid']) {
                $uuids[] = $fill['pending_uuid'];
            }
            foreach ($uuids as $uuid) {
                if (isset($present[$uuid])) {
                    continue;
                }
                $fills[] = [
                    'uuid' => $uuid,
                    'pump_number' => $fill['pump_number'],
                    'nozzle_number' => $fill['nozzle_number'],
                    'pts_transaction_id' => $fill['pts_transaction_no'],
                    'amount_sar' => (float) $fill['amount'],
                    'paid_at_ms' => ((int) strtotime($fill['ended_at'] . ' UTC')) * 1000,
                    'terminal_id' => 'mediator',
                    'order_id' => false,
                ];
                $present[$uuid] = true;
                $added++;
            }
            $this->settled->recordApplied($stationId, $fill, 'mobile_get_paid_fills');
        }
        foreach ($live as ['fill' => $liveFill, 'payer' => $payer]) {
            $uuids = [SettledFills::fuelPayFillUuid($liveFill['pts_transaction_no'], $liveFill['pump_number'])];
            if ($liveFill['pending_uuid'] !== null) {
                $uuids[] = $liveFill['pending_uuid'];
            }
            foreach ($uuids as $uuid) {
                if (isset($present[$uuid])) {
                    continue;
                }
                $fills[] = [
                    'uuid' => $uuid,
                    'pump_number' => $liveFill['pump_number'],
                    'nozzle_number' => 0,
                    'pts_transaction_id' => $liveFill['pts_transaction_no'],
                    'amount_sar' => (float) $liveFill['amount'],
                    'paid_at_ms' => ((int) strtotime($liveFill['fill_created_at'] . ' UTC')) * 1000,
                    'terminal_id' => 'mediator',
                    'order_id' => false,
                ];
                $present[$uuid] = true;
                $added++;
            }
            $this->live?->record($stationId, $liveFill, $payer, 'mobile_get_paid_fills');
        }
        if ($added === 0) {
            return null;
        }
        $result['fills'] = $fills;

        return $result;
    }

    /**
     * @param list<mixed> $positional [[config], pump, tx, uuid, amount, volume, nozzle]
     * @param array<array-key, mixed> $result
     */
    private function isPaid(int $stationId, string $since, array $positional, array $result): ?array
    {
        if (($result['paid'] ?? false) === true) {
            return null;
        }
        $pump = $positional[1] ?? null;
        $tx = $positional[2] ?? null;
        if (!is_numeric($pump) || !is_string($tx) || $tx === '') {
            return null;
        }
        $fill = $this->settled->forPumpTransaction($stationId, (int) $pump, $tx, $since);
        if ($fill === null) {
            $uuid = $positional[3] ?? null;
            $row = ['pump_number' => (int) $pump, 'pts_transaction_id' => $tx, 'amount_sar' => $positional[4] ?? null,
                'volume' => $positional[5] ?? null, 'uuid' => is_string($uuid) ? $uuid : null];
            if (!$this->liveSettle($stationId, $row, [], 'mobile_is_fill_paid')) {
                return null;
            }
            $result['paid'] = true;
            $result['settled_by_mediator'] = true;

            return $result;
        }
        $this->settled->recordApplied($stationId, $fill, 'mobile_is_fill_paid');
        $result['paid'] = true;
        $result['settled_by_mediator'] = true;

        return $result;
    }

    /**
     * D28: whether a manual sale on the same pump paid this PTS record; logs the decision when it did.
     *
     * @param array<array-key, mixed> $row Odoo unpaid-fill row (pump_number, pts_transaction_id, amount_sar, volume, uuid, created_at_ms)
     * @param list<array{pump: int, amount: string}> $others The other rows of the same answer.
     */
    private function liveSettle(int $stationId, array $row, array $others, string $method): bool
    {
        if ($this->live === null) {
            return false;
        }
        $pump = $row['pump_number'] ?? null;
        $tx = $row['pts_transaction_id'] ?? null;
        $amount = $row['amount_sar'] ?? null;
        if (!is_numeric($pump) || (!is_string($tx) && !is_int($tx)) || (string) $tx === '' || !is_numeric($amount) || (float) $amount <= 0) {
            return false;
        }
        $volume = $row['volume'] ?? null;
        $createdMs = $row['created_at_ms'] ?? null;
        $createdAt = is_numeric($createdMs) && (int) $createdMs > 0
            ? new DateTimeImmutable('@' . intdiv((int) $createdMs, 1000))
            : ($this->live->fillCreatedAt($stationId, (int) $pump, (string) $tx) ?? $this->clock->now());
        $amountText = number_format((float) $amount, 2, '.', '');
        $volumeText = is_numeric($volume) ? number_format((float) $volume, 3, '.', '') : null;

        $grade = $row['fuel_name'] ?? null;
        $payer = $this->live->payerFor($stationId, (int) $pump, (string) $tx, $amountText, $volumeText, $createdAt, $others,
            is_string($grade) && $grade !== '' ? $grade : null);
        if ($payer === null) {
            return false;
        }
        $uuid = $row['uuid'] ?? null;
        $this->live->record($stationId, [
            'pump_number' => (int) $pump,
            'pts_transaction_no' => (string) $tx,
            'amount' => $amountText,
            'volume' => $volumeText,
            'fill_created_at' => $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            'pending_uuid' => is_string($uuid) && $uuid !== '' ? $uuid : null,
        ], $payer, $method);
        $this->logger->info('Live settlement: PTS fill paid by a manual sale', [
            'station_id' => $stationId, 'pump' => (int) $pump, 'tx' => (string) $tx, 'payer' => $payer,
        ]);

        return true;
    }

    /**
     * @param array<array-key, mixed> $fills
     *
     * @return list<array{pump: int, amount: string}>
     */
    private static function othersThan(array $fills, int|string $skip): array
    {
        $others = [];
        /** @var mixed $row */
        foreach ($fills as $index => $row) {
            if ($index === $skip || !is_array($row) || !is_numeric($row['pump_number'] ?? null) || !is_numeric($row['amount_sar'] ?? null)) {
                continue;
            }
            $others[] = ['pump' => (int) $row['pump_number'], 'amount' => (string) $row['amount_sar']];
        }

        return $others;
    }

    /**
     * @param list<SettledFill> $fills
     *
     * @return array<string, SettledFill> pump|tx => fill
     */
    private function indexByPumpTx(array $fills): array
    {
        $index = [];
        foreach ($fills as $fill) {
            $index[$fill['pump_number'] . '|' . $fill['pts_transaction_no']] ??= $fill;
        }

        return $index;
    }

    private static function pumpTxKey(mixed $pump, mixed $tx): ?string
    {
        if (!is_numeric($pump) || (!is_string($tx) && !is_int($tx)) || (string) $tx === '') {
            return null;
        }

        return (int) $pump . '|' . $tx;
    }

    private static function configId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
