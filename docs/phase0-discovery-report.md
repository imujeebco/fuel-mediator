# FuelPay Mediator — Phase 0 Discovery Report

**Date:** 2026-10-11 · **Phase:** 0 (discovery, no business code) · **Status:** for approval

Supporting evidence:

| File | Contents |
|---|---|
| [discovery/environment-report.md](discovery/environment-report.md) | Toolchain, workspace map, live PTS/Odoo observations |
| [discovery/fuelpay-pts-contract.md](discovery/fuelpay-pts-contract.md) | How FuelPay talks to PTS (jsonPTS) |
| [discovery/fuelpay-odoo-contract.md](discovery/fuelpay-odoo-contract.md) | How FuelPay talks to Odoo (JSON-RPC) |
| [discovery/odoo-bridge-contract.md](discovery/odoo-bridge-contract.md) | `odoo_pts_bridge*` modules: transport, crons, dedupe, locks, shifts |

Raw captures and read-only probe scripts are in `../fuel-scratpad/phase0/`. They contain no credentials; the scripts read them from environment variables.

---

## 1. Repository and environment summary

- **`fuel-mediator`** is an empty Git repo (`main`, no commits, remote `imujeebco/fuel-mediator`). **No Yii3 application exists anywhere on the machine.** It must be created.
- **Toolchain** (all verified):
  - PHP 8.3.33 with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `sodium`.
  - Composer 2.10.2. Packagist is reachable.
  - MySQL 8.4.3 with InnoDB and durable settings. It holds no business schemas, so nothing is at risk.
  - Apache 2.4 with mod_php is running.
- **Constraint:** `ext-intl` is **blocked by Windows Application Control**. `yiisoft/app-api` 1.4.0 does not need it, so this is not a blocker. Every PHP run still prints a warning.
- **Source of truth for contracts:**
  - `fuelpay/` is a **native Kotlin Android app**, not a web or PHP app.
  - `fuel-odoo/` holds the Odoo 18 addons.
  - Both are read-only for this project.

## 2. Map of relevant folders

| Folder | Use | Modify? |
|---|---|---|
| `fuel-docs/` | Architecture reference | No |
| `fuel-mediator/` | **All mediator code and docs** | Yes |
| `fuel-scratpad/` | Probes and captures | Yes (scratch) |
| `fuelpay/` | FuelPay client contracts (`data/pts/*`, `data/odoo/OdooClient.kt`) | No |
| `fuel-odoo/odoo_pts_bridge*`, `pts_pg_lock_safe`, `admas_digitalpay_*`, `pos_payment_reconcile`, `pos_shift_reconciliation` | ERP contracts | No |
| `C:\Programs\erp\server-18\{addons,enterprise}` | Core Odoo 18 reference | No |
| `fuel-customer-app/`, `fuelksa-20/` | Out of scope | No |

## 3. Yii3 / PHP / Composer / database confirmation

| Item | Status |
|---|---|
| Yii3 app | **Absent.** Proposal: scaffold `yiisoft/app-api` **1.4.0** (PHP 8.2–8.5; yiisoft/di 1.4, router 4, validator 2.5, yii-console 2.4, Codeception 5 / PHPUnit 11 / Psalm 6) into `fuel-mediator/`. |
| DB layer | Proposal: `yiisoft/db-mysql` + `yiisoft/db-migration` with an explicit query builder, and no ORM. Journal and state-machine writes need precise transactional control and `SELECT … FOR UPDATE`. |
| PHP CLI | `C:\Programs\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe`. It is not on the Bash `PATH`; scripts will reference it explicitly. |
| MySQL | 8.4.3, `root` with blank password, local only. A dedicated `fuel_mediator` schema and user will be created in Phase 2. Sessions run in UTC (`SET time_zone='+00:00'`). |
| Web | Local dev: `./yii serve` or `php -S`, optionally a Laragon Apache vhost later. Production: Nginx + PHP-FPM on Linux. |

## 4. Integration references found (actual, not assumed)

### 4.1 Live systems (read-only verified)

**PTS controller:**
- Technotrade **PTS-2**, `POST /jsonPTS`, Digest MD5.
- Station 11 has 18 pumps, 1 nozzle each, and 3 grades.
- It supports `ReportGetPumpTransactions` (history with totalizers, `UserId`, `IsPaid`).
- `LastTransaction` is a **per-pump counter**.
- WebSocket upload is configured but not connected.
- It is **publicly reachable through Cloudflare** with **factory-default credentials accepted**.

**Odoo:**
- 18.0+e on Odoo.sh.
- 21 stations; one company per station.
- 18 `pts.backend.config` records, all in `integration_mode=direct` with `https://stationNN.fuel.net.sa/jsonPTS`.
- About 5.7k terminal sales per day.
- 98.7% of sales are flagged `manual_sale=True`.
- `odoo_pts_bridge_tx_hotfix` is installed but **missing from the repo**.

### 4.2 FuelPay → PTS

- Raw jsonPTS, one URL per device, **supplied by Odoo** (`mobile_get_pumps.pts_connection`).
- 13 packet types are used.
- Read timeout is 15 s. Authorize and stop are never retried.
- A new TCP connection is opened per request, with one in-flight request per device.
- Each device runs an independent poller (2–16 s adaptive).
- Duplicate-relevant behaviour: an authorize timeout leads to an ERROR or a manual-mode fallback. This is the documented duplicate path.

### 4.3 FuelPay → Odoo

- `POST /jsonrpc` only, via `common.authenticate` and `object.execute_kw` across about 49 model/method pairs.
- Idempotency key is the sale `uuid`; PTS fills get a deterministic `pts-tx-<tx>-p<pump>` uuid.
- No device secret. HMAC is computed but never sent. PINs are returned in clear.

### 4.4 Odoo → PTS

- Crons pull every 2–15 min. Desktop POS sends `PumpAuthorize`.
- The `backend` mode `/api/v1/*` REST contract exists as an alternative seam.

### 4.5 Odoo dedupe already in place

- UUID upsert, alias UUIDs, fingerprint claim (±0.05 / 3 s), APPROVED immutability, double-payment detection, and session binding.
- Manual sales get synthetic `mobile-<uuid>` petrol transactions.
- Controller transactions (`pts.petrol.transaction`) **are never turned into sales**. Manual-vs-PTS duplicate *matching* is display-only.

### 4.6 Payments

- FuelPay uses the Digital Pay **app-to-app SDK on the device**. Payments never pass through PTS, Odoo or a mediator.
- There is no status query or reversal in FuelPay. A crash mid-payment can produce an APPROVED card sale **without an RRN**.
- Odoo's DigitalPay cloud API has `/status/{code}/`. It is a possible recovery path, but not for SDK transactions (unverified).

## 5. What discovery changes in the plan

The architecture document stands. These findings refine **where** the mediator sits and **what it should own**.

1. **A zero-code insertion point exists, but it needs an Odoo configuration write.** Both Odoo's own crons and every FuelPay terminal read the PTS address from `pts.backend.config.direct_pts_url`. Changing that one field per station to `https://<mediator>/stations/<code>/jsonPTS`, with mediator-issued credentials, routes **all** PTS traffic through the mediator. No APK change and no Odoo code change are needed. This gives:
   - **One client per controller.** The mediator serializes Digest sessions and serves cached status to N terminals plus Odoo, instead of N+1 independent pollers.
   - **Real controller credentials stay in the mediator.** Devices stop receiving the controller password.
   - **Every authorize and stop is journaled.**
2. **The Odoo-compatible endpoint is a per-device settings change, not zero-change.** FuelPay's Odoo URL is typed into each device. Pointing it at `https://<mediator>/odoo` works: the client keeps the path prefix and calls `…/odoo/jsonrpc`. The endpoint must be an **allow-listed** JSON-RPC relay, so it is not an open proxy.
3. **The mediator must not compete with Odoo's dedupe.** Odoo already handles multi-phone and double-payment duplicates for mobile sales. The mediator's job is what Odoo cannot see:
   - the **outcome of PTS commands**;
   - the **controller's own transaction history**;
   - the **correlation** between an unknown-outcome authorize, a later controller transaction, and a manual sale.

   Initially it should **flag and surface** candidates, never suppress or alter sales on its own.
4. **Central reachability is already a fact.** Terminals already reach PTS over the public internet, via Cloudflare, not the station LAN. A central mediator does not add a new dependency on internet connectivity, but it does add about 0.6 s per hop and becomes a new single point of failure. That is the reason for observe-first rollout (§6, Phase 3a).
5. **A PTS transaction's identity is `(station, controller, pump, transaction no., start time)`.** The transaction number alone is not enough, because counters are per pump and may wrap.

## 6. Proposed implementation plan (tailored)

| Phase | Deliverable | Risk to live operations |
|---|---|---|
| **1. Design** | `architecture.md`, `api-compatibility-matrix.md` (per packet type / per model.method: proxy vs. intercept vs. serve-from-cache vs. deny), `database-design.md`, `transaction-lifecycle.md` (command, fuel-tx, sale, payment and sync state machines), `security-and-failure-handling.md`, `open-questions.md`, `decisions.md` | None |
| **2. Foundation** | Scaffold `yiisoft/app-api` 1.4.0. Add `.env` config (gitignored), MySQL schema `fuel_mediator` with migrations (stations, controllers, pumps/nozzles, devices, credentials, audit_events), `/health` and `/health/stations`, JSON logging with redaction, device Digest/Basic auth bound to a station, and Codeception/PHPUnit plus Psalm in CI-ready form | None (local only) |
| **3a. Observe mode (recommended first pilot)** | Workers that **read** controller history (`ReportGetPumpTransactions` plus status) and Odoo (`search_read` on `pts.terminal.sale` / `pts.petrol.transaction`) into the journal. Build the correlation engine and the discrepancy report **without being in any request path**. This quantifies the real duplicate rate. | Read-only load on PTS and Odoo (rate-limited per station) |
| **3b. Compatibility endpoints (mock-tested)** | `/stations/{code}/jsonPTS` (Digest server, packet router, status cache, per-controller serial command queue, 12 s response budget) and `/odoo/jsonrpc` (allow-listed relay, `register_terminal_sale` journaling, `mobile_get_pumps` rewriting `pts_connection`). Mock PTS and mock Odoo adapters are built from the captured payloads. | None (mocks) |
| **4. Durable processing** | Command journal (authorize/stop with outcome `unknown` → resolved through controller history, **never re-sent**), MySQL outbox, bounded per-station workers, restart recovery, concurrency and duplicate-submission tests | None (mocks) |
| **5. Sales, payments, POS** | Correlation of manual sales with controller transactions and unknown-outcome authorizes, a review queue, Odoo sync safeguards (search-by-uuid before any retry), session association, discrepancy records, payment-outcome states (incl. "approved without RRN") | None (mocks) |
| **6. Monitoring and multi-station** | Station health, queue lag, unknown outcomes, discrepancy views, isolation and load tests | None |
| **7. Pilot readiness** | Deployment, backup, rollback, and a pilot plan for one station: switch `direct_pts_url` for Station 11 only, with instant rollback by restoring the URL | **Requires explicit approval** |

**Local workers on Windows:** run them as long-lived `yii` console commands in a terminal, or through Windows Task Scheduler. Production uses systemd or Supervisor. Both are documented separately.

## 7. Missing information and decisions requiring approval

### Decisions (my recommendation in bold)

| # | Decision | Recommendation |
|---|---|---|
| D1 | Insertion point for PTS traffic | **Rewrite `direct_pts_url` per station** (mode stays `direct`; single jsonPTS protocol for Odoo and FuelPay). The alternative, `integration_mode=backend` with the `/api/v1/*` REST contract, is more work and changes Odoo's code path. |
| D2 | Mediator role in duplicate handling | **Detect, link and surface for review only.** No automatic suppression or alteration of sales sent to Odoo until the real duplicate rate is measured. |
| D3 | First pilot mode | **Observe mode (3a) before any in-path deployment.** |
| D4 | Framework / data layer | **`yiisoft/app-api` 1.4.0 + `yiisoft/db-mysql` + `db-migration`, no ORM.** |
| D5 | Device credentials | **Mediator-issued per-station (later per-device) Digest credentials, stored hashed.** Odoo hands these to devices in place of the controller password. |
| D6 | Git workflow | Initial scaffold commits on `main` (empty repo), then one branch per phase. **No pushes** without approval. |

### Open questions (cannot be answered from code or read-only probes)

1. Who owns `odoo_pts_bridge_tx_hotfix`, and where is its source? (Installed on Odoo, absent from the repo.)
2. Is it acceptable that PTS controllers are publicly reachable through Cloudflare with default credentials? Can access be restricted to the mediator's IP or a tunnel? **This is a security risk today, independent of the mediator.**
3. Should Odoo's own crons go through the mediator (D1 implies yes), or should only FuelPay traffic be redirected?
4. Are station-level Odoo config writes (`direct_pts_url`) acceptable for the pilot, and who approves them?
5. Can FuelPay's Odoo URL be changed on pilot devices (a settings change by the station team)?
6. Policy for "approved card sale without RRN" (crash mid-payment): flag only, or block from Odoo sync?
7. POS session closure with unresolved discrepancies: block, warn, or require supervisor approval? (Not implemented until decided.)
8. Do any stations operate with PTS on a LAN-only address (not via `fuel.net.sa`)? Which stations are non-PTS?
9. Device inventory and peak load: 108 registered terminals. How many are active per station at peak, and what latency is acceptable for authorize?
10. Is a Digital Pay status/reconciliation API available for **SDK** (app-to-app) transactions, queryable by `commandIdentifier` / RRN?
11. Is the PTS WebSocket upload (port 6600, `api.v1/jsonPTS`) an intended integration? Who configured it, and is the server it points to live?
12. Hosting target for the central mediator (OS, region, network path to `stationNN.fuel.net.sa` and to Odoo.sh)?
13. Data retention period for journal and audit records?

## 8. Phase 0 test status

No application code was written in Phase 0, so **no automated tests were executed**. The verifications below were performed and their actual results are reported in `discovery/environment-report.md`:

- toolchain version checks;
- read-only PTS packet calls (all HTTP 200);
- read-only Odoo RPC (`version`, `authenticate`, `search_read`/`search_count`/`fields_get`), all successful.

No write operation was sent to PTS or Odoo.
