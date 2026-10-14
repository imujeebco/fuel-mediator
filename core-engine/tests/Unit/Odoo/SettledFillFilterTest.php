<?php

declare(strict_types=1);

namespace App\Tests\Unit\Odoo;

use App\Odoo\Relay\OdooRelay;
use App\Odoo\Relay\PriceVersionFilter;
use App\Odoo\Relay\PumpsRewriter;
use App\Odoo\Relay\SaleObserver;
use App\Odoo\Relay\SettledFillFilter;
use App\Reconciliation\FillMatcher;
use App\Reconciliation\LiveSettlements;
use App\Reconciliation\SettledFills;
use App\Shared\Clock;
use App\Shared\Http\HttpResult;
use App\Shared\Log\Redactor;
use App\Tests\Support\Fake\FakeHttpTransport;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\NullLogger;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_column;
use function in_array;
use function json_decode;
use function json_encode;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Decision D21, from the Station 11 close on 2026-10-11: fills paid manually (or under the wrong pump/transaction
 * number) must stop being re-presented to devices, and nothing else may be hidden.
 */
final class SettledFillFilterTest extends Unit
{
    private const CONFIG = 51;

    private ConnectionInterface $db;
    private FakeHttpTransport $transport;
    private int $stationId;
    private int $controllerId;
    private int $saleSeq = 0;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
        $controller = PtsFixture::seedController($this->db, 1, 2, [15, 18, 9, 7, 2, 8], [15 => 'A 91', 18 => 'A 91', 9 => 'A 91', 7 => 'A 95', 2 => 'A 95', 8 => 'A 91']);
        $this->stationId = $controller->stationId;
        $this->controllerId = $controller->id;
        $this->transport = new FakeHttpTransport();
    }

    public function testFuelPayUuidMatchesJavaNameUuidFromBytes(): void
    {
        // Reference computed independently (MD5 name-based, version 3), as UUID.nameUUIDFromBytes does.
        assertSame('77044318-5f5a-3e91-89f8-020468fc44bb', SettledFills::fuelPayFillUuid('11826', 18));
    }

    /** Fill 18/11826 was paid by card but recorded as pump 15 tx 3318; at close the device asked for it again. */
    public function testFillPaidUnderAnotherPumpIsReportedPaidOnAllThreeCalls(): void
    {
        $this->fill(18, '11826', '9.170', '20.00', '18:46:58');
        $this->sale(15, '9.174', '20.00', '18:48:00', tx: '3318');                       // the real payment
        $this->sale(18, '9.170', '20.00', '18:47:10', tx: '11826', status: 'PENDING', uuid: 'pending-11826');
        $this->match();

        $unpaid = $this->call('mobile_get_unpaid_fills', [[self::CONFIG], 720], ['session_open' => true, 'fills' => [
            $this->unpaidRow('pending-11826', 18, '11826', 20.0),
            $this->unpaidRow('other', 9, '4582', 30.0),
        ]]);
        assertSame(['other'], array_column($unpaid['fills'], 'uuid'), 'only the settled fill is removed');

        $paid = $this->call('mobile_get_paid_fills', [[self::CONFIG], 30], ['fills' => []]);
        $uuids = array_column($paid['fills'], 'uuid');
        assertTrue(in_array('77044318-5f5a-3e91-89f8-020468fc44bb', $uuids, true), 'device uuid listed as paid');
        assertTrue(in_array('pending-11826', $uuids, true), "Odoo's PENDING uuid listed as paid");

        $isPaid = $this->call('mobile_is_fill_paid', [[self::CONFIG], 18, '11826', 'x', 20.0, 9.17, 1], ['paid' => false]);
        assertTrue($isPaid['paid']);

        assertSame(3, (int) $this->scalar('SELECT applied_count FROM fill_settlements'));
    }

    public function testUnpaidFillIsNeverHidden(): void
    {
        $this->fill(9, '4582', '13.760', '30.00', '19:02:03');
        $this->match();

        $unpaid = $this->call('mobile_get_unpaid_fills', [[self::CONFIG], 720], ['session_open' => true, 'fills' => [
            $this->unpaidRow('p-4582', 9, '4582', 30.0),
        ]]);
        assertCount(1, $unpaid['fills']);
        $isPaid = $this->call('mobile_is_fill_paid', [[self::CONFIG], 9, '4582', 'p-4582', 30.0, 13.76, 1], ['paid' => false]);
        assertFalse($isPaid['paid']);
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM fill_settlements'));
    }

    public function testFillPaidByItsOwnPtsSaleIsLeftToOdoo(): void
    {
        $this->fill(18, '11840', '22.940', '50.00', '20:48:00');
        $this->sale(18, '22.940', '50.00', '20:48:20', tx: '11840');
        $this->match();

        $paid = $this->call('mobile_get_paid_fills', [[self::CONFIG], 30], ['fills' => []]);
        assertSame([], $paid['fills']);
    }

    /** Two identical fills close together and one manual payment: the match could be wrong, so nothing is hidden. */
    public function testAmbiguousMatchHidesNothing(): void
    {
        $this->fill(18, '11850', '22.940', '50.00', '21:00:00');
        $this->fill(9, '4590', '22.940', '50.00', '21:05:00');
        $this->sale(18, '22.936', '50.00', '21:00:30');
        $this->match();

        $paid = $this->call('mobile_get_paid_fills', [[self::CONFIG], 30], ['fills' => []]);
        assertSame([], $paid['fills']);
    }

    public function testKillSwitchPassesOdooRepliesThrough(): void
    {
        $this->fill(18, '11826', '9.170', '20.00', '18:46:58');
        $this->sale(15, '9.174', '20.00', '18:48:00', tx: '3318');
        $this->match();

        $answer = '{"jsonrpc":"2.0","id":1,"result":{"session_open":true,"fills":[]}}';
        $this->transport->queue(new HttpResult(200, $answer, 10));
        $response = $this->relay(false)->handle($this->rpc('mobile_get_paid_fills', [[self::CONFIG], 30]), '10.0.0.5', 'c');

        assertSame($answer, $response->body);
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM fill_settlements'));
    }

    /**
     * D28, live finding 2026-10-15 00:07 (pump 7): the controller timed out, the attendant charged SAR 151.92 by card
     * through manual entry, and the PTS record of the same fill stayed PENDING on every device. No controller fill is
     * needed: the production collector was not running.
     */
    public function testManualSaleOnTheSamePumpSettlesThePtsRecordAtOnce(): void
    {
        $this->sale(7, '65.200', '151.92', '20:58:40');
        $row = $this->unpaidRow('p-4336', 7, '4336', 151.92, volume: 65.2, createdUtc: '20:58:21');

        $unpaid = $this->call('mobile_get_unpaid_fills', [[self::CONFIG], 720], ['session_open' => true, 'fills' => [
            $row, $this->unpaidRow('other', 9, '4590', 30.0),
        ]]);
        assertSame(['other'], array_column($unpaid['fills'], 'uuid'));

        $paid = $this->call('mobile_get_paid_fills', [[self::CONFIG], 30], ['fills' => []]);
        $uuids = array_column($paid['fills'], 'uuid');
        assertTrue(in_array(SettledFills::fuelPayFillUuid('4336', 7), $uuids, true), 'device uuid listed as paid');
        assertTrue(in_array('p-4336', $uuids, true), "Odoo's PENDING uuid listed as paid");

        $isPaid = $this->call('mobile_is_fill_paid', [[self::CONFIG], 7, '4336', 'p-4336', 151.92, 65.2, 1], ['paid' => false]);
        assertTrue($isPaid['paid']);
        assertSame('mirror', $this->scalar('SELECT payer_source FROM live_settlements'));
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM fill_settlements'));
    }

    /** Live 2026-10-15: the SAR 151.92 card payment for pump 7 was entered under pump 2 (same grade, A 95). */
    public function testManualSaleUnderAnotherPumpOfTheSameGradeSettles(): void
    {
        $this->sale(2, '65.202', '151.92', '20:58:40');

        $unpaid = $this->call('mobile_get_unpaid_fills', [[self::CONFIG], 720], ['session_open' => true, 'fills' => [
            $this->unpaidRow('p-4336', 7, '4336', 151.92, volume: 65.2, createdUtc: '20:58:21'),
        ]]);

        assertSame([], $unpaid['fills']);
    }

    /** The relay saw the manual sale seconds ago; the sales mirror only catches up within a minute. */
    public function testRelayedManualSaleSettlesBeforeTheMirrorHasIt(): void
    {
        $this->sale(9, '13.760', '30.00', '20:40:00', tx: '4580');   // the config's station is known from the mirror
        $this->transport->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['status' => 'created', 'canonical_uuid' => 'm-1']]);
        $this->relay(true)->handle($this->saleRpc([
            'uuid' => 'm-1', 'pos_config_id' => self::CONFIG, 'terminal_id' => 'sohail', 'pump_number' => 7, 'nozzle_number' => 1,
            'volume' => 65.2, 'amount_sar' => 151.92, 'pts_transaction_id' => '', 'manual_sale' => true,
            'payment_method' => 'CARD', 'payment_status' => 'APPROVED', 'station_id' => 2,
        ]), '10.0.0.5', 'c');

        $unpaid = $this->call('mobile_get_unpaid_fills', [[self::CONFIG], 720], ['session_open' => true, 'fills' => [
            $this->unpaidRow('p-4336', 7, '4336', 151.92, volume: 65.2, createdUtc: '20:59:50'),
        ]]);

        assertSame([], $unpaid['fills']);
        assertSame('relay', $this->scalar('SELECT payer_source FROM live_settlements'));
    }

    /** Another fill of the same amount on the pump: the payment cannot be attributed, so nothing is hidden. */
    public function testAnotherFillOfTheSameAmountKeepsBothUnpaid(): void
    {
        $this->sale(7, '22.936', '50.00', '20:50:00');

        $twoRows = $this->call('mobile_get_unpaid_fills', [[self::CONFIG], 720], ['session_open' => true, 'fills' => [
            $this->unpaidRow('a', 7, '4340', 50.0, createdUtc: '20:49:00'),
            $this->unpaidRow('b', 7, '4341', 50.0, createdUtc: '20:55:00'),
        ]]);
        assertCount(2, $twoRows['fills']);

        $this->sale(7, '22.940', '50.00', '20:57:00', tx: '4342');   // another PTS fill of the pump, paid normally
        $oneRow = $this->call('mobile_get_unpaid_fills', [[self::CONFIG], 720], ['session_open' => true, 'fills' => [
            $this->unpaidRow('a', 7, '4340', 50.0, createdUtc: '20:49:00'),
        ]]);
        assertCount(1, $oneRow['fills']);
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM live_settlements'));
    }

    public function testManualSaleOnAnotherPumpOrAmountDoesNotSettle(): void
    {
        $this->sale(8, '65.200', '151.92', '20:58:40');
        $this->sale(7, '64.000', '149.12', '20:58:50');

        $unpaid = $this->call('mobile_get_unpaid_fills', [[self::CONFIG], 720], ['session_open' => true, 'fills' => [
            $this->unpaidRow('p-4336', 7, '4336', 151.92, volume: 65.2, createdUtc: '20:58:21'),
        ]]);

        assertCount(1, $unpaid['fills']);
    }

    public function testLiveSettlementKillSwitch(): void
    {
        $this->sale(7, '65.200', '151.92', '20:58:40');
        $this->transport->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['session_open' => true, 'fills' => [
            $this->unpaidRow('p-4336', 7, '4336', 151.92, volume: 65.2, createdUtc: '20:58:21'),
        ]]]);

        $response = $this->relay(true, live: false)->handle($this->rpc('mobile_get_unpaid_fills', [[self::CONFIG], 720]), '10.0.0.5', 'c');

        /** @var array{result: array{fills: list<mixed>}} $decoded */
        $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        assertCount(1, $decoded['result']['fills']);
    }

    /**
     * @param array<array-key, mixed> $args
     * @param array<string, mixed> $result Odoo's result
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $args, array $result): array
    {
        $this->transport->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        $response = $this->relay(true)->handle($this->rpc($method, $args), '10.0.0.5', 'c');
        /** @var array{result: array<string, mixed>} $decoded */
        $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);

        return $decoded['result'];
    }

    private function relay(bool $enabled, bool $live = true): OdooRelay
    {
        $clock = $this->clock();

        return new OdooRelay(
            $this->transport,
            $this->db,
            new Redactor(),
            new SaleObserver($this->db, $clock, new NullLogger(), new SettledFills($this->db, $clock)),
            new PumpsRewriter($this->db, ''),
            new SettledFillFilter(new SettledFills($this->db, $clock), $clock, new NullLogger(), $enabled, $live ? new LiveSettlements($this->db, $clock) : null),
            new PriceVersionFilter($this->db, new SettledFills($this->db, $clock), new NullLogger()),
            $clock,
            new NullLogger(),
            'https://odoo.test',
        );
    }

    private function clock(): Clock
    {
        return Clock::fixedAt(new DateTimeImmutable('2026-10-11 21:00:00', new DateTimeZone('UTC')));
    }

    private function match(): void
    {
        (new FillMatcher($this->db, Clock::fixedAt(new DateTimeImmutable('2026-10-11 23:00:00', new DateTimeZone('UTC')))))
            ->match($this->stationId, '2026-10-11 12:00:00', '2026-10-11 23:00:00');
    }

    private function rpc(string $method, array $args): string
    {
        return json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'call',
            'params' => ['service' => 'object', 'method' => 'execute_kw',
                'args' => ['db', 25, 'device-secret', 'pos.config', $method, $args, (object) []]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function unpaidRow(string $uuid, int $pump, string $tx, float $amount, float $volume = 0.0, ?string $createdUtc = null): array
    {
        $row = ['uuid' => $uuid, 'pump_number' => $pump, 'nozzle_number' => 1, 'pts_transaction_id' => $tx, 'amount_sar' => $amount,
            'volume' => $volume];
        if ($createdUtc !== null) {
            $row['created_at_ms'] = (new DateTimeImmutable("2026-10-11 $createdUtc", new DateTimeZone('UTC')))->getTimestamp() * 1000;
        }

        return $row;
    }

    /** @param array<string, mixed> $payload */
    private function saleRpc(array $payload): string
    {
        return json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'call',
            'params' => ['service' => 'object', 'method' => 'execute_kw',
                'args' => ['db', 25, 'device-secret', 'pts.backend.config', 'register_terminal_sale', [$payload], (object) []]],
        ], JSON_THROW_ON_ERROR);
    }

    private function fill(int $pump, string $tx, string $volume, string $amount, string $endedUtc): void
    {
        static $meter = 50000;
        $meter += 100;
        $this->db->createCommand()->insert('fill_events', [
            'station_id' => $this->stationId, 'controller_id' => $this->controllerId, 'pump_number' => $pump,
            'nozzle_number' => 1, 'fuel_name' => 'A 91', 'pts_transaction_no' => $tx,
            'ended_at' => "2026-10-11 $endedUtc", 'volume' => $volume, 'amount' => $amount,
            'meter_volume_after' => $meter, 'source' => 'status_transition',
        ])->execute();
    }

    private function sale(int $pump, string $volume, string $amount, string $createdUtc, ?string $tx = null, string $status = 'APPROVED', ?string $uuid = null): void
    {
        $id = ++$this->saleSeq;
        $this->db->createCommand()->insert('odoo_sales', [
            'station_id' => $this->stationId, 'odoo_id' => $id, 'uuid' => $uuid ?? "u-$id", 'pump_number' => $pump,
            'nozzle_number' => 1, 'fuel_name' => 'A 91', 'volume' => $volume, 'amount' => $amount,
            'payment_status' => $status, 'payment_method' => 'CARD', 'manual_sale' => 1, 'pts_transaction_id' => $tx,
            'odoo_pos_config_id' => self::CONFIG, 'sale_created_at' => "2026-10-11 $createdUtc", 'odoo_write_date' => "2026-10-11 $createdUtc",
        ])->execute();
    }

    private function scalar(string $sql): mixed
    {
        return $this->db->createCommand($sql)->queryScalar();
    }
}
