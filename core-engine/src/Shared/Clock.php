<?php

declare(strict_types=1);

namespace App\Shared;

use DateTimeImmutable;
use DateTimeZone;

/**
 * UTC clock. Injected everywhere time matters; tests use {@see Clock::fixedAt()}.
 *
 * The constructor deliberately takes NO parameters: the DI container autowires class-typed constructor parameters
 * even when they are nullable with a default, which once injected `new DateTimeImmutable()` and froze the
 * production clock at process start-up. {@see \App\Tests\Unit\Shared\ClockTest} guards against regressions.
 */
final class Clock
{
    private ?DateTimeImmutable $fixed = null;

    public static function fixedAt(DateTimeImmutable $instant): self
    {
        $clock = new self();
        $clock->fixed = $instant;

        return $clock;
    }

    public function now(): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        return $this->fixed?->setTimezone($utc) ?? new DateTimeImmutable('now', $utc);
    }

    /** MySQL DATETIME(3) literal in UTC. */
    public function nowForDb(): string
    {
        return $this->now()->format('Y-m-d H:i:s.v');
    }
}
