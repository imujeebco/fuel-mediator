<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Stations, PTS controllers, pumps, nozzles and FuelPay devices. See docs/database-design.md.
 */
final class M261011000001CreateConfigurationTables implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE stations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NOT NULL,
                name VARCHAR(128) NOT NULL,
                odoo_station_id INT UNSIGNED NULL,
                odoo_company_id INT UNSIGNED NULL,
                odoo_company_name VARCHAR(128) NULL,
                mode ENUM('pts','manual') NOT NULL DEFAULT 'manual',
                mode_override ENUM('pts','manual') NULL,
                utc_offset_minutes SMALLINT NOT NULL DEFAULT 180,
                active TINYINT(1) NOT NULL DEFAULT 1,
                settings JSON NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                KEY ix_stations_code (code),
                UNIQUE KEY uq_stations_odoo_station (odoo_station_id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE controllers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                odoo_backend_config_id INT UNSIGNED NULL,
                name VARCHAR(128) NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                url VARCHAR(255) NULL,
                auth_mode ENUM('digest','basic','none') NOT NULL DEFAULT 'digest',
                username VARCHAR(128) NULL,
                secret_ref VARCHAR(128) NULL,
                verify_ssl TINYINT(1) NOT NULL DEFAULT 0,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                odoo_active TINYINT(1) NOT NULL DEFAULT 1,
                poll_interval_ms INT UNSIGNED NOT NULL DEFAULT 10000,
                totals_interval_s INT UNSIGNED NOT NULL DEFAULT 300,
                freshness_ms INT UNSIGNED NOT NULL DEFAULT 1500,
                stale_ms INT UNSIGNED NOT NULL DEFAULT 5000,
                command_timeout_ms INT UNSIGNED NOT NULL DEFAULT 10000,
                last_ok_at DATETIME(3) NULL,
                last_error_at DATETIME(3) NULL,
                last_error VARCHAR(512) NULL,
                consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
                circuit_open_until DATETIME(3) NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_controllers_odoo_backend (odoo_backend_config_id),
                KEY ix_controllers_station (station_id),
                CONSTRAINT fk_controllers_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE pumps (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                controller_id BIGINT UNSIGNED NOT NULL,
                pump_number SMALLINT UNSIGNED NOT NULL,
                odoo_pump_id INT UNSIGNED NULL,
                name VARCHAR(64) NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_pumps_controller_number (controller_id, pump_number),
                KEY ix_pumps_station (station_id),
                CONSTRAINT fk_pumps_station FOREIGN KEY (station_id) REFERENCES stations (id),
                CONSTRAINT fk_pumps_controller FOREIGN KEY (controller_id) REFERENCES controllers (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE nozzles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                pump_id BIGINT UNSIGNED NOT NULL,
                nozzle_number TINYINT UNSIGNED NOT NULL,
                fuel_grade_id SMALLINT UNSIGNED NULL,
                fuel_name VARCHAR(64) NULL,
                odoo_nozzle_id INT UNSIGNED NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_nozzles_pump_number (pump_id, nozzle_number),
                KEY ix_nozzles_station (station_id),
                CONSTRAINT fk_nozzles_station FOREIGN KEY (station_id) REFERENCES stations (id),
                CONSTRAINT fk_nozzles_pump FOREIGN KEY (pump_id) REFERENCES pumps (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE devices (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NULL,
                terminal_id VARCHAR(64) NOT NULL,
                odoo_uid INT UNSIGNED NULL,
                odoo_login VARCHAR(128) NULL,
                app_version VARCHAR(32) NULL,
                last_ip VARCHAR(45) NULL,
                first_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_devices_terminal (terminal_id),
                KEY ix_devices_station (station_id),
                CONSTRAINT fk_devices_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (['devices', 'nozzles', 'pumps', 'controllers', 'stations'] as $table) {
            $b->dropTable($table);
        }
    }
}
