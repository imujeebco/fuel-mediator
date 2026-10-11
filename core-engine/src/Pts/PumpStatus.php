<?php

declare(strict_types=1);

namespace App\Pts;

/**
 * One parsed `PumpGetStatus` reply packet. Fields the controller omitted are null (never 0):
 * e.g. idle packets intermittently omit `LastTotalVolume`.
 */
final class PumpStatus
{
    public const IDLE = 'idle';
    public const FILLING = 'filling';
    public const END_OF_TRANSACTION = 'end_of_transaction';
    public const OFFLINE = 'offline';
    public const ERROR = 'error';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly int $pump,
        public readonly string $state,
        public readonly string $packetType,
        public readonly ?int $nozzleUp = null,
        // Last completed fill (idle / end-of-transaction packets).
        public readonly ?int $lastTransaction = null,
        public readonly ?int $lastNozzle = null,
        public readonly ?string $lastVolume = null,
        public readonly ?string $lastAmount = null,
        public readonly ?string $lastPrice = null,
        public readonly ?string $lastTotalVolume = null,
        public readonly ?string $lastTotalAmount = null,
        public readonly ?string $lastStartedAtLocal = null,
        public readonly ?string $lastEndedAtLocal = null,
        public readonly ?string $lastUser = null,
        // Fill in progress (filling packets).
        public readonly ?int $currentTransaction = null,
        public readonly ?string $currentVolume = null,
        public readonly ?string $currentAmount = null,
        public readonly ?string $errorMessage = null,
    ) {}

    /** Whether the packet describes a completed fill that can be journaled. */
    public function hasCompletedFill(): bool
    {
        return ($this->state === self::IDLE || $this->state === self::END_OF_TRANSACTION)
            && $this->lastTransaction !== null;
    }
}
