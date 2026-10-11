<?php

declare(strict_types=1);

use App\Environment;
use App\Odoo\JsonRpcOdooReadClient;
use App\Odoo\OdooReadClientInterface;
use App\Odoo\Relay\OdooRelay;
use App\Odoo\Relay\PumpsRewriter;
use App\Shared\Http\CurlHttpTransport;
use App\Shared\Http\HttpTransportInterface;

return [
    HttpTransportInterface::class => CurlHttpTransport::class,

    OdooRelay::class => [
        '__construct()' => ['odooUrl' => (string) Environment::get('ODOO_URL')],
    ],

    PumpsRewriter::class => [
        '__construct()' => ['publicUrl' => (string) Environment::get('MEDIATOR_PUBLIC_URL')],
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
