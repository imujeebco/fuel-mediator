# Progress

## Current phase
**Phase 2 — Yii3 foundation: COMPLETE (2026-10-11).** Next: Phase 3a (observe-mode collectors). Phase 0 complete; decisions recorded in [decisions.md](decisions.md).
Yii3 baseline in `core-engine/` is cleaned and green (Unit 15/15, Functional 1/1, Console 2/2, Psalm 0 errors).

## Completed work
- Read `fuel-docs/FuelPay_Mediator_Architecture_Understanding.md` in full.
- Verified toolchain (PHP 8.3.33, Composer 2.10.2, MySQL 8.4.3, Apache 2.4.68). `ext-intl` is blocked by Windows Application Control.
- Mapped the workspace. Confirmed no Yii3 app exists and `fuel-mediator` was empty.
- Statically inventoried FuelPay's PTS and Odoo clients and the `odoo_pts_bridge*` modules.
- Read-only probes of PTS Station 11 and Odoo `fuelksa-dev` (no writes).

## Files created
- `README.md`, `.gitignore`, `.gitattributes`
- `docs/phase0-discovery-report.md`
- `docs/discovery/{environment-report,fuelpay-pts-contract,fuelpay-odoo-contract,odoo-bridge-contract}.md`
- `docs/{progress,decisions,open-questions}.md`
- Outside repo: `../fuel-scratpad/phase0/` (probe scripts, PTS captures)

## Tests executed
None. No application code exists yet. Verification probes and their results are recorded in `docs/discovery/environment-report.md`.

## Known limitations
- Contracts are derived from source code, not from captured device traffic.
- `odoo_pts_bridge_tx_hotfix` is deployed but its source is not available.

## Unresolved questions
See [open-questions.md](open-questions.md).

## Phase 0 → baseline follow-up (2026-10-11)
- User answered the open questions and approved D2–D4 and D6. D1 was revised (FuelPay only). D5 is deferred.
- `core-engine/`: removed the Zuberi membership app, PII exports, archives and cPanel files; removed `yiisoft/mailer-symfony` and `yiisoft/user`; retargeted `.env` to `fuel_mediator`.
- Created local MySQL schemas `fuel_mediator` and `fuel_mediator_test` (empty).
- Tests: Unit OK (15), Functional OK (1), Console OK (2, after making `ConsoleTester` use `PHP_BINARY`), Psalm no errors. Web suite not run (needs `composer` on PATH).

## Phase 1 (2026-10-11)
- Read-only feasibility check (Station 11): controller transaction history covers only a fraction of fills, so **meters are the reconciliation backbone**. See `discovery/reconciliation-feasibility.md`.
- Written: `architecture.md`, `api-compatibility-matrix.md`, `database-design.md`, `transaction-lifecycle.md`, `security-and-failure-handling.md`, `testing.md`.
- Pending user input: `SetFuelGradesPrices` policy (matrix §1), Q15, Q16.

## Phase 2 (2026-10-11)

What was built:
- **Migrations** (`core-engine/src/Migration/M2610110000{01..06}`): six revertible migrations creating 20 tables for configuration, observation, commands, the Odoo mirror and relay journal, reconciliation, and infrastructure (outbox, leases, cursors, audit).
- **Database:** every connection is forced to UTC (`PDO::MYSQL_ATTR_INIT_COMMAND`).
- **HTTP:** session and CSRF middleware now apply only to the UI route group, so machine endpoints are stateless. New endpoints: `/health` (checks the database; returns 503 when it is down) and `/health/stations`.
- **Logging:** JSON lines written to `runtime/logs/app.log`. Everything passes through `Redactor`, which masks sensitive keys, positional Odoo passwords and card numbers.
- **Outbound HTTP:** `CurlHttpTransport`, behind `HttpTransportInterface`. It sends an explicit User-Agent, supports Digest/Basic auth, uses hard timeouts and **never retries**.
- **Odoo:** `JsonRpcOdooReadClient`, read-only by construction. Only read methods are allowed, and the interface has no write methods.
- **Registry:** `yii station:import [--dry-run]` imports stations from Odoo. It is idempotent, creates new controllers **disabled**, and preserves operator settings on re-import.
- **Tests:** `tests/bootstrap.php` forces the `*_test` schema and blanks `ODOO_URL`. Added fakes for HTTP and Odoo, plus a `TestDatabase` helper.

Bugs found and fixed along the way:
- The stations table had a UNIQUE constraint on the Odoo `code`, which is not unique in Odoo, so five stations were merged. Station identity is now `odoo_station_id` only.
- The FastRoute route cache went stale when routes changed. It is now disabled in dev and test.

Current state:
- The local dev database holds the imported registry: 38 stations, 24 controllers (all disabled), 284 pumps and 261 nozzles.
- Test results are in `testing.md`.

## Next recommended step
Phase 3a, observe mode (read-only):
1. Controller gateway: per-controller lock, and an allow-list restricting collectors to read packets.
2. Status/totals collector writing `meter_snapshots` and `fill_events`, enabled for Station 11 only.
3. Odoo reader for `pts.terminal.sale` / `pos.session`.
4. First volume-level reconciliation report.

User input needed: Q15–Q19.

## How to resume
1. Read this file.
2. Read `phase0-discovery-report.md` §5–7.
3. Probe scripts need env vars `ODOO_DB`, `ODOO_USER`, `ODOO_PASSWORD` (never commit them).
