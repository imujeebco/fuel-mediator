# Phase 0 — Environment Report

Collected: 2026-10-11 on the Windows 11 Pro development machine. All values were **observed**, not assumed.
No secrets are reproduced in this file.

## 1. Toolchain

| Component | Observed | Notes / implications |
|---|---|---|
| Laragon | `C:\Programs\laragon` (`AutoVirtualHosts` on, `www\` contains only `index.php`) | `fuel-mediator` is **not** under `www\`; a manual vhost (or `php -S` / `./yii serve`) is needed in Phase 2. |
| PHP | 8.3.33 ZTS x64, `C:\Programs\laragon\bin\php\php-8.3.33-Win32-vs16-x64`, not on the Git-Bash `PATH` | Extensions present: `pdo_mysql`, `mysqli`, `mbstring`, `openssl`, `curl`, `sodium`, `bcmath`, `sqlite3`, `pdo_sqlite`, `fileinfo`, `gd`, `xml*`. |
| `ext-intl` | **Blocked** — "An Application Control policy has blocked this file" (`php_intl.dll`) | Every PHP invocation prints a startup warning. `yiisoft/app-api` 1.4.0 does **not** require intl. Avoid packages that hard-require it, or ask IT to allow the DLL. |
| php.ini | `memory_limit=512M`, `max_execution_time=36000`, `disable_functions=` empty, `date.timezone` unset → **UTC** | Mediator must set the timezone explicitly; store all timestamps in UTC. |
| Composer | 2.10.2 at `C:\Programs\laragon\bin\composer\composer.phar` (not on Git-Bash `PATH`) | Packagist reachable (HTTP 200). |
| MySQL | 8.4.3 (`mysql-8.4.3-winx64`), port 3306, root with blank password | Only system schemas exist — **no existing business databases** at risk. |
| MySQL durability | `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, `log_bin=1`, `REPEATABLE-READ`, default engine InnoDB, `utf8mb4_0900_ai_ci`, `max_connections=151`, `time_zone=SYSTEM` (Arab Standard Time, UTC+3) | Durable settings are suitable for a journal. `bind_address=*` + blank root password is acceptable only on an isolated dev box; production must differ. Mediator sessions should `SET time_zone='+00:00'`. |
| Web server | Apache 2.4.68 (running) with `mod_php` (PHP 8.3 `php8apache2_4.dll`); nginx 1.30.4 installed, not running | Yii3 works under Apache + mod_php for local dev. Production target per architecture doc: Nginx + PHP-FPM (Linux). |
| Python | 3.13 at `C:\Programs\laragon\bin\python\python-3.13` (Windows `python` alias points to the Store) | Used only for read-only Odoo probes in `fuel-scratpad`. |
| Yii3 | **No Yii3 project exists anywhere on the machine** (no `composer.json` under `C:\Programs\client` or `laragon\www`; no yiisoft packages in the Composer cache) | Phase 2 must create the application from `yiisoft/app-api` 1.4.0 inside `fuel-mediator`. |

## 2. Workspace map (`C:\Programs\client\fuelksa`)

| Folder | What it is | Git | Role for the mediator |
|---|---|---|---|
| `fuel-docs/` | `FuelPay_Mediator_Architecture_Understanding.md` | `master`, 1 commit, clean | **Primary business reference** (read in full). |
| `fuel-mediator/` | Empty repo, remote `github.com/imujeebco/fuel-mediator` | `main`, **no commits** | **Target for all mediator work.** |
| `fuelpay/` | FuelPay worker app — native **Kotlin / Jetpack Compose** Android (`sa.fuel.pos.terminal`), v0.2.23, Digital Pay N910 terminals | `staging-odoo` @ `91848ff`, clean | Source of truth for the PTS and Odoo client contracts. **Read only.** Contains APKs, DB dumps, screenshots, `_prefs.xml` (may contain secrets — not read). |
| `fuel-odoo/` | 91 Odoo 18 addons incl. the `odoo_pts_bridge*` family, `pts_pg_lock_safe`, `admas_digitalpay_*`, POS reconciliation modules, `_tmp_rpc_*.py` probe scripts | `main` @ `0822469d`, clean | ERP-side contract. **Read only.** |
| `fuel-customer-app/` | React Native (Expo) customer app | `react-native-port` | Not in scope (customer app, not worker app). Has a committed `.env` — not read. |
| `fuelksa-20/` | README only | `master` | Not relevant. |
| `fuel-scratpad/` | Scratch area (user-designated) | not a repo | Probe scripts and raw captures from Phase 0 (`phase0/`). |
| `C:\Programs\erp\server-18\addons`, `...\enterprise` | Odoo 18 Community / Enterprise source | — | No `pts` modules there; reference for core Odoo behaviour only. |

> The architecture doc refers to modules named `odoo_pts_*`. The actual technical names are **`odoo_pts_bridge*`** (plus `pts_pg_lock_safe`).

## 3. Live system observations (read-only)

### 3.1 PTS controller — Station 11 (`station11.fuel.net.sa`)

Captured packets are in `fuel-scratpad/phase0/pts-captures/`. Only read packets were sent
(`Get*Configuration`, `GetFirmwareInformation`, `GetDateTime`, `GetRemoteServerConfiguration`,
`GetSystemOperationInformation`, `PumpGetStatus`, `ReportGetPumpTransactions`). **No authorize, stop, price or config writes.**

| Observation | Evidence | Implication |
|---|---|---|
| Controller is a **Technotrade PTS-2** (`realm="Pts2WebServer"`), HTTP Digest (MD5, `qop=auth`), endpoint `POST /jsonPTS` | 401 challenge headers | Matches the FuelPay client implementation. |
| Controller is **publicly reachable through Cloudflare** (`Server: cloudflare`), both `http://` and `https://` | Response headers | Contradicts "do not expose PTS to the public internet". Factory-default credentials are accepted. **Security finding — high.** |
| Firmware dated 2026-06-14; 18 pumps on 3 ports (protocol 38), 1 nozzle per pump, grades 1=A 91 (2.18), 2=A 95 (2.33), 3=diesel | `PumpsConfiguration`, `PumpNozzlesConfiguration`, `FuelGradesConfiguration` | Pump/nozzle config can be read from the controller and compared with Odoo. |
| `LastTransaction` is a **per-pump counter** (pump 1: 4855, pump 2: 2086, pump 3: 1330) | `PumpIdleStatus` | A PTS transaction id is unique only within `(controller, pump)`, and counters can wrap or reset. The identity key must be `(station, controller, pump, transaction, start time)`. |
| `ReportGetPumpTransactions` works: history per pump + time window with `Transaction`, `Nozzle`, `Volume`, `Amount`, `Price`, `TotalVolume`, `TotalAmount` (totalizers), `DateTimeStart`, `DateTime`, `UserId`, `IsPaid`, `IsOffline`, `Tag` | `report.json` | Useful to confirm a specific authorized fill. **Correction (feasibility check): the history is not complete** — most fills are never recorded as controller transactions. See [reconciliation-feasibility.md](reconciliation-feasibility.md). Totalizers are the complete record. |
| Controller clock is local time (`UTCOffset: 180`), auto-synchronised | `GetDateTime` | Controller timestamps have no zone; convert using the configured offset. |
| Remote-server push: HTTP uploads **disabled**; WebSocket uploads **enabled** to `station11.fuel.net.sa:6600/api.v1/jsonPTS` but `IsWebsocketsCommunicationSuccessful=false`, no secret key | `RemoteServerConfiguration` | The controller can push transactions/status over WebSockets. This is a possible future ingestion path, but it is currently not working and not used. Changing it is a config write — **not done**. |
| No probes/tanks configured on Station 11 | `ProbesConfiguration`, `TanksConfiguration` | FuelPay's fixed `ProbeGetMeasurements` 1–3 will return errors here. |
| Round-trip latency from this machine ≈ 0.6–0.7 s per request | curl timings | A central mediator adds this to every pump command. |

### 3.2 Odoo — `fuelksa-dev.odoo.com`, db `fuelksa-stage-39597991`

Read-only JSON-RPC (`common.version`, `authenticate`, and `execute_kw` restricted to
`search_read`/`search_count`/`fields_get`/`read` by an assertion in the probe script).

| Observation | Implication |
|---|---|
| Odoo **18.0+e** (Enterprise, Odoo.sh) | Odoo SaaS/Odoo.sh: no direct DB access; integration only over RPC/HTTP. |
| Installed versions: `odoo_pts_bridge` 18.0.60.5.1, `_mobile` 18.0.1.63.0, `_pos` 18.0.3.191.0, `_shift` 18.0.1.1.7, `_shift_pos`, `_sales`, `_alerts`, `_fleet`, `_inventory`, `_pos_settlement`, `pts_pg_lock_safe`, `admas_digitalpay_*`, `pos_shift_reconciliation`, `pos_payment_reconcile` | Matches the `fuel-odoo` repo, except **`odoo_pts_bridge_tx_hotfix` (installed on Odoo) is not in the `fuel-odoo` repo**. The deployed code is not fully represented in source control. |
| 21 `pts.station` records, 18 `pts.backend.config` records; **one Odoo company per station** (e.g. Station 11 → company `F011`) | Station → company → POS config mapping exists in Odoo and can be imported. |
| Backend configs use `integration_mode='direct'`, `direct_pts_url='https://stationNN.fuel.net.sa/jsonPTS'`, Digest, and `api_poll_interval_seconds=15` | **Odoo itself also polls every PTS** (every 15 s), in addition to every FuelPay terminal. |
| Data volume: `pts.terminal.sale` 472k rows (≈170k in the last 30 days), `pts.petrol.transaction` 210k, `pts.order.meter.event` 224k, `pts.status.snapshot` 84k, `pts.mobile.terminal` 108 | Rough load: ~5.7k sales/day across stations. |
| Last 30 days: 168,275 of 170,571 terminal sales have `manual_sale=True`; 154,819 have no `pts_transaction_id` | In practice almost every sale is "manual" by FuelPay's definition (the pump was not authorized by the app). `manual_sale` is therefore **not** a reliable "no PTS" signal for duplicate detection. |
| `pts.petrol.transaction.external_tx_id` values look like `mobile-<sale uuid>` and link to `terminal_sale_id` | Recent "PTS transactions" in Odoo are derived from mobile sales, not from controller transaction ids. Needs confirmation with the Odoo module analysis. |
| `pos_sync_status`: 22 `failed`, 6 `pending` (30 days); `pos_payment_missing=True` on 468 petrol transactions | Existing reconciliation gaps the mediator should surface. |
| `pts.mobile.pump.permit` exists and is empty at rest; `pts.mobile.pump.auth` has 1,173 rows (`open`/`consumed`/`cancelled`) | The Odoo-side pump permit/lock is live and used. |
