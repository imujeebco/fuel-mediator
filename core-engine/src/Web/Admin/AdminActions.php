<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Collector\ConfigSync;
use App\Maintenance\SystemStatus;
use App\Odoo\OdooException;
use App\Odoo\OdooReadClientInterface;
use App\Pts\ControllerLock;
use App\Pts\ControllerRepository;
use App\Pts\PtsException;
use App\Shared\Audit\AuditLog;
use App\Station\Import\StationImporter;
use App\Sync\SyncService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\Flash\FlashInterface;

use function array_sum;
use function count;
use function implode;
use function is_array;
use function is_string;
use function parse_url;
use function sprintf;
use function str_starts_with;

/**
 * Dashboard buttons (POST, CSRF-protected by the UI route group). They change only the mediator's own state:
 * Odoo is read (station import, sales and sessions), controllers are read (configuration), never written.
 * Every action is recorded in the audit log and reported back through a flash message.
 */
final class AdminActions
{
    public const FLASH = 'admin.result';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly OdooReadClientInterface $odoo,
        private readonly StationImporter $importer,
        private readonly SyncService $sync,
        private readonly ControllerRepository $controllers,
        private readonly ConfigSync $configSync,
        private readonly ControllerLock $lock,
        private readonly AuditLog $audit,
        private readonly FlashInterface $flash,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger,
        private readonly SystemStatus $system,
    ) {}

    /** Refresh stations, controllers, pumps and PTS/manual mode from Odoo. */
    public function importStations(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, 'Sync stations from Odoo', function (): string {
            $result = $this->importer->import($this->odoo);
            $this->audit->record('registry', 'odoo', 'imported', 'dashboard', null, data: $result->toArray());

            return sprintf('%d stations, %d controllers, %d pumps, %d nozzles updated from Odoo; %d controller passwords stored encrypted%s.',
                $result->stations, $result->controllers, $result->pumps, $result->nozzles, $result->secretsStored,
                $result->secretsNoKey > 0 ? sprintf(' (%d not stored: MEDIATOR_SECRET_KEY is not set)', $result->secretsNoKey) : '');
        });
    }

    /** Sales, sessions and fill matching for every active station (a few pages per station to stay interactive). */
    public function syncAll(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, 'Sync all stations', function (): string {
            $results = $this->sync->run(null, 6);
            $errors = array_filter($results, static fn(array $r): bool => $r['error'] !== null);
            $this->audit->record('sync', 'all', 'run', 'dashboard', null, data: ['stations' => count($results), 'errors' => count($errors)]);

            return sprintf(
                '%d stations synced: %d new or changed sales, %d sessions.%s',
                count($results),
                array_sum(array_column($results, 'sales')),
                array_sum(array_column($results, 'sessions')),
                $errors === [] ? '' : ' Errors: ' . implode('; ', array_map(static fn(array $r): string => $r['station'] . ': ' . (string) $r['error'], $errors)),
            );
        });
    }

    public function syncStation(ServerRequestInterface $request, #[RouteArgument('id')] int $id): ResponseInterface
    {
        return $this->act($request, 'Sync station', function () use ($id): string {
            $results = $this->sync->run($id, 20);
            $r = $results[0] ?? null;
            if ($r === null) {
                throw new \RuntimeException('Station is inactive or has no Odoo company.');
            }
            $this->audit->record('sync', $id, 'run', 'dashboard', $id, data: $r);
            if ($r['error'] !== null) {
                throw new \RuntimeException($r['error']);
            }

            return sprintf('%s: %d new or changed sales, %d sessions%s.', $r['station'], $r['sales'], $r['sessions'],
                $r['matched'] === null ? '' : sprintf(', fills linked %d, unmatched %d', $r['matched'], (int) $r['unmatched']));
        });
    }

    /** Read pumps, nozzles and grades from the controller (read-only packets). */
    public function syncConfig(ServerRequestInterface $request, #[RouteArgument('id')] int $id): ResponseInterface
    {
        return $this->act($request, 'Read pump setup', function () use ($id): string {
            $row = $this->controllerRow($id);
            try {
                $endpoint = $this->controllers->endpoint($row);
                $stats = $this->lock->run($id, 5.0, fn() => $this->configSync->sync($endpoint));
            } catch (PtsException $e) {
                throw new \RuntimeException("Controller {$row['name']}: [{$e->kind}] {$e->getMessage()}");
            }
            $this->audit->record('controller', $id, 'config_synced', 'dashboard', (int) $row['station_id'], data: $stats);

            return "Pump setup read from {$row['name']}: " . implode(', ', array_map(
                static fn(string $k, mixed $v): string => "$k " . (is_scalar($v) ? (string) $v : '…'),
                array_keys($stats),
                array_values($stats),
            )) . '.';
        });
    }

    /** Start read-only status polling of a controller (D13). */
    public function enableCollection(ServerRequestInterface $request, #[RouteArgument('id')] int $id): ResponseInterface
    {
        return $this->act($request, 'Start collection', function () use ($id): string {
            $row = $this->controllerRow($id);
            $this->controllers->endpoint($row); // fails early when the URL or the password is missing
            if ($row['config_synced_at'] === null) {
                throw new \RuntimeException('Read the pump setup from the controller first.');
            }
            $columns = ['enabled' => 1, 'consecutive_failures' => 0, 'circuit_open_until' => null];
            $this->db->createCommand()->update('controllers', $columns, ['id' => $id])->execute();
            $this->audit->record('controller', $id, 'enabled', 'dashboard', (int) $row['station_id'], data: $columns);

            return "Collection started for {$row['name']}. The collector picks it up on its next pass.";
        });
    }

    public function disableCollection(ServerRequestInterface $request, #[RouteArgument('id')] int $id): ResponseInterface
    {
        return $this->act($request, 'Stop collection', function () use ($id): string {
            $row = $this->controllerRow($id);
            $this->db->createCommand()->update('controllers', ['enabled' => 0], ['id' => $id])->execute();
            $this->audit->record('controller', $id, 'disabled', 'dashboard', (int) $row['station_id']);

            return "Collection stopped for {$row['name']}.";
        });
    }

    /** Apply pending database migrations (hosts without a shell). */
    public function migrate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->act($request, 'Apply migrations', function (): string {
            $applied = $this->system->applyMigrations();
            $this->audit->record('system', 'migrations', 'applied', 'dashboard', null, data: ['applied' => $applied]);

            $names = array_map(static fn(string $class): string => substr($class, (int) strrpos($class, '\\') + 1), $applied);

            return $applied === [] ? 'nothing to apply.' : count($applied) . ' applied: ' . implode(', ', $names) . '.';
        });
    }

    /** @return array<string, mixed> */
    private function controllerRow(int $id): array
    {
        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->db))
            ->select(['c.*', 'utc_offset_minutes' => 's.utc_offset_minutes'])
            ->from(['c' => 'controllers'])
            ->innerJoin(['s' => 'stations'], 's.id = c.station_id')
            ->where(['c.id' => $id])
            ->one();
        if ($row === null) {
            throw new \RuntimeException("Controller #$id not found.");
        }

        return $row;
    }

    /** @param callable(): string $action */
    private function act(ServerRequestInterface $request, string $label, callable $action): ResponseInterface
    {
        try {
            $this->flash->set(self::FLASH, ['tone' => 'ok', 'text' => "$label: " . $action()]);
        } catch (OdooException $e) {
            $this->flash->set(self::FLASH, ['tone' => 'bad', 'text' => "$label failed (Odoo): " . $e->getMessage()]);
        } catch (Throwable $e) {
            $this->logger->warning('Dashboard action failed', ['action' => $label, 'error' => $e->getMessage()]);
            $this->flash->set(self::FLASH, ['tone' => 'bad', 'text' => "$label failed: " . $e->getMessage()]);
        }

        return $this->responseFactory->createResponse(303)->withHeader('Location', self::backTo($request));
    }

    /** Same-site path the form was posted from (never an external URL). */
    private static function backTo(ServerRequestInterface $request): string
    {
        $referer = $request->getHeaderLine('Referer');
        if ($referer === '') {
            return '/';
        }
        $parts = parse_url($referer);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }
        $query = is_array($parts) ? ($parts['query'] ?? '') : '';

        return $query !== '' ? $path . '?' . $query : $path;
    }
}
