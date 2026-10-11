# FuelPay → Odoo client contract (as implemented)

Source: `fuelpay/` branch `staging-odoo` @ `91848ff` (app 0.2.23). Read-only static analysis.
`OC` = `fuelpay/app/src/main/java/sa/fuel/pos/terminal/data/odoo/OdooClient.kt`.
**Status: derived from client source code. Not yet verified with captured device traffic.**

## 1. Transport

- **One endpoint only: `POST {odooUrl}/jsonrpc`.** It is standard Odoo external JSON-RPC (`service = db | common | object`). The app makes **no** `/web/session/*`, `/web/dataset/call_kw` or custom controller calls, and keeps no cookies (`OC:2592-2660`).
- URL normalisation (`SecureSettings.kt:18-33`, `OC:2566-2579`):
  - trim, drop a trailing `/` and a trailing `/jsonrpc`;
  - add `https://` if no scheme is given;
  - **keep any path prefix**, so `https://mediator/odoo` becomes `https://mediator/odoo/jsonrpc`.
- Login: `common.authenticate(db, user, password_or_api_key, {})` runs once and the uid is cached in memory. Every later call is `object.execute_kw(db, uid, password, model, method, args, kwargs)`, so **the password travels in every request body**.
- Envelope: `{"jsonrpc":"2.0","id":<increasing>,"method":"call","params":{"service","method","args"}}`.
- Timeouts: connect 5 s and read 15 s. `mobile_open_shift` / `mobile_close_shift` use a 60 s read timeout.
- **HTTP 429** is retried 3× (1 s / 3 s / 8 s plus jitter); `Retry-After` is honoured (max 120 s). Other failures are not retried inside the transport.
- Pacing: at least 150 ms between RPCs, process-wide.
- An HTML body resets the uid and fails, so a mediator must never return an HTML page.
- Errors: the app reads `error.data.name` and `error.data.message`. `UserError` containing "No open POS session" maps to `no_open_session`.
- **HMAC**: `HmacSigner` (HMAC-SHA256, key derived from a constant compiled into the APK plus the terminal id) is computed and stored locally but **never transmitted**. There is no signing, nonce or replay protection on the wire.
- `LoggingInterceptor` logs the first 500 characters of each request body. That includes the Odoo password in the device log.

## 2. Model/method inventory (≈49 pairs)

| Area | Calls |
|---|---|
| Setup | `db.list`; `common.authenticate`; `pos.config.pts_api_bootstrap_proxy`; `pos.config.mobile_get_price_version` (every 5 min); `product.template.search_read` (legacy fallback); `res.company.search_read` |
| Stations / pumps | `pts.backend.config.mobile_list_stations`; **`pts.backend.config.mobile_get_pumps`** (returns pumps, nozzles, prices, **and the PTS URL plus credentials**); `pts.backend.config.mobile_get_fuel_price` (unused) |
| Operators | `pos.config.mobile_list_employees`, which returns **employee PINs in clear text**. PINs are validated on the device. |
| Terminal | `pts.mobile.terminal.mobile_register_terminal`; `pts.terminal.sale.mobile_terminal_order_high_water` (payment is refused if this fails on a fresh install) |
| Sales | **`pts.backend.config.register_terminal_sale`** (upsert by `uuid`; returns `status`, `message`, `already_paid`, `pos_sync{synced,error,already_paid}`, `canonical_uuid`); `register_terminal_sales_bulk`; `mobile_retry_failed_pos_syncs` |
| Cross-device coordination | `request_mobile_pump_permit` (lock / assignment check, TTL); `release_mobile_pump_permit`; `extend_mobile_pump_permit` (90 s TTL heartbeat every 30 s); `mobile_claim_pump_auth`; `mobile_peek_pump_auth`; `pos.config.mobile_get_paid_fills` (every 15 s); `pos.config.mobile_get_unpaid_fills`; `pos.config.mobile_is_fill_paid` |
| Meters | `pts.backend.config.register_order_meter_event` (after pay); `pts.mobile.shift.mobile_sync_session_counters`; `pts.nozzle.reconciliation.sync_from_mobile`; `pts.mobile.shift.mobile_get_previous_closing_meters`; `mobile_analyze_session_meters` |
| Shift / POS session | `pts.mobile.shift.mobile_get_active_shift` (every 30 s); `mobile_open_shift` (idempotent by `mobile_uuid`); `mobile_close_shift`; `mobile_calculate_auto_opening`; `mobile_calculate_expected_closing`; `mobile_count_session_draft_orders`; `mobile_get_shift_sales`; `mobile_get_pos_session_summary` |
| Invoices | `pos.order.mobile_request_invoice`; `pos.order.mobile_get_receipt_qr` |
| Tanks | `pos.config.mobile_fuel_tanks`; `pts.tank.delivery.mobile_record_delivery`; `pts.petrol.tank.mobile_sync_tank_levels` |
| Customers / WhatsApp | `res.partner.search_read/create/write`; `whatsapp.template.search_read`; `mail.message.create`; `whatsapp.message.create`; `whatsapp.message.action_send_now` |

The app never calls `pos.order.create_from_ui` or `pos.session` directly. The Odoo addons own POS order and session creation.

## 3. Sale payload and idempotency

- Payload fields (`OC:1610-1653`):
  - **Identity:** `uuid`, `created_at_ms`, `pos_config_id`, `terminal_id`, `terminal_order_no`, `terminal_order_ref`, `station_id`, `pts_station_id`.
  - **Fuel:** `pump_number`, `nozzle_number`, `fuel_name`, `fuel_grade_id`, `price`, `volume`, `amount_sar`, `pts_transaction_id`, `manual_sale`.
  - **Payment:** `payment_method`, `payment_method_id`, `payment_lines_json`, `payment_status`, `rrn`, `approval_code`, `card_label`, `card_number_masked`, `dp_terminal_id`, `dp_merchant_id`, `dp_response_json`.
  - **People and other:** `partner_id`, `partner_name`, `operator_id`, `operator_name`, `shift_local_id`, `change_amount`, `price_locked_at_ms`, `to_invoice`, `sale_lines_json`.
- **Idempotency key = `uuid`.** Retries re-send the same uuid. How the uuid is chosen:
  1. the persisted in-flight Digital Pay uuid;
  2. otherwise the unpaid fill's uuid;
  3. otherwise the deterministic `pts-tx-<tx>-p<pump>`;
  4. otherwise a random uuid (manual sales without a tx).
- Odoo may return `canonical_uuid` to merge drafts created by two terminals.
- "Synced" means `pos_sync.synced` or `already_paid`. A transport timeout leaves the sale queued for retry with the same uuid.
- Retryable message markers (the mediator must reuse these): `no open pos session`, `no_open_session`, `timeout`, `connection`, `unavailable`, `serialize`, `concurrent`, `try again`, `temporarily`, `429`, `502`, `503`, `504`, `cross_session:`.
- `OdooSyncWorker` runs every 5 min and drains APPROVED unsynced sales one at a time.
  - The bulk endpoint means "stored", while `confirmSale` means "paid POS order exists". Their semantics differ.

## 4. Payments (Digital Pay SDK, on-device)

- `API_PERFORM_PURCHASE(amountHalalas, commandIdentifier = saleUuid)`.
  - **Approved** when `R200` + `APPROVED` + code 0..89; −7 means cancelled.
- The sale row is persisted as PENDING **before** the SDK call. Only APPROVED sales are sent to Odoo.
- **Purchases are never auto-retried.** If the process dies mid-payment, the attendant is asked to confirm or discard. Confirming promotes the sale to APPROVED **without an RRN**, an unverified card sale.
- `reconciliation()` and `reversal()` exist in the SDK wrapper but are **never called**. There is no automatic void and no terminal status query.
- **Payments do not traverse PTS or Odoo during authorization.** The mediator can only observe payment outcomes after the fact, through `register_terminal_sale`.

## 5. Implications for an Odoo-compatible mediator endpoint

1. It must implement `/jsonrpc` with `db.list`, `common.authenticate` and `object.execute_kw`, and dispatch about 49 model/method pairs.
   - Most should pass through transparently, but **only via an allow-list**. Otherwise the mediator becomes an open proxy to Odoo with the device's credentials.
2. Transaction-aware interception candidates:
   - `register_terminal_sale(_bulk)`: journal, correlate, dedupe, outbox;
   - permit / claim / peek / paid-fills: can be served from mediator state;
   - `mobile_get_pumps`: must rewrite `pts_connection` to point to the mediator and **must not hand real PTS credentials to devices**;
   - `mobile_open_shift` / `mobile_close_shift`: session association and the reconciliation gate.
3. Device identity today is a shared Odoo user (password or API key) plus a self-chosen `terminal_id`. There is **no per-device secret**, so device authentication for the mediator needs a design decision.
4. The client keeps the uid forever and sends the password each call. The mediator can map (db, login, password) to a device credential, but the credentials must be stored hashed, never logged.
