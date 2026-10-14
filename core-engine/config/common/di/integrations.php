<?php

declare(strict_types=1);

use App\Environment;
use App\Odoo\JsonRpcOdooReadClient;
use App\Odoo\OdooReadClientInterface;
use App\Odoo\Relay\OdooRelay;
use App\Odoo\Relay\PumpsRewriter;
use App\Odoo\Relay\SettledFillFilter;
use App\Reconciliation\LiveSettlements;
use App\Shared\Crypto\SecretBox;
use App\Web\Admin\Auth\AdminAuth;
use App\Shared\Http\CurlHttpTransport;
use App\Shared\Http\HttpTransportInterface;
use Yiisoft\Definitions\Reference;

return [
    HttpTransportInterface::class => CurlHttpTransport::class,

    OdooRelay::class => [
        '__construct()' => ['odooUrl' => (string) Environment::get('ODOO_URL')],
    ],

    PumpsRewriter::class => [
        '__construct()' => ['publicUrl' => (string) Environment::get('MEDIATOR_PUBLIC_URL')],
    ],

    // D21: on unless SETTLED_FILL_FILTER=0 (kill switch: devices then see Odoo's unpaid list unchanged).
    // D28 (live settlement by a manual sale on the same pump): on unless SETTLED_FILL_LIVE=0.
    SettledFillFilter::class => [
        '__construct()' => [
            'enabled' => (string) Environment::get('SETTLED_FILL_FILTER') !== '0',
            'live' => (string) Environment::get('SETTLED_FILL_LIVE') !== '0' ? Reference::to(LiveSettlements::class) : null,
        ],
    ],

    // D23: encryption of controller passwords read from Odoo.
    SecretBox::class => [
        '__construct()' => ['base64Key' => (string) Environment::get('MEDIATOR_SECRET_KEY')],
    ],

    AdminAuth::class => [
        '__construct()' => [
            'user' => (string) Environment::get('ADMIN_USER'),
            'passwordHash' => (string) Environment::get('ADMIN_PASSWORD_HASH'),
        ],
    ],

    OdooReadClientInterface::class => static fn(HttpTransportInterface $transport): JsonRpcOdooReadClient
        => new JsonRpcOdooReadClient(
            $transport,
            (string) Environment::get('ODOO_URL'),
            (string) Environment::get('ODOO_DB'),
            (string) Environment::get('ODOO_USER'),
            (string) Environment::get('ODOO_PASSWORD'),
        ),
];
