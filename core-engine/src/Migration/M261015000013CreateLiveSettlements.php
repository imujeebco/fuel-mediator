<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Decision D28: log of PTS fills reported to devices as paid because a manual sale on the same pump paid them
 * (live settlement). One row per fill; the decision itself is re-checked on every request.
 */
final class M261015000013CreateLiveSettlements implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE live_settlements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                pump_number SMALLINT UNSIGNED NOT NULL,
                pts_transaction_no VARCHAR(32) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                volume DECIMAL(14,3) NULL,
                fill_created_at DATETIME(3) NOT NULL,
                pending_uuid VARCHAR(64) NULL,
                payer_source ENUM('mirror', 'relay') NOT NULL,
                payer_ref BIGINT UNSIGNED NOT NULL,
                payer_uuid VARCHAR(64) NOT NULL,
                first_applied_at DATETIME(3) NOT NULL,
                last_applied_at DATETIME(3) NOT NULL,
                applied_count INT UNSIGNED NOT NULL DEFAULT 1,
                last_method VARCHAR(64) NOT NULL,
                UNIQUE KEY ux_live_settlements_fill (station_id, pump_number, pts_transaction_no),
                KEY ix_live_settlements_station (station_id, last_applied_at),
                CONSTRAINT fk_live_settlements_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP TABLE live_settlements');
    }
}
