# FuelPay Mediator — core-engine

Yii3 application (based on `yiisoft/app` 1.4.0) for the FuelPay Mediator. Project docs live in `../docs/`.
Start with [`../docs/progress.md`](../docs/progress.md).

## Local development (Laragon, Windows)

```bash
PHP=/c/Programs/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe
COMPOSER=/c/Programs/laragon/bin/composer/composer.phar

cp .env.example .env            # local MySQL: fuel_mediator, root, blank password
$PHP $COMPOSER install
$PHP yii migrate:up
$PHP yii serve                  # http://localhost:8080
```

`ext-intl` is blocked on this machine by Windows Application Control. Pass `-d display_startup_errors=0` to silence the startup warning.

## Tests

```bash
$PHP vendor/bin/codecept run Unit
$PHP vendor/bin/codecept run Functional
$PHP vendor/bin/codecept run Console
$PHP vendor/bin/psalm
```

The `Web` suite starts `composer serve` itself, so it needs `composer` on `PATH`.
