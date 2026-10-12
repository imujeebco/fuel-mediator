<?php

declare(strict_types=1);

use App\Web;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;
use Yiisoft\Session\SessionMiddleware;

return [
    // Machine endpoints: stateless, no session, no CSRF.
    Group::create()
        ->routes(
            Route::get('/health')
                ->action(Web\Health\HealthAction::class)
                ->name('health'),
            Route::get('/health/stations')
                ->action(Web\Health\StationsHealthAction::class)
                ->name('health/stations'),
            // PTS-compatible endpoint for FuelPay (docs/api-compatibility-matrix.md §1).
            Route::post('/stations/{backend:\d+}/jsonPTS')
                ->action(Web\Pts\JsonPtsAction::class)
                ->name('pts/jsonpts'),
            // Odoo-compatible relay for FuelPay (docs/api-compatibility-matrix.md §2).
            Route::post('/odoo/jsonrpc')
                ->action(Web\Odoo\JsonRpcRelayAction::class)
                ->name('odoo/jsonrpc'),
        ),

    // Monitoring UI.
    Group::create()
        ->middleware(SessionMiddleware::class)
        ->middleware(CsrfTokenMiddleware::class)
        ->routes(
            // Admin dashboard (docs/deployment-production.md: local-only on servers).
            Route::get('/')
                ->action([Web\Admin\AdminPages::class, 'overview'])
                ->name('home'),
            Route::get('/station/{id:\d+}')
                ->action([Web\Admin\AdminPages::class, 'station'])
                ->name('admin/station'),
            Route::get('/devices')
                ->action([Web\Admin\AdminPages::class, 'devices'])
                ->name('admin/devices'),
            Route::get('/issues')
                ->action([Web\Admin\AdminPages::class, 'issues'])
                ->name('admin/issues'),
            Route::get('/traffic')
                ->action([Web\Admin\AdminPages::class, 'traffic'])
                ->name('admin/traffic'),
        ),
];
