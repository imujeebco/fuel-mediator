<?php

declare(strict_types=1);

namespace App\Odoo\Relay;

use App\Shared\Http\JsonResponder;

use function json_encode;

final class RelayResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {}

    /**
     * Odoo-shaped JSON-RPC error (HTTP 200, like Odoo). FuelPay reads `error.data.name` / `error.data.message`.
     */
    public static function rpcError(int|string|null $id, string $name, string $message): self
    {
        return new self(200, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => 200,
                'message' => 'Odoo Server Error',
                'data' => ['name' => $name, 'message' => $message, 'arguments' => [$message]],
            ],
        ], JsonResponder::FLAGS));
    }
}
