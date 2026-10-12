<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringStartsWith;

/**
 * The admin dashboard renders on an empty database (fresh install). With no admin password configured it is open to
 * the machine itself only; any other client is sent to the login page.
 */
final class AdminDashboardCest
{
    public function pagesRenderForLocalRequests(FunctionalTester $tester): void
    {
        foreach (['/' => 'Network', '/devices' => 'Devices', '/issues' => 'Open issues', '/traffic' => 'Traffic', '/system' => 'System'] as $path => $heading) {
            $response = $tester->sendRequest(self::local($path));
            $body = $response->getBody()->getContents();

            assertSame(200, $response->getStatusCode(), $path);
            assertStringContainsString('FuelPay Mediator', $body, $path);
            assertStringContainsString($heading, $body, $path);
        }
    }

    public function remoteClientsMustSignIn(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(serverParams: ['REMOTE_ADDR' => '203.0.113.7'], uri: '/devices'));

        assertSame(302, $response->getStatusCode());
        assertStringStartsWith('/login?next=', $response->getHeaderLine('Location'));

        $login = $tester->sendRequest(new ServerRequest(serverParams: ['REMOTE_ADDR' => '203.0.113.7'], uri: '/login'));
        assertSame(200, $login->getStatusCode(), 'login page stays reachable');
    }

    public function unknownStationIsNotFound(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(self::local('/station/999999'));

        assertSame(404, $response->getStatusCode());
    }

    private static function local(string $path): ServerRequest
    {
        return new ServerRequest(serverParams: ['REMOTE_ADDR' => '127.0.0.1'], uri: $path);
    }
}
