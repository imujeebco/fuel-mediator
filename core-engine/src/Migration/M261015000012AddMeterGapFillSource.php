<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * A single fill missed between two polls is rebuilt from the meter totals (source `meter_gap`), so a sale naming its
 * transaction number still links (live finding 2026-10-14: pump 9 tx 4811 followed by a zero-volume lift).
 */
final class M261015000012AddMeterGapFillSource implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
            ALTER TABLE fill_events
                MODIFY COLUMN source ENUM('status_transition', 'report', 'authorize_confirmation', 'meter_gap') NOT NULL
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute("DELETE FROM fill_events WHERE source = 'meter_gap'");
        $b->execute(<<<'SQL'
            ALTER TABLE fill_events
                MODIFY COLUMN source ENUM('status_transition', 'report', 'authorize_confirmation') NOT NULL
            SQL);
    }
}
