<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class HttpResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly int $latencyMs,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
