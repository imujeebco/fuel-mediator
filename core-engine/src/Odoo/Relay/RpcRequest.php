<?php

declare(strict_types=1);

namespace App\Odoo\Relay;

use JsonException;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;

/**
 * A parsed Odoo external JSON-RPC request (`{"jsonrpc","id","method":"call","params":{service, method, args}}`).
 */
final class RpcRequest
{
    /**
     * @param array<array-key, mixed> $params
     * @param array<array-key, mixed> $args
     */
    private function __construct(
        public readonly int|string|null $id,
        public readonly string $service,
        public readonly string $method,
        public readonly array $params,
        public readonly array $args,
        public readonly ?string $model,
        public readonly ?string $modelMethod,
    ) {}

    public static function parse(string $raw): ?self
    {
        try {
            /** @var mixed $request */
            $request = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($request)) {
            return null;
        }
        $params = $request['params'] ?? null;
        if (!is_array($params)) {
            return null;
        }
        $id = $request['id'] ?? null;
        $service = $params['service'] ?? null;
        $method = $params['method'] ?? null;
        $args = $params['args'] ?? null;
        $args = is_array($args) ? $args : [];
        $service = is_string($service) ? $service : '';

        $model = $service === 'object' ? ($args[3] ?? null) : null;
        $modelMethod = $service === 'object' ? ($args[4] ?? null) : null;

        return new self(
            is_int($id) || is_string($id) ? $id : null,
            $service,
            is_string($method) ? $method : '',
            $params,
            $args,
            is_string($model) ? $model : null,
            is_string($modelMethod) ? $modelMethod : null,
        );
    }

    public function label(): string
    {
        return $this->model !== null ? "{$this->model}.{$this->modelMethod}" : "{$this->service}.{$this->method}";
    }

    /** Method name stored in the journal (the model method for execute_kw). */
    public function journalMethod(): string
    {
        return $this->modelMethod ?? $this->method;
    }
}
