<?php

declare(strict_types=1);

namespace App\Pts;

use App\Shared\Crypto\SecretBox;

use function getenv;
use function is_string;

/**
 * Controller passwords.
 *
 * Order (decision D23, revising D17): an environment variable (`secret_ref`, default `PTS_PASSWORD_<odoo backend id>`)
 * overrides; otherwise the password read from Odoo by the station import and stored encrypted (`secret_encrypted`).
 */
final class SecretResolver
{
    public function __construct(
        private readonly SecretBox $box = new SecretBox(''),
    ) {}

    public function get(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Password for a `controllers` row, or null when none is available.
     *
     * @param array<string, mixed> $row
     */
    public function forController(array $row): ?string
    {
        $fromEnv = $this->get(self::refFor($row));
        if ($fromEnv !== null) {
            return $fromEnv;
        }
        $stored = $row['secret_encrypted'] ?? null;

        return is_string($stored) && $stored !== '' ? $this->box->decrypt($stored) : null;
    }

    /**
     * Where a controller's password comes from: env | odoo | missing.
     *
     * @param array<string, mixed> $row
     */
    public function sourceFor(array $row): string
    {
        if ($this->get(self::refFor($row)) !== null) {
            return 'env';
        }

        return $this->forController($row) !== null ? 'odoo' : 'missing';
    }

    /** @param array<string, mixed> $row */
    public static function refFor(array $row): string
    {
        $ref = (string) ($row['secret_ref'] ?? '');

        return $ref !== '' ? $ref : self::defaultRefFor((int) ($row['odoo_backend_config_id'] ?? 0));
    }

    public static function defaultRefFor(int $odooBackendConfigId): string
    {
        return 'PTS_PASSWORD_' . $odooBackendConfigId;
    }
}
