<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Clock;
use App\Shared\Log\Redactor;
use App\Shared\Http\JsonResponder;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Append-only audit trail. Call inside the same DB transaction as the change it describes.
 */
final class AuditLog
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
        private readonly Redactor $redactor,
    ) {}

    public function record(
        string $entityType,
        string|int $entityId,
        string $event,
        string $actor,
        ?int $stationId = null,
        ?string $fromState = null,
        ?string $toState = null,
        array $data = [],
        ?string $correlationId = null,
    ): void {
        $this->db->createCommand()->insert('audit_events', [
            'station_id' => $stationId,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'event' => $event,
            'from_state' => $fromState,
            'to_state' => $toState,
            'actor' => $actor,
            'correlation_id' => $correlationId,
            'data' => $data === [] ? null : json_encode($this->redactor->redact($data), JsonResponder::FLAGS),
            'created_at' => $this->clock->nowForDb(),
        ])->execute();
    }
}
