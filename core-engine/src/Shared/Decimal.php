<?php

declare(strict_types=1);

namespace App\Shared;

use InvalidArgumentException;

use function bcadd;
use function bccomp;
use function bcmul;
use function bcsub;
use function is_numeric;

/**
 * Exact decimal arithmetic for litres and money (bcmath). Values are numeric strings; floats are never used.
 */
final class Decimal
{
    /**
     * @return numeric-string
     */
    public static function of(mixed $value, int $scale = 3): string
    {
        if ($value === null || $value === '') {
            return bcadd('0', '0', $scale);
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Not a decimal value.');
        }

        return bcadd((string) $value, '0', $scale);
    }

    /** @return numeric-string */
    public static function add(string $a, string $b, int $scale = 3): string
    {
        return bcadd(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    /** @return numeric-string */
    public static function sub(string $a, string $b, int $scale = 3): string
    {
        return bcsub(self::of($a, $scale), self::of($b, $scale), $scale);
    }

    /** @return numeric-string */
    public static function mul(string $a, string $b, int $scale = 3): string
    {
        return bcmul(self::of($a, $scale), self::of($b, 6), $scale);
    }

    /** @return numeric-string */
    public static function abs(string $a, int $scale = 3): string
    {
        $value = self::of($a, $scale);

        return self::compare($value, '0', $scale) < 0 ? self::sub('0', $value, $scale) : $value;
    }

    public static function compare(string $a, string $b, int $scale = 3): int
    {
        return bccomp(self::of($a, $scale), self::of($b, $scale), $scale);
    }
}
