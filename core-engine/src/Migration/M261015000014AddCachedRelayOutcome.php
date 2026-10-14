<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Decision D29: relay answers served from the mediator's short-lived cache are journaled as `cached`.
 */
final class M261015000014AddCachedRelayOutcome implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            ALTER TABLE relay_calls
                MODIFY COLUMN outcome ENUM('ok', 'odoo_error', 'timeout', 'transport_error', 'denied', 'cached') NOT NULL
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute("UPDATE relay_calls SET outcome = 'ok' WHERE outcome = 'cached'");
        $b->execute(<<<'SQL'
            ALTER TABLE relay_calls
                MODIFY COLUMN outcome ENUM('ok', 'odoo_error', 'timeout', 'transport_error', 'denied') NOT NULL
            SQL);
    }
}
