<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Decision D31: pump-status cache limits that fit a 10 s collector poll and a slow controller. The old defaults
 * (fresh 1.5 s, stale 5 s) were shorter than one poll, so devices got no status packet for most polls and showed the
 * pumps offline (Station 33, 2026-10-20). Rows still on the old defaults move to the new ones; tuned rows are kept.
 */
final class M261020000016RaisePumpStatusCacheLimits implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            ALTER TABLE controllers
                ALTER COLUMN freshness_ms SET DEFAULT 5000,
                ALTER COLUMN stale_ms SET DEFAULT 30000
            SQL);
        $b->execute('UPDATE controllers SET freshness_ms = 5000 WHERE freshness_ms = 1500');
        $b->execute('UPDATE controllers SET stale_ms = 30000 WHERE stale_ms = 5000');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('UPDATE controllers SET stale_ms = 5000 WHERE stale_ms = 30000');
        $b->execute('UPDATE controllers SET freshness_ms = 1500 WHERE freshness_ms = 5000');
        $b->execute(<<<'SQL'
            ALTER TABLE controllers
                ALTER COLUMN freshness_ms SET DEFAULT 1500,
                ALTER COLUMN stale_ms SET DEFAULT 5000
            SQL);
    }
}
