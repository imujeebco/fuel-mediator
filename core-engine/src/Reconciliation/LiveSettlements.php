<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Shared\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function abs;
use function array_map;
use function in_array;

/**
 * Decision D28: a PTS fill is paid when a manual sale on the same pump paid it, known the moment the mediator relays
 * that sale; no controller collection or sync pass is needed.
 *
 * When the controller is slow or down, attendants charge the customer through manual entry (no transaction number).
 * FuelPay never links that sale to the PTS fill, whose PENDING record then sits in every device's unpaid list until
 * someone charges it again (live findings 2026-10-14/15: pump 18 SAR 50 card + cash, pump 9 SAR 86 two card charges,
 * pump 7 SAR 151.92 pending after a card payment).
 *
 * Manual entries do not carry a reliable pump (pump 7's SAR 151.92 was entered under pump 2, live 2026-10-15; D19),
 * so attribution is by fuel grade G of the fill's pump (controller configuration; the fill's own pump when unknown).
 * A fill (pump P, transaction T, amount A, created at t) is paid by a manual sale when:
 *  - the sale is APPROVED, of grade G (its pump's grade or its fuel name), has no transaction number, amount A
 *    ±0.05 SAR, litres ±0.05 L when both are known, and was created within t ±30 min; it comes from the sales mirror
 *    (not linked to a different fill) or from the relay journal (Odoo accepted the call); a sale on pump P is preferred;
 *  - no OTHER fill of grade G with amount A ±0.05 exists within t ±30 min, from any source (other unpaid records,
 *    other PTS sales in Odoo, the relay journal, the collector). Otherwise the payment cannot be attributed and the
 *    fill stays unpaid, which is the behaviour without this rule. Common amounts (SAR 50) are therefore rarely
 *    settled at a busy station; unusual ones (SAR 151.92) are.
 *
 * The verdict is recomputed on every request (new evidence can only turn it back to unpaid); `live_settlements` logs
 * what was reported.
 *
 * @psalm-type Payer = array{source: 'mirror'|'relay', ref: int, uuid: string}
 * @psalm-type LiveSettlement = array{pump_number: int, pts_transaction_no: string, amount: string, volume: ?string, fill_created_at: string, pending_uuid: ?string}
 */
final class LiveSettlements
{
    public const WINDOW_SECONDS = 1800;
    private const AMOUNT_TOLERANCE = '0.05';
    private const VOLUME_TOLERANCE = '0.050';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param list<array{pump: int, amount: string}> $otherUnpaid Other unpaid records of the same device answer.
     * @param string|null $grade The fill's fuel name when known; otherwise taken from the pump's configuration.
     *
     * @return Payer|null
     */
    public function payerFor(int $stationId, int $pump, string $transactionNo, string $amount, ?string $volume, DateTimeImmutable $createdAt, array $otherUnpaid = [], ?string $grade = null): ?array
    {
        if ($pump <= 0 || $transactionNo === '') {
            return null;
        }
        $grade = $grade !== null && $grade !== '' ? $grade : $this->gradeOfPump($stationId, $pump);
        $pumps = $grade === null ? [$pump] : $this->pumpsOfGrade($stationId, $grade, $pump);
        $sameGrade = static fn(string $alias): array => $grade === null
            ? ["$alias.pump_number" => $pumps]
            : ['or', ["$alias.pump_number" => $pumps], ["$alias.fuel_name" => $grade]];
        $at = $createdAt->setTimezone(new DateTimeZone('UTC'));
        $from = $at->modify('-' . self::WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');
        $to = $at->modify('+' . self::WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');
        $amountClose = static fn(string $column): Expression => new Expression(
            "ABS($column - :amount) <= " . self::AMOUNT_TOLERANCE,
            [':amount' => $amount],
        );

        foreach ($otherUnpaid as $other) {
            if (in_array($other['pump'], $pumps, true) && abs((float) $other['amount'] - (float) $amount) <= (float) self::AMOUNT_TOLERANCE) {
                return null;
            }
        }
        if ($this->otherFillExists($stationId, $pump, $pumps, $grade, $transactionNo, $from, $to, $amountClose)) {
            return null;
        }

        $volumeClose = static fn(string $column): Expression|array => $volume === null || (float) $volume <= 0
            ? []
            : new Expression("($column IS NULL OR $column <= 0 OR ABS($column - :volume) <= " . self::VOLUME_TOLERANCE . ')', [':volume' => $volume]);

        // Mirror: approved manual sale, not linked to a fill other than this one.
        /** @var array{id: string, uuid: string}|null $mirror */
        $mirror = (new Query($this->db))->select(['s.id', 's.uuid'])->from(['s' => 'odoo_sales'])
            ->where(['s.station_id' => $stationId, 's.payment_status' => 'APPROVED'])
            ->andWhere($sameGrade('s'))
            ->andWhere(['or', ['s.pts_transaction_id' => null], ['s.pts_transaction_id' => '']])
            ->andWhere($amountClose('s.amount'))
            ->andWhere($volumeClose('s.volume'))
            ->andWhere(['between', 's.sale_created_at', $from, $to])
            ->andWhere(['not exists', (new Query($this->db))->select(new Expression('1'))->from(['ml' => 'match_links'])
                ->innerJoin(['f' => 'fill_events'], 'f.id = ml.fill_event_id')
                ->where('ml.odoo_sale_id = s.id')
                ->andWhere(['not', ['f.pump_number' => $pump, 'f.pts_transaction_no' => $transactionNo]])])
            ->orderBy([new Expression('s.pump_number <=> :pump DESC', [':pump' => $pump]), 's.sale_created_at' => SORT_ASC])
            ->one();
        if ($mirror !== null) {
            return ['source' => 'mirror', 'ref' => (int) $mirror['id'], 'uuid' => $mirror['uuid']];
        }

        // Relay journal: Odoo accepted it, seconds ago; the mirror only catches up within a minute.
        /** @var array{id: string, uuid: string}|null $relayed */
        $relayed = (new Query($this->db))->select(['o.id', 'o.uuid'])->from(['o' => 'sale_observations'])
            ->innerJoin(['rc' => 'relay_calls'], 'rc.id = o.relay_call_id')
            ->where(['o.station_id' => $stationId, 'o.pump_number' => $pumps, 'o.payment_status' => 'APPROVED', 'rc.outcome' => 'ok'])
            ->andWhere(['or', ['o.pts_transaction_id' => null], ['o.pts_transaction_id' => '']])
            ->andWhere($amountClose('o.amount'))
            ->andWhere($volumeClose('o.volume'))
            ->andWhere(['between', 'o.created_at', $from, $to])
            ->orderBy([new Expression('o.pump_number <=> :pump DESC', [':pump' => $pump]), 'o.created_at' => SORT_ASC])
            ->one();

        return $relayed === null ? null : ['source' => 'relay', 'ref' => (int) $relayed['id'], 'uuid' => $relayed['uuid']];
    }

    /**
     * Creation time of a fill's PTS record (sales mirror), for checks that only know pump and transaction number.
     */
    public function fillCreatedAt(int $stationId, int $pump, string $transactionNo): ?DateTimeImmutable
    {
        $at = (new Query($this->db))->select(new Expression('MIN(sale_created_at)'))->from('odoo_sales')
            ->where(['station_id' => $stationId, 'pump_number' => $pump, 'pts_transaction_id' => $transactionNo])
            ->scalar();

        return $at === false || $at === null ? null : new DateTimeImmutable($at . ' UTC');
    }

    /**
     * @param LiveSettlement $fill
     * @param Payer $payer
     */
    public function record(int $stationId, array $fill, array $payer, string $method): void
    {
        $now = $this->clock->nowForDb();
        $this->db->createCommand()->upsert(
            'live_settlements',
            [
                'station_id' => $stationId,
                'pump_number' => $fill['pump_number'],
                'pts_transaction_no' => $fill['pts_transaction_no'],
                'amount' => $fill['amount'],
                'volume' => $fill['volume'],
                'fill_created_at' => $fill['fill_created_at'],
                'pending_uuid' => $fill['pending_uuid'],
                'payer_source' => $payer['source'],
                'payer_ref' => $payer['ref'],
                'payer_uuid' => $payer['uuid'],
                'first_applied_at' => $now,
                'last_applied_at' => $now,
                'applied_count' => 1,
                'last_method' => $method,
            ],
            [
                'payer_source' => $payer['source'],
                'payer_ref' => $payer['ref'],
                'payer_uuid' => $payer['uuid'],
                'last_applied_at' => $now,
                'applied_count' => new Expression('live_settlements.applied_count + 1'),
                'last_method' => $method,
            ],
        )->execute();
    }

    /**
     * Fills reported as paid since $sinceUtc, still paid by today's evidence.
     *
     * @return list<array{fill: LiveSettlement, payer: Payer}>
     */
    public function recent(int $stationId, string $sinceUtc): array
    {
        /** @var list<array{pump_number: string, pts_transaction_no: string, amount: string, volume: ?string, fill_created_at: string, pending_uuid: ?string}> $rows */
        $rows = (new Query($this->db))
            ->select(['pump_number', 'pts_transaction_no', 'amount', 'volume', 'fill_created_at', 'pending_uuid'])
            ->from('live_settlements')
            ->where(['station_id' => $stationId])->andWhere(['>=', 'last_applied_at', $sinceUtc])
            ->all();
        $result = [];
        foreach ($rows as $row) {
            $fill = [
                'pump_number' => (int) $row['pump_number'],
                'pts_transaction_no' => $row['pts_transaction_no'],
                'amount' => $row['amount'],
                'volume' => $row['volume'],
                'fill_created_at' => $row['fill_created_at'],
                'pending_uuid' => $row['pending_uuid'],
            ];
            $payer = $this->payerFor($stationId, $fill['pump_number'], $fill['pts_transaction_no'], $fill['amount'], $fill['volume'],
                new DateTimeImmutable($fill['fill_created_at'] . ' UTC'));
            if ($payer !== null) {
                $result[] = ['fill' => $fill, 'payer' => $payer];
            }
        }

        return $result;
    }

    /** Fuel grade of a pump per the controller's configuration (first nozzle), null when unknown. */
    public function gradeOfPump(int $stationId, int $pump): ?string
    {
        $grade = (new Query($this->db))->select('n.fuel_name')->from(['n' => 'nozzles'])
            ->innerJoin(['p' => 'pumps'], 'p.id = n.pump_id')
            ->where(['n.station_id' => $stationId, 'p.pump_number' => $pump])
            ->andWhere(['not', ['n.fuel_name' => null]])
            ->orderBy(['n.on_controller' => SORT_DESC, 'n.nozzle_number' => SORT_ASC])
            ->scalar();

        return $grade === false || $grade === null || $grade === '' ? null : (string) $grade;
    }

    /** @return list<int> Pumps with a nozzle of this grade (always including $pump). */
    private function pumpsOfGrade(int $stationId, string $grade, int $pump): array
    {
        /** @var list<string> $numbers */
        $numbers = (new Query($this->db))->select('p.pump_number')->distinct()->from(['n' => 'nozzles'])
            ->innerJoin(['p' => 'pumps'], 'p.id = n.pump_id')
            ->where(['n.station_id' => $stationId, 'n.fuel_name' => $grade])
            ->column();
        $pumps = array_map('intval', $numbers);

        return in_array($pump, $pumps, true) ? $pumps : [...$pumps, $pump];
    }

    /**
     * Another fill of the grade with the same amount, any pump, except this fill itself (pump + transaction number).
     *
     * @param list<int> $pumps
     * @param callable(string): Expression $amountClose
     */
    private function otherFillExists(int $stationId, int $pump, array $pumps, ?string $grade, string $transactionNo, string $from, string $to, callable $amountClose): bool
    {
        $exists = static fn(Query $query): bool => $query->select(new Expression('1'))->limit(1)->scalar() !== false;
        $notThis = static fn(string $txColumn): array => ['not', ['pump_number' => $pump, $txColumn => $transactionNo]];

        return $exists((new Query($this->db))->from('fill_events')
                ->where(['station_id' => $stationId])
                ->andWhere($grade === null ? ['pump_number' => $pumps] : ['or', ['pump_number' => $pumps], ['fuel_name' => $grade]])
                ->andWhere($notThis('pts_transaction_no'))
                ->andWhere($amountClose('amount'))
                ->andWhere(['between', 'ended_at', $from, $to]))
            || $exists((new Query($this->db))->from('odoo_sales')
                ->where(['station_id' => $stationId, 'pump_number' => $pumps])
                ->andWhere(['not', ['pts_transaction_id' => null]])
                ->andWhere(['<>', 'pts_transaction_id', ''])
                ->andWhere($notThis('pts_transaction_id'))
                ->andWhere($amountClose('amount'))
                ->andWhere(['between', 'sale_created_at', $from, $to]))
            || $exists((new Query($this->db))->from('sale_observations')
                ->where(['station_id' => $stationId, 'pump_number' => $pumps])
                ->andWhere(['not', ['pts_transaction_id' => null]])
                ->andWhere(['<>', 'pts_transaction_id', ''])
                ->andWhere($notThis('pts_transaction_id'))
                ->andWhere($amountClose('amount'))
                ->andWhere(['between', 'created_at', $from, $to]));
    }
}
