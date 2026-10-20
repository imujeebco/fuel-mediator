<?php

declare(strict_types=1);

namespace App\Pts\Endpoint;

use App\Collector\StatusCollector;
use App\Pts\ControllerEndpoint;
use App\Pts\ControllerLock;
use App\Pts\ControllerRepository;
use App\Pts\PtsException;
use App\Shared\Clock;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_diff;
use function array_values;
use function is_array;
use function json_decode;
use function max;

/**
 * Pump status for devices (docs/architecture.md §5.1):
 *  - packets younger than `freshness_ms` are served from `pump_status_cache` (one controller read serves many devices);
 *  - otherwise the pumps are refreshed through the collector (which also records fills), waiting at most
 *    {@see LOCK_WAIT_SECONDS} for the controller so status reads never hold up commands;
 *  - if the controller cannot be read, a cached packet is served only while younger than the stale limit; older status
 *    is omitted (the device shows the pump offline). Stale status is never presented as current.
 *
 * The stale limit is `stale_ms`, but never less than {@see STALE_POLL_CYCLES} collector polls (D31): the collector
 * refreshes the cache only every `poll_interval_ms`, and a slow controller (Station 33: 4–12 s per read through
 * Cloudflare) keeps the lock for most of that, so a limit shorter than the poll cycle left devices without a status
 * packet on 38% of polls and every pump flickered offline.
 */
final class StatusService
{
    private const LOCK_WAIT_SECONDS = 1.0;
    private const STALE_POLL_CYCLES = 3;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly StatusCollector $collector,
        private readonly ControllerLock $lock,
        private readonly ControllerRepository $controllers,
        private readonly Clock $clock,
    ) {}

    /**
     * @param list<int> $pumps
     * @param array<string, mixed> $controllerRow
     *
     * @return array<int, array<string, mixed>|null> pump => latest status packet (null = do not answer for this pump)
     */
    public function statuses(ControllerEndpoint $controller, array $controllerRow, array $pumps, int $freshnessMs, int $staleMs): array
    {
        $staleMs = self::staleLimitMs($staleMs, (int) ($controllerRow['poll_interval_ms'] ?? 0));
        $cached = $this->cached($controller->id, $pumps);
        $now = $this->clock->now();

        $result = [];
        $stale = [];
        foreach ($pumps as $pump) {
            $entry = $cached[$pump] ?? null;
            if ($entry !== null && self::ageMs($now, $entry['observed_at']) <= $freshnessMs) {
                $result[$pump] = $entry['packet'];
            } else {
                $stale[] = $pump;
            }
        }
        if ($stale === []) {
            return $result;
        }

        $known = $this->pumpsOnController($controller->id);
        $unknown = array_values(array_diff($stale, $known));
        foreach ($unknown as $pump) {
            // Mirrors the controller's own reply for a pump it does not have (FuelPay then shows it offline).
            $result[$pump] = ['Type' => 'PumpGetStatus', 'Error' => true, 'Code' => 52, 'Message' => 'JSONPTS_ERROR_PUMP_IS_NOT_CONFIGURED'];
        }
        $refresh = array_values(array_diff($stale, $unknown));
        if ($refresh === []) {
            return $result;
        }

        if (!$this->controllers->isCircuitOpen($controllerRow) && $this->lock->acquire($controller->id, self::LOCK_WAIT_SECONDS)) {
            try {
                [, $replies] = $this->collector->collectPumps($controller, $refresh);
                $this->controllers->recordSuccess($controller->id);
                foreach ($refresh as $index => $pump) {
                    $result[$pump] = $replies[$index] ?? null;
                }
                return $result;
            } catch (PtsException $e) {
                $this->controllers->recordFailure($controller->id, $e);
            } finally {
                $this->lock->release($controller->id);
            }
        }

        // Controller unavailable or busy: last known status only if still within the stale limit.
        foreach ($refresh as $pump) {
            $entry = $cached[$pump] ?? null;
            $result[$pump] = $entry !== null && self::ageMs($now, $entry['observed_at']) <= $staleMs ? $entry['packet'] : null;
        }

        return $result;
    }

    /**
     * @param list<int> $pumps
     *
     * @return array<int, array{packet: array<string, mixed>, observed_at: string}>
     */
    private function cached(int $controllerId, array $pumps): array
    {
        /** @var list<array{pump_number: string, packet: string, observed_at: string}> $rows */
        $rows = (new Query($this->db))->select(['pump_number', 'packet', 'observed_at'])->from('pump_status_cache')
            ->where(['controller_id' => $controllerId, 'pump_number' => $pumps])->all();

        $cached = [];
        foreach ($rows as $row) {
            /** @var mixed $packet */
            $packet = json_decode($row['packet'], true);
            if (is_array($packet) && !isset($packet['missing'])) {
                /** @var array<string, mixed> $packet */
                $cached[(int) $row['pump_number']] = ['packet' => $packet, 'observed_at' => $row['observed_at']];
            }
        }

        return $cached;
    }

    /** @return list<int> */
    private function pumpsOnController(int $controllerId): array
    {
        return array_values(array_map('intval', (new Query($this->db))->select('pump_number')->from('pumps')
            ->where(['controller_id' => $controllerId, 'on_controller' => 1])->column()));
    }

    /** The configured stale limit, raised to cover {@see STALE_POLL_CYCLES} collector polls. */
    public static function staleLimitMs(int $staleMs, int $pollIntervalMs): int
    {
        return max($staleMs, self::STALE_POLL_CYCLES * max(0, $pollIntervalMs));
    }

    private static function ageMs(DateTimeImmutable $now, string $observedAt): int
    {
        $observed = new DateTimeImmutable($observedAt . ' UTC');

        return (int) (((float) $now->format('U.u') - (float) $observed->format('U.u')) * 1000);
    }
}
