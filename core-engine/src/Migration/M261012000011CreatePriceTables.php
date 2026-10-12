<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Fuel prices (decisions D25–D27): Odoo station prices (mirror), the controllers' current grade prices, and every
 * price push the engine makes with its verification. Stations opt in to automatic pushes.
 */
final class M261012000011CreatePriceTables implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE odoo_station_prices (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                odoo_id INT UNSIGNED NOT NULL,
                odoo_fuel_type_id INT UNSIGNED NULL,
                fuel_name VARCHAR(64) NOT NULL,
                price DECIMAL(12,4) NOT NULL,
                effective_date DATE NULL,
                odoo_write_date DATETIME(3) NULL,
                synced_at DATETIME(3) NOT NULL,
                UNIQUE KEY ux_odoo_station_prices_odoo (odoo_id),
                KEY ix_odoo_station_prices_station (station_id, fuel_name),
                CONSTRAINT fk_odoo_station_prices_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
        $b->execute(<<<'SQL'
            CREATE TABLE fuel_grades (
                controller_id BIGINT UNSIGNED NOT NULL,
                grade_id SMALLINT UNSIGNED NOT NULL,
                station_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(64) NOT NULL,
                price DECIMAL(12,4) NOT NULL,
                observed_at DATETIME(3) NOT NULL,
                price_changed_at DATETIME(3) NULL,
                PRIMARY KEY (controller_id, grade_id),
                KEY ix_fuel_grades_station (station_id),
                CONSTRAINT fk_fuel_grades_controller FOREIGN KEY (controller_id) REFERENCES controllers (id),
                CONSTRAINT fk_fuel_grades_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
        $b->execute(<<<'SQL'
            CREATE TABLE price_pushes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                controller_id BIGINT UNSIGNED NOT NULL,
                triggered_by VARCHAR(64) NOT NULL,
                previous JSON NOT NULL,
                requested JSON NOT NULL,
                state ENUM('sent', 'verified', 'rejected', 'mismatch', 'failed') NOT NULL,
                error VARCHAR(512) NULL,
                created_at DATETIME(3) NOT NULL,
                completed_at DATETIME(3) NULL,
                KEY ix_price_pushes_station (station_id, created_at),
                CONSTRAINT fk_price_pushes_station FOREIGN KEY (station_id) REFERENCES stations (id),
                CONSTRAINT fk_price_pushes_controller FOREIGN KEY (controller_id) REFERENCES controllers (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
        $b->execute(<<<'SQL'
            ALTER TABLE stations
                ADD COLUMN price_push_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER active,
                ADD COLUMN prices_changed_at DATETIME(3) NULL AFTER price_push_enabled
            SQL);
        $b->execute(<<<'SQL'
            ALTER TABLE discrepancies MODIFY type ENUM('unsold_volume','oversold_volume','unmatched_fill','sale_without_fill',
                'duplicate_candidate','unknown_command_outcome','odoo_sync_failed','payment_unverified','meter_gap',
                'meter_regression','session_mismatch','collector_gap','price_mismatch','price_push_failed') NOT NULL
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute("DELETE FROM discrepancies WHERE type IN ('price_mismatch', 'price_push_failed')");
        $b->execute(<<<'SQL'
            ALTER TABLE discrepancies MODIFY type ENUM('unsold_volume','oversold_volume','unmatched_fill','sale_without_fill',
                'duplicate_candidate','unknown_command_outcome','odoo_sync_failed','payment_unverified','meter_gap',
                'meter_regression','session_mismatch','collector_gap') NOT NULL
            SQL);
        $b->execute('ALTER TABLE stations DROP COLUMN prices_changed_at, DROP COLUMN price_push_enabled');
        $b->execute('DROP TABLE price_pushes');
        $b->execute('DROP TABLE fuel_grades');
        $b->execute('DROP TABLE odoo_station_prices');
    }
}
