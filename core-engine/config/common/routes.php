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
            Route::get('/')
                ->action(Web\HomePage\Action::class)
                ->name('home'),
        ),
];
