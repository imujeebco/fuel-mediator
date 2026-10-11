<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Shared\Clock;
use App\Shared\Decimal;
use App\Shared\Http\JsonResponder;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function count;
use function hash;
use function json_encode;
use function sprintf;

/**
 * Fill-level reconciliation (docs/transaction-lifecycle.md §6.2): links controller fills to Odoo sales.
 *
 * Fills are processed in chronological order. Candidate sales: same station and fuel grade, APPROVED, not yet linked,
 * created in [fill end − 2 min, fill end + grace]. The grade is used instead of the pump because, with PTS off on the
 * devices, the pump number on a manual sale is effectively the fuel grade (live finding 2026-10-11). Rules, in order:
 *   1. same pump and sale.pts_transaction_id = fill transaction no. → confirmed (tx_id)
 *   2. amount ±0.05 SAR and volume ±0.05 L, exactly one candidate    → confirmed (fingerprint)
 *   3. amount/volume fingerprint matches, several candidates         → probable, earliest sale (sequential)
 *   4. amount ±0.05 only, exactly one candidate                      → probable (fingerprint)
 *   5. otherwise, once the grace period has passed                   → unmatched_fill discrepancy
 * Amount and time alone never yield "confirmed". After matching, APPROVED sales on controller pumps left without a
 * fill past the grace period become `sale_without_fill`; if an identical sale of the same grade was linked within
 * 15 minutes it is a `duplicate_candidate` (manual sale after a timeout + PTS sale). Detect and report only (D2).
 */
final class FillMatcher
{
    private const TOLERANCE_AMOUNT = '0.05';
    private const TOLERANCE_VOLUME = '0.050';
    private const LEAD_SECONDS = 120;
    private const DUPLICATE_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
    ) {}

    public function match(int $stationId, string $fromUtc, string $toUtc, int $graceMinutes = 30): MatchResult
    {
        $result = new MatchResult();
        $now = $this->clock->now();
        $graceSeconds = $graceMinutes * 60;

        /** @var list<array{id: string, pump_number: string, nozzle_number: string, fuel_name: ?string, pts_transaction_no: ?string, ended_at: string, volume: string, amount: string}> $fills */
        $fills = (new Query($this->db))
            ->select(['id', 'pump_number', 'nozzle_number', 'fuel_name', 'pts_transaction_no', 'ended_at', 'volume', 'amount'])
            ->from('fill_events')
            ->where(['station_id' => $stationId, 'state' => ['observed', 'unmatched', 'ambiguous']])
            ->andWhere(['>=', 'ended_at', $fromUtc])->andWhere(['<', 'ended_at', $toUtc])
            ->orderBy(['ended_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        foreach ($fills as $fill) {
            $this->db->transaction(fn() => $this->matchFill($stationId, $fill, $now, $graceSeconds, $result));
        }

        $this->db->transaction(fn() => $this->flagUnlinkedSales($stationId, $fromUtc, $toUtc, $now, $graceSeconds, $result));

        return $result;
    }

    /**
     * @param array{id: string, pump_number: string, nozzle_number: string, fuel_name: ?string, pts_transaction_no: ?string, ended_at: string, volume: string, amount: string} $fill
     */
    private function matchFill(int $stationId, array $fill, DateTimeImmutable $now, int $graceSeconds, MatchResult $result): void
    {
        $ended = new DateTimeImmutable($fill['ended_at'] . ' UTC');
        $windowEnd = $ended->modify("+{$graceSeconds} seconds");

        $sameGroup = $fill['fuel_name'] !== null ? ['fuel_name' => $fill['fuel_name']] : ['pump_number' => (int) $fill['pump_number']];
        /** @var list<array{id: string, pump_number: ?string, pts_transaction_id: ?string, volume: string, amount: string, sale_created_at: string}> $candidates */
        $candidates = (new Query($this->db))
            ->select(['id', 'pump_number', 'pts_transaction_id', 'volume', 'amount', 'sale_created_at'])
            ->from('odoo_sales')
            ->where(['station_id' => $stationId, 'payment_status' => 'APPROVED', 'match_state' => 'unlinked'] + $sameGroup)
            ->andWhere(['>=', 'sale_created_at', $ended->modify('-' . self::LEAD_SECONDS . ' seconds')->format('Y-m-d H:i:s.v')])
            ->andWhere(['<=', 'sale_created_at', $windowEnd->format('Y-m-d H:i:s.v')])
            ->orderBy(['sale_created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        // Rule 1: same pump and transaction number — independent of grade (PTS-mode sales may lack a fuel name).
        if ($fill['pts_transaction_no'] !== null) {
            $txSale = (new Query($this->db))->select('id')->from('odoo_sales')
                ->where([
                    'station_id' => $stationId,
                    'payment_status' => 'APPROVED',
                    'match_state' => 'unlinked',
                    'pump_number' => (int) $fill['pump_number'],
                    'pts_transaction_id' => $fill['pts_transaction_no'],
                ])
                ->andWhere(['>=', 'sale_created_at', $ended->modify('-' . self::LEAD_SECONDS . ' seconds')->format('Y-m-d H:i:s.v')])
                ->andWhere(['<=', 'sale_created_at', $windowEnd->format('Y-m-d H:i:s.v')])
                ->orderBy(['sale_created_at' => SORT_ASC])->scalar();
            if ($txSale !== false && $txSale !== null) {
                $this->link($stationId, (int) $fill['id'], (int) $txSale, 'tx_id', 'confirmed', $result);
                return;
            }
        }

        $exact = [];
        $amountOnly = [];
        foreach ($candidates as $sale) {
            $amountClose = Decimal::compare(Decimal::abs(Decimal::sub($sale['amount'], $fill['amount'], 2), 2), self::TOLERANCE_AMOUNT, 2) <= 0;
            $volumeClose = Decimal::compare(Decimal::abs(Decimal::sub($sale['volume'], $fill['volume'])), self::TOLERANCE_VOLUME) <= 0;
            if ($amountClose && $volumeClose) {
                $exact[] = $sale;
            } elseif ($amountClose) {
                $amountOnly[] = $sale;
            }
        }

        if (count($exact) === 1) {
            $this->link($stationId, (int) $fill['id'], (int) $exact[0]['id'], 'fingerprint', 'confirmed', $result);
            return;
        }
        if (count($exact) > 1) {
            $this->link($stationId, (int) $fill['id'], (int) $exact[0]['id'], 'fingerprint', 'probable', $result);
            return;
        }
        if (count($amountOnly) === 1) {
            $this->link($stationId, (int) $fill['id'], (int) $amountOnly[0]['id'], 'fingerprint', 'probable', $result);
            return;
        }

        if ($now < $windowEnd) {
            $result->pending++;
            return; // a sale may still be registered (FuelPay offline queue)
        }

        $this->db->createCommand()->update('fill_events', ['state' => 'unmatched'], ['id' => (int) $fill['id']])->execute();
        $this->raise($stationId, 'unmatched_fill', (int) $fill['pump_number'], $fill['ended_at'], $fill['volume'], $fill['amount'], sprintf(
            'Pump %d fill %s L / SAR %s at %s UTC has no Odoo sale',
            (int) $fill['pump_number'],
            $fill['volume'],
            $fill['amount'],
            $fill['ended_at'],
        ), ['fill_event_id' => (int) $fill['id'], 'pts_transaction_no' => $fill['pts_transaction_no']], "fill:{$fill['id']}");
        $result->unmatchedFills++;
    }

    private function flagUnlinkedSales(int $stationId, string $fromUtc, string $toUtc, DateTimeImmutable $now, int $graceSeconds, MatchResult $result): void
    {
        $settledBefore = $now->modify("-{$graceSeconds} seconds")->format('Y-m-d H:i:s.v');
        $until = $toUtc < $settledBefore ? $toUtc : $settledBefore;

        $grades = (new Query($this->db))->select('fuel_name')->distinct()->from('nozzles')
            ->where(['station_id' => $stationId, 'on_controller' => 1]);
        /** @var list<array{id: string, pump_number: ?string, fuel_name: ?string, volume: string, amount: string, sale_created_at: string, uuid: string, payment_method: ?string, rrn: ?string}> $sales */
        $sales = (new Query($this->db))
            ->select(['s.id', 's.pump_number', 's.fuel_name', 's.volume', 's.amount', 's.sale_created_at', 's.uuid', 's.payment_method', 's.rrn'])
            ->from(['s' => 'odoo_sales'])
            ->where(['s.station_id' => $stationId, 's.payment_status' => 'APPROVED', 's.match_state' => 'unlinked'])
            ->andWhere(['in', 's.fuel_name', $grades])
            ->andWhere(['>=', 's.sale_created_at', $fromUtc])->andWhere(['<', 's.sale_created_at', $until])
            ->all();

        foreach ($sales as $sale) {
            $createdAt = new DateTimeImmutable($sale['sale_created_at'] . ' UTC');
            $twinId = (new Query($this->db))->select('id')->from('odoo_sales')
                ->where(['station_id' => $stationId, 'fuel_name' => $sale['fuel_name'], 'match_state' => 'linked', 'amount' => $sale['amount']])
                ->andWhere(['between', 'sale_created_at',
                    $createdAt->modify('-' . self::DUPLICATE_WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s.v'),
                    $createdAt->modify('+' . self::DUPLICATE_WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s.v')])
                ->andWhere(['not', ['id' => (int) $sale['id']]])
                ->scalar();

            $isDuplicate = $twinId !== false && $twinId !== null;
            $state = $isDuplicate ? 'duplicate_candidate' : 'sale_without_fill';
            $this->db->createCommand()->update('odoo_sales', ['match_state' => $state], ['id' => (int) $sale['id']])->execute();
            $this->raise(
                $stationId,
                $state,
                (int) ($sale['pump_number'] ?? 0),
                $sale['sale_created_at'],
                $sale['volume'],
                $sale['amount'],
                $isDuplicate
                    ? sprintf('%s: sale SAR %s may duplicate another sale of the same amount (no second fill observed)', (string) $sale['fuel_name'], $sale['amount'])
                    : sprintf('%s: sale SAR %s at %s UTC has no matching controller fill', (string) $sale['fuel_name'], $sale['amount'], $sale['sale_created_at']),
                ['odoo_sale_id' => (int) $sale['id'], 'uuid' => $sale['uuid'], 'twin_sale_id' => $isDuplicate ? (int) $twinId : null,
                    'payment_method' => $sale['payment_method'], 'rrn' => $sale['rrn']],
                "sale:{$sale['id']}",
                $isDuplicate && $sale['rrn'] !== null ? 'high' : 'warning',
            );
            $isDuplicate ? $result->duplicateCandidates++ : $result->salesWithoutFill++;
        }
    }

    private function link(int $stationId, int $fillId, int $saleId, string $method, string $confidence, MatchResult $result): void
    {
        $this->db->createCommand()->insert('match_links', [
            'station_id' => $stationId,
            'fill_event_id' => $fillId,
            'odoo_sale_id' => $saleId,
            'method' => $method,
            'confidence' => $confidence,
        ])->execute();
        $this->db->createCommand()->update('fill_events', ['state' => 'matched'], ['id' => $fillId])->execute();
        $this->db->createCommand()->update('odoo_sales', ['match_state' => 'linked'], ['id' => $saleId])->execute();
        // A late sale resolves an earlier "unmatched fill" automatically.
        $this->db->createCommand()->update(
            'discrepancies',
            ['status' => 'resolved', 'resolved_by' => 'system', 'resolved_at' => $this->clock->nowForDb(), 'resolution_note' => 'Matching sale found'],
            ['fingerprint' => hash('sha256', "$stationId|unmatched_fill|fill:$fillId"), 'status' => 'open'],
        )->execute();
        $confidence === 'confirmed' ? $result->confirmed++ : $result->probable++;
    }

    private function raise(
        int $stationId,
        string $type,
        int $pump,
        string $at,
        string $volume,
        string $amount,
        string $summary,
        array $details,
        string $key,
        string $severity = 'warning',
    ): void {
        $columns = [
            'station_id' => $stationId,
            'type' => $type,
            'severity' => $severity,
            'status' => 'open',
            'fingerprint' => hash('sha256', "$stationId|$type|$key"),
            'period_start' => $at,
            'period_end' => $at,
            'pump_number' => $pump,
            'nozzle_number' => 1,
            'volume' => $volume,
            'amount' => $amount,
            'summary' => $summary,
            'details' => json_encode($details, JsonResponder::FLAGS),
        ];
        $update = $columns;
        unset($update['status'], $update['fingerprint']);
        $this->db->createCommand()->upsert('discrepancies', $columns, $update)->execute();
    }
}
