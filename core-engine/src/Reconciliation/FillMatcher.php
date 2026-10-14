<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Shared\Clock;
use App\Shared\Decimal;
use App\Shared\Http\JsonResponder;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function count;
use function hash;
use function json_encode;
use function sprintf;

/**
 * Fill-level reconciliation (docs/transaction-lifecycle.md §6.2): links controller fills to Odoo sales.
 *
 * Fills are processed in chronological order. Candidate sales: same station and fuel grade, APPROVED, not yet linked,
 * created in [fill start − 2 min, fill end + grace] (start, so prepaid sales count). The grade is used instead of the pump because, with PTS off on the
 * devices, the pump number on a manual sale is effectively the fuel grade (live finding 2026-10-11). Rules, in order:
 *   1. same pump and sale.pts_transaction_id = fill transaction no. → confirmed (tx_id); the sale may come up to
 *      12 h after the fill (a fill left PENDING and paid later gets a new `created_at`, live finding 2026-10-14)
 * Rules 2–4 skip sales whose pump + transaction number names a fill the collector saw: such a sale belongs to that
 * fill only. A sale naming an unknown fill may still be fingerprinted (wrong pump/transaction recorded, D21).
 *   2. amount ±0.05 SAR and volume ±0.05 L, exactly one candidate    → confirmed (fingerprint)
 *   3. amount/volume fingerprint matches, several candidates         → probable, earliest sale (sequential)
 *   4. amount ±0.05 only, exactly one candidate                      → probable (fingerprint)
 *   5. otherwise, once the grace period has passed                   → unmatched_fill discrepancy
 * Amount and time alone never yield "confirmed". After matching, APPROVED sales on controller pumps left without a
 * fill past the grace period become `sale_without_fill`, or `duplicate_candidate` when another linked sale probably
 * paid the same fill (see twin(): named fill already paid, same pump + amount in the session, or the 15-min rule). Detect and report only (D2).
 */
final class FillMatcher
{
    private const TOLERANCE_AMOUNT = '0.05';
    private const TOLERANCE_VOLUME = '0.050';
    private const LEAD_SECONDS = 120;
    private const TX_LINK_SECONDS = 43200;
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

        /** @var list<array{id: string, pump_number: string, nozzle_number: string, fuel_name: ?string, pts_transaction_no: ?string, started_at: ?string, ended_at: string, volume: string, amount: string}> $fills */
        $fills = (new Query($this->db))
            ->select(['id', 'pump_number', 'nozzle_number', 'fuel_name', 'pts_transaction_no', 'started_at', 'ended_at', 'volume', 'amount'])
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
     * @param array{id: string, pump_number: string, nozzle_number: string, fuel_name: ?string, pts_transaction_no: ?string, started_at: ?string, ended_at: string, volume: string, amount: string} $fill
     */
    private function matchFill(int $stationId, array $fill, DateTimeImmutable $now, int $graceSeconds, MatchResult $result): void
    {
        $ended = new DateTimeImmutable($fill['ended_at'] . ' UTC');
        // Prepaid sales (charged before the nozzle is lifted, common when the controller is slow) precede the fill
        // start, so the lead window is measured from the start (live finding 2026-10-15: card sale 3 min before end).
        $earliest = (new DateTimeImmutable(($fill['started_at'] ?? $fill['ended_at']) . ' UTC'))
            ->modify('-' . self::LEAD_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');
        $windowEnd = $ended->modify("+{$graceSeconds} seconds");

        $sameGroup = $fill['fuel_name'] !== null ? ['s.fuel_name' => $fill['fuel_name']] : ['s.pump_number' => (int) $fill['pump_number']];
        // A sale naming a fill the collector saw belongs to that fill (rule 1). Fingerprinting it to an earlier fill of
        // the same amount shifts every later sale by one down the chain (live finding 2026-10-14).
        $namedFill = (new Query($this->db))->select(new Expression('1'))->from(['nf' => 'fill_events'])
            ->where('nf.station_id = s.station_id AND nf.pump_number = s.pump_number AND nf.pts_transaction_no = s.pts_transaction_id');
        /** @var list<array{id: string, pump_number: ?string, pts_transaction_id: ?string, volume: string, amount: string, sale_created_at: string}> $candidates */
        $candidates = (new Query($this->db))
            ->select(['s.id', 's.pump_number', 's.pts_transaction_id', 's.volume', 's.amount', 's.sale_created_at'])
            ->from(['s' => 'odoo_sales'])
            ->where(['s.station_id' => $stationId, 's.payment_status' => 'APPROVED', 's.match_state' => 'unlinked'] + $sameGroup)
            ->andWhere(['not exists', $namedFill])
            ->andWhere(['>=', 's.sale_created_at', $earliest])
            ->andWhere(['<=', 's.sale_created_at', $windowEnd->format('Y-m-d H:i:s.v')])
            ->orderBy(['s.sale_created_at' => SORT_ASC, 's.id' => SORT_ASC])
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
                ->andWhere(['>=', 'sale_created_at', $earliest])
                ->andWhere(['<=', 'sale_created_at', $ended->modify('+' . self::TX_LINK_SECONDS . ' seconds')->format('Y-m-d H:i:s.v')])
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
        /** @var list<array{id: string, pump_number: ?string, fuel_name: ?string, volume: string, amount: string, sale_created_at: string, uuid: string, payment_method: ?string, rrn: ?string, pts_transaction_id: ?string, odoo_pos_session_id: ?string}> $sales */
        $sales = (new Query($this->db))
            ->select(['s.id', 's.pump_number', 's.fuel_name', 's.volume', 's.amount', 's.sale_created_at', 's.uuid', 's.payment_method', 's.rrn',
                's.pts_transaction_id', 's.odoo_pos_session_id'])
            ->from(['s' => 'odoo_sales'])
            ->where(['s.station_id' => $stationId, 's.payment_status' => 'APPROVED', 's.match_state' => 'unlinked'])
            ->andWhere(['in', 's.fuel_name', $grades])
            ->andWhere(['>=', 's.sale_created_at', $fromUtc])->andWhere(['<', 's.sale_created_at', $until])
            ->all();

        foreach ($sales as $sale) {
            [$twinId, $paidFill] = $this->twin($stationId, $sale);

            $isDuplicate = $twinId !== null;
            $state = $isDuplicate ? 'duplicate_candidate' : 'sale_without_fill';
            $this->db->createCommand()->update('odoo_sales', ['match_state' => $state], ['id' => (int) $sale['id']])->execute();
            $this->raise(
                $stationId,
                $state,
                (int) ($sale['pump_number'] ?? 0),
                $sale['sale_created_at'],
                $sale['volume'],
                $sale['amount'],
                match (true) {
                    $paidFill => sprintf('%s: sale SAR %s pays PTS transaction %s on pump %d, which another sale already paid', (string) $sale['fuel_name'], $sale['amount'], (string) $sale['pts_transaction_id'], (int) $sale['pump_number']),
                    $isDuplicate => sprintf('%s: sale SAR %s may duplicate another sale of the same amount (no second fill observed)', (string) $sale['fuel_name'], $sale['amount']),
                    default => sprintf('%s: sale SAR %s at %s UTC has no matching controller fill', (string) $sale['fuel_name'], $sale['amount'], $sale['sale_created_at']),
                },
                ['odoo_sale_id' => (int) $sale['id'], 'uuid' => $sale['uuid'], 'twin_sale_id' => $twinId,
                    'payment_method' => $sale['payment_method'], 'rrn' => $sale['rrn']],
                "sale:{$sale['id']}",
                $isDuplicate && ($paidFill || $sale['rrn'] !== null) ? 'high' : 'warning',
            );
            $isDuplicate ? $result->duplicateCandidates++ : $result->salesWithoutFill++;
        }
    }

    /**
     * The already-linked sale an unlinked sale probably duplicates. A late payment of a PENDING record gets a new
     * `created_at` (70 min and 2 h seen on 2026-10-14), so a fixed time window misses real duplicates. In order:
     *   A. the sale names a fill (pump + transaction) that another sale paid       → definite;
     *   B. a linked sale on the same pump with the same amount in the same POS session (±12 h without a session);
     *   C. a linked sale of the same grade and amount within 15 minutes (pump unknown, D19).
     *
     * @param array{id: string, pump_number: ?string, fuel_name: ?string, amount: string, sale_created_at: string, pts_transaction_id: ?string, odoo_pos_session_id: ?string, ...} $sale
     *
     * @return array{?int, bool} [twin sale id, whether the sale names a fill already paid]
     */
    private function twin(int $stationId, array $sale): array
    {
        $saleId = (int) $sale['id'];
        $pump = (int) ($sale['pump_number'] ?? 0);

        if ($sale['pts_transaction_id'] !== null && $pump > 0) {
            $payer = (new Query($this->db))->select('ml.odoo_sale_id')->from(['f' => 'fill_events'])
                ->innerJoin(['ml' => 'match_links'], 'ml.fill_event_id = f.id')
                ->where(['f.station_id' => $stationId, 'f.pump_number' => $pump, 'f.pts_transaction_no' => $sale['pts_transaction_id']])
                ->andWhere(['<>', 'ml.odoo_sale_id', $saleId])
                ->scalar();
            if ($payer !== false && $payer !== null) {
                return [(int) $payer, true];
            }
        }

        $createdAt = new DateTimeImmutable($sale['sale_created_at'] . ' UTC');
        if ($pump > 0) {
            $query = (new Query($this->db))->select('id')->from('odoo_sales')
                ->where(['station_id' => $stationId, 'pump_number' => $pump, 'match_state' => 'linked', 'amount' => $sale['amount']])
                ->andWhere(['not', ['id' => $saleId]]);
            $query = $sale['odoo_pos_session_id'] !== null
                ? $query->andWhere(['odoo_pos_session_id' => (int) $sale['odoo_pos_session_id']])
                : $query->andWhere(['between', 'sale_created_at',
                    $createdAt->modify('-12 hours')->format('Y-m-d H:i:s.v'), $createdAt->modify('+12 hours')->format('Y-m-d H:i:s.v')]);
            $twin = $query->orderBy(['sale_created_at' => SORT_DESC])->scalar();
            if ($twin !== false && $twin !== null) {
                return [(int) $twin, false];
            }
        }

        $twin = (new Query($this->db))->select('id')->from('odoo_sales')
            ->where(['station_id' => $stationId, 'fuel_name' => $sale['fuel_name'], 'match_state' => 'linked', 'amount' => $sale['amount']])
            ->andWhere(['between', 'sale_created_at',
                $createdAt->modify('-' . self::DUPLICATE_WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s.v'),
                $createdAt->modify('+' . self::DUPLICATE_WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s.v')])
            ->andWhere(['not', ['id' => $saleId]])
            ->scalar();

        return [$twin === false || $twin === null ? null : (int) $twin, false];
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
