# Testing

Framework: Codeception 5 (Unit / Functional / Console suites) and Psalm, in `core-engine/`. Commands are listed in `core-engine/README.md`.

- Integration tests use **mock PTS and mock Odoo adapters** fed by captured payloads (`fuel-scratpad/phase0/pts-captures/`, copied into `tests/Support/Data/` with no credentials).
- DB tests run against `fuel_mediator_test` (local only).
- **No test talks to a live controller or to the Odoo instance.**

## Required scenarios (master prompt §9) → phase and status

| # | Scenario | Phase | Status |
|---|---|---|---|
| 1 | Valid / invalid station authentication | after D5 | not run (deferred, D5) |
| 2 | Multiple devices on one station | 3b | not run |
| 3 | Multiple stations independent | 2 / 6 | **partial pass (2026-10-11)**: the registry keeps stations with duplicate Odoo codes separate (`StationImporterTest`). Runtime isolation is tested in Phase 3a/6. |
| 4 | Concurrent requests for the same pump | 4 | not run |
| 5 | Duplicate submission of the same request | 4 | not run |
| 6 | PTS timeout after authorize | 4 | not run |
| 7 | Dispense succeeded, response lost | 4 | not run |
| 8 | Manual sale followed by delayed PTS transaction | 5 | not run |
| 9 | Odoo unavailable during completed sale | 3b | not run |
| 10 | Mediator restart with pending transactions | 4 | not run |
| 11 | Database transaction rollback | 2 | **passed (2026-10-11)**: `StationImporterTest::testFailureRollsBackTheWholeImport` |
| 12 | Duplicate Odoo synchronization attempts | 3b / 5 | not run |
| 13 | Digital payment timeout, unknown outcome | 5 | not run |
| 14 | POS session closing with unresolved transactions | 5 | not run |
| 15 | Station with no PTS | 3a | **partial pass (2026-10-11)**: non-PTS stations get no polled controller (import test). Session report coverage comes in Phase 5. |
| 16 | One station offline, others operational | 3a / 6 | **passed (2026-10-11)**: `CollectorRunnerTest::testFailingStationDoesNotAffectOthers` (timeout opens only that station's circuit; others keep collecting) |
| 17 | Stale pump-status data | 3b | not run |
| 18 | Recovery after connectivity returns | 3a / 4 | **partial pass (2026-10-11)**: transaction-gap and meter-gap accounting (`StatusCollectorTest`), idempotent replay after restart, lease takeover after crash (`CollectorRunnerTest`) |
| 19 | Unauthorized cross-station access | after D5 | not run (deferred, D5) |
| 20 | Logs contain no secrets / payment data | 2 | **passed (2026-10-11)**: `RedactorTest` (keys at any depth, positional Odoo passwords, card-number masking, JSON log lines) |

Each phase updates this table with **passed / failed / not executable** and the date.

## Phase 2 run (2026-10-11)

| Suite | Result |
|---|---|
| Unit | OK: 31 tests, 92 assertions. Covers Environment, Redactor, JsonLineFormat, JsonRpcOdooReadClient and StationImporter (DB). |
| Functional | OK: 4 tests, 9 assertions. Covers the home page, `/health`, `/health/stations`, and that machine endpoints set no session cookie. |
| Console | OK: 2 tests, 4 assertions |
| Psalm | No errors |
| php-cs-fixer | Clean |
| Migrations | Round trip `up` → `down --all` → `up` on `fuel_mediator_test`: 20 tables created and removed cleanly |
| Manual | `station:import` run twice against Odoo (read-only). Imported 38 stations (21 active, 18 PTS), 24 controllers (all disabled), 284 pumps and 261 nozzles. The second run changed nothing (idempotent). |
| Web suite | Not executed. It starts `composer serve`, and `composer` is not on PATH. |

## Phase 3a run (2026-10-11)

| Suite | Result |
|---|---|
| Unit | OK: 53 tests, 187 assertions. New tests cover: PtsClient (policy, chunking, failure classes, parser, time zone), StatusCollector (first fill, tx gaps, deferred meter, filling, zero volume, heartbeat, regression, replay), CollectorRunner (station isolation, missing secret, leases), VolumeReconciler (unsold flag and idempotency, partial coverage, unknown pumps, sales cursor) and Clock (DI regression). |
| Functional | OK: 4 tests, 9 assertions |
| Console | OK: 2 tests, 4 assertions |
| Psalm | No errors |
| Live, read-only | `controller sync-config 2` read 18 pumps from the controller; Odoo-only pump 21 was flagged. `collector:run` on Station 11 recorded fills with continuous transaction numbers and a consistent meter chain. |

**Bug found in live run and fixed:** `Clock` had an optional `DateTimeImmutable` constructor parameter. The DI container autowired it, which froze production time at process start. `ClockTest` now guards this. Collected rows with frozen timestamps were discarded from the local dev DB.
