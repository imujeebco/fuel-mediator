<?php

declare(strict_types=1);

namespace App\Shared;

use DateTimeImmutable;
use DateTimeZone;

/**
 * UTC clock. Injected everywhere time matters; tests pass a fixed instant.
 */
final class Clock
{
    public function __construct(
        private readonly ?DateTimeImmutable $fixed = null,
    ) {}

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
