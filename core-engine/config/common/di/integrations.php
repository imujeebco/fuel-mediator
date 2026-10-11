<?php

declare(strict_types=1);

use App\Environment;
use App\Odoo\JsonRpcOdooReadClient;
use App\Odoo\OdooReadClientInterface;
use App\Shared\Http\CurlHttpTransport;
use App\Shared\Http\HttpTransportInterface;

return [
    HttpTransportInterface::class => CurlHttpTransport::class,

    OdooReadClientInterface::class => static fn(HttpTransportInterface $transport): JsonRpcOdooReadClient
        => new JsonRpcOdooReadClient(
            $transport,
            (string) Environment::get('ODOO_URL'),
            (string) Environment::get('ODOO_DB'),
            (string) Environment::get('ODOO_USER'),
            (string) Environment::get('ODOO_PASSWORD'),
        ),
];
