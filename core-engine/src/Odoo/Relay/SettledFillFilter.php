<?php

declare(strict_types=1);

namespace App\Odoo\Relay;

use App\Reconciliation\SettledFills;
use App\Shared\Arr;
use App\Shared\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_values;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
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
        if ($settled === []) {
            return null;
        }
        $kept = [];
        $removed = 0;
        foreach ($fills as $row) {
            $key = is_array($row) ? self::pumpTxKey($row['pump_number'] ?? null, $row['pts_transaction_id'] ?? null) : null;
            if ($key !== null && isset($settled[$key])) {
                $this->settled->recordApplied($stationId, $settled[$key], 'mobile_get_unpaid_fills');
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
        if ($settled === []) {
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
            return null;
        }
        $this->settled->recordApplied($stationId, $fill, 'mobile_is_fill_paid');
        $result['paid'] = true;
        $result['settled_by_mediator'] = true;

        return $result;
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
