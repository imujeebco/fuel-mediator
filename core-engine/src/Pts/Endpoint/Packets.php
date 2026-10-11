<?php

declare(strict_types=1);

namespace App\Pts\Endpoint;

use function is_string;

/**
 * Builders for reply packets the mediator generates itself.
 *
 * FuelPay shows a truthy `Error` value as the user message (`"Error": true` would display "true"), so mediator
 * errors carry readable text in `Error` and also in `Message` (docs/discovery/fuelpay-pts-contract.md §4).
 */
final class Packets
{
    /** @return array<string, mixed> */
    public static function error(int $id, string $type, string $message, ?array $data = null): array
    {
        return ['Id' => $id, 'Type' => $type, 'Error' => $message, 'Message' => $message]
            + ($data === null ? [] : ['Data' => $data]);
    }

    /**
     * Returns a controller packet with the caller's Id; an `"Error": true` controller packet gets readable text.
     *
     * @param array<string, mixed> $packet
     *
     * @return array<string, mixed>
     */
    public static function withId(array $packet, int $id): array
    {
        $packet['Id'] = $id;
        if (($packet['Error'] ?? null) === true) {
            $message = $packet['Message'] ?? null;
            $packet['Error'] = is_string($message) && $message !== '' ? $message : 'Controller error';
        }

        return $packet;
    }

    /** @param array<string, mixed>|null $packet */
    public static function isError(?array $packet): bool
    {
        if ($packet === null) {
            return true;
        }
        $error = $packet['Error'] ?? null;

        return $error !== null && $error !== false && $error !== 'false' && $error !== '' && $error !== 0;
    }
}
