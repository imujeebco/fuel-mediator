<?php

declare(strict_types=1);

namespace App\Shared\Worker;

use App\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gethostname;
use function getmypid;

/**
 * Per-(worker, station) leases: only one process works a station at a time, and a crashed process's lease expires
 * so another can take over (restart recovery). One slow station only ever holds its own lease (isolation).
 */
final class LeaseManager
{
    private readonly string $holder;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
        ?string $holder = null,
    ) {
        $this->holder = $holder ?? ((gethostname() ?: 'host') . ':' . (string) getmypid());
    }

    public function holder(): string
    {
        return $this->holder;
    }

    public function acquire(string $worker, int $stationId, int $ttlSeconds): bool
    {
        $now = $this->clock->now();
        $nowDb = $now->format('Y-m-d H:i:s.v');
        $until = $now->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.v');

        // Take the lease if it is free, expired, or already ours.
        $this->db->createCommand(
            'INSERT INTO worker_leases (worker, station_id, holder, lease_until, heartbeat_at)'
            . ' VALUES (:worker, :station, :holder, :until, :now)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' heartbeat_at = IF(lease_until < :now2 OR holder = :holder2, :now3, heartbeat_at),'
            . ' holder = IF(lease_until < :now4 OR holder = :holder3, :holder4, holder),'
            . ' lease_until = IF(holder = :holder5, :until2, lease_until)',
            [
                ':worker' => $worker, ':station' => $stationId, ':holder' => $this->holder, ':until' => $until,
                ':now' => $nowDb, ':now2' => $nowDb, ':holder2' => $this->holder, ':now3' => $nowDb, ':now4' => $nowDb,
                ':holder3' => $this->holder, ':holder4' => $this->holder, ':holder5' => $this->holder, ':until2' => $until,
            ],
        )->execute();

        return (new Query($this->db))->select('holder')->from('worker_leases')
            ->where(['worker' => $worker, 'station_id' => $stationId])->scalar() === $this->holder;
    }

    public function release(string $worker, int $stationId): void
    {
        $this->db->createCommand()->delete('worker_leases', [
            'worker' => $worker,
            'station_id' => $stationId,
            'holder' => $this->holder,
        ])->execute();
    }
}
