<?php

declare(strict_types=1);

namespace App\Collector;

use App\Pts\ControllerLock;
use App\Pts\ControllerRepository;
use App\Pts\PtsException;
use App\Shared\Clock;
use App\Shared\Worker\LeaseManager;
use Psr\Log\LoggerInterface;

/**
 * One collector pass over the enabled controllers (optionally limited to some stations).
 *
 * Each controller is handled independently: an error opens only that controller's circuit breaker; other stations
 * continue (station isolation, test scenario 16). A controller is skipped while its circuit is open, while another
 * process holds its station lease, or until its poll interval has elapsed.
 */
final class CollectorRunner
{
    public const WORKER = 'collector';

    /** @var array<int, float> controller id => monotonic seconds of last poll */
    private array $lastPolledAt = [];

    public function __construct(
        private readonly ControllerRepository $controllers,
        private readonly StatusCollector $collector,
        private readonly ConfigSync $configSync,
        private readonly ControllerLock $lock,
        private readonly LeaseManager $leases,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<int> $stationIds
     *
     * @return array<int, array<string, mixed>> Per-controller outcome of this pass (only controllers that were due).
     */
    public function runOnce(array $stationIds = [], bool $ignoreInterval = false): array
    {
        $outcomes = [];
        foreach ($this->controllers->enabledRows($stationIds) as $row) {
            $controllerId = (int) $row['id'];
            $stationId = (int) $row['station_id'];
            $intervalSeconds = max(1, (int) $row['poll_interval_ms']) / 1000;

            if (!$ignoreInterval && isset($this->lastPolledAt[$controllerId])
                && hrtime(true) / 1e9 - $this->lastPolledAt[$controllerId] < $intervalSeconds) {
                continue;
            }
            if ($this->controllers->isCircuitOpen($row)) {
                $outcomes[$controllerId] = ['status' => 'circuit_open', 'until' => $row['circuit_open_until']];
                continue;
            }
            if (!$this->leases->acquire(self::WORKER, $stationId, (int) ceil($intervalSeconds * 3) + 30)) {
                $outcomes[$controllerId] = ['status' => 'leased_elsewhere'];
                continue;
            }

            $this->lastPolledAt[$controllerId] = hrtime(true) / 1e9;
            try {
                $endpoint = $this->controllers->endpoint($row);
                $result = $this->lock->run($controllerId, 5.0, function () use ($endpoint, $row) {
                    if ($row['config_synced_at'] === null) {
                        $this->configSync->sync($endpoint);
                    }
                    return $this->collector->collect($endpoint);
                });
                $this->controllers->recordSuccess($controllerId);
                $outcomes[$controllerId] = ['status' => 'ok'] + $result->toArray();
                if ($result->fills > 0 || $result->missedFills > 0 || $result->meterRegressions > 0) {
                    $this->logger->info('Collector pass', ['controller_id' => $controllerId] + $result->toArray());
                }
            } catch (PtsException $e) {
                $this->controllers->recordFailure($controllerId, $e);
                $outcomes[$controllerId] = ['status' => 'error', 'kind' => $e->kind, 'message' => $e->getMessage()];
                $this->logger->warning('Collector error', [
                    'controller_id' => $controllerId,
                    'kind' => $e->kind,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $outcomes;
    }

    /** @param list<int> $stationIds */
    public function releaseLeases(array $stationIds = []): void
    {
        foreach ($this->controllers->enabledRows($stationIds) as $row) {
            $this->leases->release(self::WORKER, (int) $row['station_id']);
        }
    }

    public function clock(): Clock
    {
        return $this->clock;
    }
}
