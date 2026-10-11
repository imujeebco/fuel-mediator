# Progress

## Current phase
**Phase 1 — Technical design: COMPLETE (2026-10-11).** Phase 2 (foundation) next. Phase 0 complete; decisions recorded in [decisions.md](decisions.md).
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

## Next recommended step
Phase 2: migrations (configuration, observation, journal, reconciliation, infrastructure tables), health endpoints, redacting logger, read-only Odoo station import, tests.

## How to resume
1. Read this file.
2. Read `phase0-discovery-report.md` §5–7.
3. Probe scripts need env vars `ODOO_DB`, `ODOO_USER`, `ODOO_PASSWORD` (never commit them).
