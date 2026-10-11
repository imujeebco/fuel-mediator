<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Live findings (2026-10-11, docs/discovery/reconciliation-feasibility.md):
 *  - Odoo tags terminal sales with a wrong station for devices of other companies, so sales are attributed by their
 *    POS config's company; the station Odoo tagged is kept for misattribution reporting;
 *  - in manual mode the sale's pump number is effectively the fuel grade, so fills carry their grade for grade-level
 *    reconciliation and matching.
 */
final class M261011000008AddAttributionColumns implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            ALTER TABLE odoo_sales
                ADD COLUMN odoo_tagged_station_id INT UNSIGNED NULL AFTER station_id,
                ADD COLUMN odoo_company_id INT UNSIGNED NULL AFTER odoo_tagged_station_id,
                ADD KEY ix_odoo_sales_grade_time (station_id, fuel_name, sale_created_at)
            SQL);
        $b->execute(<<<'SQL'
            ALTER TABLE fill_events
                ADD COLUMN fuel_name VARCHAR(64) NULL AFTER nozzle_number,
                ADD KEY ix_fill_grade_time (station_id, fuel_name, ended_at)
            SQL);
        // Backfill grades for fills already collected.
        $b->execute(<<<'SQL'
            UPDATE fill_events f
            JOIN pumps p ON p.controller_id = f.controller_id AND p.pump_number = f.pump_number
            JOIN nozzles n ON n.pump_id = p.id AND n.nozzle_number = f.nozzle_number
            SET f.fuel_name = n.fuel_name
            WHERE f.fuel_name IS NULL
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE fill_events DROP KEY ix_fill_grade_time, DROP COLUMN fuel_name');
        $b->execute('ALTER TABLE odoo_sales DROP KEY ix_odoo_sales_grade_time, DROP COLUMN odoo_company_id, DROP COLUMN odoo_tagged_station_id');
    }
}
