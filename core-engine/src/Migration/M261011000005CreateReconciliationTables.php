<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Fill ↔ sale links, discrepancies and reconciliation runs. See docs/transaction-lifecycle.md §6.
 */
final class M261011000005CreateReconciliationTables implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE match_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                fill_event_id BIGINT UNSIGNED NOT NULL,
                odoo_sale_id BIGINT UNSIGNED NOT NULL,
                method ENUM('tx_id','fingerprint','manual') NOT NULL,
                confidence ENUM('confirmed','probable','ambiguous') NOT NULL,
                score DECIMAL(6,3) NULL,
                reviewed_by VARCHAR(128) NULL,
                reviewed_at DATETIME(3) NULL,
                review_note VARCHAR(512) NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_match_fill_sale (fill_event_id, odoo_sale_id),
                KEY ix_match_sale (odoo_sale_id),
                KEY ix_match_station (station_id, confidence),
                CONSTRAINT fk_match_station FOREIGN KEY (station_id) REFERENCES stations (id),
                CONSTRAINT fk_match_fill FOREIGN KEY (fill_event_id) REFERENCES fill_events (id),
                CONSTRAINT fk_match_sale FOREIGN KEY (odoo_sale_id) REFERENCES odoo_sales (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE discrepancies (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                type ENUM('unsold_volume','oversold_volume','unmatched_fill','sale_without_fill',
                          'duplicate_candidate','unknown_command_outcome','odoo_sync_failed',
                          'payment_unverified','meter_gap','meter_regression','session_mismatch',
                          'collector_gap') NOT NULL,
                severity ENUM('info','warning','high','critical') NOT NULL DEFAULT 'warning',
                status ENUM('open','acknowledged','resolved','dismissed') NOT NULL DEFAULT 'open',
                fingerprint CHAR(64) NOT NULL,
                period_start DATETIME(3) NULL,
                period_end DATETIME(3) NULL,
                odoo_pos_session_id INT UNSIGNED NULL,
                pump_number SMALLINT UNSIGNED NULL,
                nozzle_number TINYINT UNSIGNED NULL,
                volume DECIMAL(14,3) NULL,
                amount DECIMAL(14,2) NULL,
                summary VARCHAR(255) NOT NULL,
                details JSON NULL,
                resolved_by VARCHAR(128) NULL,
                resolved_at DATETIME(3) NULL,
                resolution_note VARCHAR(512) NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_discrepancies_fingerprint (fingerprint),
                KEY ix_discrepancies_station (station_id, status, type),
                KEY ix_discrepancies_session (odoo_pos_session_id),
                CONSTRAINT fk_discrepancies_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB
            SQL);

        $b->execute(<<<'SQL'
            CREATE TABLE reconciliation_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                scope ENUM('period','session') NOT NULL,
                odoo_pos_session_id INT UNSIGNED NULL,
                window_start DATETIME(3) NOT NULL,
                window_end DATETIME(3) NOT NULL,
                status ENUM('running','clean','has_discrepancies','incomplete','failed') NOT NULL DEFAULT 'running',
                stats JSON NULL,
                started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                finished_at DATETIME(3) NULL,
                KEY ix_recon_station (station_id, started_at),
                CONSTRAINT fk_recon_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (['reconciliation_runs', 'discrepancies', 'match_links'] as $table) {
            $b->dropTable($table);
        }
    }
}
