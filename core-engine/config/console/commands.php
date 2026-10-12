<?php

declare(strict_types=1);

use App\Console;

return [
    'hello' => Console\HelloCommand::class,
    'station:import' => Console\StationImportCommand::class,
    'controller' => Console\ControllerCommand::class,
    'collector:run' => Console\CollectorRunCommand::class,
    'odoo:pull-sales' => Console\OdooPullSalesCommand::class,
    'recon:volume' => Console\ReconVolumeCommand::class,
    'recon:fills' => Console\ReconFillsCommand::class,
    'sync:run' => Console\SyncRunCommand::class,
    'secret:generate-key' => Console\SecretGenerateKeyCommand::class,
];
