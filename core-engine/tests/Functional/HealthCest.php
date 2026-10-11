<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use App\Tests\Support\TestDatabase;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringStartsWith;
use function PHPUnit\Framework\assertTrue;

final class HealthCest
{
    public function _before(): void
    {
        TestDatabase::connection(); // ensures the test schema is migrated
    }

    public function healthReportsDatabaseOk(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/health'));
        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        assertSame(200, $response->getStatusCode());
        assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        assertSame('ok', $data['status']);
        assertTrue($data['database']['ok']);
    }

    public function stationHealthListsStations(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/health/stations'));
        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        assertSame(200, $response->getStatusCode());
        assertArrayHasKey('stations', $data);
    }

    /** Machine endpoints must not set session cookies (devices are stateless clients). */
    public function healthDoesNotStartASession(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/health'));

        assertSame('', $response->getHeaderLine('Set-Cookie'));
    }
}
