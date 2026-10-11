<?php

declare(strict_types=1);

use App\Console;

return [
    'hello' => Console\HelloCommand::class,
    'station:import' => Console\StationImportCommand::class,
];
