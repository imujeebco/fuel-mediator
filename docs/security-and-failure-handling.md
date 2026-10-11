# Security and failure handling

Status: Phase 1 design, 2026-10-11. Credential hardening is **deferred (D5)**. The baseline rules below still apply from day one.

## 1. Baseline rules (non-negotiable now)

- **No secrets in git.** `.env` is gitignored; data exports (`*.csv`, `*.zip`) are ignored.
- **No secrets or sensitive data in logs.** A log processor redacts these keys at any depth:
  - `password`, `pass`, `pwd`, `api_key`, `token`, `secret`, `pin`, `authorization`, `cookie`;
  - the third element (password) of `execute_kw` args.
  - Card data: only `card_number_masked` (already masked by the terminal) is kept. A full PAN must never appear, so 13–19-digit sequences in card fields are rejected or masked.
- **Station scoping.** Every endpoint resolves a station from the URL (PTS) or from the device binding (relay), and queries are scoped by it. A device bound to station A that calls `/stations/B/jsonPTS` is refused once device binding is enforced (after D5). Until then it is logged as a `cross_station_access` audit event.
- **No open proxy.**
  - The Odoo relay is allow-listed by `model.method`.
  - The PTS endpoint only accepts the packet types in the compatibility matrix.
  - Upstream URLs come from the DB (controllers), never from the request.
- **Read-only toward production in this phase (D8).**
  - Collectors send only read packets (`PumpGetStatus`, `PumpGetTotals`, `Get*Configuration`, `ReportGet*`). The gateway enforces this with an allow-list per caller: the collector client **cannot** send commands.
  - The Odoo reader only uses `search_read` / `read` / `search_count` / `fields_get`.

## 2. Deferred (D5), recorded for later

- Mediator-issued per-device credentials. Real PTS credentials stay in the mediator; the relay rewrites `pts_connection` credentials.
- Restricting controller exposure (Cloudflare access rules or a tunnel) to the mediator's IP (Q2).
- An authenticated monitoring UI with roles.
- HTTPS everywhere (terminated at Nginx in production).
- Rate limiting per device. The relay must return 429 with `Retry-After`, which FuelPay honours.

## 3. Failure modes and handling

| # | Failure | Detection | Handling | Never |
|---|---|---|---|---|
| F1 | Controller timeout on **authorize** | No reply within `command_timeout_ms` | `OUTCOME_UNKNOWN`; reply to the device with "outcome unknown, check pump"; resolve from evidence (lifecycle §1) | Re-send automatically |
| F2 | Controller timeout on **stop / e-stop** | Same | `OUTCOME_UNKNOWN`, high-severity alert, reply immediately so the worker can use the **local emergency procedure** | Delay or queue a stop behind other work; be the only stop mechanism |
| F3 | Dispense succeeded, response lost | Fill event appears with no confirmed command | Command resolved as EXECUTED; the fill is reconciled normally | Treat as failed |
| F4 | Manual sale entered after a PTS timeout; PTS sale arrives later | Two sales link to one fill / oversold volume | `duplicate_candidate` for review; card RRNs compared | Delete or merge sales |
| F5 | Odoo unavailable during sale registration | Relay timeout or 5xx | Return a retryable JSON-RPC error; FuelPay keeps the sale in its offline queue and retries with the same uuid; journal the attempt | Acknowledge a sale that Odoo did not acknowledge |
| F6 | Odoo times out but actually created the sale | A retry returns `duplicate`/`updated` | Expected: Odoo upserts by uuid. The journal shows both attempts. | Create a mediator-side copy |
| F7 | Mediator restart with work in flight | Expired `worker_leases` and `outbox.locked_until`; commands in SENT state | Leases and locks are reclaimed. Commands still in `SENT` at startup become `OUTCOME_UNKNOWN` and are resolved from evidence. | Re-send SENT commands |
| F8 | MySQL unavailable | Connection error | PTS commands are refused (they cannot be journaled). JOURNAL relay methods return a retryable error. PASS relay methods continue. | Execute an unjournaled command |
| F9 | Digital Pay timeout / unknown outcome | On the device (SDK); visible to the mediator only as a sale observation | Flag APPROVED card sales without an RRN as `payment_unverified` discrepancies | Initiate or repeat a charge (the mediator has no payment path) |
| F10 | One station offline / slow | Circuit breaker, lease timeouts | Only that station's circuit opens (backoff ≤ 5 min); other stations' leases are unaffected; dashboard shows the station offline | Share a lock or queue across stations |
| F11 | Stale pump status | `observed_at` older than `stale_ms` | Packet omitted, so the device shows OFFLINE | Return stale status as current |
| F12 | Cloudflare 403 / controller auth failure | HTTP 403/401 from the gateway | Distinct alert, circuit opens, no retry storm | Forward the challenge to the device |
| F13 | Meter regression (controller reset/replacement) | Snapshot lower than the previous one | `meter_regression`; the window is excluded from automatic reconciliation | Compute a negative dispensed volume |
| F14 | Collector gap (mediator or network down) | Snapshot coverage gap | Window marked *incomplete*; meters bracket the gap, so volume-level reconciliation of the bracketing window remains valid | Report the window "clean" |

## 4. Backups (production, Phase 7)

- Nightly `mysqldump --single-transaction` plus binlog retention, enabling point-in-time recovery to the retention horizon.
- A monthly restore test into a scratch schema.
- Details in `deployment-production.md`.
