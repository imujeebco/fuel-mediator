<?php

declare(strict_types=1);

namespace App\Odoo\Relay;

use App\Shared\Arr;
use App\Shared\Clock;
use App\Shared\Http\HttpOptions;
use App\Shared\Http\HttpTransportInterface;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\TransportException;
use App\Shared\Log\Redactor;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function hrtime;
use function is_array;
use function json_decode;
use function json_encode;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Odoo-compatible JSON-RPC relay for FuelPay (`POST /odoo/jsonrpc`).
 *
 * The device's request body is forwarded verbatim (the device's own credentials, so Odoo stays the authority for
 * permissions) and Odoo's answer is returned verbatim, except for the single REWRITE method. The relay never invents
 * a business result: if Odoo did not answer, the device receives a JSON-RPC error that FuelPay treats as retryable
 * ("timeout", "503"), and FuelPay keeps the sale in its own offline queue.
 */
final class OdooRelay
{
    public function __construct(
        private readonly HttpTransportInterface $transport,
        private readonly ConnectionInterface $db,
        private readonly Redactor $redactor,
        private readonly SaleObserver $sales,
        private readonly PumpsRewriter $rewriter,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $odooUrl,
    ) {}

    public function handle(string $rawBody, string $clientIp, string $correlationId): RelayResponse
    {
        $rpc = RpcRequest::parse($rawBody);
        if ($rpc === null) {
            return RelayResponse::rpcError(null, 'odoo.exceptions.UserError', 'Malformed JSON-RPC request');
        }
        $rpcId = $rpc->id;
        $service = $rpc->service;
        $model = $rpc->model;
        $modelMethod = $rpc->modelMethod;
        $params = $rpc->params;
        $args = $rpc->args;
        $label = $rpc->label();
        $method = $rpc->journalMethod();

        $mode = RelayPolicy::mode($service, $rpc->method, $model, $modelMethod);
        if ($mode === null) {
            $this->journalCall(null, $correlationId, $service, $model, $method, $rpcId, 'denied', "Not allowed: $label", 0, null, null);
            return RelayResponse::rpcError($rpcId, 'odoo.exceptions.AccessError', "Method not allowed through mediator: $label");
        }

        if ($this->odooUrl === '') {
            return RelayResponse::rpcError($rpcId, 'mediator.Unavailable', 'Odoo relay not configured (503); try again later');
        }

        $started = hrtime(true);
        try {
            $upstream = $this->transport->post(
                rtrim($this->odooUrl, '/') . '/jsonrpc',
                $rawBody,
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                new HttpOptions(timeoutSeconds: RelayPolicy::timeoutSeconds($modelMethod)),
            );
        } catch (TransportException $e) {
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->journalCall($mode, $correlationId, $service, $model, $method, $rpcId, $e->isTimeout ? 'timeout' : 'transport_error', $e->getMessage(), $latency, $params, null);
            $this->sales->observe($modelMethod, $args, null, null);
            return RelayResponse::rpcError(
                $rpcId,
                'mediator.UpstreamUnavailable',
                $e->isTimeout ? 'Odoo timeout: no answer in time, try again' : 'Odoo connection unavailable (503), try again',
            );
        }
        $latency = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($upstream->status === 429) {
            $this->journalCall($mode, $correlationId, $service, $model, $method, $rpcId, 'odoo_error', 'HTTP 429', $latency, $params, null);
            return new RelayResponse(429, $upstream->body, ['Retry-After' => '5']);
        }

        $body = trim($upstream->body);
        if (!$upstream->isSuccessful() || str_starts_with($body, '<')) {
            $this->journalCall($mode, $correlationId, $service, $model, $method, $rpcId, 'odoo_error', sprintf('HTTP %d', $upstream->status), $latency, $params, null);
            return RelayResponse::rpcError($rpcId, 'mediator.UpstreamUnavailable', sprintf('Odoo unavailable (%d), try again', $upstream->status >= 500 ? $upstream->status : 503));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->journalCall($mode, $correlationId, $service, $model, $method, $rpcId, 'odoo_error', 'Non-JSON answer', $latency, $params, null);
            return RelayResponse::rpcError($rpcId, 'mediator.UpstreamUnavailable', 'Malformed Odoo response (503), try again');
        }
        /** @var array<string, mixed> $response */
        $response = $decoded;

        $outcome = isset($response['error']) ? 'odoo_error' : 'ok';
        $relayCallId = $this->journalCall(
            $mode,
            $correlationId,
            $service,
            $model,
            $method,
            $rpcId,
            $outcome,
            $outcome === 'ok' ? null : self::errorMessage($response),
            $latency,
            $params,
            $mode === RelayPolicy::JOURNAL ? $response : null,
        );
        $this->sales->observe($modelMethod, $args, $response, $relayCallId);

        if ($mode === RelayPolicy::REWRITE && $outcome === 'ok') {
            $rewritten = $this->rewriter->rewrite($response);
            if ($rewritten !== null) {
                return new RelayResponse(200, json_encode($rewritten, JsonResponder::FLAGS));
            }
        }

        return new RelayResponse(200, $upstream->body);
    }

    /**
     * Writes relay_calls. Bodies are stored (redacted) for JOURNAL methods only. Journal failures never block a
     * pass-through call; they are logged.
     */
    private function journalCall(
        ?string $mode,
        string $correlationId,
        string $service,
        ?string $model,
        string $method,
        int|string|null $rpcId,
        string $outcome,
        ?string $error,
        int $latencyMs,
        ?array $params,
        ?array $response,
    ): ?int {
        try {
            $this->db->createCommand()->insert('relay_calls', [
                'correlation_id' => $correlationId,
                'service' => $service,
                'model' => $model,
                'method' => $method,
                'odoo_rpc_id' => $rpcId === null ? null : (string) $rpcId,
                'outcome' => $outcome,
                'error_message' => $error === null ? null : substr($error, 0, 512),
                'latency_ms' => $latencyMs,
                'request_redacted' => $mode === RelayPolicy::JOURNAL && $params !== null
                    ? json_encode($this->redactor->redactOdooParams($params), JsonResponder::FLAGS) : null,
                'response_redacted' => $response === null ? null : json_encode($this->redactor->redact($response), JsonResponder::FLAGS),
                'created_at' => $this->clock->nowForDb(),
            ])->execute();

            return (int) $this->db->getLastInsertId();
        } catch (Throwable $e) {
            $this->logger->error('Relay journal write failed', ['method' => $method, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private static function errorMessage(array $response): string
    {
        $error = Arr::arr($response, 'error');

        return Arr::str(Arr::arr($error, 'data'), 'message') ?? Arr::str($error, 'message') ?? 'Odoo error';
    }
}
