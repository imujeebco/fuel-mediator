<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Clock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;

use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class ClockTest extends Unit
{
    /**
     * Regression guard: any constructor parameter would be autowired by the DI container (a DateTimeImmutable once
     * froze the production clock at start-up).
     */
    public function testConstructorTakesNoParametersSoDiCannotFreezeIt(): void
    {
        $constructor = (new ReflectionClass(Clock::class))->getConstructor();

        assertNull($constructor, 'Clock must not declare a constructor');
    }

    public function testSystemClockFollowsRealTimeInUtc(): void
    {
        $now = (new Clock())->now();

        assertSame('UTC', $now->getTimezone()->getName());
        assertLessThan(5, abs($now->getTimestamp() - time()));
    }

    public function testFixedClockIsConvertedToUtc(): void
    {
        $clock = Clock::fixedAt(new DateTimeImmutable('2026-10-11 06:50:00', new DateTimeZone('+03:00')));

        assertSame('2026-10-11 03:50:00.000', $clock->nowForDb());
    }
}
