<?php

declare(strict_types=1);

namespace App\Tests\Unit\Collector;

use App\Collector\CollectorRunner;
use App\Collector\ConfigSync;
use App\Collector\StatusCollector;
use App\Pts\ControllerLock;
use App\Pts\ControllerRepository;
use App\Pts\PtsClient;
use App\Pts\SecretResolver;
use App\Shared\Audit\AuditLog;
use App\Shared\Clock;
use App\Shared\Http\HttpOptions;
use App\Shared\Http\HttpResult;
use App\Shared\Http\HttpTransportInterface;
use App\Shared\Http\TransportException;
use App\Shared\Log\Redactor;
use App\Shared\Worker\LeaseManager;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class CollectorRunnerTest extends Unit
{
    private ConnectionInterface $db;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
    }

    /** Test scenario 16: one station offline while other stations remain operational. */
    public function testFailingStationDoesNotAffectOthers(): void
    {
        $down = PtsFixture::seedController($this->db, 1, 2, [1, 2]);
        $up = PtsFixture::seedController($this->db, 5, 7, [1]);

        $transport = new class implements HttpTransportInterface {
            public function post(string $url, string $body, array $headers = [], ?HttpOptions $options = null): HttpResult
            {
                if (str_contains($url, 'pts2.test')) {
                    throw new TransportException('Operation timed out', isTimeout: true);
                }
                return (PtsFixture::statusResponder(static fn(int $p): array => PtsFixture::idle($p, 50, 800.0, 12.0, 26.16)))($body);
            }
        };

        $outcomes = $this->runner($transport, '07:00:00')->runOnce();

        assertSame('error', $outcomes[$down->id]['status']);
        assertSame('timeout', $outcomes[$down->id]['kind']);
        assertSame('ok', $outcomes[$up->id]['status']);
        assertSame(1, (int) $this->db->createCommand('SELECT COUNT(*) FROM fill_events WHERE controller_id = :id', [':id' => $up->id])->queryScalar());
        assertSame(1, (int) $this->db->createCommand('SELECT consecutive_failures FROM controllers WHERE id = :id', [':id' => $down->id])->queryScalar());
        assertSame(0, (int) $this->db->createCommand('SELECT consecutive_failures FROM controllers WHERE id = :id', [':id' => $up->id])->queryScalar());

        // Next pass a few seconds later: the failed controller's circuit is open, so it is not hammered.
        $next = $this->runner($transport, '07:00:05')->runOnce(ignoreInterval: true);
        assertSame('circuit_open', $next[$down->id]['status']);
        assertSame('ok', $next[$up->id]['status']);
    }

    public function testMissingSecretIsAConfigurationErrorAndNothingIsSent(): void
    {
        $controller = PtsFixture::seedController($this->db, 1, 2, [1]);
        unset($_ENV['PTS_PASSWORD_2']);
        $transport = new class implements HttpTransportInterface {
            public int $calls = 0;
            public function post(string $url, string $body, array $headers = [], ?HttpOptions $options = null): HttpResult
            {
                $this->calls++;
                return new HttpResult(500, '', 1);
            }
        };

        $outcomes = $this->runner($transport, '07:00:00')->runOnce();

        assertSame('configuration', $outcomes[$controller->id]['kind']);
        assertSame(0, $transport->calls);
    }

    public function testLeaseKeepsASecondProcessAwayUntilItExpires(): void
    {
        PtsFixture::seedController($this->db, 1, 2, [1]);
        $stationId = (int) $this->db->createCommand('SELECT id FROM stations')->queryScalar();
        $t0 = Clock::fixedAt(new DateTimeImmutable('2026-10-11 04:00:00', new \DateTimeZone('UTC')));
        $t1 = Clock::fixedAt(new DateTimeImmutable('2026-10-11 04:01:00', new \DateTimeZone('UTC')));

        $a = new LeaseManager($this->db, $t0, 'host-a:1');
        $b = new LeaseManager($this->db, $t0, 'host-b:2');
        assertTrue($a->acquire('collector', $stationId, 30));
        assertFalse($b->acquire('collector', $stationId, 30));
        assertTrue($a->acquire('collector', $stationId, 30), 'the holder can renew');

        $bLater = new LeaseManager($this->db, $t1, 'host-b:2');
        assertTrue($bLater->acquire('collector', $stationId, 30), 'an expired lease is taken over (crash recovery)');
    }

    private function runner(HttpTransportInterface $transport, string $utcTime): CollectorRunner
    {
        $clock = Clock::fixedAt(new DateTimeImmutable("2026-10-11 $utcTime", new \DateTimeZone('UTC')));
        $pts = new PtsClient($transport);
        $audit = new AuditLog($this->db, $clock, new Redactor());

        return new CollectorRunner(
            new ControllerRepository($this->db, new SecretResolver(), $clock),
            new StatusCollector($this->db, $pts, $clock),
            new ConfigSync($this->db, $pts, $clock, $audit),
            new ControllerLock($this->db),
            new LeaseManager($this->db, $clock, 'test:1'),
            $clock,
            new NullLogger(),
        );
    }
}
