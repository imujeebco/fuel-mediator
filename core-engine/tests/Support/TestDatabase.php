<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Environment;
use PDO;
use RuntimeException;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;
use Yiisoft\Db\Mysql\Dsn;

/**
 * Access to the migrated `*_test` schema for integration tests.
 */
final class TestDatabase
{
    private static bool $migrated = false;

    /** Data tables cleared between tests, children first. */
    private const TABLES = [
        'fill_settlements', 'audit_events', 'sync_cursors', 'worker_leases', 'outbox', 'reconciliation_runs', 'discrepancies',
        'match_links', 'sale_observations', 'relay_calls', 'pos_sessions', 'odoo_sales', 'commands',
        'fill_events', 'meter_snapshots', 'pump_status_cache', 'devices', 'nozzles', 'pumps', 'controllers',
        'stations',
    ];

    public static function connection(): ConnectionInterface
    {
        self::assertTestSchema();
        self::migrate();

        return new Connection(
            new Driver(
                new Dsn(
                    host: (string) Environment::get('DB_HOST'),
                    databaseName: (string) Environment::get('DB_NAME'),
                    port: (string) Environment::get('DB_PORT'),
                    options: ['charset' => 'utf8mb4'],
                ),
                (string) Environment::get('DB_USER'),
                (string) Environment::get('DB_PASSWORD'),
                [PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"],
            ),
            new SchemaCache(new ArrayCache()),
        );
    }

    public static function truncate(ConnectionInterface $db): void
    {
        self::assertTestSchema();
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        foreach (self::TABLES as $table) {
            $db->createCommand("TRUNCATE TABLE `$table`")->execute();
        }
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
    }

    /** Applies pending migrations once per test run, in a child process (same code path as production). */
    private static function migrate(): void
    {
        if (self::$migrated) {
            return;
        }
        $command = escapeshellarg(PHP_BINARY) . ' -d display_startup_errors=0 '
            . escapeshellarg(dirname(__DIR__, 2) . '/yii') . ' migrate:up --no-interaction 2>&1';
        exec($command, $output, $code);
        if ($code !== 0) {
            throw new RuntimeException("Test migrations failed:\n" . implode("\n", $output));
        }
        self::$migrated = true;
    }

    private static function assertTestSchema(): void
    {
        if (!str_ends_with((string) Environment::get('DB_NAME'), '_test')) {
            throw new RuntimeException('TestDatabase may only be used with a *_test schema.');
        }
    }
}
