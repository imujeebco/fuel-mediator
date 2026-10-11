<?php

declare(strict_types=1);

namespace App\Web\Health;

use App\Shared\Http\JsonResponder;
use App\Station\StationHealthRepository;
use Psr\Http\Message\ResponseInterface;

/**
 * Per-station controller health (collector status, last success/error, circuit breaker).
 */
final class StationsHealthAction
{
    public function __construct(
        private readonly StationHealthRepository $repository,
        private readonly JsonResponder $responder,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->responder->respond(['stations' => $this->repository->all()]);
    }
}
