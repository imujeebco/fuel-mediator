<?php

declare(strict_types=1);

namespace App\Collector;

use App\Pts\ControllerEndpoint;
use App\Pts\PacketPolicy;
use App\Pts\PtsClient;
use App\Pts\PtsException;
use App\Pts\PumpStatus;
use App\Pts\StatusParser;
use App\Shared\Clock;
use App\Shared\Decimal;
use App\Shared\Http\JsonResponder;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_string;
use function json_encode;

/**
 * Observe-mode collector for one controller (decision D3, read-only).
 *
 * One `PumpGetStatus` batch per call for every pump on the controller. For each pump it:
 *  - refreshes `pump_status_cache` (the cache that will serve devices in Phase 3b);
 *  - records a `fill_events` row whenever an idle status reports a new `LastTransaction`. A jump of k > 1 in the
 *    per-pump transaction counter means k − 1 fills happened between polls; their count and combined volume
 *    (meter delta minus the last fill) are stored on the new event;
 *  - writes a `meter_snapshots` row when `LastTotalVolume` changes, plus a heartbeat every
 *    {@see SNAPSHOT_HEARTBEAT_SECONDS} so reconciliation can prove coverage.
 *
 * A status packet without `LastTotalVolume` (the controller omits it intermittently) never advances state: the fill
 * is recorded on the next poll that reports the meter.
 */
final class StatusCollector
{
    public const SNAPSHOT_HEARTBEAT_SECONDS = 300;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PtsClient $pts,
        private readonly Clock $clock,
    ) {}

    /**
     * @throws PtsException
     */
    public function collect(ControllerEndpoint $controller): CollectResult
    {
        /** @var list<int> $pumpNumbers */
        $pumpNumbers = array_map('intval', (new Query($this->db))->select('pump_number')->from('pumps')
            ->where(['controller_id' => $controller->id, 'on_controller' => 1])
            ->orderBy(['pump_number' => SORT_ASC])->column());
        if ($pumpNumbers === []) {
            throw new PtsException(PtsException::CONFIGURATION, 'No pumps on controller; run pts:sync-config first.');
        }

        return $this->collectPumps($controller, $pumpNumbers)[0];
    }

    /**
     * Polls the given pumps (also used when a device asks for status that is not fresh in the cache), so every
     * status read — collector or device — feeds fill detection.
     *
     * @param list<int> $pumpNumbers
     *
     * @throws PtsException
     *
     * @return array{CollectResult, list<array<string, mixed>|null>} Result and the raw reply packets in pump order.
     */
    public function collectPumps(ControllerEndpoint $controller, array $pumpNumbers): array
    {
        $packets = array_map(static fn(int $pump): array => ['Type' => 'PumpGetStatus', 'Data' => ['Pump' => $pump]], $pumpNumbers);
        $replies = $this->pts->send($controller, $packets, PacketPolicy::READ_ONLY);
        $now = $this->clock->now();

        $result = new CollectResult();
        foreach ($pumpNumbers as $index => $pump) {
            $raw = $replies[$index] ?? null;
            $status = StatusParser::parse($pump, $raw);
            $this->db->transaction(fn() => $this->applyStatus($controller, $status, $raw, $now, $result));
        }

        return [$result, $replies];
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function applyStatus(
        ControllerEndpoint $controller,
        PumpStatus $status,
        ?array $raw,
        DateTimeImmutable $now,
        CollectResult $result,
    ): void {
        $nowDb = $now->format('Y-m-d H:i:s.v');
        /** @var array{last_transaction_no: ?string, last_total_volume: ?string, last_total_amount: ?string, last_snapshot_at: ?string}|null $cache */
        $cache = (new Query($this->db))
            ->select(['last_transaction_no', 'last_total_volume', 'last_total_amount', 'last_snapshot_at'])
            ->from('pump_status_cache')
            ->where(['controller_id' => $controller->id, 'pump_number' => $status->pump])
            ->one();

        $cachedTx = $cache['last_transaction_no'] ?? null;
        $cachedTotal = $cache['last_total_volume'] ?? null;
        $lastTx = $cachedTx === null ? null : (int) $cachedTx;
        $lastTotal = $cachedTotal;
        $lastSnapshotAt = $cache['last_snapshot_at'] ?? null;

        if ($status->state === PumpStatus::ERROR || $status->state === PumpStatus::UNKNOWN) {
            $result->pumpErrors++;
        }

        // A meter reading is only usable when the packet carries it.
        $total = $status->hasCompletedFill() ? $status->lastTotalVolume : null;

        if ($total !== null && $status->lastTransaction !== null) {
            if ($lastTx === null || $status->lastTransaction !== $lastTx) {
                $this->recordFill($controller, $status, $lastTx, $lastTotal, $cache['last_total_amount'] ?? null, $raw, $nowDb, $result);
            }
            $lastTx = $status->lastTransaction;

            $changed = $lastTotal === null || Decimal::compare($total, $lastTotal) !== 0;
            $heartbeatDue = $lastSnapshotAt === null
                || $now->getTimestamp() - (new DateTimeImmutable($lastSnapshotAt . ' UTC'))->getTimestamp() >= self::SNAPSHOT_HEARTBEAT_SECONDS;
            if ($changed || $heartbeatDue) {
                $this->db->createCommand()->insert('meter_snapshots', [
                    'station_id' => $controller->stationId,
                    'controller_id' => $controller->id,
                    'pump_number' => $status->pump,
                    'nozzle_number' => $status->lastNozzle ?? 1,
                    'total_volume' => $total,
                    'total_amount' => $status->lastTotalAmount,
                    'source' => 'status',
                    'observed_at' => $nowDb,
                    'controller_time_raw' => $status->lastEndedAtLocal,
                ])->execute();
                $lastSnapshotAt = $nowDb;
                $result->snapshots++;
                if ($lastTotal !== null && Decimal::compare($total, $lastTotal) < 0) {
                    $result->meterRegressions++;
                }
            }
            $lastTotal = $total;
        }

        $columns = [
            'station_id' => $controller->stationId,
            'state' => $status->state,
            'last_transaction_no' => $lastTx,
            'last_total_volume' => $lastTotal,
            'last_total_amount' => $status->lastTotalAmount ?? ($cache['last_total_amount'] ?? null),
            'last_snapshot_at' => $lastSnapshotAt,
            'packet' => json_encode($raw ?? ['missing' => true], JsonResponder::FLAGS),
            'observed_at' => $nowDb,
        ];
        $this->db->createCommand()->upsert(
            'pump_status_cache',
            ['controller_id' => $controller->id, 'pump_number' => $status->pump] + $columns,
            $columns,
        )->execute();
        $result->pumpsPolled++;
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function recordFill(
        ControllerEndpoint $controller,
        PumpStatus $status,
        ?int $previousTx,
        ?string $previousTotal,
        ?string $previousTotalAmount,
        ?array $raw,
        string $nowDb,
        CollectResult $result,
    ): void {
        $volume = $status->lastVolume ?? '0.000';
        $total = (string) $status->lastTotalVolume;

        $missedCount = 0;
        $missedVolume = null;
        if ($previousTx !== null && $status->lastTransaction !== null && $status->lastTransaction > $previousTx) {
            $missedCount = $status->lastTransaction - $previousTx - 1;
        }
        if ($previousTotal !== null) {
            // Volume dispensed between the previous known meter and the start of this fill.
            $unexplained = Decimal::sub(Decimal::sub($total, $volume), $previousTotal);
            if (Decimal::compare($unexplained, '0.005') > 0) {
                $missedVolume = $unexplained;
            }
        }

        // Exactly one fill missed between polls is fully determined by the totals: rebuild it, so a sale naming its
        // transaction number still links. Otherwise the gap is lost whenever the visible transaction is a
        // zero-volume lift (live finding 2026-10-14: pump 9 tx 4811, 21.30 L, then tx 4812 with 0 L).
        $missedAmount = $missedCount === 1 && $missedVolume !== null && $previousTotalAmount !== null && $status->lastTotalAmount !== null
            ? Decimal::sub(Decimal::sub($status->lastTotalAmount, $status->lastAmount ?? '0.00', 2), $previousTotalAmount, 2)
            : null;
        if ($missedAmount !== null && $missedVolume !== null && $previousTx !== null && Decimal::compare($missedAmount, '0', 2) > 0) {
            $ended = $controller->controllerTimeToUtc($status->lastStartedAtLocal) ?? $nowDb;
            $rebuilt = $this->insertFill($controller, $status->pump, $status->lastNozzle ?? 1, $previousTx + 1, null, $ended,
                $missedVolume, $missedAmount, Decimal::div($missedAmount, $missedVolume, 3), Decimal::add($previousTotal ?? '0', $missedVolume),
                Decimal::add($previousTotalAmount ?? '0', $missedAmount, 2), 'meter_gap', 0, null, null, $nowDb);
            if ($rebuilt) {
                $result->fills++;
                $missedCount = 0;
                $missedVolume = null;
            }
        }
        $result->missedFills += $missedCount;

        // Zero-volume transactions (nozzle lifted, nothing dispensed) are not sales.
        if (Decimal::compare($volume, '0') <= 0) {
            return;
        }

        $inserted = $this->insertFill($controller, $status->pump, $status->lastNozzle ?? 1, (int) $status->lastTransaction,
            $controller->controllerTimeToUtc($status->lastStartedAtLocal), $controller->controllerTimeToUtc($status->lastEndedAtLocal) ?? $nowDb,
            $volume, $status->lastAmount ?? '0.00', $status->lastPrice, $total, $status->lastTotalAmount, 'status_transition',
            $missedCount, $missedVolume, $raw, $nowDb);
        if ($inserted) {
            $result->fills++;
        }
    }

    /**
     * @param array<string, mixed>|null $raw
     *
     * @return bool Whether a row was written (the meter reading is unique per pump and nozzle, so replays are ignored).
     */
    private function insertFill(
        ControllerEndpoint $controller,
        int $pump,
        int $nozzle,
        int $transactionNo,
        ?string $startedUtc,
        string $endedUtc,
        string $volume,
        string $amount,
        ?string $price,
        string $meterAfter,
        ?string $meterAmountAfter,
        string $source,
        int $missedCount,
        ?string $missedVolume,
        ?array $raw,
        string $nowDb,
    ): bool {
        return $this->db->createCommand(
            'INSERT IGNORE INTO fill_events (station_id, controller_id, pump_number, nozzle_number, fuel_name, pts_transaction_no,'
            . ' started_at, ended_at, volume, amount, price, meter_volume_after, meter_amount_after, source,'
            . ' preceding_missed_count, preceding_missed_volume, raw, created_at, updated_at)'
            . ' VALUES (:station, :controller, :pump, :nozzle, :fuel, :tx, :started, :ended, :volume, :amount, :price,'
            . ' :meter, :meter_amount, :source, :missed, :missed_volume, :raw, :now, :now)',
            [
                ':station' => $controller->stationId,
                ':controller' => $controller->id,
                ':pump' => $pump,
                ':nozzle' => $nozzle,
                ':fuel' => $this->fuelName($controller->id, $pump, $nozzle),
                ':tx' => $transactionNo,
                ':started' => $startedUtc,
                ':ended' => $endedUtc,
                ':volume' => $volume,
                ':amount' => $amount,
                ':price' => $price,
                ':meter' => $meterAfter,
                ':meter_amount' => $meterAmountAfter,
                ':source' => $source,
                ':missed' => $missedCount,
                ':missed_volume' => $missedVolume,
                ':raw' => json_encode($raw, JsonResponder::FLAGS),
                ':now' => $nowDb,
            ],
        )->execute() > 0;
    }

    /** Fuel grade of the nozzle per the controller's own configuration (see ConfigSync). */
    private function fuelName(int $controllerId, int $pump, int $nozzle): ?string
    {
        $name = (new Query($this->db))->select('n.fuel_name')->from(['n' => 'nozzles'])
            ->innerJoin(['p' => 'pumps'], 'p.id = n.pump_id')
            ->where(['p.controller_id' => $controllerId, 'p.pump_number' => $pump, 'n.nozzle_number' => $nozzle])
            ->scalar();

        return is_string($name) && $name !== '' ? $name : null;
    }
}
