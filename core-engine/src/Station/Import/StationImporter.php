<?php

declare(strict_types=1);

namespace App\Station\Import;

use App\Odoo\OdooReadClientInterface;
use App\Shared\Audit\AuditLog;
use App\Shared\Clock;
use App\Shared\Crypto\SecretBox;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function trim;

/**
 * Imports stations, PTS controllers (`pts.backend.config`), pumps and nozzles from Odoo (read-only) into the
 * mediator registry. Idempotent: re-running updates rows in place via natural keys.
 *
 * Safety: newly created controllers are DISABLED (`enabled = 0`); nothing polls a controller until an operator
 * enables it. Existing `enabled` flags and per-controller tuning are never overwritten.
 */
final class StationImporter
{
    private const DEFAULT_UTC_OFFSET_MINUTES = 180;
    private const ALL_RECORDS = ['active_test' => false];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditLog $audit,
        private readonly SecretBox $box,
        private readonly Clock $clock,
    ) {}

    public function import(OdooReadClientInterface $odoo, bool $dryRun = false): ImportResult
    {
        $stations = $odoo->searchRead(
            'pts.station',
            [],
            ['id', 'name', 'code', 'active', 'company_id', 'active_backend_config_id'],
            context: self::ALL_RECORDS,
        );
        $backends = $odoo->searchRead(
            'pts.backend.config',
            [],
            [
                'id', 'name', 'active', 'station_id', 'integration_mode', 'direct_pts_url', 'backend_url',
                // `password` is stored encrypted only (D23); it never reaches logs, audit data or the import result.
                'direct_auth_mode', 'direct_verify_ssl', 'username', 'password', 'cfg_utc_offset',
            ],
            context: self::ALL_RECORDS,
        );
        $pumps = $odoo->searchRead(
            'pts.petrol.pump',
            [],
            ['id', 'name', 'active', 'backend_config_id', 'pump_number'],
            context: self::ALL_RECORDS,
        );
        $nozzles = $odoo->searchRead(
            'pts.petrol.nozzle',
            [],
            ['id', 'pump_id', 'nozzle_number', 'fuel_grade_id', 'fuel_name'],
        );

        $result = new ImportResult();
        $backendsByStation = [];
        foreach ($backends as $backend) {
            $stationId = self::many2oneId($backend['station_id'] ?? null);
            if ($stationId !== null) {
                $backendsByStation[$stationId][] = $backend;
            }
        }

        $apply = function () use ($stations, $backendsByStation, $pumps, $nozzles, $result): void {
            $controllerIdsByBackend = [];
            foreach ($stations as $station) {
                $odooStationId = (int) $station['id'];
                $stationBackends = $backendsByStation[$odooStationId] ?? [];
                $primary = self::primaryBackend($station, $stationBackends);

                $stationId = $this->upsertStation($station, $primary);
                $result->stations++;
                $result->stationsDetail[] = [
                    'odoo_id' => $odooStationId,
                    'name' => (string) $station['name'],
                    'active' => (bool) ($station['active'] ?? true),
                    'mode' => self::hasPtsUrl($primary) ? 'pts' : 'manual',
                ];

                foreach ($stationBackends as $backend) {
                    $isPrimary = $primary !== null && $primary['id'] === $backend['id'];
                    $controllerIdsByBackend[(int) $backend['id']] = $this->upsertController($stationId, $backend, $isPrimary, $result);
                    $result->controllers++;
                }
            }

            $pumpIdsByOdooPump = [];
            foreach ($pumps as $pump) {
                $backendId = self::many2oneId($pump['backend_config_id'] ?? null);
                $controllerId = $backendId === null ? null : ($controllerIdsByBackend[$backendId] ?? null);
                if ($controllerId === null || !is_int($pump['pump_number'] ?? null) || $pump['pump_number'] <= 0) {
                    $result->skippedPumps++;
                    continue;
                }
                $pumpIdsByOdooPump[(int) $pump['id']] = $this->upsertPump($controllerId, $pump);
                $result->pumps++;
            }

            foreach ($nozzles as $nozzle) {
                $odooPumpId = self::many2oneId($nozzle['pump_id'] ?? null);
                $pumpId = $odooPumpId === null ? null : ($pumpIdsByOdooPump[$odooPumpId] ?? null);
                $rawGrade = $nozzle['fuel_grade_id'] ?? null;
                $grade = is_int($rawGrade) ? $rawGrade : 0;
                // Odoo pre-creates 6 nozzles per pump; only configured ones (with a fuel grade) are real.
                if ($pumpId === null || $grade <= 0 || !is_int($nozzle['nozzle_number'] ?? null)) {
                    $result->skippedNozzles++;
                    continue;
                }
                $this->upsertNozzle($pumpId, $nozzle, $grade);
                $result->nozzles++;
            }

            $this->audit->record('registry', 'odoo', 'station_import', 'console:station:import', data: $result->toArray());
        };

        if ($dryRun) {
            $transaction = $this->db->beginTransaction();
            try {
                $apply();
            } finally {
                $transaction->rollBack();
            }
            $result->dryRun = true;
        } else {
            $this->db->transaction(static fn() => $apply());
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $station
     * @param array<string, mixed>|null $primary
     */
    private function upsertStation(array $station, ?array $primary): int
    {
        $odooStationId = (int) $station['id'];
        $code = self::stringOrNull($station['code'] ?? null) ?? 'S' . $odooStationId;
        $rawOffset = $primary === null ? null : ($primary['cfg_utc_offset'] ?? null);
        $utcOffset = is_int($rawOffset) && $rawOffset !== 0 ? $rawOffset : self::DEFAULT_UTC_OFFSET_MINUTES;

        $columns = [
            'code' => $code,
            'name' => (string) $station['name'],
            'odoo_station_id' => $odooStationId,
            'odoo_company_id' => self::many2oneId($station['company_id'] ?? null),
            'odoo_company_name' => self::many2oneName($station['company_id'] ?? null),
            'mode' => self::hasPtsUrl($primary) ? 'pts' : 'manual',
            'utc_offset_minutes' => $utcOffset,
            'active' => ($station['active'] ?? true) ? 1 : 0,
        ];
        $this->db->createCommand()->upsert('stations', $columns, $columns)->execute();

        return (int) (new Query($this->db))->select('id')->from('stations')
            ->where(['odoo_station_id' => $odooStationId])->scalar();
    }

    /**
     * @param array<string, mixed> $backend
     */
    private function upsertController(int $stationId, array $backend, bool $isPrimary, ImportResult $result): int
    {
        $backendId = (int) $backend['id'];
        $mode = (string) ($backend['integration_mode'] ?? 'direct');
        $url = $mode === 'backend' ? self::stringOrNull($backend['backend_url'] ?? null) : self::stringOrNull($backend['direct_pts_url'] ?? null);
        $authMode = (string) ($backend['direct_auth_mode'] ?? 'digest');

        $synced = [
            'station_id' => $stationId,
            'name' => (string) $backend['name'],
            'is_primary' => $isPrimary ? 1 : 0,
            'url' => $url,
            'auth_mode' => in_array($authMode, ['digest', 'basic', 'none'], true) ? $authMode : 'digest',
            'username' => self::stringOrNull($backend['username'] ?? null),
            'verify_ssl' => ($backend['direct_verify_ssl'] ?? false) ? 1 : 0,
            'odoo_active' => ($backend['active'] ?? true) ? 1 : 0,
        ];
        // `enabled` and tuning columns are only set on insert (defaults: disabled).
        $this->db->createCommand()->upsert(
            'controllers',
            $synced + ['odoo_backend_config_id' => $backendId, 'enabled' => 0],
            $synced,
        )->execute();

        $controllerId = (int) (new Query($this->db))->select('id')->from('controllers')
            ->where(['odoo_backend_config_id' => $backendId])->scalar();
        $this->storePassword($controllerId, $backend['password'] ?? null, $result);

        return $controllerId;
    }

    /**
     * D23: keep the controller password from Odoo, encrypted. Rewritten only when it changed in Odoo.
     */
    private function storePassword(int $controllerId, mixed $password, ImportResult $result): void
    {
        if (!is_string($password) || $password === '') {
            return;
        }
        if (!$this->box->isConfigured()) {
            $result->secretsNoKey++;
            return;
        }
        $current = (new Query($this->db))->select('secret_encrypted')->from('controllers')->where(['id' => $controllerId])->scalar();
        if (is_string($current) && $this->box->decrypt($current) === $password) {
            $result->secretsStored++;
            return;
        }
        $this->db->createCommand()->update('controllers', [
            'secret_encrypted' => $this->box->encrypt($password),
            'secret_updated_at' => $this->clock->nowForDb(),
        ], ['id' => $controllerId])->execute();
        $result->secretsStored++;
    }

    /**
     * @param array<string, mixed> $pump
     */
    private function upsertPump(int $controllerId, array $pump): int
    {
        $stationId = (int) (new Query($this->db))->select('station_id')->from('controllers')
            ->where(['id' => $controllerId])->scalar();
        $number = (int) $pump['pump_number'];
        $columns = [
            'station_id' => $stationId,
            'controller_id' => $controllerId,
            'pump_number' => $number,
            'odoo_pump_id' => (int) $pump['id'],
            'name' => self::stringOrNull($pump['name'] ?? null),
            'active' => ($pump['active'] ?? true) ? 1 : 0,
        ];
        $this->db->createCommand()->upsert('pumps', $columns, $columns)->execute();

        return (int) (new Query($this->db))->select('id')->from('pumps')
            ->where(['controller_id' => $controllerId, 'pump_number' => $number])->scalar();
    }

    /**
     * @param array<string, mixed> $nozzle
     */
    private function upsertNozzle(int $pumpId, array $nozzle, int $grade): void
    {
        $stationId = (int) (new Query($this->db))->select('station_id')->from('pumps')
            ->where(['id' => $pumpId])->scalar();
        $columns = [
            'station_id' => $stationId,
            'pump_id' => $pumpId,
            'nozzle_number' => (int) $nozzle['nozzle_number'],
            'fuel_grade_id' => $grade,
            'fuel_name' => self::stringOrNull($nozzle['fuel_name'] ?? null),
            'odoo_nozzle_id' => (int) $nozzle['id'],
        ];
        $this->db->createCommand()->upsert('nozzles', $columns, $columns)->execute();
    }

    /**
     * The station's `active_backend_config_id` when it is active, otherwise its first active backend.
     *
     * @param array<string, mixed> $station
     * @param list<array<string, mixed>> $backends
     *
     * @return array<string, mixed>|null
     */
    private static function primaryBackend(array $station, array $backends): ?array
    {
        $preferred = self::many2oneId($station['active_backend_config_id'] ?? null);
        foreach ($backends as $backend) {
            if ($backend['id'] === $preferred && ($backend['active'] ?? false)) {
                return $backend;
            }
        }
        foreach ($backends as $backend) {
            if ($backend['active'] ?? false) {
                return $backend;
            }
        }

        return null;
    }

    /** Decision D9: a station is a PTS station when its active PTS configuration has a controller URL. */
    private static function hasPtsUrl(?array $backend): bool
    {
        if ($backend === null) {
            return false;
        }
        $mode = (string) ($backend['integration_mode'] ?? 'direct');
        $url = $mode === 'backend' ? ($backend['backend_url'] ?? null) : ($backend['direct_pts_url'] ?? null);

        return is_string($url) && trim($url) !== '';
    }

    /** Odoo returns many2one values as `[id, display_name]` or `false`. */
    private static function many2oneId(mixed $value): ?int
    {
        $id = is_array($value) ? ($value[0] ?? null) : null;

        return is_int($id) ? $id : null;
    }

    private static function many2oneName(mixed $value): ?string
    {
        $name = is_array($value) ? ($value[1] ?? null) : null;

        return is_string($name) ? $name : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
