# Progress

## Current phase
**Phase 3b — Compatibility endpoints: IMPLEMENTED, mock-tested (2026-10-11).** Phase 3a observe mode is running live on Station 11 (read-only). Next: review of the first live reconciliation, then Phase 4. Phase 0 complete; decisions recorded in [decisions.md](decisions.md).
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

## Phase 3a (2026-10-11)

Live findings:
- Status reports every fill with a per-pump transaction number. Only the controller's stored report is incomplete.
- `PumpGetTotals` occupies the pump, so the collector never sends it.
- Odoo lists pump 21, which the controller does not have.

What was built:
- PTS gateway: `src/Pts/`. The client uses a read-only packet policy, 8-packet chunking and classified failures, and never retries. Each controller gets its own schema-scoped MySQL lock and circuit breaker. Secrets come from the environment.
- `controller enable|disable|sync-config|show <backend id>`. Config sync makes the controller authoritative for pumps.
- `collector:run [--station] [--once] [--max-seconds]`, built on `StatusCollector`, `CollectorRunner` and `LeaseManager`.
- `odoo:pull-sales [--station] [--lookback-days]`: incremental, read-only mirror of `pts.terminal.sale`.
- `recon:volume --station --from --to [--grace] [--no-persist]`: dispensed vs sold per pump. Discrepancies are idempotent and raised only when meter coverage is complete.
- `Decimal` helper (bcmath) for exact litres and money.

Bug found and fixed: the frozen `Clock` (see `testing.md`).

How to operate it locally (Station 11 = Odoo backend 2, mediator station id 3):
```
php yii controller sync-config 2
php yii controller enable 2 --poll-ms=10000
php yii collector:run                     # long-running; Ctrl+C to stop
php yii odoo:pull-sales --station=3
php yii recon:volume --station=3 --from="2026-10-11 07:05" --to="2026-10-11 08:00"
```

## Phase 3b (2026-10-11)

**Fill-level matching** (`recon:fills`):
- Links fills to sales by transaction number or by amount + volume fingerprint.
- Reports unmatched fills after a grace period; a late sale resolves them automatically.
- Also reports sales without a fill and duplicate candidates.

**PTS-compatible endpoint** (`POST /stations/{backend}/jsonPTS`):
- Status is served from the shared cache, and refreshes feed fill detection.
- Commands go through `CommandService`. They are journaled and sent once under the controller lock. A timeout becomes `outcome_unknown`, never a retry. A refused connection is reported as "not executed". An identical authorize within 10 s replays the earlier result.
- Config and probe replies are cached. Unknown pumps and unsupported packet types never reach the controller.

**Odoo relay** (`POST /odoo/jsonrpc`):
- Allow-list of FuelPay's model/method pairs.
- Forwards verbatim, maps errors to FuelPay's retryable messages, journals with redaction, and records `sale_observations`.
- Rewrites the PTS URL in `mobile_get_pumps` when `MEDIATOR_PUBLIC_URL` is set (D1).

New environment setting: `MEDIATOR_PUBLIC_URL`.

**Not done:** no real device has used either endpoint. That requires a pilot device and your approval.

## Next recommended step
1. Review the first live volume and fill reconciliation for Station 11, then decide which stations to enable next.
2. Phase 4:
   - resolve `outcome_unknown` commands from evidence;
   - outbox and restart recovery;
   - concurrency tests.
3. Pilot preparation: point one test device at a mediator instance reachable from the station (needs hosting and approval).

## How to resume
1. Read this file.
2. Read `phase0-discovery-report.md` §5–7.
3. Probe scripts need env vars `ODOO_DB`, `ODOO_USER`, `ODOO_PASSWORD` (never commit them).

## Live observation: PTS switched on at Station 11 (2026-10-11 19:33 local / 16:33 UTC)

The user enabled FuelPay PTS mode at Station 11. Collector and sales mirror are unchanged, so the switch is a before/after experiment.
- Comparison query: `fuel-scratpad/pts_switch_observation.sql`.
- Watched: PTS transaction ids on sales, manual-sale share, pump-number accuracy, transaction-id links, duplicates, controller load.
- First effect: from 19:33 the controller stopped answering the collector (timeout after 10 s, 0 bytes received). The circuit breaker backed off.

### First PTS-mode results (19:35–19:57 local, short sample)

- **Sales:** 97% now carry a PTS transaction number (0% before), and pump numbers are physical.
- **Matching:** 20 of 22 links are by transaction number (exact).
- **Volume:**
  - A 95: 219.45 L dispensed = 219.45 L sold.
  - Diesel: 332.56 L dispensed = 332.56 L sold.
  - A 91: +29.4 L, including 2 fills that happened between polls.
- **Fixes required by PTS mode:**
  - Sales arrive with no `fuel_name` and `nozzle_number = 0`, so the grade is now derived from the controller's nozzle for that pump.
  - The transaction-number rule now runs before any grade filter.
- **`manual_sale` stays true for 92% of sales** even in PTS mode. It means "not authorized by the app", not "no PTS".
- **Potential duplicate to watch:** pump 18 at 19:44 has a manual APPROVED SAR 50 sale without a transaction number (`aqib`) next to a PENDING PTS sale with tx 11813 (`esaa`).
