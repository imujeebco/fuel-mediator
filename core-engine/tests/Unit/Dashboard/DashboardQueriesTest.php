<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard;

use App\Dashboard\DashboardQueries;
use App\Pts\SecretResolver;
use App\Reconciliation\VolumeReconciler;
use App\Shared\Clock;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_column;
use function array_map;
use function count;
use function PHPUnit\Framework\assertSame;

final class DashboardQueriesTest extends Unit
{
    private ConnectionInterface $db;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
    }

    public function testDeviceWithoutStationIsListedOnceNotUnderEveryStation(): void
    {
        $a = PtsFixture::seedController($this->db, 1, 2, [1])->stationId;
        $b = PtsFixture::seedController($this->db, 4, 5, [1])->stationId;
        $now = (new Clock())->nowForDb();
        foreach ([['N910-A', $a], ['N910-B', $b], ['N910-LOST', null]] as [$terminal, $station]) {
            $this->db->createCommand()->insert('devices', [
                'terminal_id' => $terminal, 'station_id' => $station, 'first_seen_at' => $now, 'last_seen_at' => $now,
            ])->execute();
        }
        $clock = new Clock();
        $queries = new DashboardQueries($this->db, new VolumeReconciler($this->db, $clock), $clock, new SecretResolver());
        $from = '2000-01-01 00:00:00.000';

        assertSame(['N910-A'], array_column($queries->devices($a, $from), 'terminal_id'));
        assertSame(['N910-B'], array_column($queries->devices($b, $from), 'terminal_id'));
        assertSame(['N910-LOST'], array_column($queries->devices(null, $from), 'terminal_id'));
        assertSame([], $queries->devices(null, '2999-01-01 00:00:00.000'), 'unlinked devices outside the window are hidden');
    }

    /** Live 2026-10-14 15:01–15:12: Odoo.sh answered 503; two fills of that window never got a sale. */
    public function testOdooOutageListsTheFillsStillWithoutASale(): void
    {
        $controller = PtsFixture::seedController($this->db, 1, 2, [7, 17]);
        $calls = [['11:59:00', 'ok', null], ['12:01:05', 'odoo_error', 'Attachment on FTP: 421']];
        foreach (['12:01:10', '12:02:00', '12:03:00', '12:05:00', '12:08:00', '12:11:00'] as $at) {
            $calls[] = [$at, 'odoo_error', 'HTTP 503'];
        }
        $calls[] = ['12:12:50', 'ok', null];
        $calls[] = ['12:20:00', 'timeout', 'HTTP transport error 28'];   // a lone timeout is not an outage
        $calls[] = ['12:21:00', 'ok', null];
        foreach ($calls as $i => [$at, $outcome, $error]) {
            $this->db->createCommand()->insert('relay_calls', [
                'correlation_id' => "c$i", 'service' => 'object', 'method' => 'mobile_get_unpaid_fills', 'outcome' => $outcome,
                'error_message' => $error, 'latency_ms' => 540, 'created_at' => "2026-10-14 $at",
            ])->execute();
        }
        foreach ([[7, 4317, '12:03:07', 'observed'], [17, 4923, '12:03:15', 'unmatched'], [7, 4318, '12:06:00', 'matched'], [7, 4319, '13:00:00', 'observed']] as $i => [$pump, $tx, $ended, $state]) {
            $this->db->createCommand()->insert('fill_events', [
                'station_id' => $controller->stationId, 'controller_id' => $controller->id, 'pump_number' => $pump, 'nozzle_number' => 1,
                'pts_transaction_no' => $tx, 'ended_at' => "2026-10-14 $ended", 'volume' => '10.000', 'amount' => '23.30',
                'meter_volume_after' => 1000 + $i, 'source' => 'status_transition', 'state' => $state,
            ])->execute();
        }
        $clock = new Clock();
        $queries = new DashboardQueries($this->db, new VolumeReconciler($this->db, $clock), $clock, new SecretResolver());

        $outages = $queries->odooOutages('2026-10-14 11:00:00.000');

        assertSame(1, count($outages), 'business errors and a lone timeout are not outages');
        assertSame('2026-10-14 12:01:10.000', $outages[0]['start']);
        assertSame('2026-10-14 12:12:50.000', $outages[0]['end']);
        assertSame(6, $outages[0]['failures']);
        assertSame(['4317', '4923'], array_map('strval', array_column($outages[0]['fills'], 'pts_transaction_no')), 'matched and later fills excluded');
    }
}
