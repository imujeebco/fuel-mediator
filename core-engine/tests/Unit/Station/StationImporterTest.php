<?php

declare(strict_types=1);

namespace App\Tests\Unit\Station;

use App\Shared\Audit\AuditLog;
use App\Shared\Clock;
use App\Shared\Log\Redactor;
use App\Station\Import\StationImporter;
use App\Tests\Support\Fake\FakeOdooReadClient;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Integration test against the `fuel_mediator_test` schema.
 */
final class StationImporterTest extends Unit
{
    private ConnectionInterface $db;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
    }

    public function testImportsStationsControllersPumpsAndConfiguredNozzlesOnly(): void
    {
        $result = $this->importer()->import($this->odoo());

        assertSame(3, $result->stations);
        assertSame(3, $result->controllers);
        assertSame(3, $result->pumps);
        assertSame(2, $result->nozzles);
        assertSame(4, $result->skippedNozzles, 'unconfigured (grade 0) nozzles are skipped');
        assertSame(1, $result->skippedPumps, 'pump of an unknown backend is skipped');

        assertSame('pts', $this->scalar('SELECT mode FROM stations WHERE odoo_station_id = 1'));
        assertSame('manual', $this->scalar('SELECT mode FROM stations WHERE odoo_station_id = 56'));
        assertSame('pts', $this->scalar('SELECT mode FROM stations WHERE odoo_station_id = 5'));
        assertSame('0', (string) $this->scalar('SELECT SUM(enabled) FROM controllers'), 'new controllers must be disabled');
        assertSame('1', (string) $this->scalar('SELECT is_primary FROM controllers WHERE odoo_backend_config_id = 34'));
        assertSame('0', (string) $this->scalar('SELECT is_primary FROM controllers WHERE odoo_backend_config_id = 14'));
    }

    /** Test scenario 3 (foundation): stations sharing an Odoo code stay separate records. */
    public function testStationsWithDuplicateOdooCodesAreNotMerged(): void
    {
        $this->importer()->import($this->odoo());

        assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM stations WHERE code = '09'"));
        assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM stations'));
    }

    public function testReimportIsIdempotentAndPreservesOperatorSettings(): void
    {
        $importer = $this->importer();
        $importer->import($this->odoo());
        $this->db->createCommand()->update(
            'controllers',
            ['enabled' => 1, 'poll_interval_ms' => 15000],
            ['odoo_backend_config_id' => 2],
        )->execute();

        $importer->import($this->odoo());

        assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM stations'));
        assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM controllers'));
        assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM pumps'));
        assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM nozzles'));
        assertSame('1', (string) $this->scalar('SELECT enabled FROM controllers WHERE odoo_backend_config_id = 2'));
        assertSame('15000', (string) $this->scalar('SELECT poll_interval_ms FROM controllers WHERE odoo_backend_config_id = 2'));
        assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE event = 'station_import'"));
    }

    public function testDryRunWritesNothing(): void
    {
        $result = $this->importer()->import($this->odoo(), dryRun: true);

        assertTrue($result->dryRun);
        assertSame(3, $result->stations);
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM stations'));
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM audit_events'));
    }

    /** Test scenario 11: a failure mid-import rolls back every change. */
    public function testFailureRollsBackTheWholeImport(): void
    {
        $odoo = $this->odoo();
        $odoo->set('pts.petrol.pump', [
            ['id' => 1, 'name' => str_repeat('P', 200), 'active' => true, 'backend_config_id' => [2, 'Station 11'], 'pump_number' => 1],
        ]);

        try {
            $this->importer()->import($odoo);
            self::fail('Expected a database error for an over-long pump name');
        } catch (Throwable) {
            // expected
        }

        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM stations'));
        assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM controllers'));
    }

    private function importer(): StationImporter
    {
        return new StationImporter($this->db, new AuditLog($this->db, new Clock(), new Redactor()));
    }

    private function scalar(string $sql): mixed
    {
        return $this->db->createCommand($sql)->queryScalar();
    }

    private function odoo(): FakeOdooReadClient
    {
        return new FakeOdooReadClient([
            'pts.station' => [
                ['id' => 1, 'name' => 'Fuel Station 11', 'code' => '11', 'active' => true, 'company_id' => [4, 'F011'], 'active_backend_config_id' => [2, 'Station 11']],
                ['id' => 5, 'name' => 'Fuel Station 09', 'code' => '09', 'active' => true, 'company_id' => [3, 'F009'], 'active_backend_config_id' => [34, 'Station 09']],
                ['id' => 56, 'name' => 'Fuel Station 38', 'code' => '09', 'active' => true, 'company_id' => [44, 'F038'], 'active_backend_config_id' => false],
            ],
            'pts.backend.config' => [
                ['id' => 2, 'name' => 'Station 11', 'active' => true, 'station_id' => [1, 'Fuel Station 11'], 'integration_mode' => 'direct',
                    'direct_pts_url' => 'https://station11.example/jsonPTS', 'backend_url' => false, 'direct_auth_mode' => 'digest',
                    'direct_verify_ssl' => false, 'username' => 'admin', 'cfg_utc_offset' => 180],
                ['id' => 14, 'name' => 'Station 09 (old)', 'active' => false, 'station_id' => [5, 'Fuel Station 09'], 'integration_mode' => 'direct',
                    'direct_pts_url' => 'https://old.example/jsonPTS', 'backend_url' => false, 'direct_auth_mode' => 'digest',
                    'direct_verify_ssl' => false, 'username' => 'admin', 'cfg_utc_offset' => 0],
                ['id' => 34, 'name' => 'Station 09', 'active' => true, 'station_id' => [5, 'Fuel Station 09'], 'integration_mode' => 'direct',
                    'direct_pts_url' => 'https://station09.example/jsonPTS', 'backend_url' => false, 'direct_auth_mode' => 'digest',
                    'direct_verify_ssl' => false, 'username' => 'admin', 'cfg_utc_offset' => 180],
            ],
            'pts.petrol.pump' => [
                ['id' => 1, 'name' => 'Pump 1', 'active' => true, 'backend_config_id' => [2, 'Station 11'], 'pump_number' => 1],
                ['id' => 2, 'name' => 'Pump 2', 'active' => true, 'backend_config_id' => [2, 'Station 11'], 'pump_number' => 2],
                ['id' => 3, 'name' => 'Pump 1', 'active' => true, 'backend_config_id' => [34, 'Station 09'], 'pump_number' => 1],
                ['id' => 4, 'name' => 'Ghost', 'active' => true, 'backend_config_id' => [999, 'Unknown'], 'pump_number' => 1],
            ],
            'pts.petrol.nozzle' => [
                ['id' => 1, 'pump_id' => [1, 'Pump 1'], 'nozzle_number' => 1, 'fuel_grade_id' => 1, 'fuel_name' => 'A 91'],
                ['id' => 2, 'pump_id' => [1, 'Pump 1'], 'nozzle_number' => 2, 'fuel_grade_id' => 0, 'fuel_name' => false],
                ['id' => 3, 'pump_id' => [2, 'Pump 2'], 'nozzle_number' => 1, 'fuel_grade_id' => 2, 'fuel_name' => 'A 95'],
                ['id' => 4, 'pump_id' => [2, 'Pump 2'], 'nozzle_number' => 2, 'fuel_grade_id' => 0, 'fuel_name' => false],
                ['id' => 5, 'pump_id' => [3, 'Pump 1'], 'nozzle_number' => 1, 'fuel_grade_id' => 0, 'fuel_name' => false],
                ['id' => 6, 'pump_id' => [4, 'Ghost'], 'nozzle_number' => 1, 'fuel_grade_id' => 1, 'fuel_name' => 'A 91'],
            ],
        ]);
    }
}
