<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Reconciliation\VolumeReconciler;
use App\Reconciliation\VolumeReport;
use App\Shared\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function abs;
use function array_map;
use function intdiv;
use function is_array;
use function json_decode;
use function sprintf;
use function usort;

/**
 * Read-only queries behind the admin dashboard. Nothing here writes: volume figures come from
 * {@see VolumeReconciler} with persist = false, and fill↔sale links are those the sync job already made.
 * All datetimes are UTC in the database; pages convert to station local time.
 */
final class DashboardQueries
{
    /** A device counts as online when it did something this recently. */
    public const ONLINE_SECONDS = 600;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly VolumeReconciler $reconciler,
        private readonly Clock $clock,
    ) {}

    /**
     * Stations the mediator serves (an enabled controller), with controller health.
     *
     * @return list<array<string, mixed>>
     */
    public function servedStations(): array
    {
        /** @var list<array<string, mixed>> */
        return (new Query($this->db))
            ->select([
                's.id', 's.code', 's.name', 's.utc_offset_minutes', 'controller_id' => 'c.id', 'controller_name' => 'c.name',
                'c.url', 'c.odoo_backend_config_id', 'c.last_ok_at', 'c.last_error_at', 'c.last_error', 'c.consecutive_failures',
                'c.circuit_open_until', 'c.poll_interval_ms',
            ])
            ->from(['s' => 'stations'])
            ->innerJoin(['c' => 'controllers'], 'c.station_id = s.id AND c.enabled = 1')
            ->where(['s.active' => 1])
            ->orderBy(['s.name' => SORT_ASC])
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function station(int $stationId): ?array
    {
        foreach ($this->servedStations() as $station) {
            if ((int) $station['id'] === $stationId) {
                return $station;
            }
        }

        return null;
    }

    public function zone(array $station): DateTimeZone
    {
        $minutes = (int) $station['utc_offset_minutes'];
        /** @var non-empty-string $offset */
        $offset = sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);

        return new DateTimeZone($offset);
    }

    /**
     * Reporting window. `shift` = from the first sale of the POS session(s) active in the last 3 hours (the open
     * shift), `today` = since local midnight, `12h` = last 12 hours.
     *
     * @return array{key: string, label: string, from: string, to: string, sessions: list<int>}
     */
    public function window(array $station, string $key): array
    {
        $now = $this->clock->now();
        $to = $now->format('Y-m-d H:i:s.v');
        $stationId = (int) $station['id'];
        if ($key === 'today') {
            $midnight = $now->setTimezone($this->zone($station))->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
            return ['key' => 'today', 'label' => 'Today', 'from' => $midnight->format('Y-m-d H:i:s.v'), 'to' => $to, 'sessions' => []];
        }
        if ($key === '12h') {
            return ['key' => '12h', 'label' => 'Last 12 hours', 'from' => $now->modify('-12 hours')->format('Y-m-d H:i:s.v'), 'to' => $to, 'sessions' => []];
        }

        // Open POS sessions of the station (mirrored by odoo:pull-sales); their real opening time starts the shift.
        /** @var list<array{odoo_session_id: string, name: string, start_at: ?string}> $open */
        $open = (new Query($this->db))->select(['odoo_session_id', 'name', 'start_at'])->from('pos_sessions')
            ->where(['station_id' => $stationId])
            ->andWhere(['not in', 'state', ['closed']])
            ->andWhere(['>=', 'start_at', $now->modify('-2 days')->format('Y-m-d H:i:s.v')])
            ->orderBy(['start_at' => SORT_ASC])->all();
        $sessions = array_map(static fn(array $r): int => (int) $r['odoo_session_id'], $open);
        $from = $open !== [] && $open[0]['start_at'] !== null ? $open[0]['start_at'] : $now->modify('-12 hours')->format('Y-m-d H:i:s.v');

        return ['key' => 'shift', 'label' => 'Current shift', 'from' => $from, 'to' => $to, 'sessions' => $sessions];
    }

    public function volume(int $stationId, string $fromUtc, string $toUtc, string $groupBy = VolumeReconciler::BY_GRADE): VolumeReport
    {
        return $this->reconciler->reconcile($stationId, $fromUtc, $toUtc, 10, false, $groupBy);
    }

    /** Latest meter snapshot written by the collector, UTC. */
    public function lastSnapshotAt(int $stationId): ?string
    {
        $value = (new Query($this->db))->select(new Expression('MAX(observed_at)'))->from('meter_snapshots')
            ->where(['station_id' => $stationId])->scalar();

        return $value === null || $value === false ? null : (string) $value;
    }

    /**
     * Live pump board: cached status per pump with grade and the pump's latest fill.
     *
     * @return list<array<string, mixed>>
     */
    public function pumps(int $stationId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->db))
            ->select(['p.pump_number', 'n.fuel_name', 'sc.state', 'sc.packet', 'sc.observed_at'])
            ->from(['p' => 'pumps'])
            ->leftJoin(['n' => 'nozzles'], 'n.pump_id = p.id AND n.nozzle_number = 1')
            ->leftJoin(['sc' => 'pump_status_cache'], 'sc.controller_id = p.controller_id AND sc.pump_number = p.pump_number')
            ->where(['p.station_id' => $stationId, 'p.on_controller' => 1])
            ->orderBy(['p.pump_number' => SORT_ASC])
            ->all();

        foreach ($rows as &$row) {
            /** @var mixed $packet */
            $packet = $row['packet'] === null ? null : json_decode((string) $row['packet'], true);
            $data = is_array($packet) && is_array($packet['Data'] ?? null) ? $packet['Data'] : (is_array($packet) ? $packet : []);
            $row['data'] = $data;
            $row['last_fill'] = (new Query($this->db))
                ->select(['f.id', 'f.pts_transaction_no', 'f.ended_at', 'f.volume', 'f.amount', 'f.state', 'settled' => 'fs.id'])
                ->from(['f' => 'fill_events'])
                ->leftJoin(['fs' => 'fill_settlements'], 'fs.fill_event_id = f.id')
                ->where(['f.station_id' => $stationId, 'f.pump_number' => (int) $row['pump_number']])
                ->orderBy(['f.ended_at' => SORT_DESC])->limit(1)->one();
        }

        return $rows;
    }

    /**
     * Recent fills with the sale each one is linked to.
     *
     * @return list<array<string, mixed>>
     */
    public function recentFills(int $stationId, string $fromUtc, int $limit = 80): array
    {
        /** @var list<array<string, mixed>> */
        return (new Query($this->db))
            ->select([
                'f.id', 'f.pump_number', 'f.fuel_name', 'f.pts_transaction_no', 'f.ended_at', 'f.volume', 'f.amount', 'f.state',
                'f.preceding_missed_count', 'ml.method', 'ml.confidence', 'sale_amount' => 's.amount', 'sale_pump' => 's.pump_number',
                'sale_tx' => 's.pts_transaction_id', 's.payment_method', 's.terminal_id', 's.operator_name', 'sale_at' => 's.sale_created_at',
                'settled' => 'fs.id',
            ])
            ->from(['f' => 'fill_events'])
            ->leftJoin(['ml' => 'match_links'], 'ml.fill_event_id = f.id')
            ->leftJoin(['s' => 'odoo_sales'], 's.id = ml.odoo_sale_id')
            ->leftJoin(['fs' => 'fill_settlements'], 'fs.fill_event_id = f.id')
            ->where(['f.station_id' => $stationId])
            ->andWhere(['>=', 'f.ended_at', $fromUtc])
            ->orderBy(['f.ended_at' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Fills without a sale, sales without a fill, possible duplicates, and fills reported to devices as paid.
     *
     * @return array{unmatched: list<array<string, mixed>>, unlinked: list<array<string, mixed>>, settled: list<array<string, mixed>>}
     */
    public function exceptions(int $stationId, string $fromUtc): array
    {
        /** @var list<array<string, mixed>> $unmatched */
        $unmatched = (new Query($this->db))
            ->select(['id', 'pump_number', 'fuel_name', 'pts_transaction_no', 'ended_at', 'volume', 'amount'])
            ->from('fill_events')
            ->where(['station_id' => $stationId, 'state' => 'unmatched'])
            ->andWhere(['>=', 'ended_at', $fromUtc])
            ->orderBy(['ended_at' => SORT_DESC])->all();

        /** @var list<array<string, mixed>> $unlinked */
        $unlinked = (new Query($this->db))
            ->select(['id', 'odoo_id', 'pump_number', 'fuel_name', 'pts_transaction_id', 'sale_created_at', 'volume', 'amount',
                'payment_method', 'payment_status', 'terminal_id', 'operator_name', 'match_state'])
            ->from('odoo_sales')
            ->where(['station_id' => $stationId])
            ->andWhere(['or', ['match_state' => ['sale_without_fill', 'duplicate_candidate']], ['payment_status' => 'PENDING']])
            ->andWhere(['>=', 'sale_created_at', $fromUtc])
            ->orderBy(['sale_created_at' => SORT_DESC])->all();

        /** @var list<array<string, mixed>> $settled */
        $settled = (new Query($this->db))
            ->select(['fs.pump_number', 'fs.pts_transaction_no', 'fs.amount', 'fs.first_applied_at', 'fs.last_applied_at', 'fs.applied_count',
                'fs.last_method', 'f.ended_at', 'sale_pump' => 's.pump_number', 'sale_tx' => 's.pts_transaction_id', 's.payment_method',
                's.terminal_id'])
            ->from(['fs' => 'fill_settlements'])
            ->innerJoin(['f' => 'fill_events'], 'f.id = fs.fill_event_id')
            ->innerJoin(['s' => 'odoo_sales'], 's.id = fs.odoo_sale_id')
            ->where(['fs.station_id' => $stationId])
            ->andWhere(['>=', 'f.ended_at', $fromUtc])
            ->orderBy(['f.ended_at' => SORT_DESC])->all();

        return ['unmatched' => $unmatched, 'unlinked' => $unlinked, 'settled' => $settled];
    }

    /**
     * Terminals seen at a station: from Odoo sales (every device, direct or not) and from the mediator's own traffic.
     *
     * @return list<array<string, mixed>>
     */
    public function devices(int $stationId, string $fromUtc): array
    {
        /** @var list<array<string, mixed>> $fromSales */
        $fromSales = (new Query($this->db))
            ->select([
                'terminal_id', 'sales' => new Expression('COUNT(*)'), 'amount' => new Expression('SUM(amount)'),
                'litres' => new Expression('SUM(volume)'), 'last_sale_at' => new Expression('MAX(sale_created_at)'),
                'pts_tx' => new Expression("SUM(pts_transaction_id IS NOT NULL AND pts_transaction_id <> '')"),
                'cash' => new Expression("SUM(IF(payment_method = 'CASH', amount, 0))"),
                'card' => new Expression("SUM(IF(payment_method = 'CARD', amount, 0))"),
                'pending' => new Expression("SUM(payment_status <> 'APPROVED')"),
                'issues' => new Expression("SUM(match_state IN ('sale_without_fill', 'duplicate_candidate'))"),
                'operators' => new Expression('GROUP_CONCAT(DISTINCT operator_name SEPARATOR \', \')'),
                'configs' => new Expression('GROUP_CONCAT(DISTINCT odoo_pos_config_id)'),
            ])
            ->from('odoo_sales')
            ->where(['station_id' => $stationId])
            ->andWhere(['>=', 'sale_created_at', $fromUtc])
            ->andWhere(['not', ['terminal_id' => null]])
            ->groupBy('terminal_id')
            ->all();

        /** @var array<string, array<string, mixed>> $viaMediator */
        $viaMediator = [];
        /** @var list<array<string, mixed>> $known */
        $known = (new Query($this->db))->from('devices')->where(['or', ['station_id' => $stationId], ['station_id' => null]])->all();
        foreach ($known as $device) {
            $viaMediator[(string) $device['terminal_id']] = $device;
        }

        /** @var list<array<string, mixed>> $devices */
        $devices = [];
        foreach ($fromSales as $row) {
            $terminal = (string) $row['terminal_id'];
            $row['via_mediator'] = $viaMediator[$terminal] ?? null;
            unset($viaMediator[$terminal]);
            $devices[] = $row;
        }
        foreach ($viaMediator as $terminal => $device) {
            $devices[] = ['terminal_id' => $terminal, 'sales' => 0, 'amount' => null, 'litres' => null, 'last_sale_at' => null,
                'pts_tx' => 0, 'cash' => null, 'card' => null, 'pending' => 0, 'issues' => 0, 'operators' => null, 'configs' => null,
                'via_mediator' => $device];
        }

        $now = $this->clock->now()->getTimestamp();
        foreach ($devices as &$device) {
            $last = isset($device['last_sale_at']) ? (string) $device['last_sale_at'] : null;
            $via = $device['via_mediator'] ?? null;
            $seen = is_array($via) && isset($via['last_seen_at']) ? (string) $via['last_seen_at'] : null;
            $latest = $seen !== null && ($last === null || $seen > $last) ? $seen : $last;
            $device['last_activity_at'] = $latest;
            $device['online'] = $latest !== null && $now - (new DateTimeImmutable($latest . ' UTC'))->getTimestamp() <= self::ONLINE_SECONDS;
        }
        unset($device);
        usort($devices, static fn(array $a, array $b): int => [(int) !$a['online'], (string) $b['last_activity_at']] <=> [(int) !$b['online'], (string) $a['last_activity_at']]);

        return $devices;
    }

    /**
     * Open issues, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function issues(?int $stationId, ?string $type, int $limit = 300): array
    {
        $query = (new Query($this->db))
            ->select(['d.*', 'station_name' => 's.name', 'utc_offset_minutes' => 's.utc_offset_minutes'])
            ->from(['d' => 'discrepancies'])
            ->innerJoin(['s' => 'stations'], 's.id = d.station_id')
            ->where(['d.status' => 'open'])
            ->orderBy(['d.period_start' => SORT_DESC])
            ->limit($limit);
        if ($stationId !== null) {
            $query->andWhere(['d.station_id' => $stationId]);
        }
        if ($type !== null && $type !== '') {
            $query->andWhere(['d.type' => $type]);
        }

        /** @var list<array<string, mixed>> */
        return $query->all();
    }

    /** @return array<string, int> type => open count */
    public function issueCounts(?int $stationId = null, ?string $sinceUtc = null): array
    {
        $query = (new Query($this->db))->select(['type', 'n' => new Expression('COUNT(*)')])->from('discrepancies')
            ->where(['status' => 'open'])->groupBy('type');
        if ($stationId !== null) {
            $query->andWhere(['station_id' => $stationId]);
        }
        if ($sinceUtc !== null) {
            $query->andWhere(['>=', 'period_start', $sinceUtc]);
        }
        $counts = [];
        /** @var list<array{type: string, n: string}> $rows */
        $rows = $query->all();
        foreach ($rows as $row) {
            $counts[$row['type']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Relay traffic and pump commands over the last $minutes.
     *
     * @return array{relay: list<array<string, mixed>>, commands: list<array<string, mixed>>, errors: list<array<string, mixed>>, failedCommands: list<array<string, mixed>>}
     */
    public function traffic(int $minutes): array
    {
        $since = $this->clock->now()->modify("-{$minutes} minutes")->format('Y-m-d H:i:s.v');

        /** @var list<array<string, mixed>> $relay */
        $relay = (new Query($this->db))
            ->select(['model', 'method', 'outcome', 'n' => new Expression('COUNT(*)'), 'avg_ms' => new Expression('ROUND(AVG(latency_ms))'),
                'max_ms' => new Expression('MAX(latency_ms)'), 'last_at' => new Expression('MAX(created_at)')])
            ->from('relay_calls')->where(['>=', 'created_at', $since])
            ->groupBy(['model', 'method', 'outcome'])->orderBy(['n' => SORT_DESC])->all();

        /** @var list<array<string, mixed>> $commands */
        $commands = (new Query($this->db))
            ->select(['type', 'state', 'n' => new Expression('COUNT(*)'), 'last_at' => new Expression('MAX(received_at)')])
            ->from('commands')->where(['>=', 'received_at', $since])
            ->groupBy(['type', 'state'])->orderBy(['type' => SORT_ASC, 'state' => SORT_ASC])->all();

        /** @var list<array<string, mixed>> $errors */
        $errors = (new Query($this->db))
            ->select(['created_at', 'model', 'method', 'outcome', 'error_message', 'latency_ms', 'correlation_id'])
            ->from('relay_calls')->where(['>=', 'created_at', $since])->andWhere(['<>', 'outcome', 'ok'])
            ->orderBy(['created_at' => SORT_DESC])->limit(50)->all();

        /** @var list<array<string, mixed>> $failedCommands */
        $failedCommands = (new Query($this->db))
            ->select(['received_at', 'type', 'pump_number', 'state', 'error', 'public_id'])
            ->from('commands')->where(['>=', 'received_at', $since])
            ->andWhere(['state' => ['failed', 'outcome_unknown', 'needs_review']])
            ->orderBy(['received_at' => SORT_DESC])->limit(50)->all();

        return ['relay' => $relay, 'commands' => $commands, 'errors' => $errors, 'failedCommands' => $failedCommands];
    }
}
