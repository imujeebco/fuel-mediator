<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Collector support (docs/discovery/reconciliation-feasibility.md, addendum):
 *  - pumps/nozzles follow the controller's own configuration (D15);
 *  - pump_status_cache remembers the last transaction number and meter per pump (tx continuity);
 *  - fill_events record how many fills were missed before them and the volume those fills dispensed.
 */
final class M261011000007AddCollectorColumns implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            ALTER TABLE pumps
                ADD COLUMN on_controller TINYINT(1) NOT NULL DEFAULT 0 AFTER active,
                ADD COLUMN in_odoo TINYINT(1) NOT NULL DEFAULT 1 AFTER on_controller,
                ADD COLUMN controller_port SMALLINT UNSIGNED NULL AFTER in_odoo,
                ADD COLUMN controller_address SMALLINT UNSIGNED NULL AFTER controller_port,
                ADD COLUMN config_synced_at DATETIME(3) NULL AFTER controller_address,
                MODIFY odoo_pump_id INT UNSIGNED NULL
            SQL);

        $b->execute(<<<'SQL'
            ALTER TABLE nozzles
                ADD COLUMN on_controller TINYINT(1) NOT NULL DEFAULT 0 AFTER odoo_nozzle_id
            SQL);

        $b->execute(<<<'SQL'
            ALTER TABLE controllers
                ADD COLUMN config_identifier VARCHAR(32) NULL AFTER verify_ssl,
                ADD COLUMN config_synced_at DATETIME(3) NULL AFTER config_identifier,
                MODIFY totals_interval_s INT UNSIGNED NOT NULL DEFAULT 0
            SQL);

        $b->execute(<<<'SQL'
            ALTER TABLE pump_status_cache
                ADD COLUMN last_transaction_no INT UNSIGNED NULL AFTER state,
                ADD COLUMN last_total_volume DECIMAL(14,3) NULL AFTER last_transaction_no,
                ADD COLUMN last_total_amount DECIMAL(14,2) NULL AFTER last_total_volume,
                ADD COLUMN last_snapshot_at DATETIME(3) NULL AFTER last_total_amount
            SQL);

        $b->execute(<<<'SQL'
            ALTER TABLE fill_events
                ADD COLUMN preceding_missed_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER pts_user_id,
                ADD COLUMN preceding_missed_volume DECIMAL(14,3) NULL AFTER preceding_missed_count
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE fill_events DROP COLUMN preceding_missed_volume, DROP COLUMN preceding_missed_count');
        $b->execute('ALTER TABLE pump_status_cache DROP COLUMN last_snapshot_at, DROP COLUMN last_total_amount,'
            . ' DROP COLUMN last_total_volume, DROP COLUMN last_transaction_no');
        $b->execute('ALTER TABLE controllers DROP COLUMN config_synced_at, DROP COLUMN config_identifier,'
            . ' MODIFY totals_interval_s INT UNSIGNED NOT NULL DEFAULT 300');
        $b->execute('ALTER TABLE nozzles DROP COLUMN on_controller');
        $b->execute('ALTER TABLE pumps DROP COLUMN config_synced_at, DROP COLUMN controller_address,'
            . ' DROP COLUMN controller_port, DROP COLUMN in_odoo, DROP COLUMN on_controller');
    }
}
