<?php

declare(strict_types=1);

namespace App\Web\Odoo;

use App\Odoo\Relay\OdooRelay;
use App\Odoo\Relay\RelayResponse;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function bin2hex;
use function random_bytes;

/**
 * Odoo-compatible endpoint for FuelPay: the device's Odoo URL is set to `https://<mediator>/odoo`, and FuelPay
 * appends `/jsonrpc` (docs/discovery/fuelpay-odoo-contract.md §1).
 */
final class JsonRpcRelayAction
{
    public function __construct(
        private readonly OdooRelay $relay,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $correlationId = bin2hex(random_bytes(8));
        $server = $request->getServerParams();
        try {
            $result = $this->relay->handle((string) $request->getBody(), (string) ($server['REMOTE_ADDR'] ?? ''), $correlationId);
        } catch (Throwable $e) {
            // E.g. MySQL down: never answer with an HTML error page (FuelPay would reset its Odoo session); answer with
            // a JSON-RPC error FuelPay treats as retryable, so the sale stays in its offline queue.
            $this->logger->error('Odoo relay failed', ['correlation_id' => $correlationId, 'error' => $e->getMessage()]);
            $result = RelayResponse::rpcError(null, 'mediator.Unavailable', 'Mediator temporarily unavailable (503), try again');
        }

        $response = $this->responseFactory->createResponse($result->status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Correlation-Id', $correlationId)
            ->withBody($this->streamFactory->createStream($result->body));
        foreach ($result->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
