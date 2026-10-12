<?php

declare(strict_types=1);

namespace App\Tests\Unit\Price;

use App\Price\PriceService;
use App\Pts\ControllerEndpoint;
use App\Pts\ControllerLock;
use App\Pts\ControllerRepository;
use App\Pts\Endpoint\ConfigGeneration;
use App\Pts\PtsClient;
use App\Pts\SecretResolver;
use App\Shared\Audit\AuditLog;
use App\Shared\Clock;
use App\Shared\Log\Redactor;
use App\Tests\Support\Fake\FakeHttpTransport;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\NullLogger;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * D25: Odoo prices reach the controller as a complete, verified grade set; automatic pushes stay within limits.
 */
final class PriceServiceTest extends Unit
{
    private ConnectionInterface $db;
    private ControllerEndpoint $controller;
    private FakeHttpTransport $transport;
    private ArrayCache $cache;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
        $this->controller = PtsFixture::seedController($this->db, 1, 2, [1, 2], [1 => 'A 91', 2 => 'A 95']);
        $this->transport = new FakeHttpTransport();
        $this->cache = new ArrayCache();
    }

    public function testPushSendsEveryGradeAndVerifiesTheReadBack(): void
    {
        $this->odooPrice('A91', '2.25');   // Odoo spells it differently from the controller ("A 91")
        $this->odooPrice('A 95', '2.33');
        $this->grades(['A 91' => 2.18, 'A 95' => 2.33]);                       // read before the push
        $this->transport->queueJson(['Protocol' => 'jsonPTS', 'Packets' => [['Id' => 1, 'Type' => 'SetFuelGradesPrices', 'Message' => 'OK']]]);
        $this->grades(['A 91' => 2.25, 'A 95' => 2.33]);                       // read-back

        $result = $this->service()->push($this->controller->stationId, 'test', false);

        assertSame('verified', $result['state'], $result['message']);
        /** @var array{Packets: list<array{Type: string, Data: array{FuelGradesPrices: list<array{FuelGradeId: int, Price: float}>}}>} $set */
        $set = $this->transport->decodedRequest(1);
        assertSame('SetFuelGradesPrices', $set['Packets'][0]['Type']);
        assertSame([['FuelGradeId' => 1, 'Price' => 2.25], ['FuelGradeId' => 2, 'Price' => 2.33]], $set['Packets'][0]['Data']['FuelGradesPrices'], 'complete grade set');
        assertSame('verified', $this->scalar('SELECT state FROM price_pushes'));
        assertNotNull($this->scalar('SELECT prices_changed_at FROM stations'));
        assertSame(1, ConfigGeneration::get($this->cache, $this->controller->id), 'device-facing config cache invalidated');
    }

    public function testAutomaticPushRefusesLargeChanges(): void
    {
        $this->odooPrice('A 91', '3.00');
        $this->odooPrice('A 95', '2.33');
        $this->grades(['A 91' => 2.18, 'A 95' => 2.33]);

        $result = $this->service()->push($this->controller->stationId, 'auto', false);

        assertSame('refused', $result['state']);
        assertStringContainsString('more than 25%', $result['message']);
        assertCount(1, $this->transport->requests, 'only the read; nothing sent');
    }

    public function testNothingIsSentWhenPricesAlreadyMatch(): void
    {
        $this->odooPrice('A 91', '2.18');
        $this->odooPrice('A 95', '2.33');
        $this->grades(['A 91' => 2.18, 'A 95' => 2.33]);

        $result = $this->service()->push($this->controller->stationId, 'test', true);

        assertSame('unchanged', $result['state']);
        assertCount(1, $this->transport->requests);
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM price_pushes'));
    }

    public function testControllerRejectionIsReportedAndRaisesAnIssue(): void
    {
        $this->odooPrice('A 91', '2.25');
        $this->odooPrice('A 95', '2.33');
        $this->grades(['A 91' => 2.18, 'A 95' => 2.33]);
        $this->transport->queueJson(['Protocol' => 'jsonPTS', 'Packets' => [['Id' => 1, 'Type' => 'SetFuelGradesPrices', 'Error' => true, 'Message' => 'JSONPTS_ERROR_WRONG_FUEL_GRADES']]]);

        $result = $this->service()->push($this->controller->stationId, 'test', true);

        assertSame('rejected', $result['state']);
        assertSame('open', $this->scalar("SELECT status FROM discrepancies WHERE type = 'price_push_failed'"));
    }

    private function service(): PriceService
    {
        $clock = Clock::fixedAt(new DateTimeImmutable('2026-10-12 06:00:00', new DateTimeZone('UTC')));

        return new PriceService(
            $this->db,
            new PtsClient($this->transport),
            new ControllerRepository($this->db, new SecretResolver(), $clock),
            new ControllerLock($this->db),
            $this->cache,
            new AuditLog($this->db, $clock, new Redactor()),
            $clock,
            new NullLogger(),
        );
    }

    /** @param array<string, float> $prices grade name => price, ids 1.. in order */
    private function grades(array $prices): void
    {
        $grades = [];
        $id = 0;
        foreach ($prices as $name => $price) {
            $grades[] = ['Id' => ++$id, 'Name' => $name, 'Price' => $price, 'ExpansionCoefficient' => 0.0];
        }
        $this->transport->queueJson(['Protocol' => 'jsonPTS', 'Packets' => [['Id' => 1, 'Type' => 'FuelGradesConfiguration', 'Data' => ['FuelGrades' => $grades]]]]);
    }

    private function odooPrice(string $fuel, string $price): void
    {
        static $id = 0;
        $this->db->createCommand()->insert('odoo_station_prices', [
            'station_id' => $this->controller->stationId, 'odoo_id' => ++$id + 1000, 'fuel_name' => $fuel, 'price' => $price,
            'effective_date' => '2026-10-01', 'synced_at' => '2026-10-12 05:00:00',
        ])->execute();
    }

    private function scalar(string $sql): mixed
    {
        return $this->db->createCommand($sql)->queryScalar();
    }
}
