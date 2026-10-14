<?php

declare(strict_types=1);

namespace App\Odoo\Relay;

use Psr\SimpleCache\CacheInterface;
use Throwable;

use function hash;
use function is_int;
use function is_string;
use function json_encode;

/**
 * Decision D29: short-lived cache of read-only Odoo answers that block the payment screen.
 *
 * FuelPay waits for `pts_api_bootstrap_proxy` (products, prices, payment methods; 1.9 s on average, up to 13 s) every
 * time the payment sheet opens, and `mobile_list_employees` keeps failing on an attachment Odoo stores on FTP. Both
 * change rarely. Only successful results are kept, for RelayPolicy::cacheSeconds(). The key covers the device's full
 * credentials (database, user, password), so an answer is only ever served to a caller Odoo already accepted with
 * the same credentials, plus the method and its arguments. A price change bumps the generation, which makes every
 * cached answer unreachable at once (as for the controller configuration, D26).
 */
final class ResponseCache
{
    private const GENERATION_KEY = 'relay.response-generation';

    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * @param array<array-key, mixed> $args execute_kw args: [db, uid, password, model, method, positional, kwargs]
     *
     * @return string|null Odoo's raw JSON-RPC answer, or null on a miss.
     */
    public function get(?string $modelMethod, array $args): ?string
    {
        if (RelayPolicy::cacheSeconds($modelMethod) === 0) {
            return null;
        }
        try {
            $value = $this->cache->get($this->key($args));
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $args
     * @param string $body Odoo's raw answer: kept as text so JSON objects stay objects (`{}` is not `[]`).
     */
    public function set(?string $modelMethod, array $args, string $body): void
    {
        $ttl = RelayPolicy::cacheSeconds($modelMethod);
        if ($ttl === 0) {
            return;
        }
        try {
            $this->cache->set($this->key($args), $body, $ttl);
        } catch (Throwable) {
            // A cache failure only costs the next caller an Odoo round trip.
        }
    }

    /** Makes every cached answer unreachable (prices changed). */
    public static function invalidate(CacheInterface $cache): void
    {
        $value = $cache->get(self::GENERATION_KEY);
        $cache->set(self::GENERATION_KEY, (is_int($value) ? $value : 0) + 1, 86400);
    }

    /** @param array<array-key, mixed> $args */
    private function key(array $args): string
    {
        return 'relay.response.' . $this->generation() . '.' . hash('sha256', (string) json_encode($args));
    }

    private function generation(): int
    {
        $value = $this->cache->get(self::GENERATION_KEY);

        return is_int($value) ? $value : 0;
    }
}
