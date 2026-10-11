<?php

declare(strict_types=1);

namespace App\Pts\Endpoint;

use App\Pts\ControllerEndpoint;
use App\Pts\ControllerLock;
use App\Pts\PacketPolicy;
use App\Pts\PtsClient;
use App\Pts\PtsException;
use Psr\SimpleCache\CacheInterface;

use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * Routes each packet of a device request (docs/api-compatibility-matrix.md §1). Replies keep the device's packet
 * Ids and order; FuelPay matches replies by Type + Data.Pump.
 */
final class PacketRouter
{
    private const CONFIG_TYPES = ['GetPumpsConfiguration', 'GetPumpNozzlesConfiguration', 'GetFuelGradesConfiguration', 'GetConfigurationIdentifier'];
    private const CONFIG_TTL_SECONDS = 60;
    private const PROBE_TTL_SECONDS = 30;
    private const PASSTHROUGH_LOCK_WAIT_SECONDS = 2.0;

    public function __construct(
        private readonly StatusService $status,
        private readonly CommandService $commands,
        private readonly PtsClient $pts,
        private readonly ControllerLock $lock,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * @param list<array{Id: int, Type: string, Data?: array<string, mixed>}> $packets Validated request packets.
     * @param array<string, mixed> $controllerRow
     *
     * @return list<array<string, mixed>>
     */
    public function route(ControllerEndpoint $controller, array $controllerRow, array $packets, string $clientKey, string $correlationId): array
    {
        $statusPumps = [];
        foreach ($packets as $packet) {
            if ($packet['Type'] === 'PumpGetStatus') {
                $pump = $packet['Data']['Pump'] ?? null;
                if (is_int($pump)) {
                    $statusPumps[] = $pump;
                }
            }
        }
        $statuses = $statusPumps === [] ? [] : $this->status->statuses(
            $controller,
            $controllerRow,
            $statusPumps,
            (int) $controllerRow['freshness_ms'],
            (int) $controllerRow['stale_ms'],
        );

        $replies = [];
        foreach ($packets as $packet) {
            $type = $packet['Type'];
            $id = $packet['Id'];

            if (!PacketPolicy::allows(PacketPolicy::DEVICE, $type)) {
                $replies[] = Packets::error($id, $type, 'Not supported by mediator');
                continue;
            }
            if ($type === 'PumpGetStatus') {
                $pump = $packet['Data']['Pump'] ?? null;
                if (!is_int($pump)) {
                    $replies[] = Packets::error($id, $type, 'Missing Data.Pump');
                    continue;
                }
                $reply = $statuses[$pump] ?? null;
                if ($reply !== null) {
                    $replies[] = Packets::withId($reply, $id);
                }
                // No packet for a pump whose status is unknown or stale: FuelPay marks it offline.
                continue;
            }
            if (in_array($type, PacketPolicy::COMMAND_TYPES, true)) {
                $replies[] = $this->commands->execute($controller, $packet, $clientKey, $correlationId);
                continue;
            }
            $replies[] = $this->passthrough($controller, $packet);
        }

        return $replies;
    }

    /**
     * Configuration (cached), probes (cached) and totals (forwarded). No physical effect on dispensing.
     *
     * @param array{Id: int, Type: string, Data?: array<string, mixed>} $packet
     *
     * @return array<string, mixed>
     */
    private function passthrough(ControllerEndpoint $controller, array $packet): array
    {
        $type = $packet['Type'];
        $request = ['Type' => $type] + (isset($packet['Data']) ? ['Data' => $packet['Data']] : []);
        $ttl = in_array($type, self::CONFIG_TYPES, true) ? self::CONFIG_TTL_SECONDS
            : ($type === 'ProbeGetMeasurements' ? self::PROBE_TTL_SECONDS : 0);
        $key = 'pts.' . $controller->id . '.' . hash('sha256', (string) json_encode($request));

        if ($ttl > 0) {
            /** @var mixed $hit */
            $hit = $this->cache->get($key);
            if (is_array($hit)) {
                /** @var array<string, mixed> $hit */
                return Packets::withId($hit, $packet['Id']);
            }
        }

        if (!$this->lock->acquire($controller->id, self::PASSTHROUGH_LOCK_WAIT_SECONDS)) {
            return Packets::error($packet['Id'], $type, 'Controller busy, try again');
        }
        try {
            $reply = $this->pts->send($controller, [$request], PacketPolicy::DEVICE)[0] ?? null;
        } catch (PtsException $e) {
            return Packets::error($packet['Id'], $type, 'PTS unavailable: ' . $e->kind);
        } finally {
            $this->lock->release($controller->id);
        }
        if ($reply === null) {
            return Packets::error($packet['Id'], $type, 'No reply from controller');
        }
        if ($ttl > 0 && !Packets::isError($reply)) {
            $this->cache->set($key, $reply, $ttl);
        }

        return Packets::withId($reply, $packet['Id']);
    }

    /**
     * Validates the device request body into packets; returns an error string for a malformed request.
     *
     * @return list<array{Id: int, Type: string, Data?: array<string, mixed>}>|string
     */
    public static function parseRequest(mixed $body): array|string
    {
        if (!is_array($body) || !is_array($body['Packets'] ?? null)) {
            return 'Request must be a jsonPTS object with a Packets array';
        }
        $packets = [];
        /** @var mixed $packet */
        foreach ($body['Packets'] as $packet) {
            $packetId = is_array($packet) ? ($packet['Id'] ?? null) : null;
            $packetType = is_array($packet) ? ($packet['Type'] ?? null) : null;
            if (!is_int($packetId) || !is_string($packetType)) {
                return 'Each packet needs an integer Id and a string Type';
            }
            $data = is_array($packet) ? ($packet['Data'] ?? null) : null;
            $parsed = ['Id' => $packetId, 'Type' => $packetType];
            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                $parsed['Data'] = $data;
            }
            $packets[] = $parsed;
        }
        if ($packets === [] || count($packets) > 32) {
            return 'Packets must contain between 1 and 32 packets';
        }

        return $packets;
    }
}
