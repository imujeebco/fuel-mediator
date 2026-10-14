<?php

declare(strict_types=1);

namespace App\Tests\Unit\Odoo;

use App\Odoo\Relay\OdooRelay;
use App\Odoo\Relay\PriceVersionFilter;
use App\Odoo\Relay\PumpsRewriter;
use App\Odoo\Relay\SaleObserver;
use App\Odoo\Relay\SettledFillFilter;
use App\Reconciliation\SettledFills;
use App\Shared\Clock;
use App\Shared\Http\HttpResult;
use App\Shared\Http\TransportException;
use App\Shared\Log\Redactor;
use App\Tests\Support\Fake\FakeHttpTransport;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class OdooRelayTest extends Unit
{
    private ConnectionInterface $db;
    private FakeHttpTransport $transport;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
        PtsFixture::seedController($this->db, 1, 2, [1]);
        $this->transport = new FakeHttpTransport();
    }

    public function testAllowedCallIsForwardedVerbatimAndPinsAreNotJournaled(): void
    {
        $odooAnswer = '{"jsonrpc":"2.0","id":7,"result":[{"id":3,"name":"Ali","pin":"1234"}]}';
        $this->transport->queue(new HttpResult(200, $odooAnswer, 20));
        $request = $this->rpc(7, 'pos.config', 'mobile_list_employees', [[58]]);

        $response = $this->relay()->handle($request, '10.0.0.5', 'c1');

        assertSame($odooAnswer, $response->body);
        assertSame($request, $this->transport->requests[0]['body'], 'device body forwarded byte for byte');
        assertSame('https://odoo.test/jsonrpc', $this->transport->requests[0]['url']);
        assertNull($this->scalar('SELECT response_redacted FROM relay_calls'), 'PASS methods store no bodies');
        assertSame('ok', $this->scalar('SELECT outcome FROM relay_calls'));
    }

    public function testMethodsOutsideTheAllowListAreDeniedWithoutReachingOdoo(): void
    {
        foreach ([['res.users', 'write'], ['account.move', 'unlink'], ['pts.backend.config', 'unlink'], ['pos.order', 'search_read']] as [$model, $method]) {
            $response = $this->relay()->handle($this->rpc(1, $model, $method, [[1]]), '10.0.0.5', 'c');
            assertStringContainsString('odoo.exceptions.AccessError', $response->body);
        }
        $db = $this->relay()->handle('{"jsonrpc":"2.0","id":1,"method":"call","params":{"service":"db","method":"drop","args":["x"]}}', '10.0.0.5', 'c');

        assertStringContainsString('AccessError', $db->body);
        assertCount(0, $this->transport->requests);
        assertSame(5, (int) $this->scalar("SELECT COUNT(*) FROM relay_calls WHERE outcome = 'denied'"));
    }

    public function testSaleRegistrationIsJournaledRedactedAndObserved(): void
    {
        $this->transport->queueJson(['jsonrpc' => '2.0', 'id' => 3, 'result' => [
            'status' => 'created', 'id' => 491700, 'canonical_uuid' => 'u-1', 'pos_sync' => ['synced' => true, 'order_id' => 99],
        ]]);

        $this->relay()->handle($this->rpc(3, 'pts.backend.config', 'register_terminal_sale', [$this->salePayload('u-1')]), '10.0.0.5', 'c');

        $request = (string) $this->scalar('SELECT request_redacted FROM relay_calls');
        assertStringNotContainsString('device-secret', $request, 'execute_kw password is redacted');
        assertStringContainsString('register_terminal_sale', $request);
        assertSame('created', $this->scalar('SELECT odoo_status FROM sale_observations'));
        assertSame('1', (string) $this->scalar('SELECT pos_synced FROM sale_observations'));
        assertSame('100.00', (string) $this->scalar('SELECT amount FROM sale_observations'));
        assertSame('N910-ABCD1234', $this->scalar('SELECT terminal_id FROM devices'));
        assertSame($this->scalar('SELECT id FROM stations'), $this->scalar('SELECT station_id FROM sale_observations'));
    }

    public function testSaleNamingAnUnknownStationDoesNotUnlinkTheDevice(): void
    {
        $answer = ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['status' => 'created', 'canonical_uuid' => 'x']];
        $this->transport->queueJson($answer)->queueJson($answer);
        $unknown = ['station_id' => 1] + $this->salePayload('u-5'); // backend 1: not served by the mediator

        $this->relay()->handle($this->rpc(3, 'pts.backend.config', 'register_terminal_sale', [$this->salePayload('u-4')]), '10.0.0.5', 'c');
        $this->relay()->handle($this->rpc(3, 'pts.backend.config', 'register_terminal_sale', [$unknown]), '10.0.0.5', 'c');

        assertSame($this->scalar('SELECT id FROM stations'), $this->scalar('SELECT station_id FROM devices'));
        assertNull($this->scalar("SELECT station_id FROM sale_observations WHERE uuid = 'u-5'"));
    }

    /** Live finding 2026-10-14: FuelPay omits station_id when the device has no Odoo station set. */
    public function testDeviceWithoutStationIdIsLinkedThroughItsPosConfig(): void
    {
        $stationId = $this->scalar('SELECT id FROM stations');
        $this->db->createCommand()->insert('odoo_sales', [
            'station_id' => $stationId, 'odoo_id' => 900, 'uuid' => 'mirrored', 'odoo_pos_config_id' => 58, 'pump_number' => 1,
            'volume' => '1.000', 'amount' => '2.18', 'payment_status' => 'APPROVED', 'sale_created_at' => '2026-10-14 10:00:00',
            'odoo_write_date' => '2026-10-14 10:00:00',
        ])->execute();
        $this->transport->queueJson(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['status' => 'created', 'canonical_uuid' => 'x']]);
        $payload = $this->salePayload('u-6');
        unset($payload['station_id']);

        $this->relay()->handle($this->rpc(3, 'pts.backend.config', 'register_terminal_sale', [$payload]), '10.0.0.5', 'c');

        assertSame($stationId, $this->scalar('SELECT station_id FROM devices'));
        assertSame($stationId, $this->scalar("SELECT station_id FROM sale_observations WHERE uuid = 'u-6'"));
    }

    /** Test scenario 9: Odoo unavailable during a completed sale — the mediator never acknowledges it itself. */
    public function testOdooTimeoutReturnsRetryableErrorAndKeepsTheAttempt(): void
    {
        $this->transport->queue(new TransportException('Operation timed out', isTimeout: true));

        $response = $this->relay()->handle($this->rpc(4, 'pts.backend.config', 'register_terminal_sale', [$this->salePayload('u-2')]), '10.0.0.5', 'c');

        assertStringContainsString('timeout', $response->body, 'FuelPay treats "timeout" as retryable and keeps the sale queued');
        assertStringNotContainsString('"result"', $response->body);
        assertSame('timeout', $this->scalar('SELECT outcome FROM relay_calls'));
        assertSame('u-2', $this->scalar('SELECT uuid FROM sale_observations'));
        assertNull($this->scalar('SELECT odoo_status FROM sale_observations'));
    }

    /** Test scenario 12: duplicate synchronization attempts are forwarded for Odoo's uuid upsert and traced. */
    public function testRepeatedSyncOfTheSameUuidIsForwardedAndTraced(): void
    {
        $this->transport->queueJson(['jsonrpc' => '2.0', 'id' => 5, 'result' => ['status' => 'created', 'canonical_uuid' => 'u-3']]);
        $this->transport->queueJson(['jsonrpc' => '2.0', 'id' => 6, 'result' => ['status' => 'duplicate', 'canonical_uuid' => 'u-3', 'already_paid' => true]]);

        $this->relay()->handle($this->rpc(5, 'pts.backend.config', 'register_terminal_sale', [$this->salePayload('u-3')]), '10.0.0.5', 'c');
        $this->relay()->handle($this->rpc(6, 'pts.backend.config', 'register_terminal_sale', [$this->salePayload('u-3')]), '10.0.0.5', 'c');

        assertCount(2, $this->transport->requests);
        assertSame('created,duplicate', $this->scalar("SELECT GROUP_CONCAT(odoo_status ORDER BY id) FROM sale_observations WHERE uuid = 'u-3'"));
    }

    public function testUpstreamFailuresMapToRetryableErrors(): void
    {
        $this->transport->queue(new HttpResult(502, 'Bad gateway', 5));
        $this->transport->queue(new HttpResult(200, '<!DOCTYPE html><html>Odoo login</html>', 5));
        $this->transport->queue(new HttpResult(429, '{"error":"rate limited"}', 5));

        $bad = $this->relay()->handle($this->rpc(1, 'pos.config', 'mobile_get_price_version', [[58]]), 'ip', 'c');
        $html = $this->relay()->handle($this->rpc(2, 'pos.config', 'mobile_get_price_version', [[58]]), 'ip', 'c');
        $limited = $this->relay()->handle($this->rpc(3, 'pos.config', 'mobile_get_price_version', [[58]]), 'ip', 'c');

        assertStringContainsString('502', $bad->body);
        assertStringContainsString('503', $html->body, 'an HTML page is never passed to the device');
        assertSame(429, $limited->status);
        assertSame('5', $limited->headers['Retry-After']);
    }

    public function testMobileGetPumpsIsRewrittenToTheMediatorOnlyWhenConfigured(): void
    {
        $answer = ['jsonrpc' => '2.0', 'id' => 8, 'result' => [
            'station_id' => 1, 'backend_config_id' => 2, 'pts_enabled' => true,
            'pts_connection' => ['integration_mode' => 'direct', 'auth_mode' => 'digest', 'direct_pts_url' => 'https://station11.fuel.net.sa/jsonPTS',
                'backend_url' => false, 'username' => 'admin', 'password' => 'admin'],
            'pumps' => [['pump_number' => 1]],
        ]];
        $this->transport->queueJson($answer)->queueJson($answer);
        $request = $this->rpc(8, 'pts.backend.config', 'mobile_get_pumps', [[0]]);

        $rewritten = json_decode($this->relay('https://mediator.test')->handle($request, 'ip', 'c')->body, true);
        $untouched = json_decode($this->relay('')->handle($request, 'ip', 'c')->body, true);

        assertSame('https://mediator.test/stations/2/jsonPTS', $rewritten['result']['pts_connection']['direct_pts_url']);
        assertSame('admin', $rewritten['result']['pts_connection']['username'], 'FuelPay needs a non-blank username');
        assertSame([['pump_number' => 1]], $rewritten['result']['pumps']);
        assertSame('https://station11.fuel.net.sa/jsonPTS', $untouched['result']['pts_connection']['direct_pts_url']);
    }

    private function relay(string $publicUrl = ''): OdooRelay
    {
        $clock = new Clock();

        return new OdooRelay(
            $this->transport,
            $this->db,
            new Redactor(),
            new SaleObserver($this->db, $clock, new NullLogger(), new SettledFills($this->db, $clock)),
            new PumpsRewriter($this->db, $publicUrl),
            new SettledFillFilter(new SettledFills($this->db, $clock), $clock, new NullLogger(), true),
            new PriceVersionFilter($this->db, new SettledFills($this->db, $clock), new NullLogger()),
            $clock,
            new NullLogger(),
            'https://odoo.test',
        );
    }

    private function rpc(int $id, string $model, string $method, array $args, array $kwargs = []): string
    {
        return json_encode([
            'jsonrpc' => '2.0', 'id' => $id, 'method' => 'call',
            'params' => ['service' => 'object', 'method' => 'execute_kw',
                'args' => ['fuelksa-db', 25, 'device-secret', $model, $method, $args, (object) $kwargs]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function salePayload(string $uuid): array
    {
        return [
            'uuid' => $uuid, 'created_at_ms' => 1791260400000, 'pos_config_id' => 58, 'terminal_id' => 'N910-ABCD1234',
            'pump_number' => 1, 'nozzle_number' => 1, 'fuel_name' => 'A 91', 'price' => 2.18, 'volume' => 45.87,
            'amount_sar' => 100.0, 'pts_transaction_id' => '', 'manual_sale' => true, 'payment_method' => 'CASH',
            'payment_status' => 'APPROVED', 'station_id' => 2,
        ];
    }

    private function scalar(string $sql): mixed
    {
        return $this->db->createCommand($sql)->queryScalar();
    }
}
