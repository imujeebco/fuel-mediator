# Local development on Laragon (Windows)

Verified 2026-10-11 on PHP 8.3.33, MySQL 8.4.3 and Composer 2.10.2.

## One-time setup

```bash
cd fuel-mediator/core-engine
PHP=/c/Programs/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe
COMPOSER=/c/Programs/laragon/bin/composer/composer.phar
MYSQL=/c/Programs/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe

$MYSQL -uroot -e "CREATE DATABASE IF NOT EXISTS fuel_mediator CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
                  CREATE DATABASE IF NOT EXISTS fuel_mediator_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
cp .env.example .env        # then fill in ODOO_* (read-only Odoo user); .env is gitignored
$PHP $COMPOSER install
$PHP yii migrate:up
$PHP yii station:import --dry-run    # reads Odoo, writes nothing
$PHP yii station:import              # imports the registry; controllers are created DISABLED
$PHP yii serve                       # http://localhost:8080/health
```

## Notes

- Windows Application Control blocks `ext-intl`. Add `-d display_startup_errors=0` to silence the startup warning.
- `php` and `composer` are not on the Git Bash `PATH`. Always call them by full path, as above.
- Route caching is off in `dev` and `test` (`config/environments/*/params.php`). In `prod`, compiled routes are cached under `runtime/cache`, which **must be cleared on every deploy**.
- Tests always use `fuel_mediator_test`:
  - `tests/bootstrap.php` refuses to run against any schema whose name doesn't end in `_test`.
  - It also blanks `ODOO_URL`, so tests can never reach Odoo.
- Workers (from Phase 3a onwards) run as long-lived `yii` console commands, each in its own terminal window. Production uses systemd instead (see `deployment-production.md`, Phase 7).
