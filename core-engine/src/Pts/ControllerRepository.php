<?php

declare(strict_types=1);

namespace App\Pts;

use App\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function min;
use function sprintf;
use function substr;

/**
 * Loads controller endpoints and maintains per-controller health / circuit breaker state.
 */
final class ControllerRepository
{
    /** Circuit breaker: wait base × 2^(failures−1) after a failure, capped. */
    private const BACKOFF_BASE_SECONDS = 10;
    private const BACKOFF_MAX_SECONDS = 300;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SecretResolver $secrets,
        private readonly Clock $clock,
    ) {}

    /**
     * Enabled controllers of active stations, optionally limited to some stations.
     *
     * @param list<int> $stationIds
     *
     * @return list<array<string, mixed>>
     */
    public function enabledRows(array $stationIds = []): array
    {
        $query = (new Query($this->db))
            ->select(['c.*', 'utc_offset_minutes' => 's.utc_offset_minutes'])
            ->from(['c' => 'controllers'])
            ->innerJoin(['s' => 'stations'], 's.id = c.station_id')
            ->where(['c.enabled' => 1, 's.active' => 1])
            ->orderBy(['c.id' => SORT_ASC]);
        if ($stationIds !== []) {
            $query->andWhere(['c.station_id' => $stationIds]);
        }

        /** @var list<array<string, mixed>> */
        return $query->all();
    }

    /**
     * @param array<string, mixed> $row A controllers row joined with `utc_offset_minutes`.
     *
     * @throws PtsException When the controller has no URL or its secret is missing.
     */
    public function endpoint(array $row): ControllerEndpoint
    {
        $id = (int) $row['id'];
        $url = (string) ($row['url'] ?? '');
        if ($url === '') {
            throw new PtsException(PtsException::CONFIGURATION, sprintf('Controller #%d has no URL.', $id));
        }

        $authMode = (string) $row['auth_mode'];
        $password = '';
        if ($authMode !== 'none') {
            $ref = (string) ($row['secret_ref'] ?? '');
            if ($ref === '') {
                $ref = SecretResolver::defaultRefFor((int) $row['odoo_backend_config_id']);
            }
            $password = $this->secrets->get($ref)
                ?? throw new PtsException(PtsException::CONFIGURATION, sprintf('Secret "%s" for controller #%d is not set.', $ref, $id));
        }

        return new ControllerEndpoint(
            id: $id,
            stationId: (int) $row['station_id'],
            name: (string) $row['name'],
            url: $url,
            authMode: $authMode,
            username: (string) ($row['username'] ?? ''),
            password: $password,
            verifySsl: (bool) $row['verify_ssl'],
            timeoutMs: (int) $row['command_timeout_ms'],
            pollIntervalMs: (int) $row['poll_interval_ms'],
            utcOffsetMinutes: (int) ($row['utc_offset_minutes'] ?? 180),
        );
    }

    /** @param array<string, mixed> $row */
    public function isCircuitOpen(array $row): bool
    {
        $until = $row['circuit_open_until'] ?? null;

        return $until !== null && (string) $until > $this->clock->nowForDb();
    }

    public function recordSuccess(int $controllerId): void
    {
        $this->db->createCommand()->update('controllers', [
            'last_ok_at' => $this->clock->nowForDb(),
            'consecutive_failures' => 0,
            'circuit_open_until' => null,
        ], ['id' => $controllerId])->execute();
    }

    public function recordFailure(int $controllerId, PtsException $error): void
    {
        $failures = 1 + (int) (new Query($this->db))->select('consecutive_failures')->from('controllers')
            ->where(['id' => $controllerId])->scalar();
        $backoff = min(self::BACKOFF_MAX_SECONDS, self::BACKOFF_BASE_SECONDS * 2 ** min($failures - 1, 10));
        $now = $this->clock->now();

        $this->db->createCommand()->update('controllers', [
            'last_error_at' => $now->format('Y-m-d H:i:s.v'),
            'last_error' => substr('[' . $error->kind . '] ' . $error->getMessage(), 0, 512),
            'consecutive_failures' => $failures,
            'circuit_open_until' => $now->modify("+{$backoff} seconds")->format('Y-m-d H:i:s.v'),
        ], ['id' => $controllerId])->execute();
    }
}
