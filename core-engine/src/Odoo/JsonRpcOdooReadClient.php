<?php

declare(strict_types=1);

namespace App\Odoo;

use App\Shared\Http\HttpOptions;
use App\Shared\Http\HttpTransportInterface;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\TransportException;
use JsonException;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function sprintf;

/**
 * Odoo external JSON-RPC (`/jsonrpc`, `common.authenticate` + `object.execute_kw`), restricted to read methods.
 */
final class JsonRpcOdooReadClient implements OdooReadClientInterface
{
    /** The only `execute_kw` methods this client will ever send. */
    public const READ_METHODS = ['search_read', 'search_count', 'read', 'fields_get'];

    private ?int $uid = null;
    private int $requestId = 0;

    public function __construct(
        private readonly HttpTransportInterface $transport,
        private readonly string $url,
        private readonly string $database,
        private readonly string $username,
        #[\SensitiveParameter]
        private readonly string $password,
        private readonly float $timeoutSeconds = 30.0,
    ) {}

    public function searchRead(
        string $model,
        array $domain,
        array $fields,
        ?int $limit = null,
        ?string $order = null,
        array $context = [],
    ): array {
        $kwargs = ['fields' => $fields];
        if ($limit !== null) {
            $kwargs['limit'] = $limit;
        }
        if ($order !== null) {
            $kwargs['order'] = $order;
        }
        if ($context !== []) {
            $kwargs['context'] = $context;
        }

        $result = $this->executeKw($model, 'search_read', [$domain], $kwargs);
        if (!is_array($result)) {
            throw new OdooException(sprintf('Unexpected search_read result for %s.', $model));
        }

        /** @var list<array<string, mixed>> */
        return array_values($result);
    }

    public function searchCount(string $model, array $domain, array $context = []): int
    {
        $result = $this->executeKw($model, 'search_count', [$domain], $context === [] ? [] : ['context' => $context]);
        if (!is_int($result)) {
            throw new OdooException(sprintf('Unexpected search_count result for %s.', $model));
        }

        return $result;
    }

    private function executeKw(string $model, string $method, array $args, array $kwargs): mixed
    {
        if (!in_array($method, self::READ_METHODS, true)) {
            throw new OdooException(sprintf('Method "%s" is not allowed: the Odoo client is read-only.', $method));
        }

        return $this->call('object', 'execute_kw', [
            $this->database,
            $this->authenticate(),
            $this->password,
            $model,
            $method,
            $args,
            (object) $kwargs,
        ]);
    }

    private function authenticate(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }
        if ($this->url === '' || $this->database === '' || $this->username === '') {
            throw new OdooException('Odoo connection is not configured (ODOO_URL, ODOO_DB, ODOO_USER, ODOO_PASSWORD).');
        }

        $uid = $this->call('common', 'authenticate', [$this->database, $this->username, $this->password, (object) []]);
        if (!is_int($uid) || $uid <= 0) {
            throw new OdooException('Odoo authentication failed.', 'AccessDenied');
        }

        return $this->uid = $uid;
    }

    private static function errorFromEnvelope(array $error): OdooException
    {
        $data = $error['data'] ?? null;
        $data = is_array($data) ? $data : [];
        $dataMessage = $data['message'] ?? null;
        $errorMessage = $error['message'] ?? null;
        $name = $data['name'] ?? null;

        $message = is_string($dataMessage) ? $dataMessage : (is_string($errorMessage) ? $errorMessage : 'Odoo error');

        return new OdooException($message, is_string($name) ? $name : '');
    }

    private function call(string $service, string $method, array $args): mixed
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'call',
            'id' => ++$this->requestId,
            'params' => ['service' => $service, 'method' => $method, 'args' => $args],
        ], JsonResponder::FLAGS);

        try {
            $response = $this->transport->post(
                rtrim($this->url, '/') . '/jsonrpc',
                $body,
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                new HttpOptions(timeoutSeconds: $this->timeoutSeconds),
            );
        } catch (TransportException $e) {
            throw new OdooException('Odoo transport error: ' . $e->getMessage(), isTransport: true);
        }

        if (!$response->isSuccessful()) {
            throw new OdooException(sprintf('Odoo HTTP %d.', $response->status), isTransport: true);
        }

        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OdooException('Odoo returned a non-JSON response.', isTransport: true);
        }
        if (!is_array($decoded)) {
            throw new OdooException('Odoo returned a malformed JSON-RPC envelope.', isTransport: true);
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            throw self::errorFromEnvelope($decoded['error']);
        }

        if (!array_key_exists('result', $decoded)) {
            throw new OdooException('Odoo JSON-RPC response has no result.', isTransport: true);
        }

        return $decoded['result'];
    }
}
