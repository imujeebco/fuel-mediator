<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Dashboard\DashboardQueries;
use App\Maintenance\SystemStatus;
use App\Price\PriceService;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use App\Reconciliation\VolumeReconciler;
use DateTimeZone;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\Flash\FlashInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function in_array;
use function is_array;
use function is_string;

/**
 * Admin dashboard pages (UI route group; local-only on servers, see docs/deployment-production.md).
 */
final class AdminPages
{
    private const WINDOWS = ['shift', 'today', '12h'];

    public function __construct(
        private readonly DashboardQueries $queries,
        private readonly WebViewRenderer $viewRenderer,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly FlashInterface $flash,
        private readonly SystemStatus $system,
        private readonly PriceService $prices,
        private readonly ConnectionInterface $db,
    ) {}

    /** Network: every station from Odoo, with collection status, today's sales and reconciliation where collected. */
    public function network(ServerRequestInterface $request): ResponseInterface
    {
        $showInactive = ($request->getQueryParams()['all'] ?? '') === '1';
        $stations = $this->queries->stations($showInactive);
        $zone = $stations === [] ? new DateTimeZone('+03:00') : $this->queries->zone($stations[0]);
        $today = $this->queries->window($stations[0] ?? ['id' => 0, 'utc_offset_minutes' => 180], 'today');

        $volumes = [];
        foreach ($stations as $station) {
            if ($station['collecting']) {
                $window = $this->queries->window($station, 'today');
                $volumes[(int) $station['id']] = $this->queries->volume((int) $station['id'], $window['from'], $window['to']);
            }
        }

        return $this->render('network', 'network', [
            'stations' => $stations,
            'sales' => $this->queries->salesByStation($today['from']),
            'sessions' => $this->queries->openSessionsByStation(),
            'issues' => $this->queries->issueCountsByStation(),
            'snapshots' => $this->queries->lastSnapshotByStation(),
            'volumes' => $volumes,
            'showInactive' => $showInactive,
            'fmt' => new Fmt($zone),
        ]);
    }

    public function station(ServerRequestInterface $request, #[RouteArgument('id')] int $id): ResponseInterface
    {
        $station = $this->queries->station($id);
        if ($station === null) {
            return $this->responseFactory->createResponse(404);
        }
        $window = $this->queries->window($station, $this->windowKey($request));
        $collecting = (bool) $station['collecting'];

        return $this->render('station', 'station:' . $id, [
            'station' => $station,
            'fmt' => new Fmt($this->queries->zone($station)),
            'window' => $window,
            'collecting' => $collecting,
            'volume' => $collecting ? $this->queries->volume($id, $window['from'], $window['to']) : null,
            'byPump' => $collecting ? $this->queries->volume($id, $window['from'], $window['to'], VolumeReconciler::BY_PUMP) : null,
            'pumps' => $collecting ? $this->queries->pumps($id) : [],
            'fills' => $collecting ? $this->queries->recentFills($id, $window['from']) : [],
            'exceptions' => $this->queries->exceptions($id, $window['from']),
            'salesByGrade' => $this->queries->salesByGrade($id, $window['from']),
            'sessions' => $this->queries->sessions($id, $window['from']),
            'devices' => $this->queries->devices($id, $window['from']),
            'lastSnapshot' => $collecting ? $this->queries->lastSnapshotAt($id) : null,
        ]);
    }

    public function devices(ServerRequestInterface $request): ResponseInterface
    {
        $windowKey = $this->windowKey($request, 'today');
        $query = $request->getQueryParams();
        $only = isset($query['station']) && $query['station'] !== '' ? (int) $query['station'] : null;
        $groups = [];
        $all = $this->queries->stations();
        foreach ($all as $station) {
            if ($only !== null && (int) $station['id'] !== $only) {
                continue;
            }
            $window = $this->queries->window($station, $windowKey);
            $devices = $this->queries->devices((int) $station['id'], $window['from']);
            if ($devices === [] && $only === null) {
                continue; // no activity: keep the network list short
            }
            $groups[] = ['station' => $station, 'fmt' => new Fmt($this->queries->zone($station)), 'window' => $window, 'devices' => $devices];
        }

        return $this->render('devices', 'devices', ['groups' => $groups, 'windowKey' => $windowKey, 'stations' => $all, 'only' => $only]);
    }

    public function issues(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $rawType = $query['type'] ?? null;
        $type = is_string($rawType) ? $rawType : null;
        $stationId = isset($query['station']) && $query['station'] !== '' ? (int) $query['station'] : null;
        $stations = $this->queries->stations();

        return $this->render('issues', 'issues', [
            'issues' => $this->queries->issues($stationId, $type),
            'counts' => $this->queries->issueCounts($stationId),
            'type' => $type,
            'stationId' => $stationId,
            'stations' => $stations,
            'fmt' => new Fmt($stations === [] ? new DateTimeZone('UTC') : $this->queries->zone($stations[0])),
        ]);
    }

    public function traffic(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $minutes = in_array($query['m'] ?? '60', ['15', '60', '240', '1440'], true) ? (int) ($query['m'] ?? 60) : 60;
        $stations = $this->queries->stations();

        return $this->render('traffic', 'traffic', [
            'traffic' => $this->queries->traffic($minutes),
            'minutes' => $minutes,
            'fmt' => new Fmt($stations === [] ? new DateTimeZone('UTC') : $this->queries->zone($stations[0])),
        ]);
    }

    /** Prices: Odoo vs controller vs pumps vs latest sales, per station and grade (D25–D27). */
    public function prices(): ResponseInterface
    {
        $stations = array_values(array_filter($this->queries->stations(), static fn(array $s): bool => $s['controller_id'] !== null));
        $rows = [];
        foreach ($stations as $station) {
            $rows[(int) $station['id']] = $this->prices->compare((int) $station['id']);
        }
        /** @var list<array<string, mixed>> $pushes */
        $pushes = (new Query($this->db))->select(['pp.*', 'station' => 's.name'])->from(['pp' => 'price_pushes'])
            ->innerJoin(['s' => 'stations'], 's.id = pp.station_id')->orderBy(['pp.id' => SORT_DESC])->limit(30)->all();
        /** @var array<int, string> $optedIn */
        $optedIn = (new Query($this->db))->select('price_push_enabled')->from('stations')->indexBy('id')->column();

        return $this->render('prices', 'prices', [
            'stations' => $stations,
            'rows' => $rows,
            'pushes' => $pushes,
            'optedIn' => $optedIn,
            'fmt' => new Fmt($stations === [] ? new DateTimeZone('UTC') : $this->queries->zone($stations[0])),
        ]);
    }

    public function system(): ResponseInterface
    {
        $revisionFile = dirname(__DIR__, 4) . '/REVISION';
        $pending = [];
        try {
            $pending = $this->system->pendingMigrations();
        } catch (\Throwable) {
            // shown by the database check
        }

        return $this->render('system', 'system', [
            'checks' => $this->system->checks(),
            'pending' => $pending,
            'cron' => $this->system->cronLines(),
            'revision' => is_file($revisionFile) ? trim((string) file_get_contents($revisionFile)) : 'development',
        ]);
    }

    private function windowKey(ServerRequestInterface $request, string $default = 'shift'): string
    {
        $value = $request->getQueryParams()['w'] ?? $default;

        return is_string($value) && in_array($value, self::WINDOWS, true) ? $value : $default;
    }

    /** @param array<string, mixed> $params */
    private function render(string $view, string $active, array $params): ResponseInterface
    {
        $flash = $this->flash->get(AdminActions::FLASH);

        return $this->viewRenderer
            ->withLayout(__DIR__ . '/layout.php')
            ->render(__DIR__ . '/views/' . $view, $params + [
                'adminNav' => [
                    'collecting' => array_values(array_filter($this->queries->stations(), static fn(array $s): bool => (bool) $s['collecting'])),
                    'active' => $active,
                    'refresh' => true,
                    'flash' => is_array($flash) ? $flash : null,
                ],
            ]);
    }
}
