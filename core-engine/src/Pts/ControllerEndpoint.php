<?php

declare(strict_types=1);

namespace App\Pts;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

use function intdiv;
use function sprintf;

/**
 * Everything needed to talk to one PTS controller. Built by {@see ControllerRepository}.
 */
final class ControllerEndpoint
{
    public function __construct(
        public readonly int $id,
        public readonly int $stationId,
        public readonly string $name,
        public readonly string $url,
        public readonly string $authMode,
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
        public readonly bool $verifySsl,
        public readonly int $timeoutMs,
        public readonly int $pollIntervalMs,
        public readonly int $utcOffsetMinutes,
    ) {}

    /**
     * Controller timestamps are local wall-clock time without a zone (e.g. "2026-10-11T06:41:43").
     * Returns a MySQL DATETIME(3) literal in UTC, or null when the value cannot be parsed.
     */
    public function controllerTimeToUtc(?string $local): ?string
    {
        if ($local === null || $local === '') {
            return null;
        }
        $minutes = $this->utcOffsetMinutes;
        /** @var non-empty-string $offset */
        $offset = sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);

        try {
            return (new DateTimeImmutable($local, new DateTimeZone($offset)))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.v');
        } catch (Exception) {
            return null;
        }
    }
}
