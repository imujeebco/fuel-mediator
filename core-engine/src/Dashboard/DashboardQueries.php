<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Pts\SecretResolver;
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
use function array_reverse;
use function in_array;
use function intdiv;
use function is_array;
use function json_decode;
use function preg_match;
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
        private readonly SecretResolver $secrets,
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

    /**
     * Every station from the Odoo registry with its main controller: the collecting one if any, else the primary,
     * else the first. `mode` (pts | manual) comes from Odoo (backend config active or not, D9/D14).
     *
     * @return list<array<string, mixed>>
     */
    public function stations(bool $includeInactive = false): array
    {
        $query = (new Query($this->db))
            ->select(['s.id', 's.code', 's.name', 's.mode', 's.mode_override', 's.active', 's.odoo_station_id', 's.odoo_company_id',
                's.odoo_company_name', 's.utc_offset_minutes'])
            ->from(['s' => 'stations'])
            ->orderBy(['s.active' => SORT_DESC, 's.name' => SORT_ASC, 's.id' => SORT_ASC]);
        if (!$includeInactive) {
            $query->where(['s.active' => 1]);
        }
        /** @var list<array<string, mixed>> $stations */
        $stations = $query->all();

        /** @var list<array<string, mixed>> $controllers */
        $controllers = (new Query($this->db))
            ->select(['c.*', 'pumps' => new Expression('(SELECT COUNT(*) FROM pumps p WHERE p.controller_id = c.id AND p.on_controller = 1)')])
            ->from(['c' => 'controllers'])
            ->orderBy(['c.enabled' => SORT_DESC, 'c.is_primary' => SORT_DESC, 'c.id' => SORT_ASC])
            ->all();
        $byStation = [];
        foreach ($controllers as $controller) {
            $byStation[(int) $controller['station_id']][] = $controller;
        }

        foreach ($stations as &$station) {
            $list = $byStation[(int) $station['id']] ?? [];
            $main = $list[0] ?? null;
            $station['controllers'] = $list;
            $station['controller_id'] = $main['id'] ?? null;
            $station['controller_name'] = $main['name'] ?? null;
            $station['url'] = $main['url'] ?? null;
            $station['odoo_backend_config_id'] = $main['odoo_backend_config_id'] ?? null;
            $station['collecting'] = $main !== null && (int) $main['enabled'] === 1;
            $station['pumps'] = $main['pumps'] ?? 0;
            foreach (['last_ok_at', 'last_error_at', 'last_error', 'consecutive_failures', 'circuit_open_until', 'poll_interval_ms', 'config_synced_at', 'odoo_active'] as $key) {
                $station[$key] = $main[$key] ?? null;
            }
            $station['secret_ref'] = $main === null ? null : SecretResolver::refFor($main);
            $station['secret_source'] = $main === null ? null : ($main['auth_mode'] === 'none' ? 'none' : $this->secrets->sourceFor($main));
            $station['secret_ok'] = $main !== null && $station['secret_source'] !== 'missing';
            unset($station['controllers']);
        }
        unset($station);

        return $stations;
    }

    /** @return array<string, mixed>|null Any station, collecting or not. */
    public function station(int $stationId): ?array
    {
        foreach ($this->stations(true) as $station) {
            if ((int) $station['id'] === $stationId) {
                return $station;
            }
        }

        return null;
    }

    /**
     * Sales figures per station since $fromUtc (fuel sales only), from the Odoo mirror.
     *
     * @return array<int, array<string, mixed>> station id => figures
     */
    public function salesByStation(string $fromUtc): array
    {
        $onlineSince = $this->clock->now()->modify('-' . self::ONLINE_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->db))
            ->select([
                'station_id',
                'sales' => new Expression("SUM(payment_status = 'APPROVED')"),
                'litres' => new Expression("SUM(IF(payment_status = 'APPROVED', volume, 0))"),
                'amount' => new Expression("SUM(IF(payment_status = 'APPROVED', amount, 0))"),
                'pending' => new Expression("SUM(payment_status = 'PENDING')"),
                'flagged' => new Expression("SUM(match_state IN ('sale_without_fill', 'duplicate_candidate'))"),
                'with_tx' => new Expression("SUM(pts_transaction_id IS NOT NULL AND pts_transaction_id <> '')"),
                'last_sale_at' => new Expression('MAX(sale_created_at)'),
                'devices' => new Expression('COUNT(DISTINCT terminal_id)'),
                'devices_online' => new Expression('COUNT(DISTINCT IF(sale_created_at >= :online, terminal_id, NULL))', [':online' => $onlineSince]),
            ])
            ->from('odoo_sales')
            ->where(['>=', 'sale_created_at', $fromUtc])
            ->andWhere(['or', ['fuel_name' => null], ['<>', 'fuel_name', 'Shop']])
            ->groupBy('station_id')
            ->all();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['station_id']] = $row;
        }

        return $result;
    }

    /**
     * Open POS sessions per station (mirrored from Odoo).
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function openSessionsByStation(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->db))->select(['station_id', 'odoo_session_id', 'name', 'odoo_pos_config_id', 'state', 'start_at'])
            ->from('pos_sessions')->where(['not in', 'state', ['closed']])
            ->andWhere(['>=', 'start_at', $this->clock->now()->modify('-3 days')->format('Y-m-d H:i:s.v')])
            ->orderBy(['start_at' => SORT_ASC])->all();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['station_id']][] = $row;
        }

        return $result;
    }

    /** @return array<int, int> station id => open issues */
    public function issueCountsByStation(): array
    {
        /** @var list<array{station_id: string, n: string}> $rows */
        $rows = (new Query($this->db))->select(['station_id', 'n' => new Expression('COUNT(*)')])->from('discrepancies')
            ->where(['status' => 'open'])->groupBy('station_id')->all();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['station_id']] = (int) $row['n'];
        }

        return $result;
    }

    /** @return array<int, string> station id => latest meter snapshot (UTC) */
    public function lastSnapshotByStation(): array
    {
        /** @var list<array{station_id: string, t: string}> $rows */
        $rows = (new Query($this->db))->select(['station_id', 't' => new Expression('MAX(observed_at)')])->from('meter_snapshots')
            ->groupBy('station_id')->all();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['station_id']] = $row['t'];
        }

        return $result;
    }

    /**
     * Sales by grade for stations without meter data (sales-only view).
     *
     * @return list<array<string, mixed>>
     */
    public function salesByGrade(int $stationId, string $fromUtc): array
    {
        /** @var list<array<string, mixed>> */
        return (new Query($this->db))
            ->select(['fuel_name', 'sales' => new Expression('COUNT(*)'), 'litres' => new Expression('SUM(volume)'),
                'amount' => new Expression('SUM(amount)'), 'cash' => new Expression("SUM(IF(payment_method = 'CASH', amount, 0))"),
                'card' => new Expression("SUM(IF(payment_method = 'CARD', amount, 0))")])
            ->from('odoo_sales')
            ->where(['station_id' => $stationId, 'payment_status' => 'APPROVED'])
            ->andWhere(['>=', 'sale_created_at', $fromUtc])
            ->groupBy('fuel_name')->orderBy(['fuel_name' => SORT_ASC])->all();
    }

    /**
     * POS sessions of a station overlapping the window.
     *
     * @return list<array<string, mixed>>
     */
    public function sessions(int $stationId, string $fromUtc): array
    {
        /** @var list<array<string, mixed>> */
        return (new Query($this->db))->from('pos_sessions')
            ->where(['station_id' => $stationId])
            ->andWhere(['or', ['stop_at' => null], ['>=', 'stop_at', $fromUtc]])
            ->orderBy(['start_at' => SORT_DESC])->all();
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
     * With a null station: terminals that talked to the mediator in the window but are not linked to any station.
     *
     * @return list<array<string, mixed>>
     */
    public function devices(?int $stationId, string $fromUtc): array
    {
        /** @var list<array<string, mixed>> $fromSales */
        $fromSales = $stationId === null ? [] : (new Query($this->db))
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
        $known = $stationId === null
            ? (new Query($this->db))->from('devices')->where(['station_id' => null])->andWhere(['>=', 'last_seen_at', $fromUtc])->all()
            : (new Query($this->db))->from('devices')->where(['station_id' => $stationId])->all();
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
     * @return array{relay: list<array<string, mixed>>, commands: list<array<string, mixed>>, errors: list<array<string, mixed>>, failedCommands: list<array<string, mixed>>, outages: list<array{start: string, end: ?string, failures: int, fills: list<array<string, mixed>>}>}
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
            ->from('relay_calls')->where(['>=', 'created_at', $since])->andWhere(['not in', 'outcome', ['ok', 'cached']])
            ->orderBy(['created_at' => SORT_DESC])->limit(50)->all();

        /** @var list<array<string, mixed>> $failedCommands */
        $failedCommands = (new Query($this->db))
            ->select(['received_at', 'type', 'pump_number', 'state', 'error', 'public_id'])
            ->from('commands')->where(['>=', 'received_at', $since])
            ->andWhere(['state' => ['failed', 'outcome_unknown', 'needs_review']])
            ->orderBy(['received_at' => SORT_DESC])->limit(50)->all();

        return ['relay' => $relay, 'commands' => $commands, 'errors' => $errors, 'failedCommands' => $failedCommands,
            'outages' => $this->odooOutages($since)];
    }

    /**
     * Periods when Odoo did not answer relayed calls (HTTP 5xx, timeouts, connection failures; business errors do not
     * count), with the controller fills that ended meanwhile and still have no sale. During an outage FuelPay cannot
     * show unpaid fills or authorize pumps, so attendants take payment outside the app (live 2026-10-14: two Odoo.sh
     * maintenance windows, five fills without a sale): this is the station's list to reconcile.
     *
     * @return list<array{start: string, end: ?string, failures: int, fills: list<array<string, mixed>>}>
     */
    public function odooOutages(string $sinceUtc, int $minFailures = 5): array
    {
        /** @var list<array{created_at: string, outcome: string, error_message: ?string}> $calls */
        $calls = (new Query($this->db))->select(['created_at', 'outcome', 'error_message'])->from('relay_calls')
            ->where(['>=', 'created_at', $sinceUtc])->andWhere(['not in', 'outcome', ['cached', 'denied']])
            ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])->all();

        /** @var list<array{start: string, end: ?string, failures: int, fills: list<array<string, mixed>>}> $outages */
        $outages = [];
        /** @var array{start: string, end: ?string, failures: int, fills: list<array<string, mixed>>}|null $open */
        $open = null;
        foreach ($calls as $call) {
            $failed = in_array($call['outcome'], ['timeout', 'transport_error'], true)
                || ($call['outcome'] === 'odoo_error' && preg_match('/^(HTTP 5\d\d|Non-JSON answer)$/', (string) $call['error_message']) === 1);
            if ($failed) {
                if ($open === null) {
                    $open = ['start' => $call['created_at'], 'end' => null, 'failures' => 0, 'fills' => []];
                }
                $open['failures'] += 1;
            } elseif ($call['outcome'] === 'ok' && $open !== null) {
                $open['end'] = $call['created_at'];
                $outages[] = $open;
                $open = null;
            }
        }
        if ($open !== null) {
            $outages[] = $open;
        }

        $result = [];
        foreach ($outages as $outage) {
            if ($outage['failures'] < $minFailures) {
                continue;
            }
            $end = $outage['end'] ?? $this->clock->now()->format('Y-m-d H:i:s.v');
            /** @var list<array<string, mixed>> $fills */
            $fills = (new Query($this->db))
                ->select(['station' => 's.name', 'f.pump_number', 'f.pts_transaction_no', 'f.fuel_name', 'f.volume', 'f.amount', 'f.ended_at', 'f.state'])
                ->from(['f' => 'fill_events'])->innerJoin(['s' => 'stations'], 's.id = f.station_id')
                ->where(['f.state' => ['observed', 'unmatched', 'ambiguous']])
                ->andWhere(['between', 'f.ended_at',
                    (new DateTimeImmutable($outage['start'] . ' UTC'))->modify('-2 minutes')->format('Y-m-d H:i:s.v'),
                    (new DateTimeImmutable($end . ' UTC'))->modify('+2 minutes')->format('Y-m-d H:i:s.v')])
                ->orderBy(['s.name' => SORT_ASC, 'f.ended_at' => SORT_ASC])->all();
            $outage['fills'] = $fills;
            $result[] = $outage;
        }

        return array_reverse($result);
    }
}
