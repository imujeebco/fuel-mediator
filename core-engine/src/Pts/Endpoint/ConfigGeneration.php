<?php

declare(strict_types=1);

namespace App\Pts\Endpoint;

use Psr\SimpleCache\CacheInterface;

use function is_int;

/**
 * Per-controller generation number in the configuration cache key. Bumping it makes every cached configuration
 * reply of that controller unreachable at once (used after a price change, D26).
 */
final class ConfigGeneration
{
    public static function get(CacheInterface $cache, int $controllerId): int
    {
        $value = $cache->get(self::key($controllerId));

        return is_int($value) ? $value : 0;
    }

    public static function bump(CacheInterface $cache, int $controllerId): void
    {
        $cache->set(self::key($controllerId), self::get($cache, $controllerId) + 1, 86400);
    }

    private static function key(int $controllerId): string
    {
        return 'pts.config-generation.' . $controllerId;
    }
}
