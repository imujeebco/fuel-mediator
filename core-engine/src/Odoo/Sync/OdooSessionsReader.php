<?php

declare(strict_types=1);

namespace App\Odoo\Sync;

use App\Odoo\OdooReadClientInterface;
use App\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_map;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * Read-only mirror of the POS sessions the mirrored sales belong to (`pos.session` → `pos_sessions`).
 *
 * A session's real opening time cannot be taken from its sales: a sale queued offline on a device keeps its original
 * timestamp, so the earliest sale of a session can be hours before the session opened (seen on 2026-10-12).
 * Sessions already mirrored as closed are not read again.
 */
final class OdooSessionsReader
{
    private const FIELDS = ['id', 'name', 'config_id', 'state', 'start_at', 'stop_at', 'cash_register_balance_start',
        'cash_register_balance_end_real', 'write_date'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
    ) {}

    /** @return int Number of sessions upserted. */
    public function pull(OdooReadClientInterface $odoo, int $stationId, int $lookbackDays = 2): int
    {
        $since = $this->clock->now()->modify("-{$lookbackDays} days")->format('Y-m-d H:i:s.v');
        $closed = (new Query($this->db))->select('odoo_session_id')->from('pos_sessions')
            ->where(['station_id' => $stationId, 'state' => 'closed']);
        /** @var list<string> $ids */
        $ids = (new Query($this->db))->select('odoo_pos_session_id')->distinct()->from('odoo_sales')
            ->where(['station_id' => $stationId])
            ->andWhere(['not', ['odoo_pos_session_id' => null]])
            ->andWhere(['>=', 'sale_created_at', $since])
            ->andWhere(['not in', 'odoo_pos_session_id', $closed])
            ->column();
        if ($ids === []) {
            return 0;
        }

        $rows = $odoo->searchRead('pos.session', [['id', 'in', array_map('intval', $ids)]], self::FIELDS);
        foreach ($rows as $row) {
            $columns = [
                'station_id' => $stationId,
                'odoo_session_id' => (int) $row['id'],
                'odoo_pos_config_id' => self::many2oneId($row['config_id'] ?? null),
                'name' => (string) ($row['name'] ?? ''),
                'state' => (string) ($row['state'] ?? 'unknown'),
                'start_at' => self::str($row['start_at'] ?? null),
                'stop_at' => self::str($row['stop_at'] ?? null),
                'cash_register_balance_start' => self::num($row['cash_register_balance_start'] ?? null),
                'cash_register_balance_end_real' => self::num($row['cash_register_balance_end_real'] ?? null),
                'odoo_write_date' => (string) ($row['write_date'] ?? $this->clock->nowForDb()),
            ];
            $update = $columns;
            unset($update['odoo_session_id']);
            $this->db->createCommand()->upsert('pos_sessions', $columns, $update)->execute();
        }

        return count($rows);
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function num(mixed $value): ?string
    {
        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    private static function many2oneId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_int($value) ? $value : null;
    }
}
