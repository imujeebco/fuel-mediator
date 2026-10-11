<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Shared\Clock;
use App\Shared\Decimal;
use App\Shared\Http\JsonResponder;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function hash;
use function is_numeric;
use function json_encode;
use function ltrim;
use function sprintf;

/**
 * Volume-level reconciliation (docs/transaction-lifecycle.md §6.1): litres dispensed according to the controller vs
 * litres sold in Odoo, for one station and window.
 *
 *   dispensed = Σ fill volume (fills ending in window) + Σ volume of fills missed between polls (tx/meter gaps)
 *   sold      = Σ volume of APPROVED Odoo sales attributed to the station (by POS company): sales linked to a fill
 *               (FillMatcher) count in their fill's window, unlinked sales by their creation time. Run the matcher first;
 *               `$graceMinutes` is reported only (the matcher owns the registration grace).
 *
 * Grouping:
 *  - BY_GRADE (default): per fuel grade. With PTS off on the devices, the pump number on a manual sale is effectively
 *    the fuel grade (live finding 2026-10-11), so per-pump comparison is meaningless there.
 *  - BY_PUMP: per physical pump; only meaningful where devices record the real pump (PTS on).
 *
 * Coverage is "complete" only when the collector observed every pump in the group before the window started; partial
 * groups never raise discrepancies (a window is never reported clean without evidence). Detect and report only (D2).
 */
final class VolumeReconciler
{
    public const BY_GRADE = 'grade';
    public const BY_PUMP = 'pump';

    private const MIN_GAP_LITRES = '0.500';
    private const MIN_GAP_RATIO = '0.005';
    private const NON_FUEL = ['Shop'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param string $fromUtc MySQL datetime, UTC
     * @param string $toUtc MySQL datetime, UTC
     */
    public function reconcile(
        int $stationId,
        string $fromUtc,
        string $toUtc,
        int $graceMinutes = 10,
        bool $persist = true,
        string $groupBy = self::BY_GRADE,
    ): VolumeReport {
        $report = new VolumeReport($stationId, $fromUtc, $toUtc, $graceMinutes, $groupBy);

        /** @var list<array{controller_id: string, pump_number: string, fuel_name: ?string}> $pumps */
        $pumps = (new Query($this->db))->select(['p.controller_id', 'p.pump_number', 'n.fuel_name'])->from(['p' => 'pumps'])
            ->innerJoin(['c' => 'controllers'], 'c.id = p.controller_id')
            ->leftJoin(['n' => 'nozzles'], 'n.pump_id = p.id AND n.on_controller = 1 AND n.nozzle_number = 1')
            ->where(['p.station_id' => $stationId, 'p.on_controller' => 1, 'c.enabled' => 1])
            ->orderBy(['p.pump_number' => SORT_ASC])->all();

        /** @var array<string, array{label: string, pumps: list<array{int, int}>, grade: ?string, pump: ?int}> $groups */
        $groups = [];
        foreach ($pumps as $pump) {
            $grade = $pump['fuel_name'];
            $key = $groupBy === self::BY_PUMP ? 'pump:' . $pump['pump_number'] : 'grade:' . ($grade ?? '?');
            $groups[$key] ??= [
                'label' => $groupBy === self::BY_PUMP ? 'Pump ' . $pump['pump_number'] : ($grade ?? 'Unknown grade'),
                'pumps' => [],
                'grade' => $grade,
                'pump' => $groupBy === self::BY_PUMP ? (int) $pump['pump_number'] : null,
            ];
            $groups[$key]['pumps'][] = [(int) $pump['controller_id'], (int) $pump['pump_number']];
        }

        foreach ($groups as $group) {
            $fillVolume = '0.000';
            $missedVolume = '0.000';
            $fills = 0;
            $missed = 0;
            $covered = true;
            foreach ($group['pumps'] as [$controllerId, $number]) {
                /** @var array{n: string, volume: ?string, missed_n: ?string, missed_volume: ?string} $row */
                $row = (new Query($this->db))->select([
                    'n' => 'COUNT(*)',
                    'volume' => 'SUM(volume)',
                    'missed_n' => 'SUM(preceding_missed_count)',
                    'missed_volume' => 'SUM(COALESCE(preceding_missed_volume, 0))',
                ])->from('fill_events')
                    ->where(['controller_id' => $controllerId, 'pump_number' => $number])
                    ->andWhere(['>=', 'ended_at', $fromUtc])->andWhere(['<', 'ended_at', $toUtc])
                    ->one();
                $fills += (int) $row['n'];
                $missed += (int) ($row['missed_n'] ?? 0);
                $fillVolume = Decimal::add($fillVolume, self::dec($row['volume'] ?? null));
                $missedVolume = Decimal::add($missedVolume, self::dec($row['missed_volume'] ?? null));
                $covered = $covered && (new Query($this->db))->from('meter_snapshots')
                    ->where(['controller_id' => $controllerId, 'pump_number' => $number])
                    ->andWhere(['<=', 'observed_at', $fromUtc])->exists();
            }

            // Sales linked to a fill count in the fill's window (late registration is not penalised); unlinked sales
            // count by their own creation time within the window (docs/transaction-lifecycle.md §6.1).
            $linked = (new Query($this->db))->select(['n' => 'COUNT(*)', 'volume' => 'SUM(s.volume)', 'amount' => 'SUM(s.amount)'])
                ->from(['s' => 'odoo_sales'])
                ->innerJoin(['ml' => 'match_links'], 'ml.odoo_sale_id = s.id')
                ->innerJoin(['f' => 'fill_events'], 'f.id = ml.fill_event_id')
                ->where(['s.station_id' => $stationId, 's.payment_status' => 'APPROVED'])
                ->andWhere(['>=', 'f.ended_at', $fromUtc])->andWhere(['<', 'f.ended_at', $toUtc]);
            $unlinked = (new Query($this->db))->select(['n' => 'COUNT(*)', 'volume' => 'SUM(s.volume)', 'amount' => 'SUM(s.amount)'])
                ->from(['s' => 'odoo_sales'])
                ->leftJoin(['ml' => 'match_links'], 'ml.odoo_sale_id = s.id')
                ->where(['s.station_id' => $stationId, 's.payment_status' => 'APPROVED', 'ml.id' => null])
                ->andWhere(['>=', 's.sale_created_at', $fromUtc])->andWhere(['<', 's.sale_created_at', $toUtc]);
            if ($groupBy === self::BY_PUMP) {
                $linked->andWhere(['f.pump_number' => $group['pump']]);
                $unlinked->andWhere(['s.pump_number' => $group['pump']]);
            } else {
                $linked->andWhere(['f.fuel_name' => $group['grade']]);
                $unlinked->andWhere(['s.fuel_name' => $group['grade']]);
            }
            /** @var array{n: string, volume: ?string, amount: ?string} $linkedRow */
            $linkedRow = $linked->one();
            /** @var array{n: string, volume: ?string, amount: ?string} $unlinkedRow */
            $unlinkedRow = $unlinked->one();
            $sold = [
                'n' => (int) $linkedRow['n'] + (int) $unlinkedRow['n'],
                'volume' => Decimal::add(self::dec($linkedRow['volume'] ?? null), self::dec($unlinkedRow['volume'] ?? null)),
                'amount' => Decimal::add(self::dec($linkedRow['amount'] ?? null, 2), self::dec($unlinkedRow['amount'] ?? null, 2), 2),
            ];

            $dispensed = Decimal::add($fillVolume, $missedVolume);
            $soldVolume = self::dec($sold['volume'] ?? null);
            $gap = Decimal::sub($dispensed, $soldVolume);

            $line = new VolumeLine(
                label: $group['label'],
                pump: $group['pump'],
                grade: $group['grade'],
                fills: $fills,
                missedFills: $missed,
                dispensed: $dispensed,
                missedVolume: $missedVolume,
                sales: (int) $sold['n'],
                sold: $soldVolume,
                soldAmount: self::dec($sold['amount'] ?? null, 2),
                gap: $gap,
                coverage: $covered ? 'complete' : 'partial',
                flagged: $covered && self::significant($gap, $dispensed),
            );
            $report->lines[] = $line;

            if ($persist && $line->flagged) {
                $this->raise($stationId, $fromUtc, $toUtc, $line);
            }
        }

        $this->countUnmatchedSales($report, $stationId, $fromUtc, $toUtc, $groupBy);

        if ($persist) {
            $this->db->createCommand()->insert('reconciliation_runs', [
                'station_id' => $stationId,
                'scope' => 'period',
                'window_start' => $fromUtc,
                'window_end' => $toUtc,
                'status' => $report->status(),
                'stats' => json_encode($report->totals(), JsonResponder::FLAGS),
                'started_at' => $this->clock->nowForDb(),
                'finished_at' => $this->clock->nowForDb(),
            ])->execute();
        }

        return $report;
    }

    /**
     * Sales the grouping cannot place: grade mode → fuel names the controller does not dispense (non-fuel excluded);
     * pump mode → pump numbers the controller does not have.
     */
    private function countUnmatchedSales(VolumeReport $report, int $stationId, string $fromUtc, string $toUtc, string $groupBy): void
    {
        $query = (new Query($this->db))->select(['n' => 'COUNT(*)', 'volume' => 'SUM(s.volume)'])->from(['s' => 'odoo_sales'])
            ->where(['s.station_id' => $stationId, 's.payment_status' => 'APPROVED'])
            ->andWhere(['>=', 's.sale_created_at', $fromUtc])->andWhere(['<', 's.sale_created_at', $toUtc])
            ->andWhere(['not in', 's.fuel_name', self::NON_FUEL]);
        if ($groupBy === self::BY_PUMP) {
            $query->leftJoin(['p' => 'pumps'], 'p.station_id = s.station_id AND p.pump_number = s.pump_number AND p.on_controller = 1')
                ->andWhere(['p.id' => null]);
        } else {
            $grades = (new Query($this->db))->select('n.fuel_name')->distinct()->from(['n' => 'nozzles'])
                ->where(['n.station_id' => $stationId, 'n.on_controller' => 1]);
            $query->andWhere(['or', ['s.fuel_name' => null], ['not in', 's.fuel_name', $grades]]);
        }
        /** @var array{n: string, volume: ?string} $row */
        $row = $query->one();
        $report->unplacedSales = (int) $row['n'];
        $report->unplacedSalesVolume = self::dec($row['volume'] ?? null);
    }

    private function raise(int $stationId, string $fromUtc, string $toUtc, VolumeLine $line): void
    {
        $type = Decimal::compare($line->gap, '0') > 0 ? 'unsold_volume' : 'oversold_volume';
        $columns = [
            'station_id' => $stationId,
            'type' => $type,
            'severity' => 'warning',
            'status' => 'open',
            'fingerprint' => hash('sha256', "$stationId|$type|{$line->label}|$fromUtc|$toUtc"),
            'period_start' => $fromUtc,
            'period_end' => $toUtc,
            'pump_number' => $line->pump,
            'nozzle_number' => $line->pump === null ? null : 1,
            'volume' => $line->gap,
            'summary' => sprintf(
                '%s: dispensed %s L, sold %s L (%s %s L)',
                $line->label,
                $line->dispensed,
                $line->sold,
                $type === 'unsold_volume' ? 'unsold' : 'oversold',
                ltrim($line->gap, '-'),
            ),
            'details' => json_encode($line, JsonResponder::FLAGS),
        ];
        $update = $columns;
        unset($update['status'], $update['fingerprint']);
        $this->db->createCommand()->upsert('discrepancies', $columns, $update)->execute();
    }

    private static function significant(string $gap, string $dispensed): bool
    {
        $threshold = Decimal::mul($dispensed, self::MIN_GAP_RATIO);
        if (Decimal::compare($threshold, self::MIN_GAP_LITRES) < 0) {
            $threshold = self::MIN_GAP_LITRES;
        }

        return Decimal::compare(Decimal::abs($gap), $threshold) > 0;
    }

    /** @return numeric-string */
    private static function dec(mixed $value, int $scale = 3): string
    {
        return Decimal::of(is_numeric($value) ? $value : null, $scale);
    }
}
