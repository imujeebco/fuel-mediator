<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Read-only mirror of Odoo sales and POS sessions, plus the Odoo relay journal.
 */
final class M261011000004CreateOdooMirrorTables implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE odoo_sales (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                odoo_id INT UNSIGNED NOT NULL,
                uuid VARCHAR(64) NOT NULL,
                alias_uuids TEXT NULL,
                pump_number SMALLINT UNSIGNED NULL,
                nozzle_number TINYINT UNSIGNED NULL,
                fuel_name VARCHAR(64) NULL,
                volume DECIMAL(14,3) NOT NULL DEFAULT 0,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                price DECIMAL(10,3) NULL,
                payment_status VARCHAR(16) NOT NULL,
                payment_method VARCHAR(32) NULL,
                rrn VARCHAR(32) NULL,
                approval_code VARCHAR(32) NULL,
                manual_sale TINYINT(1) NOT NULL DEFAULT 0,
                pts_transaction_id VARCHAR(32) NULL,
                terminal_id VARCHAR(64) NULL,
                terminal_order_ref VARCHAR(80) NULL,
                operator_name VARCHAR(128) NULL,
                odoo_pos_config_id INT UNSIGNED NULL,
                odoo_pos_session_id INT UNSIGNED NULL,
                odoo_pos_order_id INT UNSIGNED NULL,
                pos_sync_status VARCHAR(16) NULL,
                match_state ENUM('unlinked','linked','no_fill_expected','sale_without_fill','duplicate_candidate')
                    NOT NULL DEFAULT 'unlinked',
                sale_created_at DATETIME(3) NOT NULL,
                odoo_write_date DATETIME(3) NOT NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_odoo_sales_odoo_id (odoo_id),
                UNIQUE KEY uq_odoo_sales_uuid (uuid),
                KEY ix_odoo_sales_station_time (station_id, sale_created_at),
                KEY ix_odoo_sales_pump_time (station_id, pump_number, sale_created_at),
                KEY ix_odoo_sales_session (odoo_pos_session_id),
                CONSTRAINT fk_odoo_sales_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE pos_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                odoo_session_id INT UNSIGNED NOT NULL,
                odoo_pos_config_id INT UNSIGNED NULL,
                odoo_mobile_shift_id INT UNSIGNED NULL,
                name VARCHAR(64) NOT NULL,
                state VARCHAR(32) NOT NULL,
                start_at DATETIME(3) NULL,
                stop_at DATETIME(3) NULL,
                cash_register_balance_start DECIMAL(14,2) NULL,
                cash_register_balance_end_real DECIMAL(14,2) NULL,
                odoo_write_date DATETIME(3) NOT NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_pos_sessions_odoo (odoo_session_id),
                KEY ix_pos_sessions_station (station_id, start_at),
                CONSTRAINT fk_pos_sessions_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE relay_calls (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NULL,
                device_id BIGINT UNSIGNED NULL,
                correlation_id VARCHAR(64) NOT NULL,
                service VARCHAR(16) NOT NULL,
                model VARCHAR(96) NULL,
                method VARCHAR(96) NOT NULL,
                odoo_rpc_id VARCHAR(32) NULL,
                outcome ENUM('ok','odoo_error','timeout','transport_error','denied') NOT NULL,
                error_message VARCHAR(512) NULL,
                latency_ms INT UNSIGNED NULL,
                request_redacted JSON NULL,
                response_redacted JSON NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY ix_relay_station_time (station_id, created_at),
                KEY ix_relay_method_time (method, created_at),
                CONSTRAINT fk_relay_station FOREIGN KEY (station_id) REFERENCES stations (id),
                CONSTRAINT fk_relay_device FOREIGN KEY (device_id) REFERENCES devices (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE sale_observations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NULL,
                device_id BIGINT UNSIGNED NULL,
                relay_call_id BIGINT UNSIGNED NULL,
                uuid VARCHAR(64) NOT NULL,
                payment_status VARCHAR(16) NULL,
                pump_number SMALLINT UNSIGNED NULL,
                nozzle_number TINYINT UNSIGNED NULL,
                volume DECIMAL(14,3) NULL,
                amount DECIMAL(14,2) NULL,
                pts_transaction_id VARCHAR(32) NULL,
                manual_sale TINYINT(1) NULL,
                rrn VARCHAR(32) NULL,
                approval_code VARCHAR(32) NULL,
                odoo_status VARCHAR(16) NULL,
                canonical_uuid VARCHAR(64) NULL,
                pos_synced TINYINT(1) NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY ix_sale_obs_uuid (uuid),
                KEY ix_sale_obs_station_time (station_id, created_at),
                CONSTRAINT fk_sale_obs_station FOREIGN KEY (station_id) REFERENCES stations (id),
                CONSTRAINT fk_sale_obs_device FOREIGN KEY (device_id) REFERENCES devices (id),
                CONSTRAINT fk_sale_obs_relay FOREIGN KEY (relay_call_id) REFERENCES relay_calls (id)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (['sale_observations', 'relay_calls', 'pos_sessions', 'odoo_sales'] as $table) {
            $b->dropTable($table);
        }
    }
}
