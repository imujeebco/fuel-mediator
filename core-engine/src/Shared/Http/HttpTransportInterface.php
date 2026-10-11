<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Minimal outbound HTTP abstraction (PTS controllers, Odoo). Implementations must never retry on their own:
 * retry policy is a business decision taken by the caller (physical commands are never retried).
 */
interface HttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException On connection failure or timeout (no HTTP response received).
     */
    public function post(string $url, string $body, array $headers = [], ?HttpOptions $options = null): HttpResult;
}
