<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Transactional outbox, per-station worker leases, incremental sync cursors and the append-only audit log.
 */
final class M261011000006CreateInfrastructureTables implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE outbox (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NULL,
                kind VARCHAR(64) NOT NULL,
                payload JSON NOT NULL,
                idempotency_key VARCHAR(128) NOT NULL,
                status ENUM('pending','processing','done','failed','dead') NOT NULL DEFAULT 'pending',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts INT UNSIGNED NOT NULL DEFAULT 10,
                next_attempt_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                locked_by VARCHAR(96) NULL,
                locked_until DATETIME(3) NULL,
                last_error VARCHAR(1024) NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_outbox_idempotency (idempotency_key),
                KEY ix_outbox_due (status, next_attempt_at),
                KEY ix_outbox_station (station_id, status),
                CONSTRAINT fk_outbox_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE worker_leases (
                worker VARCHAR(64) NOT NULL,
                station_id BIGINT UNSIGNED NOT NULL,
                holder VARCHAR(96) NOT NULL,
                lease_until DATETIME(3) NOT NULL,
                heartbeat_at DATETIME(3) NOT NULL,
                PRIMARY KEY (worker, station_id),
                CONSTRAINT fk_leases_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB
            SQL);

        // station_id 0 means "global" cursor; no FK so that global cursors are possible.
        $b->execute(<<<'SQL'
            CREATE TABLE sync_cursors (
                source VARCHAR(64) NOT NULL,
                station_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                cursor_value VARCHAR(64) NOT NULL,
                cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                PRIMARY KEY (source, station_id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE audit_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NULL,
                entity_type VARCHAR(48) NOT NULL,
                entity_id VARCHAR(64) NOT NULL,
                event VARCHAR(64) NOT NULL,
                from_state VARCHAR(32) NULL,
                to_state VARCHAR(32) NULL,
                actor VARCHAR(128) NOT NULL,
                correlation_id VARCHAR(64) NULL,
                data JSON NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY ix_audit_entity (entity_type, entity_id),
                KEY ix_audit_station_time (station_id, created_at)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (['audit_events', 'sync_cursors', 'worker_leases', 'outbox'] as $table) {
            $b->dropTable($table);
        }
    }
}
