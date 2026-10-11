# API compatibility matrix

Status: Phase 1 design, 2026-10-11.

Every contract below comes from **client source code** (see [discovery/](discovery/)). None has yet been validated against captured device traffic. A row is marked ✅ only after a test against captured or mock payloads passes. ✅ (mock) means verified against mocks built from captured shapes, but not yet with a real device.

**Handling modes:**
- **PASS**: forward unchanged and journal the metadata.
- **JOURNAL**: forward unchanged and persist request plus response.
- **REWRITE**: forward, then transform the response.
- **CACHE**: answer from a fresh mediator cache (forward on a miss).
- **QUEUE**: serialize through the controller command queue and journal.
- **DENY**: not on the allow-list; return a JSON-RPC access error.

## 1. PTS-compatible endpoint: `POST /stations/{backend_config_id}/jsonPTS`

Transport requirements (from `fuelpay-pts-contract.md`):
- **Auth:** Digest MD5 `qop=auth` or Basic. A device that sends no auth gets a 401 challenge; an unauthenticated request is never answered with 200.
- **Response:** JSON object with `Protocol` and `Packets[]`. Every pump packet carries `Data.Pump`; packet `Id` mirrors the request.
- **Timing:** reply in under 12 s (device read timeout is 15 s). Up to 8 packets per request.
- **Errors:** error packets put human-readable text in `Error` (a string). Control replies use `Message:"OK"`.

| Packet type (request) | Mode | Mediator behaviour | Validation |
|---|---|---|---|
| `PumpGetStatus` | CACHE | Serve from `pump_status_cache` if younger than `freshness_ms` (1500). Otherwise refresh through the gateway. If the controller is down, serve a snapshot only if it is younger than `stale_ms` (5000), else omit the packet. | ✅ (mock) |
| `GetPumpsConfiguration`, `GetPumpNozzlesConfiguration`, `GetFuelGradesConfiguration` | CACHE (TTL 60 s) | Same keys as the controller. Fuel grades include both `Id/Name` and `FuelGradeId/FuelGradeName/Price`. | pending |
| `ProbeGetMeasurements` | CACHE (TTL 30 s) | Pass the controller's error packet through when no probes exist (Station 11 has none). | pending |
| `PumpGetTotals` | CACHE (TTL 5 s) / forward | Also stored as `meter_snapshots` (source `device_totals`). | pending |
| `PumpAuthorize` | QUEUE | Journal → queue → send once. Return confirmation or error verbatim. Timeout → `OUTCOME_UNKNOWN` error packet, **never re-sent**. Identical authorize within 10 s returns the journaled result. | ✅ (mock) |
| `PumpStop`, `PumpEmergencyStop` | QUEUE (priority) | Jumps the queue. Never coalesced or deduplicated: each stop is sent. Timeout → `OUTCOME_UNKNOWN`, reported immediately. | ✅ (mock) |
| `PumpSuspend`, `PumpResume` | QUEUE | As for authorize, without the idempotency window. | ✅ (mock) |
| `SetFuelGradesPrices` | QUEUE | Forwarded to the controller and journaled as a command (D16). | ✅ (mock) |
| Any other type | DENY | Error packet `"Error":"Not supported by mediator"` | — |

## 2. Odoo-compatible relay: `POST /odoo/jsonrpc`

Transport requirements (from `fuelpay-odoo-contract.md`):
- **Envelope:** Odoo JSON-RPC `{jsonrpc, id, result | error{message, data{name, message}}}`, always as JSON, never HTML.
- **Rate limiting:** HTTP 429 with `Retry-After` when rate-limited.
- **Timeouts:** must fit inside the device's 15 s read timeout (60 s for shift open/close).
- The relay forwards the device's own `db/uid/password` to Odoo, so Odoo stays the authority for permissions. Credential handling is deferred (D5).

| Service / model.method | Mode | Notes |
|---|---|---|
| `db.list` | PASS | — |
| `common.authenticate` | PASS | Journals the uid ↔ device mapping (no password stored). |
| `pts.backend.config.mobile_get_pumps` | **REWRITE** | Replace `pts_connection.direct_pts_url` with the mediator station URL and force `integration_mode="direct"`. `username`/`password` are passed through unchanged for now (D5); later they become mediator-issued. Record the device → station binding. |
| `pts.backend.config.mobile_list_stations` | PASS | — |
| `pts.backend.config.register_terminal_sale` | **JOURNAL** | Core sale observation. |
| `pts.backend.config.register_terminal_sales_bulk` | **JOURNAL** | Per-item observation. |
| `pts.backend.config.request_mobile_pump_permit` / `release_` / `extend_` | JOURNAL | Lock events give evidence for reconciliation. |
| `pts.backend.config.mobile_claim_pump_auth` / `mobile_peek_pump_auth` | JOURNAL | Links authorize ownership to the PTS tx. |
| `pts.backend.config.register_order_meter_event` | JOURNAL | Meter evidence (often 0 today). |
| `pts.backend.config.mobile_retry_failed_pos_syncs` | PASS | — |
| `pts.terminal.sale.mobile_terminal_order_high_water` | PASS | — |
| `pos.config.pts_api_bootstrap_proxy`, `mobile_get_price_version`, `mobile_list_employees`, `mobile_fuel_tanks` | PASS | `mobile_list_employees` returns PINs; the relay must not log response bodies for it. |
| `pos.config.mobile_get_paid_fills` / `mobile_get_unpaid_fills` / `mobile_is_fill_paid` | PASS | High-frequency (15 s). Metadata only, no body journaling. |
| `pos.order.mobile_request_invoice` / `mobile_get_receipt_qr` | PASS | — |
| `pts.mobile.shift.mobile_open_shift` / `mobile_close_shift` | **JOURNAL** | Session association and reconciliation trigger. Longer timeout (60 s). |
| `pts.mobile.shift.*` (other read methods), `mobile_sync_session_counters`, `pts.nozzle.reconciliation.sync_from_mobile`, `mobile_analyze_session_meters` | JOURNAL | Counter rows are meter evidence. |
| `pts.mobile.terminal.mobile_register_terminal` | JOURNAL | Device registry. |
| `pts.tank.delivery.mobile_record_delivery`, `pts.petrol.tank.mobile_sync_tank_levels` | PASS | — |
| `product.template.search_read`, `res.company.search_read`, `res.partner.search_read`, `whatsapp.template.search_read` | PASS | Limited to the exact models listed. |
| `res.partner.create` / `write`, `mail.message.create`, `whatsapp.message.create` / `action_send_now` | PASS | Writes initiated by the device, as today. |
| Anything else | **DENY** | `error.data.name = "odoo.exceptions.AccessError"`, message "Method not allowed through mediator". |

## 3. Behaviour when upstream is unavailable

| Situation | PTS endpoint | Odoo relay |
|---|---|---|
| Upstream timeout | Status: stale rules above. Commands: `OUTCOME_UNKNOWN` error packet. | JSON-RPC error with message containing `timeout` (FuelPay treats this as retryable). |
| Upstream 5xx | Error packet with text `PTS unavailable (HTTP n)` | JSON-RPC error containing `503` |
| Upstream 401/403 | Error packet `PTS rejected mediator credentials` plus an alert. Never forward the controller's challenge to the device. | Pass Odoo's auth error through |
| Malformed upstream body | Error packet `Malformed PTS response` plus the raw body stored for diagnosis | JSON-RPC error `Malformed Odoo response` |
| Mediator DB unavailable | Commands refused (error packet) because they cannot be journaled. Status may still be served from memory for ≤ `stale_ms`. | Relay refuses JOURNAL methods (retryable error). PASS methods continue. |

## 4. Open compatibility questions

- Capture real device traffic (a pilot device pointed at the mediator in pass-through mode) before enabling REWRITE or QUEUE for a live station.
- `SetFuelGradesPrices` policy (see §1).
- Behaviour of FuelPay when `pts_connection.username` is blank: it disables PTS mode. A non-blank username must always be returned.
