<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reconciliation;

use App\Odoo\Sync\OdooSalesReader;
use App\Reconciliation\VolumeReconciler;
use App\Shared\Clock;
use App\Tests\Support\Fake\FakeOdooReadClient;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertSame;

final class VolumeReconcilerTest extends Unit
{
    private ConnectionInterface $db;
    private int $stationId;
    private int $controllerId;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
        $controller = PtsFixture::seedController($this->db, 1, 2, [1, 2], [1 => 'A 91', 2 => 'A 95']);
        $this->stationId = $controller->stationId;
        $this->controllerId = $controller->id;
    }

    public function testUnsoldVolumeIsFlaggedAndPersistedIdempotently(): void
    {
        $this->snapshot(1, '1000.000', '2026-10-11 03:55:00');
        $this->snapshot(2, '500.000', '2026-10-11 03:55:00');
        $this->fill(1, 101, '20.000', '1020.000', '2026-10-11 04:10:00');
        $this->fill(1, 103, '15.000', '1060.000', '2026-10-11 04:40:00', missed: 1, missedVolume: '25.000');
        $this->fill(2, 7, '10.000', '510.000', '2026-10-11 04:20:00');
        $this->pullSales([
            $this->sale(1, 1, '20.000', '2026-10-11 04:11:00'),
            $this->sale(2, 1, '15.000', '2026-10-11 04:41:00'),
            $this->sale(3, 2, '10.000', '2026-10-11 04:21:00'),
        ]);

        $reconciler = new VolumeReconciler($this->db, new Clock());
        $report = $reconciler->reconcile($this->stationId, '2026-10-11 04:00:00.000', '2026-10-11 05:00:00.000');

        $pump1 = $report->lines[0];
        assertSame('60.000', $pump1->dispensed, '20 + 15 observed + 25 missed between polls');
        assertSame('35.000', $pump1->sold);
        assertSame('25.000', $pump1->gap);
        assertSame(true, $pump1->flagged);
        assertSame(false, $report->lines[1]->flagged);
        assertSame('has_discrepancies', $report->status());

        $reconciler->reconcile($this->stationId, '2026-10-11 04:00:00.000', '2026-10-11 05:00:00.000');
        assertSame(1, (int) $this->db->createCommand("SELECT COUNT(*) FROM discrepancies WHERE type = 'unsold_volume'")->queryScalar());
        assertSame('25.000', (string) $this->db->createCommand('SELECT volume FROM discrepancies')->queryScalar());
    }

    public function testWindowWithoutPriorCoverageIsIncompleteAndNotFlagged(): void
    {
        $this->fill(1, 101, '20.000', '1020.000', '2026-10-11 04:10:00');
        $this->snapshot(1, '1020.000', '2026-10-11 04:10:05');

        $report = (new VolumeReconciler($this->db, new Clock()))
            ->reconcile($this->stationId, '2026-10-11 04:00:00.000', '2026-10-11 05:00:00.000');

        assertSame('partial', $report->lines[0]->coverage);
        assertSame(false, $report->lines[0]->flagged);
        assertSame('incomplete', $report->status());
        assertSame(0, (int) $this->db->createCommand('SELECT COUNT(*) FROM discrepancies')->queryScalar());
    }

    public function testUnplacedSalesAreCountedPerGroupingMode(): void
    {
        $this->snapshot(1, '1000.000', '2026-10-11 03:55:00');
        $this->snapshot(2, '500.000', '2026-10-11 03:55:00');
        $this->pullSales([
            $this->sale(9, 21, '30.000', '2026-10-11 04:30:00'),                    // pump the controller lacks, known grade
            $this->sale(10, 1, '5.000', '2026-10-11 04:31:00', fuel: 'Kerosene'),   // grade the station does not dispense
            $this->sale(11, 1, '1.000', '2026-10-11 04:32:00', fuel: 'Shop'),       // non-fuel, ignored
        ]);
        $reconciler = new VolumeReconciler($this->db, new Clock());

        $byGrade = $reconciler->reconcile($this->stationId, '2026-10-11 04:00:00.000', '2026-10-11 05:00:00.000', persist: false);
        $byPump = $reconciler->reconcile($this->stationId, '2026-10-11 04:00:00.000', '2026-10-11 05:00:00.000', persist: false, groupBy: VolumeReconciler::BY_PUMP);

        assertSame(1, $byGrade->unplacedSales, 'only the unknown grade; pump 21 sale is placed by its grade');
        assertSame('5.000', $byGrade->unplacedSalesVolume);
        assertSame(1, $byPump->unplacedSales, 'pump 21 is unknown to the controller');
        assertSame('30.000', $byPump->unplacedSalesVolume);
    }

    /** Manual-mode reality: sales keyed to pump 1 regardless of the pump used are still reconciled by grade. */
    public function testGradeModeReconcilesSalesKeyedToTheWrongPump(): void
    {
        $this->snapshot(1, '1000.000', '2026-10-11 03:55:00');
        $this->snapshot(2, '500.000', '2026-10-11 03:55:00');
        $this->fill(2, 7, '10.000', '510.000', '2026-10-11 04:20:00');
        $this->pullSales([$this->sale(1, 1, '10.000', '2026-10-11 04:21:00', fuel: 'A 95')]);

        $report = (new VolumeReconciler($this->db, new Clock()))
            ->reconcile($this->stationId, '2026-10-11 04:00:00.000', '2026-10-11 05:00:00.000', persist: false);

        assertSame('A 95', $report->lines[1]->label);
        assertSame('0.000', $report->lines[1]->gap);
        assertSame('clean', $report->status());
    }

    public function testSalesReaderUpsertsAndAdvancesCursor(): void
    {
        $odoo = new FakeOdooReadClient(['pts.terminal.sale' => [$this->sale(1, 1, '20.000', '2026-10-11 04:11:00')]]);
        $reader = new OdooSalesReader($this->db, Clock::fixedAt(new DateTimeImmutable('2026-10-11 06:00:00')));

        assertSame(1, $reader->pull($odoo, $this->stationId, 1, 1));
        assertSame(1, $reader->pull($odoo, $this->stationId, 1, 1)); // fake ignores the domain; upsert keeps one row

        assertSame(1, (int) $this->db->createCommand('SELECT COUNT(*) FROM odoo_sales')->queryScalar());
        assertSame('2026-10-11 04:12:00', (string) $this->db->createCommand('SELECT cursor_value FROM sync_cursors')->queryScalar());
    }

    private function snapshot(int $pump, string $total, string $at): void
    {
        $this->db->createCommand()->insert('meter_snapshots', [
            'station_id' => $this->stationId, 'controller_id' => $this->controllerId, 'pump_number' => $pump,
            'nozzle_number' => 1, 'total_volume' => $total, 'source' => 'status', 'observed_at' => $at,
        ])->execute();
    }

    private function fill(int $pump, int $tx, string $volume, string $meterAfter, string $endedAt, int $missed = 0, ?string $missedVolume = null): void
    {
        $this->db->createCommand()->insert('fill_events', [
            'station_id' => $this->stationId, 'controller_id' => $this->controllerId, 'pump_number' => $pump,
            'nozzle_number' => 1, 'fuel_name' => $pump === 2 ? 'A 95' : 'A 91', 'pts_transaction_no' => $tx, 'ended_at' => $endedAt, 'volume' => $volume,
            'amount' => '0.00', 'meter_volume_after' => $meterAfter, 'source' => 'status_transition',
            'preceding_missed_count' => $missed, 'preceding_missed_volume' => $missedVolume,
        ])->execute();
    }

    /** @return array<string, mixed> */
    private function sale(int $odooId, int $pump, string $volume, string $createdAt, ?string $fuel = null): array
    {
        return [
            'id' => $odooId, 'uuid' => "uuid-$odooId", 'alias_uuids' => false, 'pump_number' => $pump, 'nozzle_number' => 1,
            'fuel_name' => $fuel ?? ($pump === 2 ? 'A 95' : 'A 91'), 'station_id' => [1, 'Fuel Station 11'], 'volume' => (float) $volume, 'amount_sar' => round((float) $volume * 2.18, 2), 'price' => 2.18,
            'payment_status' => 'APPROVED', 'payment_method' => 'CASH', 'rrn' => false, 'approval_code' => false,
            'manual_sale' => true, 'pts_transaction_id' => '', 'terminal_id' => 'T1', 'terminal_order_ref' => false,
            'operator_name' => 'Op', 'pos_config_id' => [58, 'Eko'], 'pos_session_id' => [900, 'POS/0900'], 'pos_order_id' => false,
            'pos_sync_status' => 'synced', 'created_at' => $createdAt,
            'create_date' => $createdAt, 'write_date' => substr($createdAt, 0, 14) . sprintf('%02d', (int) substr($createdAt, 14, 2) + 1) . substr($createdAt, 16),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function pullSales(array $rows): void
    {
        (new OdooSalesReader($this->db, new Clock()))->pull(new FakeOdooReadClient(['pts.terminal.sale' => $rows]), $this->stationId, 1, 1);
    }
}
