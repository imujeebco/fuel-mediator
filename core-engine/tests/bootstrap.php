<?php

declare(strict_types=1);

// Tests always run against the isolated test schema, never the dev database.
// Explicit environment variables (CI) still take precedence.
foreach (['APP_ENV' => 'test', 'DB_NAME' => 'fuel_mediator_test'] as $name => $default) {
    if (getenv($name) === false) {
        putenv("$name=$default");
    }
}
if (!str_ends_with((string) getenv('DB_NAME'), '_test')) {
    throw new RuntimeException('Refusing to run tests: DB_NAME must end with "_test".');
}
// Never let tests reach a real Odoo instance.
putenv('ODOO_URL=');

App\Environment::prepare();
