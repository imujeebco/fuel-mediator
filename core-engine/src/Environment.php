<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

use function array_key_exists;
use function in_array;
use function sprintf;

final class Environment
{
    public const DEV = 'dev';
    public const TEST = 'test';
    public const PROD = 'prod';

    public const ENVIRONMENTS = [
        self::DEV,
        self::TEST,
        self::PROD,
    ];

    private static array $values = [];

    public static function prepare(): void
    {
        self::setEnvironment();
        self::setBoolean('APP_C3', false);
        self::setBoolean('APP_DEBUG', false);
        self::setNonEmptyStringOrNull('APP_HOST_PATH', null);
        self::setString('APP_URL', 'http://localhost:8080');

        self::setString('DB_HOST', '127.0.0.1');
        self::setString('DB_PORT', '3306');
        self::setString('DB_NAME', 'fuel_mediator');
        self::setString('DB_USER', 'root');
        self::setString('DB_PASSWORD', '');

        self::setString('ODOO_URL', '');
        self::setString('ODOO_DB', '');
        self::setString('ODOO_USER', '');
        self::setString('ODOO_PASSWORD', '');

        self::setInteger('RETENTION_DAYS', 90);

        // Public base URL devices use to reach this mediator (e.g. https://mediator.example.com). Empty = the Odoo
        // relay does not redirect devices' PTS traffic (decision D1 disabled).
        self::setString('MEDIATOR_PUBLIC_URL', '');

        // Decision D21: report fills already paid by another sale as paid to devices. "0" switches it off.
        self::setString('SETTLED_FILL_FILTER', '1');

        // Decision D28: a PTS fill paid by a manual sale on the same pump is reported as paid at once. "0" switches it off.
        self::setString('SETTLED_FILL_LIVE', '1');

        // Decision D30: controllers push through their remote-server HTTP upload to /stations/{id}/upload/{kind}.
        // "0" answers 404 (nothing recorded). Raw captures are kept PTS_UPLOAD_KEEP_HOURS.
        self::setString('PTS_UPLOAD_CAPTURE', '1');
        self::setInteger('PTS_UPLOAD_KEEP_HOURS', 72);

        // Decision D23: key (32 bytes, base64) for controller passwords read from Odoo and stored encrypted.
        // Generate with `yii secret:generate-key`. Empty = passwords are not stored (env PTS_PASSWORD_<id> only).
        self::setString('MEDIATOR_SECRET_KEY', '');

        // Dashboard login (one admin). Hash from the /login/password-hash page or password_hash(). Empty = the
        // dashboard is reachable only from the server itself.
        self::setString('ADMIN_USER', 'admin');
        self::setString('ADMIN_PASSWORD_HASH', '');
    }

    /**
     * Returns a value read during {@see prepare()}, e.g. `Environment::get('DB_HOST')`.
     */
    public static function get(string $key): string|int|bool|null
    {
        if (!array_key_exists($key, self::$values)) {
            throw new RuntimeException(sprintf('Environment variable "%s" is not prepared.', $key));
        }

        /** @var string|int|bool|null */
        return self::$values[$key];
    }

    /**
     * @return non-empty-string
     */
    public static function appEnv(): string
    {
        /** @var non-empty-string */
        return self::$values['APP_ENV'];
    }

    public static function isDev(): bool
    {
        return self::appEnv() === self::DEV;
    }

    public static function isTest(): bool
    {
        return self::appEnv() === self::TEST;
    }

    public static function isProd(): bool
    {
        return self::appEnv() === self::PROD;
    }

    /**
     * @return non-empty-string|null
     */
    public static function appHostPath(): ?string
    {
        /** @var non-empty-string|null */
        return self::$values['APP_HOST_PATH'];
    }

    public static function appC3(): bool
    {
        /** @var bool */
        return self::$values['APP_C3'];
    }

    public static function appDebug(): bool
    {
        /** @var bool */
        return self::$values['APP_DEBUG'];
    }

    private static function setEnvironment(): void
    {
        $environment = self::getRawValue('APP_ENV') ?: self::PROD;

        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new RuntimeException(
                sprintf(
                    'APP_ENV="%s" is invalid. Valid values are "%s".',
                    $environment,
                    implode('", "', self::ENVIRONMENTS),
                ),
            );
        }

        self::$values['APP_ENV'] = $environment;
    }

    private static function setBoolean(string $key, bool $default): void
    {
        $value = self::getRawValue($key);
        self::$values[$key] = $value === null
            ? $default
            : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    private static function setInteger(string $key, int $default): void
    {
        $value = self::getRawValue($key);
        self::$values[$key] = $value === null ? $default : (int) $value;
    }

    private static function setString(string $key, string $default): void
    {
        $value = self::getRawValue($key);
        self::$values[$key] = $value ?? $default;
    }

    private static function setNonEmptyStringOrNull(string $key, ?string $default): void
    {
        $value = self::getRawValue($key);
        self::$values[$key] = $value === null || $value === '' ? $default : $value;
    }

    private static function getRawValue(string $key): ?string
    {
        $value = getenv($key, true);
        if ($value !== false) {
            return $value;
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return isset($_ENV[$key]) ? (string) $_ENV[$key] : null;
    }
}
