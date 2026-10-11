<?php

declare(strict_types=1);

namespace App\Pts;

use RuntimeException;

final class PtsException extends RuntimeException
{
    /** No response within the timeout. For a command: outcome unknown. */
    public const TIMEOUT = 'timeout';
    /** Connection-level failure (DNS, refused, TLS). */
    public const TRANSPORT = 'transport';
    /** 401/403: credentials rejected, or the Cloudflare edge blocked the request. */
    public const AUTH = 'auth';
    /** Any other non-2xx status. */
    public const HTTP = 'http';
    /** Response body is not a jsonPTS object. */
    public const MALFORMED = 'malformed';
    /** The caller's packet policy forbids a packet type; nothing was sent. */
    public const POLICY = 'policy';
    /** Controller not usable (no URL, missing secret, disabled). */
    public const CONFIGURATION = 'configuration';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
