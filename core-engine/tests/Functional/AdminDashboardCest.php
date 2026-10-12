<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The admin dashboard renders on an empty database (fresh install) without errors.
 */
final class AdminDashboardCest
{
    public function pagesRender(FunctionalTester $tester): void
    {
        foreach (['/' => 'Network', '/devices' => 'Devices', '/issues' => 'Open issues', '/traffic' => 'Traffic'] as $path => $heading) {
            $response = $tester->sendRequest(new ServerRequest(uri: $path));
            $body = $response->getBody()->getContents();

            assertSame(200, $response->getStatusCode(), $path);
            assertStringContainsString('FuelPay Mediator', $body, $path);
            assertStringContainsString($heading, $body, $path);
        }
    }

    public function unknownStationIsNotFound(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/station/999999'));

        assertSame(404, $response->getStatusCode());
    }
}
