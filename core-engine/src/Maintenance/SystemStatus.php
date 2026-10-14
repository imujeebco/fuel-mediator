<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Environment;
use App\Shared\Clock;
use App\Shared\Crypto\SecretBox;
use App\Shared\Http\HttpOptions;
use App\Shared\Http\HttpTransportInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\Migrator;
use Yiisoft\Db\Migration\Runner\UpdateRunner;
use Yiisoft\Db\Migration\Service\MigrationService;
use Yiisoft\Db\Query\Query;
use Yiisoft\Injector\Injector;

use function dirname;
use function count;
use function extension_loaded;
use function implode;
use function ini_get;
use function intdiv;
use function is_file;
use function is_writable;
use function sprintf;
use function str_contains;
use function rtrim;
use function version_compare;

/**
 * Health of the installation, for hosts without a shell (CWP): PHP and extensions, files, database and migrations,
 * configuration, Odoo reachability, cron heartbeats. Also applies pending migrations from the browser.
 */
final class SystemStatus
{
    private const EXTENSIONS = ['bcmath', 'curl', 'pdo_mysql', 'sodium', 'mbstring', 'json'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Injector $injector,
        private readonly HttpTransportInterface $transport,
        private readonly SecretBox $box,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<array{group: string, check: string, ok: bool|null, detail: string}> ok null = information only
     */
    public function checks(): array
    {
        $root = dirname(__DIR__, 2);
        $checks = [];
        $add = static function (string $group, string $check, ?bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['group' => $group, 'check' => $check, 'ok' => $ok, 'detail' => $detail];
        };

        $add('PHP', 'Version', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION . ' (' . PHP_SAPI . ')');
        foreach (self::EXTENSIONS as $ext) {
            $add('PHP', "Extension $ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing: enable it in the PHP selector');
        }
        $add('PHP', 'max_execution_time', null, (string) ini_get('max_execution_time') . ' s (relay needs up to 70 s for shift close)');

        $add('Files', 'runtime/ writable', is_writable($root . '/runtime'), $root . '/runtime');
        $add('Files', '.env present', is_file($root . '/.env'), $root . '/.env');

        $env = (string) Environment::get('APP_ENV');
        $add('Configuration', 'APP_ENV', $env === 'prod' ? true : null, $env);
        $add('Configuration', 'APP_DEBUG off', !(bool) Environment::get('APP_DEBUG'), Environment::get('APP_DEBUG') ? 'on: error pages show internals' : 'off');
        $add('Configuration', 'MEDIATOR_SECRET_KEY', $this->box->isConfigured(), $this->box->isConfigured() ? 'set' : 'missing: controller passwords from Odoo cannot be stored (D23)');
        $add('Configuration', 'Admin password', (string) Environment::get('ADMIN_PASSWORD_HASH') !== '', (string) Environment::get('ADMIN_PASSWORD_HASH') !== '' ? 'set' : 'missing: dashboard only reachable from the server itself');
        $public = (string) Environment::get('MEDIATOR_PUBLIC_URL');
        $add('Configuration', 'MEDIATOR_PUBLIC_URL', null, $public === '' ? 'empty: devices keep talking to controllers directly (switch-over not active)' : $public);
        $add('Configuration', 'SETTLED_FILL_FILTER (D21)', null, (string) Environment::get('SETTLED_FILL_FILTER') === '0' ? 'off' : 'on');
        $add('Configuration', 'SETTLED_FILL_LIVE (D28)', null, (string) Environment::get('SETTLED_FILL_LIVE') === '0' ? 'off' : 'on');

        try {
            $version = (string) $this->db->createCommand('SELECT VERSION()')->queryScalar();
            $add('Database', 'Connection', true, $version);
            $pending = $this->pendingMigrations();
            $add('Database', 'Migrations', $pending === [], $pending === [] ? 'up to date' : count($pending) . ' pending: ' . implode(', ', $pending));
        } catch (Throwable $e) {
            $add('Database', 'Connection', false, $e->getMessage());
        }

        $odoo = (string) Environment::get('ODOO_URL');
        if ($odoo === '') {
            $add('Odoo', 'Reachable', false, 'ODOO_URL is empty');
        } else {
            try {
                $r = $this->transport->post(rtrim($odoo, '/') . '/jsonrpc', '{"jsonrpc":"2.0","id":1,"method":"call","params":{"service":"common","method":"version","args":[]}}',
                    ['Content-Type' => 'application/json'], new HttpOptions(timeoutSeconds: 8));
                $add('Odoo', 'Reachable', $r->isSuccessful() && str_contains($r->body, 'server_version'), sprintf('%s · HTTP %d', $odoo, $r->status));
            } catch (Throwable $e) {
                $add('Odoo', 'Reachable', false, $e->getMessage());
            }
            $add('Odoo', 'Read user', (string) Environment::get('ODOO_USER') !== '', (string) Environment::get('ODOO_DB') . ' / ' . ((string) Environment::get('ODOO_USER') !== '' ? 'user set' : 'ODOO_USER missing'));
        }

        try {
            $collector = (new Query($this->db))->select(new Expression('MAX(heartbeat_at)'))->from('worker_leases')->where(['worker' => 'collector'])->scalar();
            $sync = (new Query($this->db))->select(new Expression('MAX(heartbeat_at)'))->from('worker_leases')->where(['worker' => 'sync'])->scalar();
            $collecting = (int) (new Query($this->db))->from('controllers')->where(['enabled' => 1])->count();
            $add('Cron', 'Collector heartbeat', $collecting === 0 ? null : $this->fresh($collector, 180),
                $collecting === 0 ? 'no station collected yet' : $this->ago($collector) . " · $collecting controller(s) collected");
            $add('Cron', 'Sync heartbeat', $this->fresh($sync, 180), $this->ago($sync));
        } catch (Throwable $e) {
            $add('Cron', 'Heartbeats', false, $e->getMessage());
        }

        return $checks;
    }

    /** @return list<string> */
    public function pendingMigrations(): array
    {
        /** @var list<string> */
        return $this->migrationService()->getNewMigrations();
    }

    /** @return list<string> Lines of output. */
    public function applyMigrations(): array
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $service = $this->migrationService();
        $runner = new UpdateRunner(new Migrator($this->db, new NullMigrationInformer()));
        $runner->setIo($io);
        $applied = [];
        foreach ($service->makeMigrations($service->getNewMigrations()) as $i => $migration) {
            $runner->run($migration, $i + 1);
            $applied[] = $migration::class;
        }

        return $applied;
    }

    /** Cron lines for this installation (paths of the running PHP and of the app). */
    public function cronLines(): array
    {
        $php = PHP_BINARY !== '' && !str_contains(PHP_BINARY, 'php-fpm') && !str_contains(PHP_BINARY, 'php-cgi') ? PHP_BINARY : '/usr/local/bin/php';
        $app = dirname(__DIR__, 2);
        $log = dirname($app) . '/logs';

        return [
            "* * * * * cd $app && $php yii collector:run --max-seconds=55 >> $log/collector.log 2>&1",
            "* * * * * cd $app && $php yii sync:run >> $log/sync.log 2>&1",
        ];
    }

    private function migrationService(): MigrationService
    {
        $service = new MigrationService($this->db, $this->injector, new Migrator($this->db, new NullMigrationInformer()));
        $service->setSourceNamespaces(['App\\Migration']);
        $service->setNewMigrationNamespace('App\\Migration');

        return $service;
    }

    private function fresh(mixed $utc, int $seconds): bool
    {
        return $utc !== null && $utc !== false
            && $this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $utc . ' UTC'))->getTimestamp() <= $seconds;
    }

    private function ago(mixed $utc): string
    {
        if ($utc === null || $utc === false) {
            return 'never ran';
        }
        $s = $this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $utc . ' UTC'))->getTimestamp();

        return 'last run ' . ($s < 120 ? "$s s" : intdiv($s, 60) . ' min') . ' ago';
    }
}
