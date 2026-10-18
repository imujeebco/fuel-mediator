<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Decision D30, step 1: raw requests the controllers push to the mediator (remote-server HTTP upload), kept so the
 * packet format, authentication and timing can be confirmed before anything is derived from them. Short retention.
 */
final class M261018000015CreatePtsUploads implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            CREATE TABLE pts_uploads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                controller_id BIGINT UNSIGNED NOT NULL,
                station_id BIGINT UNSIGNED NOT NULL,
                upload_kind VARCHAR(32) NOT NULL,
                packet_types VARCHAR(255) NULL,
                packet_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                pts_id VARCHAR(64) NULL,
                auth_kind VARCHAR(16) NOT NULL,
                auth_user VARCHAR(64) NULL,
                remote_addr VARCHAR(64) NULL,
                headers JSON NOT NULL,
                body MEDIUMTEXT NOT NULL,
                parse_error VARCHAR(255) NULL,
                received_at DATETIME(3) NOT NULL,
                KEY ix_pts_uploads_controller (controller_id, received_at),
                KEY ix_pts_uploads_received (received_at),
                CONSTRAINT fk_pts_uploads_controller FOREIGN KEY (controller_id) REFERENCES controllers (id),
                CONSTRAINT fk_pts_uploads_station FOREIGN KEY (station_id) REFERENCES stations (id)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('pts_uploads');
    }
}
