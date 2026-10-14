<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation;

use App\Reconciliation\FillMatcher;
use App\Shared\Clock;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertSame;

final class FillMatcherTest extends Unit
{
    private ConnectionInterface $db;
    private int $stationId;
    private int $controllerId;
    private int $saleSeq = 0;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
        $controller = PtsFixture::seedController($this->db, 1, 2, [1, 2, 3], [1 => 'A 91', 2 => 'A 95', 3 => 'A 91']);
        $this->stationId = $controller->stationId;
        $this->controllerId = $controller->id;
    }

    public function testFingerprintAndTransactionLinks(): void
    {
        $a = $this->fill(1, 101, '22.940', '50.00', '04:10:00');
        $b = $this->fill(2, 7, '13.760', '30.00', '04:12:00');
        $this->sale(1, '22.936', '50.00', '04:11:30');               // fingerprint (Odoo volume rounding)
        $this->sale(2, '13.700', '30.00', '04:13:00', tx: '7');       // transaction number

        $result = $this->matcher('06:00:00')->match($this->stationId, '2026-10-11 04:00:00', '2026-10-11 05:00:00');

        assertSame(2, $result->confirmed);
        assertSame('fingerprint', $this->scalar("SELECT method FROM match_links WHERE fill_event_id = $a"));
        assertSame('tx_id', $this->scalar("SELECT method FROM match_links WHERE fill_event_id = $b"));
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM discrepancies'));
    }

    /** Test scenario 8: manual sale after a timeout, then the delayed PTS-based sale for the same physical fill. */
    public function testSecondSaleForOneFillIsADuplicateCandidate(): void
    {
        $this->fill(1, 101, '45.870', '100.00', '04:10:00');
        $this->sale(1, '45.870', '100.00', '04:11:00');
        $this->sale(1, '45.870', '100.00', '04:16:00', rrn: '125402109352');

        $result = $this->matcher('06:00:00')->match($this->stationId, '2026-10-11 04:00:00', '2026-10-11 05:00:00');

        assertSame(1, $result->probable, 'two identical candidates: linked sequentially, not confirmed');
        assertSame(1, $result->duplicateCandidates);
        assertSame('high', $this->scalar("SELECT severity FROM discrepancies WHERE type = 'duplicate_candidate'"), 'card charge involved');
        assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM odoo_sales'), 'no sale is ever deleted or merged');
    }

    public function testUnmatchedFillWaitsForGraceThenIsResolvedByALateSale(): void
    {
        $fill = $this->fill(1, 101, '30.000', '65.40', '04:10:00');

        $early = $this->matcher('04:20:00')->match($this->stationId, '2026-10-11 04:00:00', '2026-10-11 05:00:00');
        assertSame(1, $early->pending, 'within the grace period nothing is raised');

        $late = $this->matcher('05:30:00')->match($this->stationId, '2026-10-11 04:00:00', '2026-10-11 05:00:00');
        assertSame(1, $late->unmatchedFills);
        assertSame('open', $this->scalar("SELECT status FROM discrepancies WHERE type = 'unmatched_fill'"));

        // FuelPay's offline queue delivers the sale late (still within the extended search window).
        $this->sale(1, '30.000', '65.40', '04:35:00');
        $this->matcher('05:40:00')->match($this->stationId, '2026-10-11 04:00:00', '2026-10-11 05:00:00', graceMinutes: 30);

        assertSame('matched', $this->scalar("SELECT state FROM fill_events WHERE id = $fill"));
        assertSame('resolved', $this->scalar("SELECT status FROM discrepancies WHERE type = 'unmatched_fill'"));
    }

    public function testSaleWithoutFillAndAmountOnlyMatchIsNeverConfirmed(): void
    {
        $this->fill(1, 101, '20.000', '43.60', '04:10:00');
        $this->sale(1, '25.000', '43.60', '04:11:00');   // same amount, different volume → probable at most
        $this->sale(2, '10.000', '21.80', '04:20:00');   // no fill on pump 2

        $result = $this->matcher('06:00:00')->match($this->stationId, '2026-10-11 04:00:00', '2026-10-11 05:00:00');

        assertSame(0, $result->confirmed);
        assertSame(1, $result->probable);
        assertSame(1, $result->salesWithoutFill);
    }

    /** PTS mode: FuelPay sends the transaction number but no fuel name — the tx rule must still link. */
    public function testTransactionLinkWorksWithoutFuelName(): void
    {
        $fill = $this->fill(1, 4887, '69.720', '152.00', '04:50:30');
        $this->sale(1, '69.720', '152.00', '04:51:00', tx: '4887');
        $this->db->createCommand()->update('odoo_sales', ['fuel_name' => null])->execute();

        $result = $this->matcher('06:00:00')->match($this->stationId, '2026-10-11 04:00:00', '2026-10-11 05:00:00');

        assertSame(1, $result->confirmed);
        assertSame('tx_id', $this->scalar("SELECT method FROM match_links WHERE fill_event_id = $fill"));
    }

    /**
     * Live finding 2026-10-14 (Station 11): an earlier SAR 50 fill still waiting for its sale took a later SAR 50 sale of
     * the same grade by fingerprint although that sale named another pump's transaction, and the shift cascaded down
     * the chain. A sale carrying a PTS transaction number belongs to that fill only.
     */
    public function testSaleWithTransactionNumberIsNeverTakenByAnotherFill(): void
    {
        $waiting = $this->fill(3, 2550, '22.940', '50.00', '04:12:00');   // its sale never arrives
        $own = $this->fill(1, 10507, '22.940', '50.00', '04:38:00');
        $this->sale(1, '22.940', '50.00', '04:38:50', tx: '10507');

        $result = $this->matcher('06:00:00')->match($this->stationId, '2026-10-11 04:00:00', '2026-10-11 05:00:00');

        assertSame(1, $result->confirmed);
        assertSame('tx_id', $this->scalar("SELECT method FROM match_links WHERE fill_event_id = $own"));
        assertSame('unmatched', $this->scalar("SELECT state FROM fill_events WHERE id = $waiting"), 'the fill really without a sale is reported');
        assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM discrepancies WHERE type = 'unmatched_fill' AND pump_number = 3"));
    }

    /**
     * Live finding 2026-10-14: a fill left PENDING was paid 70 min later and FuelPay rewrote `created_at` to the payment
     * time. The transaction number still links it; it is not a duplicate of the next sale of the same amount.
     */
    public function testLatePaidSaleLinksByTransactionNumber(): void
    {
        $late = $this->fill(1, 12135, '9.170', '20.00', '03:06:00');
        $this->fill(1, 12140, '9.170', '20.00', '04:02:00');
        $this->sale(1, '9.170', '20.00', '04:02:30', tx: '12140');
        $this->sale(1, '9.170', '20.00', '04:16:59', tx: '12135');

        $result = $this->matcher('06:00:00')->match($this->stationId, '2026-10-11 03:00:00', '2026-10-11 05:00:00');

        assertSame(2, $result->confirmed);
        assertSame('tx_id', $this->scalar("SELECT method FROM match_links WHERE fill_event_id = $late"));
        assertSame(0, $result->duplicateCandidates);
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM discrepancies'));
    }

    public function testRerunIsIdempotent(): void
    {
        $this->fill(1, 101, '20.000', '43.60', '04:10:00');
        $this->sale(2, '10.000', '21.80', '04:20:00');
        $matcher = $this->matcher('06:00:00');

        $matcher->match($this->stationId, '2026-10-11 04:00:00', '2026-10-11 05:00:00');
        $matcher->match($this->stationId, '2026-10-11 04:00:00', '2026-10-11 05:00:00');

        assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM discrepancies'));
    }

    private function matcher(string $utcTime): FillMatcher
    {
        return new FillMatcher($this->db, Clock::fixedAt(new DateTimeImmutable("2026-10-11 $utcTime", new DateTimeZone('UTC'))));
    }

    private function fill(int $pump, int $tx, string $volume, string $amount, string $endedUtc): int
    {
        static $meter = 10000;
        $meter += 100;
        $this->db->createCommand()->insert('fill_events', [
            'station_id' => $this->stationId, 'controller_id' => $this->controllerId, 'pump_number' => $pump,
            'nozzle_number' => 1, 'fuel_name' => $pump === 2 ? 'A 95' : 'A 91', 'pts_transaction_no' => $tx,
            'ended_at' => "2026-10-11 $endedUtc", 'volume' => $volume,
            'amount' => $amount, 'meter_volume_after' => $meter, 'source' => 'status_transition',
        ])->execute();

        return (int) $this->db->getLastInsertId();
    }

    private function sale(int $pump, string $volume, string $amount, string $createdUtc, ?string $tx = null, ?string $rrn = null): void
    {
        $id = ++$this->saleSeq;
        $this->db->createCommand()->insert('odoo_sales', [
            'station_id' => $this->stationId, 'odoo_id' => $id, 'uuid' => "u-$id", 'pump_number' => $pump, 'nozzle_number' => 1,
            'fuel_name' => $pump === 2 ? 'A 95' : 'A 91',
            'volume' => $volume, 'amount' => $amount, 'payment_status' => 'APPROVED', 'payment_method' => $rrn ? 'CARD' : 'CASH',
            'rrn' => $rrn, 'manual_sale' => 1, 'pts_transaction_id' => $tx, 'sale_created_at' => "2026-10-11 $createdUtc",
            'odoo_write_date' => "2026-10-11 $createdUtc",
        ])->execute();
    }

    private function scalar(string $sql): mixed
    {
        return $this->db->createCommand($sql)->queryScalar();
    }
}
