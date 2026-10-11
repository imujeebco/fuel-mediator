<?php

declare(strict_types=1);

namespace App\Shared;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Typed access to decoded JSON (untrusted, loosely typed input from devices, controllers and Odoo).
 */
final class Arr
{
    /** @return array<array-key, mixed> The nested array at $key, or [] when absent or not an array. */
    public static function arr(mixed $data, string|int $key): array
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_array($value) ? $value : [];
    }

    /** @return array<array-key, mixed>|null */
    public static function arrOrNull(mixed $data, string|int $key): ?array
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_array($value) ? $value : null;
    }

    public static function str(mixed $data, string|int $key): ?string
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function int(mixed $data, string|int $key): ?int
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_int($value) ? $value : null;
    }

    public static function bool(mixed $data, string|int $key): ?bool
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_bool($value) ? $value : null;
    }

    public static function raw(mixed $data, string|int $key): mixed
    {
        return is_array($data) ? ($data[$key] ?? null) : null;
    }
}
