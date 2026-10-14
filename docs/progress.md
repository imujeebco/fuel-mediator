# Progress

## Current phase
**Go-live preparation (2026-10-12): all Station 11 devices through the mediator (D22).** Server kit, D21 and hardening are built; deployment waits for the Contabo server access. See the section at the end.

Previously: **Phase 3b — Compatibility endpoints: IMPLEMENTED, mock-tested (2026-10-11).** Phase 3a observe mode is running live on Station 11 (read-only). Next: review of the first live reconciliation, then Phase 4. Phase 0 complete; decisions recorded in [decisions.md](decisions.md).
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

## Session 42742 close and go-live preparation (2026-10-12)

Live findings (reports in `fuel-scratpad/session_report.py`, `session_render.py`):
- Session 42742 (12:01–00:07 local): with 8 duplicate payments at close removed, A 91 balanced, A 95 +225 L and Diesel +113 L unsold (about SAR 774), mostly before PTS was switched on.
- The duplicates come from FuelPay itself: no link between manual sales and PTS fills, and the close is blocked until Odoo's unpaid list is empty. → **D21**.
- Odoo session meters: openings were seeded from earlier shifts (not live) on several pumps; at the 42742 close, 7 pumps (3, 6, 9, 11, 13, 16, 18) got closing = opening + litres sold instead of a reading, and the next session 42846 copied them as openings. Session 42842 (other POS config) opened live on 14 pumps; pumps 1, 2, 16, 18 were seeded. Two F011 sessions were open on the same pumps at once.

Built:
- **D21 settled fills**: `Reconciliation/SettledFills`, `Odoo/Relay/SettledFillFilter`, relay mode `FILTER`, table `fill_settlements` (migration 000009), env `SETTLED_FILL_FILTER`. Tests: `tests/Unit/Odoo/SettledFillFilterTest.php` (uuid parity with FuelPay, the 42742 wrong-pump case, unpaid fill never hidden, own PTS sale untouched, ambiguity guard, kill switch).
- **Hardening**: both device endpoints answer protocol-shaped JSON on any internal failure (e.g. MySQL down) instead of an HTML error page.
- **Server kit** `deploy/`: nginx (only device paths public), PHP-FPM pool (24 workers), collector service, sync timer (sales mirror + fill matching every minute), `install.sh`, `push.sh`, read-only `smoke-test.sh`. Runbook: `docs/deployment-production.md`.

Tests: Unit 90 OK, Functional 8 OK, Psalm clean. Web suite needs a running server (unchanged).

Next: server access → install → registry → smoke test → switch devices (runbook §5).

## Live observation from a clean log (2026-10-14, from 13:24:48 local / 10:24:48 UTC)

The local instance reads Odoo directly. Devices reach Odoo through the production mediator (fuelksa.iwebcrux.com), which runs the same code. The local transactional tables were truncated at the start (backup: `fuel-scratpad/backups/fuel_mediator_before_reset_20261014.sql`), and sales cursors were set to the reset time. Snapshots every 5 min: `fuel-scratpad/observe_snapshot.sh` → `fuel-scratpad/observation.log`.

Station 11 (PTS mode):
- About 97% of sales carry a PTS transaction number, and app-authorized sales (`manual_sale=0`) match their fills exactly.
- Other terminals complete a PENDING sale on the same Odoo record.
- One orphan PENDING twin: aqib, pump 15, SAR 124.94 (Odoo 516977) next to its APPROVED sale 516980. This is the D21 pattern.
- A fill left PENDING can be paid much later (70 min seen), and FuelPay then rewrites `created_at`.

**Fixed (18db510):** a sale that named a known fill could be taken by an earlier fill of the same amount, which shifted the links down the chain. Rebuilt: 70 links, 66 by tx, 0 contradicting.

Odoo.sh maintenance, 15:01–15:12 local (HTTP 503):
- The production relay answered devices in about 0.5 s with `odoo_error`. Devices kept polling about 4 calls/s.
- One sale reached Odoo 40 s after recovery.
- Two fills from mid-outage (pump 7 SAR 65, pump 17 SAR 30) still had no sale at the time of writing.

Production findings (read-only look, 13:25):
- Collector cron last ran about 46 h ago, and the sync cron is irregular.
  - This makes Station 11 show +32,425 L "pumped − sold": a 46 h meter jump is booked as fills between polls.
  - It also produces about 1,000 sale/fill issues.
- `APP_DEBUG` is on.
- Station 11 devices using the relay appear as "Not linked to a station": FuelPay omits `station_id` when `odooStationId` is unset.

Local environment:
- MySQL 8.4.3 (Laragon) crashed with an access violation on `TRUNCATE TABLE stations` during the full unit suite. InnoDB recovered with no data loss.
- Don't run the full suite while observing.

Open follow-ups:
- Production cron and `APP_DEBUG`.
- Raise a meter jump across a collector outage as `collector_gap`, not volume.
- Link devices through `pos_config_id` when `station_id` is missing.
- Log the circuit breaker once per open/close, not twice a second.
- Optional: short-circuit relay calls for a few seconds after an Odoo 503.
- Deploy 18db510.

### Afternoon of 2026-10-14 (continued observation)

Station 11 has 3 confirmed duplicate payments, all in the D21 pattern: a PENDING record left behind after a disruption is settled again later.

| Pump | Fill | First payment | Second payment |
|---|---|---|---|
| 15 | 57.312 L / SAR 124.94 | CARD 13:34 (Odoo 516980) | CASH 15:41 (516977) |
| 18 | tx 12160, SAR 50 | manual CARD 17:04 (518015) | CASH 17:05 (517999) |
| 9 | tx 4784, SAR 86 | manual CARD 17:23 (518131, RRN 123997508952) | CARD 18:05 (518169, RRN 122282880422): two card charges |

- The production D21 filter cannot hide paid fills while the production collector cron is down.
- The duplicate check missed pumps 15 and 9 because the late payment rewrites `created_at`, which puts it outside the 15-minute twin window.

Disruptions:
- **Odoo.sh 503 maintenance:** 15:01–15:12 and 16:23–16:33.
  - Sale registrations queued and retried correctly. FuelPay's `OdooSyncWorker` retries every 5 min with no limit.
  - `mobile_get_unpaid_fills` and `request_mobile_pump_permit` failed throughout, so attendants took payment outside the app. Five fills from the outage windows have no sale.
- **Station 11 controller unreachable** (HTTP 530 / DNS), 17:01–17:03.
- **All devices in manual mode** 17:03 → ~17:30.
  - In FuelPay, manual mode is only the persisted Settings switch `pts_enabled`. Odoo's `mobile_offline_mode` and `pts_soft_connectivity_errors` are never read.
  - So attendants switched PTS off and later back on, and the two pump 18 and pump 9 duplicates came out of that switch.
- **Relay timeouts:** 14 `register_terminal_sale` calls hit the 13 s limit. The slowest successful call took 12.9 s.
- **Odoo attachment error:** `mobile_list_employees` keeps failing on an attachment stored on external FTP (421 Too many connections).

Observer gaps (local only, issues dismissed as `observation-reset` / `observation-gap`):
- MySQL crash, 15:32–15:37.
- PC internet drop, 17:30–17:44.
- PC standby, ~18:00–22:14 (169 fills between polls).

Open follow-ups, added to the list above:
- Duplicate rule independent of `created_at`: pump + litres + amount within the shift, using the fill's tx.
- An outage reconciliation list: fills dispensed while Odoo was down.
- A relay timeout above Odoo's worst case.

## Development round from the 2026-10-14/15 observation (local commits, awaiting review before push)

The user approved these on 2026-10-15 and asked to review before any push. Production is not deployed.

| Commit | Change |
|---|---|
| fix(relay) POS-config station | Devices are linked to their station through the sale's POS config. Before, Station 11's relayed devices showed as "Not linked". |
| fix(collector) circuit log | An open circuit is logged once per open period, not twice a second. |
| fix(collector) meter_gap | A single fill missed between polls is rebuilt from the meter totals (new source `meter_gap`, migration 000012). Before, it was dropped when the visible transaction was a zero-volume lift (pump 9 tx 4811). |
| feat(recon) duplicates | Duplicate payments are found whatever the late payment's timestamp. Rules: the sale names a fill another sale already paid (high severity); or same pump and amount in the same POS session; or the previous 15-min rule. |
| feat(relay) D28 | Live settlement: a PTS fill paid by a manual sale of the same grade is reported paid at once (`live_settlements`, migration 000013, `SETTLED_FILL_LIVE`). |
| feat(relay) D29 | Short-lived cache for `pts_api_bootstrap_proxy` and `mobile_list_employees` (outcome `cached`, migration 000014). |
| fix(collector) collector_gap | Fills missed during a collector outage (pump unread for over 5 min) become a `collector_gap` issue, and overlapping windows show partial coverage. This removes the +32,425 L artefact seen on production. |
| feat(dashboard) outages | The Traffic page lists Odoo outages and the fills from them still without a sale. |

Also pushed earlier on 2026-10-14/15:
- 18db510: a sale naming a known fill is never fingerprinted to another fill; the tx rule now reaches 12 h back.
- 258e5e6: prepaid sales are linked; the lead window is now measured from the fill start.

Tests: Unit Odoo 30, Reconciliation 19, Collector 12, Dashboard 2, Price 4 and Pts 23, all OK; Psalm clean on all changed files.
- The full suite was not run in one go: it crashed local MySQL 8.4.3 on `TRUNCATE TABLE stations` on 2026-10-14.

Not changed, by design:
- The relay timeout stays at 13 s. It must stay below FuelPay's own 15 s read timeout, so the device always gets an answer.

Findings for others (not mediator code):

| Owner | Finding |
|---|---|
| FuelPay app | About 9 blocking Odoo calls per card sale, all serialized on one queue, roughly 18–20 s of waiting. Proposal: one atomic "claim fill" call before the payment screen, no server calls during payment, registration through the existing retry queue. |
| FuelPay app | "Cancel & Enter Manually" only navigates back. Manual entry should record the pump. |
| FuelPay app | Manual mode is only the persisted `pts_enabled` switch. Odoo's `mobile_offline_mode` is never read. |
| Odoo | When a PTS sale arrives, link it to an unlinked APPROVED manual sale of the same config, pump/grade and amount within 30 min, instead of creating a new PENDING record. This is the authoritative version of D28. |
| Odoo | `mobile_list_employees` attachment 6334 is stored on FTP and fails with "421 Too many connections". |
| Odoo.sh | Two maintenance windows during trading hours: 15:01–15:12 and 16:23–16:33. |
| Station 11 | The controller link via Cloudflare (`station11.fuel.net.sa`) times out about every 5 min in the evening. |

Duplicate payments seen at Station 11 since 2026-10-14 13:24, SAR 379.62 in total. Check them at the shift close.

| Pump | Amount | Payments |
|---|---|---|
| 15 | SAR 124.94 | card + cash |
| 18 | SAR 50 | card + cash |
| 9 | SAR 86 | two cards |
| 10 | SAR 88.68 | card + cash |
| 17 | SAR 30 | card + cash |

Observer note: this laptop's battery is at 5% and the PC sleeps (18:00–22:14 and 01:07–01:18). Local fill coverage has gaps there.
