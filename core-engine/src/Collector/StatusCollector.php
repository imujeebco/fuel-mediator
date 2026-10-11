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
                $this->recordFill($controller, $status, $lastTx, $lastTotal, $raw, $nowDb, $result);
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
        if ($missedCount > 0 || $missedVolume !== null) {
            $result->missedFills += $missedCount;
        }

        // Zero-volume transactions (nozzle lifted, nothing dispensed) are not sales.
        if (Decimal::compare($volume, '0') <= 0) {
            return;
        }

        $inserted = $this->db->createCommand(
            'INSERT IGNORE INTO fill_events (station_id, controller_id, pump_number, nozzle_number, pts_transaction_no,'
            . ' started_at, ended_at, volume, amount, price, meter_volume_after, meter_amount_after, source,'
            . ' preceding_missed_count, preceding_missed_volume, raw, created_at, updated_at)'
            . ' VALUES (:station, :controller, :pump, :nozzle, :tx, :started, :ended, :volume, :amount, :price,'
            . ' :meter, :meter_amount, \'status_transition\', :missed, :missed_volume, :raw, :now, :now)',
            [
                ':station' => $controller->stationId,
                ':controller' => $controller->id,
                ':pump' => $status->pump,
                ':nozzle' => $status->lastNozzle ?? 1,
                ':tx' => $status->lastTransaction,
                ':started' => $controller->controllerTimeToUtc($status->lastStartedAtLocal),
                ':ended' => $controller->controllerTimeToUtc($status->lastEndedAtLocal) ?? $nowDb,
                ':volume' => $volume,
                ':amount' => $status->lastAmount ?? '0.00',
                ':price' => $status->lastPrice,
                ':meter' => $total,
                ':meter_amount' => $status->lastTotalAmount,
                ':missed' => $missedCount,
                ':missed_volume' => $missedVolume,
                ':raw' => json_encode($raw, JsonResponder::FLAGS),
                ':now' => $nowDb,
            ],
        )->execute();

        if ($inserted > 0) {
            $result->fills++;
        }
    }
}
