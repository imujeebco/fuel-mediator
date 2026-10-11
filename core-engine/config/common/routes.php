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
