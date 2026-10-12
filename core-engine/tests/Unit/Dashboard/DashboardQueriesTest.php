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
}
