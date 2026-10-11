<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Collector output: latest pump status, meter snapshots (primary dispensing evidence) and fill events.
 */
final class M261011000002CreateObservationTables implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE pump_status_cache (
                controller_id BIGINT UNSIGNED NOT NULL,
                pump_number SMALLINT UNSIGNED NOT NULL,
                station_id BIGINT UNSIGNED NOT NULL,
                state VARCHAR(32) NOT NULL,
                packet JSON NOT NULL,
                observed_at DATETIME(3) NOT NULL,
                PRIMARY KEY (controller_id, pump_number),
                KEY ix_pump_status_station (station_id),
                CONSTRAINT fk_pump_status_controller FOREIGN KEY (controller_id) REFERENCES controllers (id),
                CONSTRAINT fk_pump_status_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE meter_snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                controller_id BIGINT UNSIGNED NOT NULL,
                pump_number SMALLINT UNSIGNED NOT NULL,
                nozzle_number TINYINT UNSIGNED NOT NULL,
                total_volume DECIMAL(14,3) NOT NULL,
                total_amount DECIMAL(14,2) NULL,
                source ENUM('status','totals','report','device_totals','odoo_counter') NOT NULL,
                observed_at DATETIME(3) NOT NULL,
                controller_time_raw VARCHAR(32) NULL,
                KEY ix_meter_nozzle_time (station_id, controller_id, pump_number, nozzle_number, observed_at),
                KEY ix_meter_observed (observed_at),
                CONSTRAINT fk_meter_station FOREIGN KEY (station_id) REFERENCES stations (id),
                CONSTRAINT fk_meter_controller FOREIGN KEY (controller_id) REFERENCES controllers (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE fill_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                controller_id BIGINT UNSIGNED NOT NULL,
                pump_number SMALLINT UNSIGNED NOT NULL,
                nozzle_number TINYINT UNSIGNED NOT NULL,
                pts_transaction_no INT UNSIGNED NULL,
                started_at DATETIME(3) NULL,
                ended_at DATETIME(3) NOT NULL,
                volume DECIMAL(14,3) NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                price DECIMAL(10,3) NULL,
                meter_volume_after DECIMAL(14,3) NOT NULL,
                meter_amount_after DECIMAL(14,2) NULL,
                pts_user_id INT UNSIGNED NULL,
                source ENUM('status_transition','report','authorize_confirmation') NOT NULL,
                state ENUM('observed','matched','ambiguous','unmatched','accepted_loss') NOT NULL DEFAULT 'observed',
                raw JSON NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_fill_meter (controller_id, pump_number, nozzle_number, meter_volume_after),
                KEY ix_fill_station_ended (station_id, ended_at),
                KEY ix_fill_tx (controller_id, pump_number, pts_transaction_no),
                KEY ix_fill_state (station_id, state),
                CONSTRAINT fk_fill_station FOREIGN KEY (station_id) REFERENCES stations (id),
                CONSTRAINT fk_fill_controller FOREIGN KEY (controller_id) REFERENCES controllers (id)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (['fill_events', 'meter_snapshots', 'pump_status_cache'] as $table) {
            $b->dropTable($table);
        }
    }
}
