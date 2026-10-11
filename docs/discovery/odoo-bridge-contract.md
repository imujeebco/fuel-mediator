# Odoo `odoo_pts_bridge*` contract (as implemented)

Source: `fuel-odoo/` branch `main` @ `0822469d`. This is a read-only static analysis. Installed versions on `fuelksa-dev` match the repo, **except `odoo_pts_bridge_tx_hotfix`, which is installed but not in the repo**.
Paths below are relative to `fuel-odoo/`.

## 1. Key facts

1. **No inbound PTS push endpoint exists.** Odoo **pulls** from each controller:
   - in `direct` mode it sends jsonPTS over `requests` with Digest/Basic auth;
   - in `backend` mode it calls a REST "Backend API" at `{backend_url}/api/v1/...`.
2. **There is one per-station switch: `pts.backend.config`.** Both Odoo's crons **and** every FuelPay terminal take their PTS address from it:
   - fields: `integration_mode`, `direct_pts_url`, `backend_url`, `username`, `password`;
   - the mobile payload is built in `odoo_pts_bridge_mobile/models/pts_backend_config_mobile.py:185-210`, and it hands the URL **and the password in clear** to devices.
3. **Sales are recorded in two places, and they are not linked:**
   - Controller transactions go to `pts.petrol.transaction`, unique `(backend_config_id, external_tx_id)`, sealed and immutable (`odoo_pts_bridge/models/pts_petrol.py:1378-1520`).
   - Mobile sales go to `pts.terminal.sale`, unique `uuid`, and become a `pos.order` (unique `mobile_client_uuid`) and then an invoice/ZATCA document.
   - A PTS transaction **never** creates a `pos.order` by itself. The two are linked only for display, by heuristics (`odoo_pts_bridge_pos/models/pts_petrol_transaction_pos_payment.py`).
4. **The code acknowledges that controllers reuse transaction ids** (`odoo_pts_bridge_pos/models/pts_terminal_sale.py:404-407`), so `pts_transaction_id` is deliberately not unique.
5. **Authentication is plain Odoo user auth.** That means the session, or db/uid/password-or-API-key over `/jsonrpc`. There is no device auth.
   - The HMAC only logs a warning on mismatch, and its key is derived from the public `terminal_id`.
   - The terminal whitelist field (`require_terminal_whitelist`) is defined nowhere, so it is never enforced.
   - Operator PINs are an unsalted SHA-256 of 4 digits.
6. **Debug leftovers in production code:**
   - writes to `c:\Users\INTEL\...` and `/tmp/debug-*.log`;
   - POSTs to `http://127.0.0.1:7665/ingest` (`odoo_pts_bridge/models/pts_backend_config.py:80-104`, `pts_terminal_sale.py:4416-4440`).

## 2. Outbound Odoo → PTS transport

| Mode | Transport | Evidence |
|---|---|---|
| `direct` | `POST direct_pts_url` (must end `/jsonPTS`), `{"Protocol":"jsonPTS","Packets":[…]}`, `HTTPDigestAuth`/`HTTPBasicAuth`, `verify=direct_verify_ssl`, ≤ 8 packets per request. Read connectivity errors are "soft"; writes are strict. | `pts_backend_config.py:527-534, 605-607, 713-820`; `pts_petrol.py:69` |
| `backend` | `POST {backend_url}/api/v1/auth/token` `{username,password}` → `{access_token}` | `:969-978` |
| | `POST /api/v1/commands/{action}` `{"action","payload"}` with headers `Authorization: Bearer`, `X-API-Key`. The response is jsonPTS-shaped and may be wrapped in `result`. | `:940-945, 517-525, 139-157` |
| | `POST /api/v1/pts/batch` `{"packets":[…]}` | `:1183-1196` |
| | `GET /api/v1/status/backend` and `/status/device` | `:1028-1037` |
| | `GET`/`PUT /api/v1/fuel-prices`; `GET /api/v1/transactions`; `GET /api/v1/pumps`; `GET /api/v1/probes` | `:1056-1071, 1583-1592, 1705-1713` |

**jsonPTS commands Odoo sends:**
- **Status and pump control:** `PumpGetStatus`, `PumpAuthorize` (desktop POS pay-then-authorize, `odoo_pts_bridge_pos/models/pts_pos.py:3130-3200, 2381-2459`), `PumpStop`, `PumpEmergencyStop`, `PumpSuspend`, `PumpResume`, `PumpLock`, `PumpUnlock`, `PumpSetLights`, `PumpCloseTransaction`, `PumpGetTotals`.
- **Configuration:** `Get*Configuration`, `GetFuelGradesPrices`, `SetFuelGradesPrices`, `GetDateTime`, `Get/SetRemoteServerConfiguration`.
- **Shifts:** `ShiftOpen`, `ShiftClose`.
- **Reports:** `ReportGetPumpTransactions` (lookback anchored on the last imported tx), `ReportGetInTankDeliveries`, probe measurements.

### Crons that touch PTS

| Cron | Interval |
|---|---|
| Status sync | 15 min |
| Background sync queue | 2 min |
| Full auto sync (2 stalest controllers) | 15 min |
| **Transaction sync (5 stalest controllers)** | 5 min |
| Pump counter capture | 10 min |
| Scheduled price push | daily |
| Centralized price retry | 5 min |

There is one sync per controller at a time, enforced by Postgres advisory lock 711.

## 3. Inbound routes and RPC methods used by FuelPay

- FuelPay calls model methods via `/jsonrpc` `execute_kw`.
  - `odoo_pts_bridge_pos/controllers/pts_rpc_sanitize.py:21` **overrides core `/jsonrpc`**. It strips QR junk from the db name.
- The `/mobile/*` and `/pts/api/pos/*` HTTP routes (all `auth="user"`) are thin wrappers. FuelPay does not use them.
  - `/pts/api/pos/*` is declared `type="jsonrpc"`, an Odoo 19 name, so these routes may not dispatch on Odoo 18.

### Main sale ingest: `pts.backend.config.register_terminal_sale(payload)`

Location: `pts_terminal_sale.py:1646`.

- **Idempotency:**
  - by `uuid`, with an advisory lock and a savepoint fallback to update;
  - canonicalisation through `alias_uuids`.
- **Fingerprint claim for one physical fill seen by several phones** (`:1527-1643`):
  - with a tx: lock on `(pos_config, tx)`, then amount ±0.05, volume ±0.05, same pump and nozzle;
  - without a tx (PTS station): pump + amount within 3 s.
- **APPROVED sales are locked.** Money and card fields are stripped from later writes (`:1350-1396, 1790-1813`).
- **Double payment of the same fill** (`:4207-4321`): a sibling APPROVED sale with the same pump and amount ±0.01, within 15 min (with tx) or 45 s (without).
  - For card sales, the RRN/approval code must also match.
  - **Manual sales are never collapsed.**
- **Session binding** (`:4155-4205`): `created_at` decides which session gets the sale.
  - A closed session or pre-session time makes the sale `blocked`.
  - An APPROVED sale auto-opens a session if needed.
- **PENDING with tx:** creates a draft `pos.order` and broadcasts `pts_fill_unpaid` on the bus.
- **APPROVED:** creates a paid `pos.order` via `mobile_pos_sync_order` and broadcasts `pts_fill_paid`. Picking and invoice are deferred to the drain cron (every 5 min).
- **Manual sales:** a synthetic `pts.petrol.transaction` with `external_tx_id="mobile-<uuid>"` is created unless a real PTS tx matches by id, or within ±35 min (`:764-849`).
  - This explains the `mobile-…` ids observed live.
  - **The prefix `mobile-` is reserved.**

### Return shape

```
{status: created|updated|duplicate, id, canonical_uuid,
 pos_sync{synced, order_id, state, deferred, blocked, error},
 already_paid?, invoice_id?}
```

## 4. Locks and permits

- `pts.mobile.pump.permit`: the pay lock.
  - Unique `(pos_config_id, pump_number)` plus an advisory lock.
  - TTL 90 s (range 15–600 s), with extend and release.
  - Expired permits are purged every 2 min.
  - **Fails open** when config or pump is missing.
  - The wrapper also checks employee→pump assignment and `already_paid`.
- `pts.mobile.pump.auth`: who authorized the pump.
  - One open claim per `(config, pump)`, TTL 1800 s.
  - It is consumed when the sale registers, and the authorizer becomes the operator of the fill.
- Hardware `PumpLock`/`PumpUnlock` exist only as backend buttons.

## 5. Shifts and POS sessions

- **`pts.mobile.shift`** is the shift FuelPay uses. Its `station_id` points to **`pts.backend.config`**.
  - **Open** is idempotent by `mobile_uuid`, adopts orphan sessions, and does a fast open without counter capture.
  - **Close** works like this:
    1. Pulls unsynced APPROVED sales.
    2. Computes variance. Above 5% it is only logged.
    3. Closes the POS session. The shift closes **only if the POS session really closed**.
  - **Close is blocked by remaining draft orders** (`UserError … N draft order(s) remain`, `pts_pos_mobile_sync.py:2147-2152`). Unbalanced sessions are force-closed via the core wizard.
- **`pts.shift`** is the forecourt shift. There is one open per station, with meter and tank capture and optional `ShiftOpen`/`ShiftClose` to PTS. Mobile fast paths skip it.
- **`pts.pos.pump.counter`** holds opening/closing meters per session and nozzle. Closing meters are captured by cron, or back-filled from paid lines.

## 6. Payments (DigitalPay)

- Odoo-side cloud API `https://mqtt.digitalpay.sa/v3`:
  - endpoints: `/checkout/`, `/status/{code}/`, `/cancel/`;
  - statuses: PENDING, PROCESSING, APPROVED, DECLINED, CANCELLED, FAILED_*.
  - **This status query is the only payment-outcome recovery mechanism in the estate.** FuelPay uses the app-to-app SDK instead and never queries status.
- FuelPay card fields are mapped onto `pos.payment` (`payment_ref_no`=RRN, `payment_method_authcode`).
- Bank reconciliation (`pos_payment_reconcile`) matches on RRN plus approval code.

## 7. Station / company mapping

The mapping chain is:

```
pos.config.pts_station_id → pts.station (company_id required) → active_backend_config_id → pts.backend.config
```

- There is no controller serial field and no uniqueness on the URL. `cfg_configuration_identifier` is the closest identifier.
- There is no `branch_id` in any PTS module.
- `_pts_active_backend_for_company` falls back to **any** active backend if a company has none (`pts_backend_config.py:327-337`). This is a cross-station risk.
- `request_policy` given to devices is `max_concurrent=1, digest_serial, max_packets=8`. **The controllers cannot handle parallel Digest sessions, so all access to a controller must be serialized.**
