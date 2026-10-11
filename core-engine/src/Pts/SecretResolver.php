<?php

declare(strict_types=1);

namespace App\Pts;

use function getenv;
use function is_string;

/**
 * Resolves controller secrets from the environment (decision D17): `.env` locally, server/systemd env in production.
 * Secrets are never stored in the database.
 */
final class SecretResolver
{
    public function get(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function defaultRefFor(int $odooBackendConfigId): string
    {
        return 'PTS_PASSWORD_' . $odooBackendConfigId;
    }
}
