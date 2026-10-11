<?php

declare(strict_types=1);

namespace App\Pts;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Cross-process mutex per controller (MySQL named lock). PTS-2 controllers cannot handle parallel Digest sessions,
 * so every request to one controller — from web or workers — is serialized through this lock. The lock is released
 * automatically if the holding connection dies.
 */
final class ControllerLock
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    public function acquire(int $controllerId, float $timeoutSeconds): bool
    {
        return (int) $this->db->createCommand('SELECT GET_LOCK(CONCAT(DATABASE(), :name), :timeout)', [
            ':name' => self::name($controllerId),
            ':timeout' => $timeoutSeconds,
        ])->queryScalar() === 1;
    }

    public function release(int $controllerId): void
    {
        $this->db->createCommand('SELECT RELEASE_LOCK(CONCAT(DATABASE(), :name))', [':name' => self::name($controllerId)])->queryScalar();
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @throws PtsException When the lock cannot be acquired in time.
     *
     * @return T
     */
    public function run(int $controllerId, float $timeoutSeconds, callable $work): mixed
    {
        if (!$this->acquire($controllerId, $timeoutSeconds)) {
            throw new PtsException(PtsException::TIMEOUT, "Controller #$controllerId is busy (lock timeout).");
        }
        try {
            return $work();
        } finally {
            $this->release($controllerId);
        }
    }

    /** Named locks are server-wide in MySQL; the name is prefixed with the schema (see SQL) to isolate deployments. */
    private static function name(int $controllerId): string
    {
        return ':fuel-mediator:pts:' . $controllerId;
    }
}
