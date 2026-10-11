# Testing

Framework: Codeception 5 (Unit / Functional / Console suites) and Psalm, in `core-engine/`. Commands are listed in `core-engine/README.md`.

- Integration tests use **mock PTS and mock Odoo adapters** fed by captured payloads (`fuel-scratpad/phase0/pts-captures/`, copied into `tests/Support/Data/` with no credentials).
- DB tests run against `fuel_mediator_test` (local only).
- **No test talks to a live controller or to the Odoo instance.**

## Required scenarios (master prompt §9) → phase and status

| # | Scenario | Phase | Status |
|---|---|---|---|
| 1 | Valid / invalid station authentication | 2 (foundation), full auth after D5 | not run |
| 2 | Multiple devices on one station | 3b | not run |
| 3 | Multiple stations independent | 2 / 6 | not run |
| 4 | Concurrent requests for the same pump | 4 | not run |
| 5 | Duplicate submission of the same request | 4 | not run |
| 6 | PTS timeout after authorize | 4 | not run |
| 7 | Dispense succeeded, response lost | 4 | not run |
| 8 | Manual sale followed by delayed PTS transaction | 5 | not run |
| 9 | Odoo unavailable during completed sale | 3b | not run |
| 10 | Mediator restart with pending transactions | 4 | not run |
| 11 | Database transaction rollback | 2 | not run |
| 12 | Duplicate Odoo synchronization attempts | 3b / 5 | not run |
| 13 | Digital payment timeout, unknown outcome | 5 | not run |
| 14 | POS session closing with unresolved transactions | 5 | not run |
| 15 | Station with no PTS | 3a | not run |
| 16 | One station offline, others operational | 3a / 6 | not run |
| 17 | Stale pump-status data | 3b | not run |
| 18 | Recovery after connectivity returns | 3a / 4 | not run |
| 19 | Unauthorized cross-station access | 2 (audit), enforcement after D5 | not run |
| 20 | Logs contain no secrets / payment data | 2 | not run |

Each phase updates this table with **passed / failed / not executable** and the date.
