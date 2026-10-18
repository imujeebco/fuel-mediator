<?php

declare(strict_types=1);

namespace App\Web\Pts;

use App\Environment;
use App\Pts\ControllerRepository;
use App\Pts\Upload\UploadCapture;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function strlen;

/**
 * Remote-server HTTP upload target for controllers (decision D30): `POST /stations/{backend}/upload/{kind}`, where
 * {backend} is the Odoo `pts.backend.config` id and {kind} names the upload (the controller's "Server request URI",
 * e.g. `stations/27/upload/transactions`). Step 1 records and acknowledges only; see {@see UploadCapture}.
 */
final class UploadAction
{
    public function __construct(
        private readonly ControllerRepository $controllers,
        private readonly UploadCapture $capture,
        private readonly JsonResponder $responder,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument('backend')] int $backend,
        #[RouteArgument('kind')] string $kind,
    ): ResponseInterface {
        if ((string) Environment::get('PTS_UPLOAD_CAPTURE') === '0') {
            return $this->responder->respond(['Protocol' => 'jsonPTS', 'Error' => 'Uploads are not accepted'], 404);
        }
        $row = $this->controllers->rowByOdooBackendId($backend);
        if ($row === null) {
            return $this->responder->respond(['Protocol' => 'jsonPTS', 'Error' => 'Unknown station controller'], 404);
        }
        $body = (string) $request->getBody();
        if (strlen($body) > UploadCapture::MAX_BODY_BYTES) {
            $this->logger->warning('PTS upload too large', ['backend' => $backend, 'kind' => $kind, 'bytes' => strlen($body)]);
            return $this->responder->respond(['Protocol' => 'jsonPTS', 'Error' => 'Request too large'], 413);
        }

        try {
            $server = $request->getServerParams();
            $reply = $this->capture->capture($row, $kind, $body, $request->getHeaders(), isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null);
        } catch (Throwable $e) {
            // Not acknowledged: the controller keeps the record and sends it again.
            $this->logger->error('PTS upload failed', ['backend' => $backend, 'kind' => $kind, 'error' => $e->getMessage()]);
            return $this->responder->respond(['Protocol' => 'jsonPTS', 'Error' => 'Mediator temporarily unavailable'], 503);
        }

        return $this->responder->respond($reply['body'], $reply['status']);
    }
}
