<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function chr;
use function md5;
use function ord;
use function substr;
use function vsprintf;

/**
 * Decision D21: controller fills that were already paid by a different sale record.
 *
 * FuelPay never links a manual sale to a PTS fill (it matches by uuid or pump + transaction number only), so a fill
 * paid manually, or with the wrong pump or transaction number, stays in Odoo's unpaid list and the device blocks the
 * session close until it is paid again (live finding 2026-10-11: 8 duplicate payments at close). The fill matcher
 * already knows which sale paid which fill; this class turns that into a conservative "settled" verdict:
 *
 *  - the fill has a controller transaction number;
 *  - it is linked with confidence `confirmed` to an APPROVED sale;
 *  - that sale is not the PTS sale of the fill itself (same pump and same transaction number);
 *  - no other fill of the same grade and amount (±0.05 SAR) that is still unpaid ended within ±15 minutes, so an
 *    ambiguous match can never hide a real unpaid fill.
 *
 * Anything that fails a rule stays unpaid on the device, which is today's behaviour.
 *
 * @psalm-type SettledFill = array{fill_event_id: int, odoo_sale_id: int, pump_number: int, nozzle_number: int, pts_transaction_no: string, amount: string, volume: string, ended_at: string, fill_uuid: string, pending_uuid: ?string}
 */
final class SettledFills
{
    private const AMBIGUITY_WINDOW_SECONDS = 900;
    private const AMOUNT_TOLERANCE = '0.05';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Settled fills of a station that ended at or after $sinceUtc.
     *
     * @return list<SettledFill>
     */
    public function forStation(int $stationId, string $sinceUtc): array
    {
        return $this->query($stationId, ['>=', 'f.ended_at', $sinceUtc]);
    }

    /**
     * The settled fill for a pump and transaction number, if any (most recent first).
     *
     * @return SettledFill|null
     */
    public function forPumpTransaction(int $stationId, int $pump, string $transactionNo, string $sinceUtc): ?array
    {
        $rows = $this->query($stationId, ['and', ['>=', 'f.ended_at', $sinceUtc], ['f.pump_number' => $pump, 'f.pts_transaction_no' => $transactionNo]]);

        return $rows[0] ?? null;
    }

    /** Mediator station for an Odoo POS config, from the sales mirror (attribution by the config's company, D18). */
    public function stationForPosConfig(int $posConfigId): ?int
    {
        $id = (new Query($this->db))->select('station_id')->from('odoo_sales')
            ->where(['odoo_pos_config_id' => $posConfigId])
            ->orderBy(['id' => SORT_DESC])->limit(1)->scalar();

        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * Records that a settled fill was reported to a device as paid.
     *
     * @param SettledFill $fill
     */
    public function recordApplied(int $stationId, array $fill, string $method): void
    {
        $now = $this->clock->nowForDb();
        $this->db->createCommand()->upsert(
            'fill_settlements',
            [
                'station_id' => $stationId,
                'fill_event_id' => $fill['fill_event_id'],
                'odoo_sale_id' => $fill['odoo_sale_id'],
                'pump_number' => $fill['pump_number'],
                'pts_transaction_no' => $fill['pts_transaction_no'],
                'amount' => $fill['amount'],
                'fill_uuid' => $fill['fill_uuid'],
                'first_applied_at' => $now,
                'last_applied_at' => $now,
                'applied_count' => 1,
                'last_method' => $method,
            ],
            [
                'last_applied_at' => $now,
                'applied_count' => new Expression('fill_settlements.applied_count + 1'),
                'last_method' => $method,
            ],
        )->execute();
    }

    /**
     * FuelPay's deterministic uuid for a PTS fill: `UUID.nameUUIDFromBytes("pts-tx-<tx>-p<pump>")` (an MD5,
     * version-3 UUID), see FuelPay PumpListViewModel.enqueueUnpaidFill.
     */
    public static function fuelPayFillUuid(string $transactionNo, int $pump): string
    {
        $hash = md5("pts-tx-{$transactionNo}-p{$pump}", true);
        $hash[6] = chr((ord($hash[6]) & 0x0f) | 0x30);
        $hash[8] = chr((ord($hash[8]) & 0x3f) | 0x80);
        $hex = bin2hex($hash);

        return vsprintf('%s-%s-%s-%s-%s', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }

    /**
     * @param array<array-key, mixed> $condition Extra condition on the fill.
     *
     * @return list<SettledFill>
     */
    private function query(int $stationId, array $condition): array
    {
        $window = self::AMBIGUITY_WINDOW_SECONDS;
        $tolerance = self::AMOUNT_TOLERANCE;

        /** @var list<array{fill_event_id: string, odoo_sale_id: string, pump_number: string, nozzle_number: string, pts_transaction_no: string, amount: string, volume: string, ended_at: string}> $rows */
        $rows = (new Query($this->db))
            ->select([
                'fill_event_id' => 'f.id', 'odoo_sale_id' => 's.id', 'f.pump_number', 'f.nozzle_number',
                'f.pts_transaction_no', 'f.amount', 'f.volume', 'f.ended_at',
            ])
            ->from(['f' => 'fill_events'])
            ->innerJoin(['ml' => 'match_links'], 'ml.fill_event_id = f.id')
            ->innerJoin(['s' => 'odoo_sales'], 's.id = ml.odoo_sale_id')
            ->where(['f.station_id' => $stationId, 'ml.confidence' => 'confirmed', 's.payment_status' => 'APPROVED'])
            ->andWhere(['not', ['f.pts_transaction_no' => null]])
            ->andWhere(['<>', 'f.pts_transaction_no', ''])
            ->andWhere($condition)
            // Not the fill's own PTS sale: that case never stays unpaid in Odoo.
            ->andWhere(new Expression('NOT (s.pump_number <=> f.pump_number AND s.pts_transaction_id <=> f.pts_transaction_no)'))
            // Ambiguity guard: no other still-unpaid fill of the same grade and amount close in time.
            ->andWhere(new Expression(<<<SQL
                NOT EXISTS (
                    SELECT 1 FROM fill_events f2
                    WHERE f2.station_id = f.station_id AND f2.id <> f.id
                      AND f2.fuel_name <=> f.fuel_name
                      AND f2.state IN ('observed', 'unmatched', 'ambiguous')
                      AND ABS(f2.amount - f.amount) <= {$tolerance}
                      AND f2.ended_at BETWEEN f.ended_at - INTERVAL {$window} SECOND AND f.ended_at + INTERVAL {$window} SECOND
                )
                SQL))
            ->orderBy(['f.ended_at' => SORT_DESC])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $pump = (int) $row['pump_number'];
            $pending = (new Query($this->db))->select('uuid')->from('odoo_sales')
                ->where(['station_id' => $stationId, 'pump_number' => $pump, 'pts_transaction_id' => $row['pts_transaction_no'], 'payment_status' => 'PENDING'])
                ->orderBy(['id' => SORT_DESC])->limit(1)->scalar();
            $result[] = [
                'fill_event_id' => (int) $row['fill_event_id'],
                'odoo_sale_id' => (int) $row['odoo_sale_id'],
                'pump_number' => $pump,
                'nozzle_number' => (int) $row['nozzle_number'],
                'pts_transaction_no' => $row['pts_transaction_no'],
                'amount' => $row['amount'],
                'volume' => $row['volume'],
                'ended_at' => $row['ended_at'],
                'fill_uuid' => self::fuelPayFillUuid($row['pts_transaction_no'], $pump),
                'pending_uuid' => $pending === false || $pending === null ? null : (string) $pending,
            ];
        }

        return $result;
    }
}
