# Database design

Status: Phase 1 design, 2026-10-11. MySQL 8.4, InnoDB, `utf8mb4_0900_ai_ci`.

## Conventions

- **Time:** every timestamp is `DATETIME(3)` in **UTC**; the connection sets `time_zone='+00:00'`. Controller local times are converted with the station's `utc_offset_minutes` (Station 11: +180). Raw controller times are also stored as strings for audit.
- **Money:** `DECIMAL(14,2)`. Volume and meters: `DECIMAL(14,3)`. Floats are never used.
- **Identity:** surrogate `BIGINT UNSIGNED AUTO_INCREMENT` primary keys. External identifiers get **unique constraints scoped to their real namespace** (e.g. `(controller_id, pump_number, transaction_no, …)`, never `transaction_no` alone).
- **Station scope:** every operational table has `station_id` (FK) and its indexes lead with it.
- **Migrations:** `yiisoft/db-migration`, revertible. Nothing is dropped or truncated in place.
- **Retention:** high-volume tables (`meter_snapshots`, `pump_status_history`, `relay_calls`) are purged after `RETENTION_DAYS` (default 90, D11) by a console command. Discrepancies and audit events are kept until resolved plus the retention period.

## Tables

### Configuration

| Table | Key columns | Constraints |
|---|---|---|
| `stations` | `code`, `name`, `odoo_station_id`, `odoo_company_id`, `mode` (`pts`/`manual`, D9), `utc_offset_minutes`, `active`, `settings` JSON | UNIQUE `odoo_station_id`, UNIQUE `code` |
| `controllers` | `station_id`, `odoo_backend_config_id`, `name`, `url`, `auth_mode`, `username`, `secret_ref` (credentials deferred, D5; never logged), `verify_ssl`, `enabled`, `poll_interval_ms` (10000), `totals_interval_s` (300), `freshness_ms` (1500), `stale_ms` (5000), `command_timeout_ms` (10000), health columns (`last_ok_at`, `last_error`, `consecutive_failures`, `circuit_open_until`) | UNIQUE `odoo_backend_config_id` |
| `pumps` | `controller_id`, `pump_number`, `odoo_pump_id` | UNIQUE `(controller_id, pump_number)` |
| `nozzles` | `pump_id`, `nozzle_number`, `fuel_grade_id`, `fuel_name`, `odoo_nozzle_id` | UNIQUE `(pump_id, nozzle_number)` |
| `devices` | `station_id` (from the last `mobile_get_pumps`), `terminal_id`, `odoo_uid`, `odoo_login`, `app_version`, `last_ip`, `first_seen_at`, `last_seen_at` | UNIQUE `terminal_id` |

### Observation (collector)

| Table | Purpose | Constraints / indexes |
|---|---|---|
| `pump_status_cache` | The latest status packet per pump, used to serve devices | PK `(controller_id, pump_number)`; `packet` JSON, `observed_at`, `state` |
| `meter_snapshots` | **Primary dispensing evidence.** Holds `total_volume`, `total_amount`, `source` (`status`/`totals`/`report`/`device_totals`/`odoo_counter`), `observed_at`, `controller_time_raw` | INDEX `(station_id, controller_id, pump_number, nozzle_number, observed_at)` |
| `fill_events` | One physical fill as observed: `pts_transaction_no` (nullable), `started_at`, `ended_at`, `volume`, `amount`, `price`, `meter_volume_after`, `meter_amount_after`, `source` (`status_transition`/`report`/`authorize_confirmation`), `state` (see lifecycle) | **UNIQUE `(controller_id, pump_number, nozzle_number, meter_volume_after)`**. The meter reading after a fill is strictly increasing per nozzle, so it is a natural idempotency key that works even without a transaction number. INDEX `(station_id, ended_at)`, INDEX `(controller_id, pump_number, pts_transaction_no)` |

### Commands (PTS endpoint)

| Table | Purpose | Constraints |
|---|---|---|
| `commands` | `public_id` (UUID), `station_id`, `controller_id`, `device_id`, `correlation_id`, `type`, `pump_number`, `nozzle_number`, `request` JSON, `idempotency_key`, `state`, `response` JSON, `pts_transaction_no`, `error`, `received_at`, `sent_at`, `completed_at`, `resolved_by_fill_event_id` | UNIQUE `public_id`; UNIQUE `idempotency_key` (NULL allowed for stops); INDEX `(station_id, state, received_at)` |

### Odoo mirror and relay journal

| Table | Purpose | Constraints |
|---|---|---|
| `odoo_sales` | Mirror of `pts.terminal.sale` (reader and relay). Columns: `odoo_id`, `uuid`, `alias_uuids`, pump/nozzle, volume, amount, price, `payment_status`, `payment_method`, `rrn`, `approval_code`, `manual_sale`, `pts_transaction_id`, `terminal_id`, `operator_name`, `odoo_pos_session_id`, `odoo_pos_order_id`, `pos_sync_status`, `sale_created_at`, `odoo_write_date` | UNIQUE `odoo_id`; UNIQUE `uuid`; INDEX `(station_id, sale_created_at)`, INDEX `(station_id, pump_number, sale_created_at)` |
| `pos_sessions` | Mirror of `pos.session`, plus the linked `pts.mobile.shift`: state, `start_at`, `stop_at`, opening/closing cash, expected and actual totals | UNIQUE `odoo_session_id` |
| `relay_calls` | Metadata for each relayed call: `device_id`, `service`, `model`, `method`, `odoo_rpc_id`, `latency_ms`, `outcome` (`ok`/`odoo_error`/`timeout`/`transport_error`/`denied`), `error_message`. The body is stored only for JOURNAL methods, **redacted** (passwords, PINs and card data removed; masked PAN is kept). | INDEX `(station_id, created_at)`, INDEX `(method, created_at)` |
| `sale_observations` | Sale payloads seen by the relay: `uuid`, `payment_status`, amounts, payment refs, Odoo response status, `canonical_uuid`, `pos_synced` | INDEX `(uuid)`, INDEX `(station_id, created_at)` |

### Reconciliation

| Table | Purpose | Constraints |
|---|---|---|
| `match_links` | Fill ↔ sale links: `method` (`tx_id`/`fingerprint`/`manual`), `confidence` (`confirmed`/`probable`/`ambiguous`), `score`, `reviewed_by`, `reviewed_at`, `review_note` | UNIQUE `(fill_event_id, odoo_sale_id)` |
| `discrepancies` | `type` (`unsold_volume`, `oversold_volume`, `unmatched_fill`, `sale_without_fill`, `duplicate_candidate`, `unknown_command_outcome`, `odoo_sync_failed`, `meter_gap`, `meter_regression`, `session_mismatch`, `collector_gap`), `severity`, `status` (`open`/`acknowledged`/`resolved`/`dismissed`), `period_start`, `period_end`, `odoo_pos_session_id`, pump/nozzle, `volume`, `amount`, `details` JSON, `fingerprint` | **UNIQUE `fingerprint`**, so a re-run updates the existing row instead of duplicating it. INDEX `(station_id, status, type)` |
| `reconciliation_runs` | One row per run: `scope` (`period`/`session`), window, status, statistics JSON | INDEX `(station_id, created_at)` |

### Infrastructure

| Table | Purpose | Constraints |
|---|---|---|
| `outbox` | Durable outbound work: `kind`, `payload` JSON, `idempotency_key`, `status` (`pending`/`processing`/`done`/`failed`/`dead`), `attempts`, `max_attempts`, `next_attempt_at`, `locked_by`, `locked_until`, `last_error` | UNIQUE `idempotency_key`; INDEX `(status, next_attempt_at)`; INDEX `(station_id, status)` |
| `worker_leases` | Per-worker, per-station lease for isolation and crash recovery | PK `(worker, station_id)` |
| `sync_cursors` | Incremental Odoo-read cursors (`write_date`, `id`) per source and station | PK `(source, station_id)` |
| `audit_events` | Append-only: `entity_type`, `entity_id`, `event`, `from_state`, `to_state`, `actor`, `data` JSON, `created_at` | INDEX `(entity_type, entity_id)`; the application never updates or deletes rows |

## Concurrency rules

- A state change is `SELECT … FOR UPDATE` on the row, then a transition check, then an `UPDATE` plus an `audit_events` insert, all in **one transaction**.
- Workers claim outbox rows with `UPDATE … SET locked_by=?, locked_until=? WHERE status='pending' AND next_attempt_at<=NOW(3) … LIMIT n`, then process only the rows they locked. Expired locks are reclaimable, which gives restart recovery.
- Controller serialization uses `GET_LOCK('pts:<controller_id>', timeout)` around each gateway call. It holds across web and worker processes, and is released automatically if the connection dies.
- Upserts of observations (`fill_events`, `odoo_sales`) use `INSERT … ON DUPLICATE KEY UPDATE` against the natural unique key, so they are idempotent under re-reads.
