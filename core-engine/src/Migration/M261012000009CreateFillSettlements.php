<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Decision D21: a controller fill already paid by another sale (manual, wrong pump or wrong transaction number) is
 * reported to devices as paid, so FuelPay stops re-presenting it. Each fill the relay reported this way is recorded
 * here with the sale that settled it, for the session report and for review.
 */
final class M261012000009CreateFillSettlements implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE fill_settlements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                station_id BIGINT UNSIGNED NOT NULL,
                fill_event_id BIGINT UNSIGNED NOT NULL,
                odoo_sale_id BIGINT UNSIGNED NOT NULL,
                pump_number SMALLINT UNSIGNED NOT NULL,
                pts_transaction_no VARCHAR(32) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                fill_uuid CHAR(36) NOT NULL,
                first_applied_at DATETIME(3) NOT NULL,
                last_applied_at DATETIME(3) NOT NULL,
                applied_count INT UNSIGNED NOT NULL DEFAULT 1,
                last_method VARCHAR(64) NOT NULL,
                UNIQUE KEY ux_fill_settlements_fill (fill_event_id),
                KEY ix_fill_settlements_station (station_id, last_applied_at),
                CONSTRAINT fk_fill_settlements_station FOREIGN KEY (station_id) REFERENCES stations (id),
                CONSTRAINT fk_fill_settlements_fill FOREIGN KEY (fill_event_id) REFERENCES fill_events (id),
                CONSTRAINT fk_fill_settlements_sale FOREIGN KEY (odoo_sale_id) REFERENCES odoo_sales (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP TABLE fill_settlements');
    }
}
