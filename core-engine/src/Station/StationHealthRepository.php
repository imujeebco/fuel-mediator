<?php

declare(strict_types=1);

namespace App\Station;

use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final class StationHealthRepository
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->db))
            ->select([
                's.id', 's.code', 's.name', 's.mode', 's.active',
                'controller_id' => 'c.id', 'controller_name' => 'c.name', 'c.enabled',
                'c.last_ok_at', 'c.last_error_at', 'c.last_error', 'c.consecutive_failures', 'c.circuit_open_until',
            ])
            ->from(['s' => 'stations'])
            ->leftJoin(['c' => 'controllers'], 'c.station_id = s.id AND c.is_primary = 1')
            ->orderBy(['s.name' => SORT_ASC])
            ->all();

        $stations = [];
        foreach ($rows as $row) {
            $stations[] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'mode' => (string) $row['mode'],
                'active' => (bool) $row['active'],
                'controller' => $row['controller_id'] === null ? null : [
                    'id' => (int) $row['controller_id'],
                    'name' => (string) $row['controller_name'],
                    'collector_enabled' => (bool) $row['enabled'],
                    'last_ok_at' => $row['last_ok_at'],
                    'last_error_at' => $row['last_error_at'],
                    'last_error' => $row['last_error'],
                    'consecutive_failures' => (int) $row['consecutive_failures'],
                    'circuit_open_until' => $row['circuit_open_until'],
                ],
            ];
        }

        return $stations;
    }
}
