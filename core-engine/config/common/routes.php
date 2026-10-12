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
        ->middleware(Web\Admin\Auth\AdminAuthMiddleware::class)
        ->routes(
            // Login (the only pages open without a session).
            Route::get('/login')
                ->action([Web\Admin\Auth\LoginPages::class, 'form'])
                ->name('login'),
            Route::post('/login')
                ->action([Web\Admin\Auth\LoginPages::class, 'login'])
                ->name('login/submit'),
            Route::methods(['GET', 'POST'], '/login/password-hash')
                ->action([Web\Admin\Auth\LoginPages::class, 'passwordHash'])
                ->name('login/password-hash'),
            Route::post('/logout')
                ->action([Web\Admin\Auth\LoginPages::class, 'logout'])
                ->name('logout'),
            Route::get('/system')
                ->action([Web\Admin\AdminPages::class, 'system'])
                ->name('admin/system'),
            Route::post('/actions/system/migrate')
                ->action([Web\Admin\AdminActions::class, 'migrate'])
                ->name('admin/action/migrate'),
            // Admin dashboard (docs/deployment-production.md: local-only on servers).
            Route::get('/')
                ->action([Web\Admin\AdminPages::class, 'network'])
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
            // Dashboard actions (POST + CSRF). Odoo and controllers are only read.
            Route::post('/actions/stations/import')
                ->action([Web\Admin\AdminActions::class, 'importStations'])
                ->name('admin/action/import'),
            Route::post('/actions/sync')
                ->action([Web\Admin\AdminActions::class, 'syncAll'])
                ->name('admin/action/sync'),
            Route::post('/actions/station/{id:\d+}/sync')
                ->action([Web\Admin\AdminActions::class, 'syncStation'])
                ->name('admin/action/station-sync'),
            Route::post('/actions/controller/{id:\d+}/config')
                ->action([Web\Admin\AdminActions::class, 'syncConfig'])
                ->name('admin/action/controller-config'),
            Route::post('/actions/controller/{id:\d+}/enable')
                ->action([Web\Admin\AdminActions::class, 'enableCollection'])
                ->name('admin/action/controller-enable'),
            Route::post('/actions/controller/{id:\d+}/disable')
                ->action([Web\Admin\AdminActions::class, 'disableCollection'])
                ->name('admin/action/controller-disable'),
        ),
];
