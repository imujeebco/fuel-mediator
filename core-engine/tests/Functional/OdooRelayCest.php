<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use App\Tests\Support\TestDatabase;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * HTTP-level checks of the Odoo relay that never reach Odoo.
 */
final class OdooRelayCest
{
    public function _before(): void
    {
        TestDatabase::connection();
    }

    public function deniedMethodGetsOdooShapedAccessError(FunctionalTester $tester): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"call","params":{"service":"object","method":"execute_kw",'
            . '"args":["db",2,"pw","res.users","write",[[1],{"login":"x"}],{}]}}';
        $response = $tester->sendRequest(
            (new ServerRequest(method: 'POST', uri: '/odoo/jsonrpc', headers: ['Content-Type' => 'application/json']))
                ->withBody((new StreamFactory())->createStream($body)),
        );
        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        assertSame(200, $response->getStatusCode());
        assertSame(1, $data['id']);
        assertSame('odoo.exceptions.AccessError', $data['error']['data']['name']);
        assertStringContainsString('res.users.write', $data['error']['data']['message']);
    }
}
