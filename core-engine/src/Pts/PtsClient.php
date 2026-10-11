<?php

declare(strict_types=1);

namespace App\Pts;

use App\Shared\Http\HttpOptions;
use App\Shared\Http\HttpTransportInterface;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\TransportException;
use JsonException;

use function array_chunk;
use function count;
use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use function sprintf;
use function substr;

/**
 * jsonPTS client: the only code that sends packets to a controller.
 *
 * - Enforces a {@see PacketPolicy} before anything is sent.
 * - Splits into requests of at most {@see MAX_PACKETS_PER_REQUEST} (controller limit used by Odoo and FuelPay).
 * - Matches response packets to request packets by `Id`; a request packet without a reply yields `null`.
 * - Never retries. Callers must serialize access per controller ({@see ControllerLock}).
 */
final class PtsClient
{
    public const MAX_PACKETS_PER_REQUEST = 8;

    public function __construct(
        private readonly HttpTransportInterface $transport,
    ) {}

    /**
     * @param list<array{Type: string, Data?: array<string, mixed>}> $packets
     *
     * @throws PtsException
     *
     * @return list<array<string, mixed>|null> Response packets in request order.
     */
    public function send(ControllerEndpoint $controller, array $packets, string $policy): array
    {
        foreach ($packets as $packet) {
            if (!PacketPolicy::allows($policy, $packet['Type'])) {
                throw new PtsException(PtsException::POLICY, sprintf('Packet "%s" not allowed by policy "%s".', $packet['Type'], $policy));
            }
        }
        if ($controller->url === '') {
            throw new PtsException(PtsException::CONFIGURATION, sprintf('Controller #%d has no URL.', $controller->id));
        }

        $responses = [];
        foreach (array_chunk($packets, self::MAX_PACKETS_PER_REQUEST) as $chunk) {
            foreach ($this->sendChunk($controller, $chunk) as $response) {
                $responses[] = $response;
            }
        }

        return $responses;
    }

    /**
     * @param list<array{Type: string, Data?: array<string, mixed>}> $chunk
     *
     * @return list<array<string, mixed>|null>
     */
    private function sendChunk(ControllerEndpoint $controller, array $chunk): array
    {
        $requestPackets = [];
        foreach ($chunk as $index => $packet) {
            $requestPackets[] = ['Id' => $index + 1, 'Type' => $packet['Type']] + (isset($packet['Data']) ? ['Data' => $packet['Data']] : []);
        }
        $body = json_encode(['Protocol' => 'jsonPTS', 'Packets' => $requestPackets], JsonResponder::FLAGS);

        try {
            $result = $this->transport->post(
                $controller->url,
                $body,
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                new HttpOptions(
                    timeoutSeconds: $controller->timeoutMs / 1000,
                    connectTimeoutSeconds: 5.0,
                    authMode: $controller->authMode,
                    username: $controller->username,
                    password: $controller->password,
                    verifySsl: $controller->verifySsl,
                ),
            );
        } catch (TransportException $e) {
            throw new PtsException(
                $e->isTimeout ? PtsException::TIMEOUT : PtsException::TRANSPORT,
                $e->getMessage(),
                requestMayHaveBeenSent: $e->requestMayHaveBeenSent,
            );
        }

        if ($result->status === 401 || $result->status === 403) {
            throw new PtsException(PtsException::AUTH, sprintf('Controller rejected the request (HTTP %d).', $result->status), $result->status);
        }
        if (!$result->isSuccessful()) {
            throw new PtsException(PtsException::HTTP, sprintf('Controller HTTP %d.', $result->status), $result->status);
        }

        try {
            $decoded = json_decode($result->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PtsException(PtsException::MALFORMED, 'Controller response is not JSON: ' . substr($result->body, 0, 120));
        }
        if (!is_array($decoded) || !is_array($decoded['Packets'] ?? null)) {
            throw new PtsException(PtsException::MALFORMED, 'Controller response has no Packets array.');
        }

        $byId = [];
        /** @var mixed $packet */
        foreach ($decoded['Packets'] as $packet) {
            $packetId = is_array($packet) ? ($packet['Id'] ?? null) : null;
            if (is_array($packet) && is_int($packetId)) {
                /** @var array<string, mixed> $packet */
                $byId[$packetId] = $packet;
            }
        }

        $ordered = [];
        for ($id = 1, $n = count($requestPackets); $id <= $n; $id++) {
            $ordered[] = $byId[$id] ?? null;
        }

        return $ordered;
    }
}
