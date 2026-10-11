<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Journal of physical PTS commands (authorize, stop, ...). See docs/transaction-lifecycle.md §1.
 */
final class M261011000003CreateCommandTables implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE commands (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                public_id CHAR(36) NOT NULL,
                station_id BIGINT UNSIGNED NOT NULL,
                controller_id BIGINT UNSIGNED NOT NULL,
                device_id BIGINT UNSIGNED NULL,
                correlation_id VARCHAR(64) NOT NULL,
                type VARCHAR(48) NOT NULL,
                pump_number SMALLINT UNSIGNED NULL,
                nozzle_number TINYINT UNSIGNED NULL,
                request JSON NOT NULL,
                idempotency_key VARCHAR(128) NULL,
                state ENUM('received','queued','sent','confirmed','failed','outcome_unknown',
                           'resolved_executed','resolved_not_executed','needs_review') NOT NULL DEFAULT 'received',
                response JSON NULL,
                pts_transaction_no INT UNSIGNED NULL,
                error VARCHAR(512) NULL,
                received_at DATETIME(3) NOT NULL,
                sent_at DATETIME(3) NULL,
                completed_at DATETIME(3) NULL,
                resolved_by_fill_event_id BIGINT UNSIGNED NULL,
                UNIQUE KEY uq_commands_public (public_id),
                UNIQUE KEY uq_commands_idempotency (idempotency_key),
                KEY ix_commands_station_state (station_id, state, received_at),
                KEY ix_commands_pump (controller_id, pump_number, received_at),
                CONSTRAINT fk_commands_station FOREIGN KEY (station_id) REFERENCES stations (id),
                CONSTRAINT fk_commands_controller FOREIGN KEY (controller_id) REFERENCES controllers (id),
                CONSTRAINT fk_commands_device FOREIGN KEY (device_id) REFERENCES devices (id),
                CONSTRAINT fk_commands_fill FOREIGN KEY (resolved_by_fill_event_id) REFERENCES fill_events (id)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('commands');
    }
}
