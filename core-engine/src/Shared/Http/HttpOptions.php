<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class HttpOptions
{
    public const AUTH_NONE = 'none';
    public const AUTH_BASIC = 'basic';
    public const AUTH_DIGEST = 'digest';

    public function __construct(
        public readonly float $timeoutSeconds = 10.0,
        public readonly float $connectTimeoutSeconds = 5.0,
        public readonly string $authMode = self::AUTH_NONE,
        public readonly string $username = '',
        #[\SensitiveParameter]
        public readonly string $password = '',
        public readonly bool $verifySsl = true,
    ) {}
}
