<?php

declare(strict_types=1);

namespace App\Web\Pts;

use App\Pts\ControllerRepository;
use App\Pts\Endpoint\PacketRouter;
use App\Pts\PtsException;
use App\Shared\Http\JsonResponder;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function bin2hex;
use function is_string;
use function json_decode;
use function random_bytes;

/**
 * PTS-compatible endpoint: `POST /stations/{backend}/jsonPTS`, where {backend} is the Odoo `pts.backend.config` id
 * (the id FuelPay's station configuration refers to). Speaks the controller's jsonPTS protocol.
 *
 * Authentication is deferred (decision D5): FuelPay sends its first request without credentials and accepts any
 * non-401 reply, so no challenge is issued. Every request is still tied to an identified controller and journaled.
 */
final class JsonPtsAction
{
    public function __construct(
        private readonly ControllerRepository $controllers,
        private readonly PacketRouter $router,
        private readonly JsonResponder $responder,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request, #[RouteArgument('backend')] int $backend): ResponseInterface
    {
        $correlationId = bin2hex(random_bytes(8));
        try {
            return $this->handle($request, $backend, $correlationId);
        } catch (Throwable $e) {
            // E.g. MySQL down: answer in jsonPTS form instead of an HTML error page; the device shows the pump offline.
            $this->logger->error('PTS endpoint failed', ['backend' => $backend, 'correlation_id' => $correlationId, 'error' => $e->getMessage()]);
            return $this->responder->respond(['Protocol' => 'jsonPTS', 'Error' => 'Mediator temporarily unavailable'], 503)
                ->withHeader('X-Correlation-Id', $correlationId);
        }
    }

    private function handle(ServerRequestInterface $request, int $backend, string $correlationId): ResponseInterface
    {
        $row = $this->controllers->rowByOdooBackendId($backend);
        if ($row === null) {
            return $this->responder->respond(['Protocol' => 'jsonPTS', 'Error' => 'Unknown station controller'], 404);
        }

        try {
            /** @var mixed $body */
            $body = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->responder->respond(['Protocol' => 'jsonPTS', 'Error' => 'Malformed JSON'], 400);
        }
        $packets = PacketRouter::parseRequest($body);
        if (is_string($packets)) {
            return $this->responder->respond(['Protocol' => 'jsonPTS', 'Error' => $packets], 400);
        }

        try {
            $endpoint = $this->controllers->endpoint($row);
        } catch (PtsException $e) {
            $this->logger->error('PTS endpoint misconfigured', ['backend' => $backend, 'kind' => $e->kind, 'message' => $e->getMessage()]);
            return $this->responder->respond(['Protocol' => 'jsonPTS', 'Error' => 'Station controller not configured on mediator'], 503);
        }

        $server = $request->getServerParams();
        $clientKey = 'ip:' . (string) ($server['REMOTE_ADDR'] ?? 'unknown');
        $replies = $this->router->route($endpoint, $row, $packets, $clientKey, $correlationId);

        return $this->responder->respond(['Protocol' => 'jsonPTS', 'Packets' => $replies])
            ->withHeader('X-Correlation-Id', $correlationId);
    }
}
