<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Decision D23: controller passwords are read from Odoo by the station import and stored encrypted
 * (libsodium secretbox, key MEDIATOR_SECRET_KEY in the server environment). Never stored in plaintext.
 */
final class M261012000010AddControllerSecrets implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            ALTER TABLE controllers
                ADD COLUMN secret_encrypted TEXT NULL AFTER secret_ref,
                ADD COLUMN secret_updated_at DATETIME(3) NULL AFTER secret_encrypted
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE controllers DROP COLUMN secret_updated_at, DROP COLUMN secret_encrypted');
    }
}
