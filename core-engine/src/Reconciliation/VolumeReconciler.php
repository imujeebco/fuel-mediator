<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Shared\Clock;
use App\Shared\Decimal;
use App\Shared\Http\JsonResponder;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function hash;
use function json_encode;
use function ltrim;

/**
 * Volume-level reconciliation (docs/transaction-lifecycle.md §6.1): for each pump/nozzle in a window, litres
 * dispensed according to the controller vs litres sold in Odoo.
 *
 *   dispensed = Σ fill volume (fills ending in window) + Σ volume of fills missed between polls (from tx/meter gaps)
 *   sold      = Σ volume of APPROVED Odoo terminal sales on that pump created in [from, to + grace]
 *
 * Coverage is "complete" only when the collector had already observed the pump before the window started;
 * otherwise "partial" and no discrepancy is raised (a window is never reported clean without evidence).
 * Detect and report only (decision D2): nothing is changed in Odoo.
 */
final class VolumeReconciler
{
    private const MIN_GAP_LITRES = '0.500';
    private const MIN_GAP_RATIO = '0.005';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param string $fromUtc MySQL datetime, UTC
     * @param string $toUtc MySQL datetime, UTC
     */
    public function reconcile(int $stationId, string $fromUtc, string $toUtc, int $graceMinutes = 10, bool $persist = true): VolumeReport
    {
        $salesTo = (new DateTimeImmutable($toUtc . ' UTC'))->modify("+{$graceMinutes} minutes")->format('Y-m-d H:i:s.v');
        $report = new VolumeReport($stationId, $fromUtc, $toUtc, $graceMinutes);

        /** @var list<array{controller_id: string, pump_number: string}> $pumps */
        $pumps = (new Query($this->db))->select(['p.controller_id', 'p.pump_number'])->from(['p' => 'pumps'])
            ->innerJoin(['c' => 'controllers'], 'c.id = p.controller_id')
            ->where(['p.station_id' => $stationId, 'p.on_controller' => 1, 'c.enabled' => 1])
            ->orderBy(['p.pump_number' => SORT_ASC])->all();

        foreach ($pumps as $pump) {
            $controllerId = (int) $pump['controller_id'];
            $number = (int) $pump['pump_number'];

            /** @var array{n: string, volume: ?string, missed_n: ?string, missed_volume: ?string} $fills */
            $fills = (new Query($this->db))->select([
                'n' => 'COUNT(*)',
                'volume' => 'SUM(volume)',
                'missed_n' => 'SUM(preceding_missed_count)',
                'missed_volume' => 'SUM(COALESCE(preceding_missed_volume, 0))',
            ])->from('fill_events')
                ->where(['controller_id' => $controllerId, 'pump_number' => $number])
                ->andWhere(['>=', 'ended_at', $fromUtc])->andWhere(['<', 'ended_at', $toUtc])
                ->one();

            /** @var array{n: string, volume: ?string, amount: ?string} $sales */
            $sales = (new Query($this->db))->select(['n' => 'COUNT(*)', 'volume' => 'SUM(volume)', 'amount' => 'SUM(amount)'])
                ->from('odoo_sales')
                ->where(['station_id' => $stationId, 'pump_number' => $number, 'payment_status' => 'APPROVED'])
                ->andWhere(['>=', 'sale_created_at', $fromUtc])->andWhere(['<', 'sale_created_at', $salesTo])
                ->one();

            $covered = (new Query($this->db))->from('meter_snapshots')
                ->where(['controller_id' => $controllerId, 'pump_number' => $number])
                ->andWhere(['<=', 'observed_at', $fromUtc])->exists();

            $fillVolume = self::dec($fills['volume'] ?? null);
            $missedVolume = self::dec($fills['missed_volume'] ?? null);
            $dispensed = Decimal::add($fillVolume, $missedVolume);
            $sold = self::dec($sales['volume'] ?? null);
            $gap = Decimal::sub($dispensed, $sold);

            $line = new VolumeLine(
                pump: $number,
                fills: (int) $fills['n'],
                missedFills: (int) ($fills['missed_n'] ?? 0),
                dispensed: $dispensed,
                missedVolume: $missedVolume,
                sales: (int) $sales['n'],
                sold: $sold,
                soldAmount: self::dec($sales['amount'] ?? null, 2),
                gap: $gap,
                coverage: $covered ? 'complete' : 'partial',
                flagged: $covered && self::significant($gap, $dispensed),
            );
            $report->lines[] = $line;

            if ($persist && $line->flagged) {
                $this->raise($stationId, $number, $fromUtc, $toUtc, $line);
            }
        }

        // Sales on pumps the controller does not have (e.g. wrong pump keyed in a manual sale).
        /** @var array{n: string, volume: ?string} $orphans */
        $orphans = (new Query($this->db))->select(['n' => 'COUNT(*)', 'volume' => 'SUM(s.volume)'])
            ->from(['s' => 'odoo_sales'])
            ->leftJoin(['p' => 'pumps'], 'p.station_id = s.station_id AND p.pump_number = s.pump_number AND p.on_controller = 1')
            ->where(['s.station_id' => $stationId, 's.payment_status' => 'APPROVED', 'p.id' => null])
            ->andWhere(['>=', 's.sale_created_at', $fromUtc])->andWhere(['<', 's.sale_created_at', $salesTo])
            ->one();
        $report->salesOnUnknownPumps = (int) $orphans['n'];
        $report->salesOnUnknownPumpsVolume = self::dec($orphans['volume'] ?? null);

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

    private function raise(int $stationId, int $pump, string $fromUtc, string $toUtc, VolumeLine $line): void
    {
        $type = Decimal::compare($line->gap, '0') > 0 ? 'unsold_volume' : 'oversold_volume';
        $columns = [
            'station_id' => $stationId,
            'type' => $type,
            'severity' => 'warning',
            'status' => 'open',
            'fingerprint' => hash('sha256', "$stationId|$type|$pump|$fromUtc|$toUtc"),
            'period_start' => $fromUtc,
            'period_end' => $toUtc,
            'pump_number' => $pump,
            'nozzle_number' => 1,
            'volume' => $line->gap,
            'summary' => sprintf(
                'Pump %d: dispensed %s L, sold %s L (%s %s L)',
                $pump,
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
