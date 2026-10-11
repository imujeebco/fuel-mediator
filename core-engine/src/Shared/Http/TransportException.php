<?php

declare(strict_types=1);

namespace App\Shared\Http;

use RuntimeException;

/**
 * No HTTP response was received. For a physical command this means OUTCOME UNKNOWN, never "failed".
 */
final class TransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $isTimeout,
        public readonly int $latencyMs = 0,
    ) {
        parent::__construct($message);
    }
}
