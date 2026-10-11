<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pts;

use App\Pts\ControllerEndpoint;
use App\Pts\PacketPolicy;
use App\Pts\PtsClient;
use App\Pts\PtsException;
use App\Pts\StatusParser;
use App\Pts\PumpStatus;
use App\Shared\Http\HttpOptions;
use App\Shared\Http\HttpResult;
use App\Shared\Http\TransportException;
use App\Tests\Support\Fake\FakeHttpTransport;
use App\Tests\Support\PtsFixture;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class PtsClientTest extends Unit
{
    public function testReadOnlyPolicyRejectsCommandsBeforeSendingAnything(): void
    {
        $transport = new FakeHttpTransport();

        foreach (['PumpAuthorize', 'PumpStop', 'PumpEmergencyStop', 'SetFuelGradesPrices', 'PumpGetTotals'] as $type) {
            try {
                (new PtsClient($transport))->send($this->endpoint(), [
                    ['Type' => 'PumpGetStatus', 'Data' => ['Pump' => 1]],
                    ['Type' => $type, 'Data' => ['Pump' => 1]],
                ], PacketPolicy::READ_ONLY);
                self::fail("$type must be rejected");
            } catch (PtsException $e) {
                assertSame(PtsException::POLICY, $e->kind);
            }
        }
        assertCount(0, $transport->requests, 'nothing may be sent when any packet is not allowed');
        assertFalse(PacketPolicy::allows('unknown-policy', 'PumpGetStatus'));
    }

    public function testChunksIntoEightPacketRequestsAndKeepsRequestOrder(): void
    {
        $transport = new FakeHttpTransport();
        $responder = PtsFixture::statusResponder(static fn(int $pump): array => PtsFixture::idle($pump, 1000 + $pump, 500.0 + $pump, 1.0, 2.18));
        $transport->queue($responder)->queue($responder)->queue($responder);

        $packets = array_map(static fn(int $p): array => ['Type' => 'PumpGetStatus', 'Data' => ['Pump' => $p]], range(1, 18));
        $replies = (new PtsClient($transport))->send($this->endpoint(), $packets, PacketPolicy::READ_ONLY);

        assertCount(3, $transport->requests);
        assertCount(8, $transport->decodedRequest(0)['Packets']);
        assertCount(2, $transport->decodedRequest(2)['Packets']);
        assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_column($transport->decodedRequest(1)['Packets'], 'Id'));
        assertCount(18, $replies);
        assertSame(18, $replies[17]['Data']['Pump']);
        assertSame(1018, $replies[17]['Data']['LastTransaction']);

        $options = $transport->requests[0]['options'];
        assertSame(HttpOptions::AUTH_DIGEST, $options?->authMode);
        assertSame('secret', $options?->password);
    }

    public function testMissingReplyPacketIsNull(): void
    {
        $transport = (new FakeHttpTransport())->queueJson(['Protocol' => 'jsonPTS', 'Packets' => [
            ['Id' => 2, 'Type' => 'PumpIdleStatus', 'Data' => ['Pump' => 2]],
        ]]);

        $replies = (new PtsClient($transport))->send($this->endpoint(), [
            ['Type' => 'PumpGetStatus', 'Data' => ['Pump' => 1]],
            ['Type' => 'PumpGetStatus', 'Data' => ['Pump' => 2]],
        ], PacketPolicy::READ_ONLY);

        assertNull($replies[0]);
        assertSame(2, $replies[1]['Data']['Pump']);
    }

    public function testFailuresAreClassified(): void
    {
        $cases = [
            [new TransportException('timed out', isTimeout: true), PtsException::TIMEOUT],
            [new TransportException('refused', isTimeout: false), PtsException::TRANSPORT],
            [new HttpResult(401, '', 3), PtsException::AUTH],
            [new HttpResult(403, '<html>cloudflare</html>', 3), PtsException::AUTH],
            [new HttpResult(502, '', 3), PtsException::HTTP],
            [new HttpResult(200, 'not json', 3), PtsException::MALFORMED],
            [new HttpResult(200, '{"Protocol":"jsonPTS"}', 3), PtsException::MALFORMED],
        ];
        foreach ($cases as [$response, $kind]) {
            $transport = (new FakeHttpTransport())->queue($response);
            try {
                (new PtsClient($transport))->send($this->endpoint(), [['Type' => 'PumpGetStatus', 'Data' => ['Pump' => 1]]], PacketPolicy::READ_ONLY);
                self::fail("Expected $kind");
            } catch (PtsException $e) {
                assertSame($kind, $e->kind);
            }
        }
    }

    public function testStatusParserHandlesCapturedShapes(): void
    {
        $idle = StatusParser::parse(1, ['Id' => 1] + PtsFixture::idle(1, 4859, 15839.18, 13.76, 30.0));
        assertSame(PumpStatus::IDLE, $idle->state);
        assertTrue($idle->hasCompletedFill());
        assertSame(4859, $idle->lastTransaction);
        assertSame('15839.180', $idle->lastTotalVolume);
        assertSame('13.760', $idle->lastVolume);
        assertSame('30.00', $idle->lastAmount);

        $noTotal = StatusParser::parse(1, ['Id' => 1] + PtsFixture::idle(1, 4859, null, 13.76, 30.0));
        assertNull($noTotal->lastTotalVolume, 'an omitted meter is unknown, never zero');

        $filling = StatusParser::parse(9, ['Id' => 1] + PtsFixture::filling(9, 4520, 2.47));
        assertSame(PumpStatus::FILLING, $filling->state);
        assertFalse($filling->hasCompletedFill());
        assertSame(4520, $filling->currentTransaction);

        $error = StatusParser::parse(19, ['Id' => 3, 'Type' => 'PumpGetStatus', 'Error' => true, 'Code' => 52, 'Message' => 'JSONPTS_ERROR_PUMP_IS_NOT_CONFIGURED']);
        assertSame(PumpStatus::ERROR, $error->state);
        assertSame('JSONPTS_ERROR_PUMP_IS_NOT_CONFIGURED', $error->errorMessage);

        assertSame(PumpStatus::UNKNOWN, StatusParser::parse(5, null)->state);
    }

    public function testControllerTimeIsConvertedToUtc(): void
    {
        assertSame('2026-10-11 03:41:43.000', $this->endpoint()->controllerTimeToUtc('2026-10-11T06:41:43'));
        assertNull($this->endpoint()->controllerTimeToUtc(''));
    }

    private function endpoint(): ControllerEndpoint
    {
        return new ControllerEndpoint(1, 1, 'Test', 'https://pts.test/jsonPTS', 'digest', 'admin', 'secret', false, 10000, 10000, 180);
    }
}
