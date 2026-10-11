<?php

declare(strict_types=1);

namespace App\Tests\Unit\Odoo;

use App\Odoo\JsonRpcOdooReadClient;
use App\Odoo\OdooException;
use App\Shared\Http\HttpResult;
use App\Shared\Http\TransportException;
use App\Tests\Support\Fake\FakeHttpTransport;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

final class JsonRpcOdooReadClientTest extends Unit
{
    public function testAuthenticatesOnceAndSendsSearchReadEnvelope(): void
    {
        $transport = (new FakeHttpTransport())
            ->queueJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => 25])
            ->queueJson(['jsonrpc' => '2.0', 'id' => 2, 'result' => [['id' => 1, 'name' => 'Fuel Station 11']]])
            ->queueJson(['jsonrpc' => '2.0', 'id' => 3, 'result' => 7]);
        $client = $this->client($transport);

        $rows = $client->searchRead('pts.station', [['active', '=', true]], ['id', 'name'], 10, 'id asc', ['active_test' => false]);
        $count = $client->searchCount('pts.station', []);

        assertSame([['id' => 1, 'name' => 'Fuel Station 11']], $rows);
        assertSame(7, $count);
        assertCount(3, $transport->requests, 'authenticate must be called only once');
        assertSame('https://odoo.test/jsonrpc', $transport->requests[0]['url']);

        $auth = $transport->decodedRequest(0);
        assertSame(['common', 'authenticate'], [$auth['params']['service'], $auth['params']['method']]);

        $search = $transport->decodedRequest(1);
        assertSame('execute_kw', $search['params']['method']);
        assertSame(['db', 25, 'secret', 'pts.station', 'search_read'], array_slice($search['params']['args'], 0, 5));
        assertSame(
            ['fields' => ['id', 'name'], 'limit' => 10, 'order' => 'id asc', 'context' => ['active_test' => false]],
            $search['params']['args'][6],
        );
    }

    public function testOnlyReadMethodsAreEverSent(): void
    {
        assertSame(['search_read', 'search_count', 'read', 'fields_get'], JsonRpcOdooReadClient::READ_METHODS);
    }

    public function testOdooErrorEnvelopeBecomesException(): void
    {
        $transport = (new FakeHttpTransport())
            ->queueJson(['result' => 25])
            ->queueJson(['error' => ['message' => 'Odoo Server Error', 'data' => [
                'name' => 'odoo.exceptions.AccessError',
                'message' => 'You are not allowed to access this document',
            ]]]);

        try {
            $this->client($transport)->searchRead('pts.station', [], ['id']);
            self::fail('Expected OdooException');
        } catch (OdooException $e) {
            assertSame('odoo.exceptions.AccessError', $e->odooErrorName);
            assertSame('You are not allowed to access this document', $e->getMessage());
            assertFalse($e->isTransport);
        }
    }

    public function testTimeoutIsReportedAsTransportErrorWithoutLeakingPassword(): void
    {
        $transport = (new FakeHttpTransport())
            ->queue(new TransportException('HTTP transport error 28: Operation timed out', isTimeout: true));

        try {
            $this->client($transport)->searchRead('pts.station', [], ['id']);
            self::fail('Expected OdooException');
        } catch (OdooException $e) {
            assertTrue($e->isTransport);
            assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testFailedAuthenticationAndHtmlResponsesAreRejected(): void
    {
        $denied = (new FakeHttpTransport())->queueJson(['result' => false]);
        try {
            $this->client($denied)->searchCount('pts.station', []);
            self::fail('Expected OdooException');
        } catch (OdooException $e) {
            assertSame('AccessDenied', $e->odooErrorName);
        }

        $html = (new FakeHttpTransport())->queue(new HttpResult(200, '<!DOCTYPE html><html>login</html>', 3));
        try {
            $this->client($html)->searchCount('pts.station', []);
            self::fail('Expected OdooException');
        } catch (OdooException $e) {
            assertTrue($e->isTransport);
        }
    }

    public function testUnconfiguredClientNeverCallsTheNetwork(): void
    {
        $transport = new FakeHttpTransport();
        $client = new JsonRpcOdooReadClient($transport, '', '', '', '');

        try {
            $client->searchCount('pts.station', []);
            self::fail('Expected OdooException');
        } catch (OdooException) {
            assertCount(0, $transport->requests);
        }
    }

    private function client(FakeHttpTransport $transport): JsonRpcOdooReadClient
    {
        return new JsonRpcOdooReadClient($transport, 'https://odoo.test/', 'db', 'user@example.com', 'secret');
    }
}
