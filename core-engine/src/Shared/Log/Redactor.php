<?php

declare(strict_types=1);

namespace App\Shared\Log;

use function is_array;
use function is_string;
use function in_array;
use function preg_replace_callback;
use function str_ends_with;
use function str_repeat;
use function strlen;
use function substr;
use function strtolower;

/**
 * Removes secrets and sensitive payment data from values before they are logged or journaled.
 *
 * Rules (docs/security-and-failure-handling.md §1):
 *  - values of sensitive keys (password, token, pin, authorization, ...) are replaced at any depth;
 *  - the password position of Odoo `execute_kw` args (index 2) is replaced;
 *  - 13–19 digit sequences that look like a full card number are masked, except the last 4 digits.
 */
final class Redactor
{
    public const MASK = '***';

    /** Exact (case-insensitive) key names. */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pass', 'pwd', 'pin', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'cookie', 'set-cookie', 'x-api-key',
    ];

    /** Key suffixes, e.g. `odoo_pass`, `access_token`, `mobile_pin`, `client_secret`. */
    private const SENSITIVE_SUFFIXES = [
        '_password', '_passwd', '_pass', '_pwd', '_pin', '_secret', '_token', '_api_key',
    ];

    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->redactArray($value);
        }

        if (is_string($value)) {
            return $this->maskCardNumbers($value);
        }

        return $value;
    }

    /**
     * Redacts an Odoo JSON-RPC `params` structure, including positional `execute_kw` / `authenticate` passwords.
     */
    public function redactOdooParams(array $params): array
    {
        $service = $params['service'] ?? null;
        $method = $params['method'] ?? null;
        $args = $params['args'] ?? null;

        if (is_array($args) && $service === 'object' && $method === 'execute_kw' && isset($args[2])) {
            $args[2] = self::MASK;
            $params['args'] = $args;
        }
        if (is_array($args) && $service === 'common' && $method === 'authenticate' && isset($args[2])) {
            $args[2] = self::MASK;
            $params['args'] = $args;
        }

        /** @var array */
        return $this->redactArray($params);
    }

    private function redactArray(array $data): array
    {
        $result = [];
        /** @var mixed $value */
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::MASK;
                continue;
            }
            /** @psalm-suppress MixedAssignment */
            $result[$key] = $this->redact($value);
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        if (in_array($lower, self::SENSITIVE_KEYS, true)) {
            return true;
        }
        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function maskCardNumbers(string $value): string
    {
        // Contiguous 13–19 digits, not part of a longer digit run (e.g. RRNs are 12 digits and stay intact).
        return (string) preg_replace_callback(
            '/(?<!\d)\d{13,19}(?!\d)/',
            static fn(array $m): string => str_repeat('*', strlen($m[0]) - 4) . substr($m[0], -4),
            $value,
        );
    }
}
