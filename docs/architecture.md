# Architecture

Status: Phase 1 design, 2026-10-11. Business reference: `fuel-docs/FuelPay_Mediator_Architecture_Understanding.md`.
Evidence: [discovery/](discovery/). Decisions: [decisions.md](decisions.md).

## 1. Objectives (in priority order)

1. **No sale is lost.** Every litre dispensed (measured by the nozzle meters) is accounted for by an Odoo sale or reported as a discrepancy.
2. **Reconciliation.** Per station and per POS session, compare dispensing, sales, collections and Odoo postings in one consolidated report (D10).
3. **Coordinated, journaled FuelPay ↔ PTS traffic.** FuelPay traffic only (D1): serialized per controller, with cached status, no blind retries of physical commands, and every command journaled.
4. **Traceability.** Every observation, command and decision is recorded, with its source, so failures are recoverable.

Non-goals (for now):
- replacing Odoo's dedupe (D2);
- routing Odoo's own PTS crons (Q3);
- hardening credentials (D5);
- PTS WebSocket ingestion (Q11);
- central/local failover.

## 2. Context

```text
                        +------------------------------+
                        |   Odoo 18 (Odoo.sh)          |
                        |   odoo_pts_bridge* modules   |<------ Odoo crons poll PTS directly (unchanged, Q3)
                        +---------------+--------------+                      |
                                        ^ JSON-RPC (allow-listed relay)       |
                                        | + read-only collectors              |
+--------------------+   /odoo/jsonrpc  |                                     v
| FuelPay terminals  |----------------->+------------------------------+  +-----------------+
| 4-6 per station    |                  |      FuelPay Mediator         |  | PTS-2 controller|
| (Odoo URL setting  |----------------->|      core-engine (Yii3)       |->| per station     |
|  -> mediator)      | /stations/{id}/  |  MySQL journal + workers      |  | (via Cloudflare)|
+--------------------+    jsonPTS       +------------------------------+  +-----------------+
```

- **How FuelPay is redirected (D1):** the worker sets the device's Odoo URL to `https://<mediator>/odoo`. The relay forwards allow-listed calls to the real Odoo. In `pts.backend.config.mobile_get_pumps` responses it rewrites `pts_connection` so that `direct_pts_url = https://<mediator>/stations/<backend_config_id>/jsonPTS` and `integration_mode = direct`. The device then sends its jsonPTS traffic to the mediator.
- **Devices that are not redirected keep working.** They talk to Odoo and PTS directly and are invisible to the mediator. The mediator never requires full adoption.

## 3. Components (inside `core-engine/`)

| Component | Responsibility | Runs as |
|---|---|---|
| **Station registry** | Stations, controllers (`pts.backend.config`), pumps/nozzles, devices. Imported read-only from Odoo, with mediator overrides (poll interval, enabled flags). | Web plus console sync command |
| **Odoo relay** `/odoo/jsonrpc` | Allow-listed JSON-RPC pass-through that preserves Odoo envelopes. Intercepts a small set of methods for journaling (`register_terminal_sale*`, shift open/close, permit/claim calls) and one method for rewriting (`mobile_get_pumps`). Never invents a business result. | Web |
| **PTS endpoint** `/stations/{id}/jsonPTS` | jsonPTS-compatible server. Serves `PumpGetStatus` and configuration from a fresh cache, and forwards commands through the per-controller command queue. Journals every command and its outcome. | Web |
| **Controller gateway** | The only code that talks to a controller. Digest client, explicit User-Agent, a timeout budget below FuelPay's 15 s, **one in-flight request per controller** (MySQL named lock), and priority for stop and emergency-stop commands. | Library used by web and workers |
| **Collector (observe mode, D3)** | Per-station read-only polling of status and totals. Writes meter snapshots and derived fill events. Pulls controller transaction reports where available. | Console worker |
| **Odoo reader** | Read-only incremental pulls: `pts.terminal.sale`, `pos.order`/`pos.payment` summaries, `pts.mobile.shift`, `pos.session`, `pts.pos.pump.counter`, keyed by `write_date` cursors. | Console worker |
| **Reconciliation engine** | Volume-level (meter vs. sold), fill-level (fill ↔ sale), session-level (consolidated) reconciliation. Writes `discrepancies` and `match_links`. Detect, link and report only (D2). | Console worker |
| **Outbox** | Durable outbound work items: future Odoo writes, notifications, deferred command-outcome resolution. **No Odoo writes are enabled in the observe phase (D8).** | Console worker |
| **Monitoring UI** | Station health, collector lag, unknown command outcomes, discrepancy lists, consolidated session report. | Web (server-rendered views) |
| **Audit** | Append-only `audit_events` for state transitions and operator actions | Library |

## 4. Station isolation

- All rows carry `station_id`. Every query in workers and endpoints is scoped by station.
- Workers process stations in **bounded slices**: each station has a lease row (`worker_leases`), so one slow controller holds only its own lease. A per-station circuit breaker (consecutive failures → exponential backoff, maximum 5 min) prevents one bad station from consuming worker time.
- Each controller has its own command queue and lock. A blocked controller never blocks another.
- Timeouts are bounded everywhere: controller requests have a ≤ 10 s budget, Odoo requests ≤ 20 s.

## 5. Request flows

### 5.1 FuelPay status poll (via mediator)

```
device → POST /stations/S/jsonPTS [PumpGetStatus × n]
  → if cache(S, pump) is fresher than freshness_ms (default 5000): answer from cache
  → else one gateway call (batched, ≤ 8 packets), update cache, answer
  → if the controller is unreachable: return the last snapshot ONLY IF younger than the stale limit
    (stale_ms, default 30000, never less than 3 × poll_interval_ms — D31);
    otherwise no packet for that pump (device shows OFFLINE). Stale status is never presented as current.
```

### 5.2 FuelPay authorize / stop

```
device → PumpAuthorize
  1. Journal command (state RECEIVED, idempotency key = hash(device, packet body, 10 s window)).
  2. Enqueue on the controller queue (stop and e-stop jump the queue and are never delayed behind status refreshes).
  3. Gateway sends once. Outcome:
     - confirmation   → CONFIRMED, return the controller reply verbatim
     - error packet   → FAILED, return it verbatim
     - timeout/5xx    → OUTCOME_UNKNOWN; reply to the device before its 15 s timeout with an error packet
                        ("Outcome unknown – check pump before retrying"); NEVER re-send.
  4. OUTCOME_UNKNOWN is resolved later by status/totals/report evidence (lifecycle doc). It is listed on the dashboard.
```

A duplicate identical authorize from the same device within the idempotency window returns the journaled result instead of sending a second command.

### 5.3 Sale registration (via relay)

```
device → execute_kw pts.backend.config.register_terminal_sale(payload)
  1. Journal the request (sale_observations: uuid, station, pump, amounts, payment refs, raw payload hash).
  2. Forward to Odoo unchanged; journal the response (status, canonical_uuid, pos_sync).
  3. Return Odoo's response unchanged. On an Odoo timeout, return a JSON-RPC error whose message contains
     "timeout" (FuelPay treats it as retryable and re-sends the same uuid; Odoo upserts by uuid).
```

The mediator never acknowledges a sale that Odoo did not acknowledge. FuelPay keeps its own offline queue, so durability is preserved end to end.

### 5.4 Observe-mode reconciliation (D3, the first pilot)

```
collector(S) every poll_interval: PumpGetStatus (all pumps) → meter_snapshots, fill_events
collector(S) every totals_interval: PumpGetTotals per nozzle → meter_snapshots (source=totals)
odoo_reader every 2 min: new/changed terminal sales, sessions, shifts → odoo_sales, pos_sessions
reconciler every 5 min + at session close: volume, fill and session reconciliation → discrepancies
```

## 6. Deployment

- **Local:** Laragon on Windows. `yii serve` or an Apache vhost; workers run as console commands in terminals. See `deployment-laragon.md` (Phase 2).
- **Production:** Ubuntu on Contabo (D11). Nginx + PHP-FPM, MySQL 8.4, workers under systemd. See `deployment-production.md` (Phase 7).
- **Central-first, local-capable.** All station-specific settings are data, not code, so the same codebase can run at a station with its own MySQL later. No failover is designed yet.

## 7. Known limitations

- A central mediator cannot capture requests that never reach it (station internet outage). FuelPay's offline queue remains the durability mechanism on the device.
- Fill-level matching cannot be complete while controllers do not record most fills as transactions (Q16). Volume-level reconciliation is complete wherever meters are readable.
- The mediator adds about 0.6 s per controller round-trip for redirected devices, and it is a new single point of failure for them. Mitigations: observe-first rollout, a per-device rollback (restore the Odoo URL), and health checks.
