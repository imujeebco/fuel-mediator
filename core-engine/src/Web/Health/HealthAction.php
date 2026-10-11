<?php

declare(strict_types=1);

namespace App\Web\Health;

use App\Shared\Clock;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function hrtime;

/**
 * Liveness + database check. 200 when the database answers, 503 otherwise.
 */
final class HealthAction
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly JsonResponder $responder,
        private readonly Clock $clock,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $started = hrtime(true);
        try {
            $this->db->createCommand('SELECT 1')->queryScalar();
            $database = ['ok' => true, 'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
        } catch (Throwable) {
            // Never expose driver messages (they may contain host/user details).
            $database = ['ok' => false];
        }

        return $this->responder->respond(
            [
                'status' => $database['ok'] ? 'ok' : 'degraded',
                'service' => 'fuel-mediator',
                'time' => $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
                'database' => $database,
            ],
            $database['ok'] ? 200 : 503,
        );
    }
}
