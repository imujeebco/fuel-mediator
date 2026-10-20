<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pts;

use App\Collector\StatusCollector;
use App\Price\PriceService;
use App\Pts\ControllerEndpoint;
use App\Pts\ControllerLock;
use App\Pts\ControllerRepository;
use App\Pts\Endpoint\CommandService;
use App\Pts\Endpoint\PacketRouter;
use App\Pts\Endpoint\StatusService;
use App\Pts\PtsClient;
use App\Pts\SecretResolver;
use App\Shared\Audit\AuditLog;
use App\Shared\Clock;
use App\Shared\Http\TransportException;
use App\Shared\Log\Redactor;
use App\Tests\Support\Fake\FakeHttpTransport;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * PTS-compatible endpoint behaviour against a scripted controller (mock; no live system).
 */
final class PtsEndpointTest extends Unit
{
    private ConnectionInterface $db;
    private ControllerEndpoint $controller;
    private FakeHttpTransport $transport;
    private ArrayCache $cache;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
        $this->controller = PtsFixture::seedController($this->db, 1, 2, [1, 2]);
        $this->transport = new FakeHttpTransport();
        $this->cache = new ArrayCache();
    }

    public function testAuthorizeIsJournaledAndConfirmed(): void
    {
        $this->transport->queueJson(['Protocol' => 'jsonPTS', 'Packets' => [
            ['Id' => 1, 'Type' => 'PumpAuthorizeConfirmation', 'Data' => ['Pump' => 1, 'Transaction' => 4861]],
        ]]);

        $replies = $this->route([['Id' => 5, 'Type' => 'PumpAuthorize', 'Data' => ['Pump' => 1, 'Nozzle' => 1, 'Type' => 'Amount', 'Dose' => '50.00']]]);

        assertSame(5, $replies[0]['Id'], "device's packet Id is preserved");
        assertSame('PumpAuthorizeConfirmation', $replies[0]['Type']);
        assertSame('confirmed', $this->scalar('SELECT state FROM commands'));
        assertSame('4861', (string) $this->scalar('SELECT pts_transaction_no FROM commands'));
        assertSame(4, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE entity_type = 'command'"), 'received + 3 transitions');
    }

    /** D27: prices change only from Odoo; a device's SetFuelGradesPrices never reaches the controller. */
    public function testDevicesCannotSetFuelPrices(): void
    {
        $replies = $this->route([['Id' => 3, 'Type' => 'SetFuelGradesPrices', 'Data' => ['FuelGradesPrices' => [['FuelGradeId' => 1, 'Price' => 1.0]]]]]);

        assertCount(0, $this->transport->requests);
        assertStringContainsString('managed from Odoo', (string) $replies[0]['Error']);
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM commands'));
    }

    /** D26: a device authorizing with a stale price gets the controller's current grade price. */
    public function testAuthorizePriceIsCorrectedToTheControllersCurrentPrice(): void
    {
        $this->db->createCommand()->insert('fuel_grades', [
            'controller_id' => $this->controller->id, 'grade_id' => 1, 'station_id' => $this->controller->stationId,
            'name' => 'A 91', 'price' => '2.1800', 'observed_at' => '2026-10-11 03:00:00',
        ])->execute();
        $this->transport->queueJson(['Protocol' => 'jsonPTS', 'Packets' => [
            ['Id' => 1, 'Type' => 'PumpAuthorizeConfirmation', 'Data' => ['Pump' => 1, 'Transaction' => 12]],
        ]]);

        $this->route([['Id' => 1, 'Type' => 'PumpAuthorize', 'Data' => ['Pump' => 1, 'Nozzle' => 1, 'Type' => 'Amount', 'Dose' => '50.00', 'Price' => '2.05']]]);

        /** @var array{Packets: list<array{Data: array{Price: string}}>} $sent */
        $sent = $this->transport->decodedRequest(0);
        assertSame('2.18', $sent['Packets'][0]['Data']['Price']);
        assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE event = 'price_corrected' AND data LIKE '%2.05%'"));
    }

    /** Test scenario 6: PTS timeout after an authorization request. */
    public function testAuthorizeTimeoutIsOutcomeUnknownAndNeverRetried(): void
    {
        $this->transport->queue(new TransportException('Operation timed out', isTimeout: true));

        $replies = $this->route([['Id' => 1, 'Type' => 'PumpAuthorize', 'Data' => ['Pump' => 1, 'Nozzle' => 1, 'Type' => 'FullTank']]]);

        assertCount(1, $this->transport->requests, 'sent exactly once');
        assertStringContainsString('Outcome unknown', (string) $replies[0]['Error']);
        assertStringContainsString('Check the pump', (string) $replies[0]['Error']);
        assertSame('outcome_unknown', $this->scalar('SELECT state FROM commands'));
    }

    public function testRefusedConnectionIsReportedAsNotExecuted(): void
    {
        $this->transport->queue(new TransportException('Connection refused', isTimeout: false, requestMayHaveBeenSent: false));

        $replies = $this->route([['Id' => 1, 'Type' => 'PumpStop', 'Data' => ['Pump' => 1]]]);

        assertStringContainsString('NOT executed', (string) $replies[0]['Error']);
        assertSame('failed', $this->scalar('SELECT state FROM commands'));
    }

    /** Test scenario 5: duplicate submission of the same request. */
    public function testIdenticalAuthorizeWithinWindowIsNotSentTwice(): void
    {
        $this->transport->queueJson(['Protocol' => 'jsonPTS', 'Packets' => [
            ['Id' => 1, 'Type' => 'PumpAuthorizeConfirmation', 'Data' => ['Pump' => 1, 'Transaction' => 7]],
        ]]);
        $packet = ['Id' => 1, 'Type' => 'PumpAuthorize', 'Data' => ['Pump' => 1, 'Nozzle' => 1, 'Type' => 'Amount', 'Dose' => '20.00']];

        $this->route([$packet]);
        $second = $this->route([['Id' => 9] + $packet]);

        assertCount(1, $this->transport->requests);
        assertSame('PumpAuthorizeConfirmation', $second[0]['Type'], 'journaled result is replayed');
        assertSame(9, $second[0]['Id']);
        assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM commands'));
    }

    public function testStopsAreNeverDeduplicated(): void
    {
        $ok = ['Protocol' => 'jsonPTS', 'Packets' => [['Id' => 1, 'Type' => 'PumpStop', 'Message' => 'OK']]];
        $this->transport->queueJson($ok)->queueJson($ok);

        $this->route([['Id' => 1, 'Type' => 'PumpStop', 'Data' => ['Pump' => 1]]]);
        $this->route([['Id' => 1, 'Type' => 'PumpStop', 'Data' => ['Pump' => 1]]]);

        assertCount(2, $this->transport->requests);
        assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM commands WHERE state = 'confirmed'"));
    }

    /** Test scenario 4: concurrent requests for the same controller are serialized; a busy controller is not hit. */
    public function testCommandIsNotSentWhenControllerIsBusy(): void
    {
        $other = TestDatabase::connection(); // another process holding the controller
        (new ControllerLock($other))->acquire($this->controller->id, 1.0);
        try {
            $replies = $this->route([['Id' => 1, 'Type' => 'PumpResume', 'Data' => ['Pump' => 1]]]);
        } finally {
            (new ControllerLock($other))->release($this->controller->id);
        }

        assertCount(0, $this->transport->requests);
        assertStringContainsString('NOT sent', (string) $replies[0]['Error']);
        assertSame('failed', $this->scalar('SELECT state FROM commands'));
    }

    public function testControllerErrorPacketGetsReadableTextAndFailsCommand(): void
    {
        $this->transport->queueJson(['Protocol' => 'jsonPTS', 'Packets' => [
            ['Id' => 1, 'Type' => 'PumpAuthorize', 'Error' => true, 'Code' => 41, 'Message' => 'JSONPTS_ERROR_PUMP_BUSY_OTHER_REQUEST_EXECUTED'],
        ]]);

        $replies = $this->route([['Id' => 3, 'Type' => 'PumpAuthorize', 'Data' => ['Pump' => 1, 'Nozzle' => 1, 'Type' => 'Volume', 'Dose' => '10.00']]]);

        assertSame('JSONPTS_ERROR_PUMP_BUSY_OTHER_REQUEST_EXECUTED', $replies[0]['Error']);
        assertSame('failed', $this->scalar('SELECT state FROM commands'));
    }

    /** Test scenario 17: stale pump-status data is never presented as current. */
    public function testStatusFreshCacheThenRefreshThenStaleIsOmitted(): void
    {
        $responder = PtsFixture::statusResponder(static fn(int $p): array => PtsFixture::idle($p, 100 + $p, 1000.0 + $p, 5.0, 10.9));
        $this->transport->queue($responder);

        $first = $this->route($this->statusPackets(), '06:50:00.000');
        assertCount(1, $this->transport->requests, 'cold cache: one controller read for both pumps');
        assertCount(2, $first);

        $this->route($this->statusPackets(), '06:50:04.000');
        assertCount(1, $this->transport->requests, 'within freshness (5 s) devices share the cached read');

        $this->transport->queue(new TransportException('timed out', isTimeout: true));
        $degraded = $this->route($this->statusPackets(), '06:50:20.000');
        assertCount(2, $degraded, 'controller down: cache younger than stale limit (30 s) still served');

        $this->transport->queue(new TransportException('timed out', isTimeout: true));
        $stale = $this->route($this->statusPackets(), '06:50:40.000', resetCircuit: true);
        assertCount(0, $stale, 'older than stale limit: no packet, so the device shows the pump offline');
    }

    /** D31: a stale limit shorter than the collector's poll cycle is raised to three polls. */
    public function testStaleLimitCoversThreeCollectorPolls(): void
    {
        $this->db->createCommand()->update('controllers', ['freshness_ms' => 1500, 'stale_ms' => 5000], ['id' => $this->controller->id])->execute();
        $this->transport->queue(PtsFixture::statusResponder(static fn(int $p): array => PtsFixture::idle($p, 100 + $p, 1000.0 + $p, 5.0, 10.9)));
        $this->route($this->statusPackets(), '06:50:00.000');

        $this->transport->queue(new TransportException('timed out', isTimeout: true));
        $busy = $this->route($this->statusPackets(), '06:50:12.000');
        assertCount(2, $busy, 'stale_ms 5 s with a 10 s poll: last status still served 12 s later (limit 30 s)');

        assertSame(5000, StatusService::staleLimitMs(5000, 0));
        assertSame(60000, StatusService::staleLimitMs(60000, 10000));
    }

    public function testUnknownPumpUnsupportedTypeAndConfigCache(): void
    {
        $this->transport->queueJson(['Protocol' => 'jsonPTS', 'Packets' => [
            ['Id' => 1, 'Type' => 'PumpsConfiguration', 'Data' => ['Pumps' => [['Id' => 1], ['Id' => 2]]]],
        ]]);

        $replies = $this->route([
            ['Id' => 1, 'Type' => 'PumpGetStatus', 'Data' => ['Pump' => 21]],
            ['Id' => 2, 'Type' => 'PumpLock', 'Data' => ['Pump' => 1]],
            ['Id' => 3, 'Type' => 'GetPumpsConfiguration'],
        ]);
        $again = $this->route([['Id' => 7, 'Type' => 'GetPumpsConfiguration']]);

        assertSame('JSONPTS_ERROR_PUMP_IS_NOT_CONFIGURED', $replies[0]['Error']);
        assertSame('Not supported by mediator', $replies[1]['Error']);
        assertSame('PumpsConfiguration', $replies[2]['Type']);
        assertArrayNotHasKey('Error', $again[0]);
        assertSame(7, $again[0]['Id']);
        assertCount(1, $this->transport->requests, 'pump 21 and PumpLock never reach the controller; config served from cache');
    }

    public function testRequestValidation(): void
    {
        assertSame('Request must be a jsonPTS object with a Packets array', PacketRouter::parseRequest(['Protocol' => 'jsonPTS']));
        assertSame('Each packet needs an integer Id and a string Type', PacketRouter::parseRequest(['Packets' => [['Type' => 'PumpGetStatus']]]));
        assertSame('Packets must contain between 1 and 32 packets', PacketRouter::parseRequest(['Packets' => []]));
    }

    /** @return list<array{Id: int, Type: string, Data: array{Pump: int}}> */
    private function statusPackets(): array
    {
        return [['Id' => 1, 'Type' => 'PumpGetStatus', 'Data' => ['Pump' => 1]], ['Id' => 2, 'Type' => 'PumpGetStatus', 'Data' => ['Pump' => 2]]];
    }

    /**
     * @param list<array{Id: int, Type: string, Data?: array<string, mixed>}> $packets
     *
     * @return list<array<string, mixed>>
     */
    private function route(array $packets, string $localTime = '06:50:00.000', bool $resetCircuit = false): array
    {
        if ($resetCircuit) {
            $this->db->createCommand()->update('controllers', ['circuit_open_until' => null, 'consecutive_failures' => 0], ['id' => $this->controller->id])->execute();
        }
        $clock = Clock::fixedAt(new DateTimeImmutable("2026-10-11 $localTime", new DateTimeZone('+03:00')));
        $pts = new PtsClient($this->transport);
        $lock = new ControllerLock($this->db);
        $audit = new AuditLog($this->db, $clock, new Redactor());
        $repository = new ControllerRepository($this->db, new SecretResolver(), $clock);
        $router = new PacketRouter(
            new StatusService($this->db, new StatusCollector($this->db, $pts, $clock), $lock, $repository, $clock),
            new CommandService($this->db, $pts, $lock, $audit, $clock,
                new PriceService($this->db, $pts, $repository, $lock, $this->cache, $audit, $clock, new NullLogger())),
            $pts,
            $lock,
            $this->cache,
        );
        $row = $repository->rowByOdooBackendId(2) ?? throw new \LogicException('controller row');

        return $router->route($this->controller, $row, $packets, 'ip:10.0.0.5', 'corr-1');
    }

    private function scalar(string $sql): mixed
    {
        return $this->db->createCommand($sql)->queryScalar();
    }
}
