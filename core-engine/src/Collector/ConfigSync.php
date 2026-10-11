<?php

declare(strict_types=1);

namespace App\Collector;

use App\Pts\ControllerEndpoint;
use App\Pts\PacketPolicy;
use App\Pts\PtsClient;
use App\Pts\PtsException;
use App\Shared\Audit\AuditLog;
use App\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;
use function is_int;
use function is_string;

/**
 * Reads the controller's own pump/nozzle/grade configuration and makes it authoritative (decision D15):
 * pumps on the controller get `on_controller = 1` (created if Odoo does not know them, `in_odoo = 0`);
 * pumps known only to Odoo get `on_controller = 0` and are never polled.
 */
final class ConfigSync
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PtsClient $pts,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws PtsException
     *
     * @return array{pumps_on_controller: int, pumps_odoo_only: int, nozzles: int, config_identifier: ?string}
     */
    public function sync(ControllerEndpoint $controller): array
    {
        $replies = $this->pts->send($controller, [
            ['Type' => 'GetConfigurationIdentifier'],
            ['Type' => 'GetPumpsConfiguration'],
            ['Type' => 'GetPumpNozzlesConfiguration'],
            ['Type' => 'GetFuelGradesConfiguration'],
        ], PacketPolicy::READ_ONLY);
        $identifier = $replies[0] ?? null;
        $pumps = self::list($replies[1] ?? null, 'Pumps');
        if ($pumps === []) {
            throw new PtsException(PtsException::MALFORMED, 'Controller returned no pump configuration.');
        }
        $grades = [];
        foreach (self::list($replies[3] ?? null, 'FuelGrades') as $grade) {
            $gradeId = $grade['Id'] ?? null;
            $gradeName = $grade['Name'] ?? null;
            if (is_int($gradeId) && is_string($gradeName)) {
                $grades[$gradeId] = $gradeName;
            }
        }
        $gradeIdsByPump = [];
        foreach (self::list($replies[2] ?? null, 'PumpNozzles') as $row) {
            $pumpId = $row['PumpId'] ?? null;
            $gradeIds = $row['FuelGradeIds'] ?? null;
            if (is_int($pumpId) && is_array($gradeIds)) {
                $gradeIdsByPump[$pumpId] = $gradeIds;
            }
        }
        $identifierData = is_array($identifier) ? ($identifier['Data'] ?? null) : null;
        $identifierValue = is_array($identifierData) ? ($identifierData['Id'] ?? null) : null;
        $configId = is_string($identifierValue) ? $identifierValue : null;

        $now = $this->clock->nowForDb();
        /** @var array{pumps_on_controller: int, pumps_odoo_only: int, nozzles: int, config_identifier: ?string} $stats */
        $stats = ['pumps_on_controller' => 0, 'pumps_odoo_only' => 0, 'nozzles' => 0, 'config_identifier' => $configId];

        $this->db->transaction(function () use ($controller, $pumps, $gradeIdsByPump, $grades, $now, $configId, &$stats): void {
            $seen = [];
            foreach ($pumps as $pump) {
                $number = $pump['Id'] ?? null;
                if (!is_int($number)) {
                    continue;
                }
                $port = $pump['Port'] ?? null;
                $address = $pump['Address'] ?? null;
                $port = is_int($port) ? $port : null;
                $address = is_int($address) ? $address : null;
                $seen[] = $number;
                $this->db->createCommand()->upsert(
                    'pumps',
                    [
                        'station_id' => $controller->stationId,
                        'controller_id' => $controller->id,
                        'pump_number' => $number,
                        'name' => 'Pump ' . $number,
                        'on_controller' => 1,
                        'in_odoo' => 0,
                        'controller_port' => $port,
                        'controller_address' => $address,
                        'config_synced_at' => $now,
                    ],
                    [
                        'on_controller' => 1,
                        'controller_port' => $port,
                        'controller_address' => $address,
                        'config_synced_at' => $now,
                    ],
                )->execute();
                $stats['pumps_on_controller']++;

                $pumpId = (int) (new Query($this->db))->select('id')->from('pumps')
                    ->where(['controller_id' => $controller->id, 'pump_number' => $number])->scalar();
                $this->db->createCommand()->update('nozzles', ['on_controller' => 0], ['pump_id' => $pumpId])->execute();

                /** @var mixed $gradeId */
                foreach ($gradeIdsByPump[$number] ?? [] as $index => $gradeId) {
                    if (!is_int($gradeId) || $gradeId <= 0) {
                        continue;
                    }
                    $columns = [
                        'station_id' => $controller->stationId,
                        'pump_id' => $pumpId,
                        'nozzle_number' => (int) $index + 1,
                        'fuel_grade_id' => $gradeId,
                        'fuel_name' => $grades[$gradeId] ?? null,
                        'on_controller' => 1,
                    ];
                    $this->db->createCommand()->upsert('nozzles', $columns, $columns)->execute();
                    $stats['nozzles']++;
                }
            }

            $condition = ['and', ['controller_id' => $controller->id]];
            if ($seen !== []) {
                $condition[] = ['not in', 'pump_number', $seen];
            }
            $stats['pumps_odoo_only'] = $this->db->createCommand()
                ->update('pumps', ['on_controller' => 0, 'config_synced_at' => $now], $condition)
                ->execute();

            $this->db->createCommand()->update(
                'controllers',
                ['config_identifier' => $configId, 'config_synced_at' => $now],
                ['id' => $controller->id],
            )->execute();

            $this->audit->record('controller', $controller->id, 'config_synced', 'collector', $controller->stationId, data: $stats);
        });

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $packet, string $key): array
    {
        $data = is_array($packet) ? ($packet['Data'] ?? null) : null;
        $items = is_array($data) ? ($data[$key] ?? null) : null;
        if (!is_array($items)) {
            return [];
        }
        $rows = [];
        /** @var mixed $item */
        foreach ($items as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $rows[] = $item;
            }
        }

        return $rows;
    }
}
