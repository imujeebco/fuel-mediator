<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Dashboard\DashboardQueries;
use App\Reconciliation\VolumeReconciler;
use DateTimeZone;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function in_array;
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
    ) {}

    public function overview(ServerRequestInterface $request): ResponseInterface
    {
        $windowKey = $this->windowKey($request);
        $cards = [];
        foreach ($this->queries->servedStations() as $station) {
            $id = (int) $station['id'];
            $window = $this->queries->window($station, $windowKey);
            $devices = $this->queries->devices($id, $window['from']);
            $exceptions = $this->queries->exceptions($id, $window['from']);
            $cards[] = [
                'station' => $station,
                'fmt' => new Fmt($this->queries->zone($station)),
                'window' => $window,
                'volume' => $this->queries->volume($id, $window['from'], $window['to']),
                'lastSnapshot' => $this->queries->lastSnapshotAt($id),
                'pumps' => $this->queries->pumps($id),
                'devicesOnline' => count(array_filter($devices, static fn(array $d): bool => (bool) $d['online'])),
                'devicesViaMediator' => count(array_filter($devices, static fn(array $d): bool => $d['via_mediator'] !== null)),
                'devicesTotal' => count($devices),
                'exceptions' => $exceptions,
                'issueCounts' => $this->queries->issueCounts($id),
            ];
        }

        return $this->render('overview', 'overview', ['cards' => $cards, 'windowKey' => $windowKey]);
    }

    public function station(ServerRequestInterface $request, #[RouteArgument('id')] int $id): ResponseInterface
    {
        $station = $this->queries->station($id);
        if ($station === null) {
            return $this->responseFactory->createResponse(404);
        }
        $window = $this->queries->window($station, $this->windowKey($request));

        return $this->render('station', 'station:' . $id, [
            'station' => $station,
            'fmt' => new Fmt($this->queries->zone($station)),
            'window' => $window,
            'volume' => $this->queries->volume($id, $window['from'], $window['to']),
            'byPump' => $this->queries->volume($id, $window['from'], $window['to'], VolumeReconciler::BY_PUMP),
            'pumps' => $this->queries->pumps($id),
            'fills' => $this->queries->recentFills($id, $window['from']),
            'exceptions' => $this->queries->exceptions($id, $window['from']),
            'lastSnapshot' => $this->queries->lastSnapshotAt($id),
        ]);
    }

    public function devices(ServerRequestInterface $request): ResponseInterface
    {
        $windowKey = $this->windowKey($request, 'today');
        $groups = [];
        foreach ($this->queries->servedStations() as $station) {
            $window = $this->queries->window($station, $windowKey);
            $groups[] = [
                'station' => $station,
                'fmt' => new Fmt($this->queries->zone($station)),
                'window' => $window,
                'devices' => $this->queries->devices((int) $station['id'], $window['from']),
            ];
        }

        return $this->render('devices', 'devices', ['groups' => $groups, 'windowKey' => $windowKey]);
    }

    public function issues(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $rawType = $query['type'] ?? null;
        $type = is_string($rawType) ? $rawType : null;
        $stationId = isset($query['station']) && $query['station'] !== '' ? (int) $query['station'] : null;
        $stations = $this->queries->servedStations();

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
        $stations = $this->queries->servedStations();

        return $this->render('traffic', 'traffic', [
            'traffic' => $this->queries->traffic($minutes),
            'minutes' => $minutes,
            'fmt' => new Fmt($stations === [] ? new DateTimeZone('UTC') : $this->queries->zone($stations[0])),
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
        return $this->viewRenderer
            ->withLayout(__DIR__ . '/layout.php')
            ->render(__DIR__ . '/views/' . $view, $params + [
                'adminNav' => ['stations' => $this->queries->servedStations(), 'active' => $active, 'refresh' => true],
            ]);
    }
}
