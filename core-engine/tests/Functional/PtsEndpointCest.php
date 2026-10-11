<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringStartsWith;

/**
 * HTTP-level checks of the PTS-compatible endpoint that never reach a controller.
 */
final class PtsEndpointCest
{
    public function _before(): void
    {
        $db = TestDatabase::connection();
        TestDatabase::truncate($db);
        PtsFixture::seedController($db, 1, 2, [1]);
    }

    public function unknownControllerIs404(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest($this->post('/stations/999/jsonPTS', '{"Protocol":"jsonPTS","Packets":[]}'));

        assertSame(404, $response->getStatusCode());
        assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    }

    /** A device POST without CSRF token or session must reach the action (not be rejected by middleware). */
    public function malformedBodyIs400NotCsrfError(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest($this->post('/stations/2/jsonPTS', 'not json'));

        assertSame(400, $response->getStatusCode());
        assertSame('{"Protocol":"jsonPTS","Error":"Malformed JSON"}', $response->getBody()->getContents());
    }

    public function invalidPacketsAre400(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest($this->post('/stations/2/jsonPTS', '{"Protocol":"jsonPTS","Packets":[{"Type":"PumpGetStatus"}]}'));

        assertSame(400, $response->getStatusCode());
    }

    private function post(string $uri, string $body): ServerRequest
    {
        return (new ServerRequest(method: 'POST', uri: $uri, headers: ['Content-Type' => 'application/json']))
            ->withBody((new StreamFactory())->createStream($body));
    }
}
