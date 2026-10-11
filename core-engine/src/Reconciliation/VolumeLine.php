<?php

declare(strict_types=1);

namespace App\Reconciliation;

final class VolumeLine
{
    public function __construct(
        public readonly int $pump,
        public readonly int $fills,
        public readonly int $missedFills,
        public readonly string $dispensed,
        public readonly string $missedVolume,
        public readonly int $sales,
        public readonly string $sold,
        public readonly string $soldAmount,
        public readonly string $gap,
        public readonly string $coverage,
        public readonly bool $flagged,
    ) {}
}
