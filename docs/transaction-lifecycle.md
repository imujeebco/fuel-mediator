# Transaction lifecycle and reconciliation rules

Status: Phase 1 design, 2026-10-11. Transitions are enforced in code by a transition table per entity (`App\…\StateMachine`).
Every transition writes an `audit_events` row in the same DB transaction. Any transition not listed is rejected.

## 1. PTS command (`commands`)

```
RECEIVED ──journaled──► QUEUED ──gateway sends──► SENT ─┬─ confirmation ─────► CONFIRMED
    │                     │                             ├─ error packet ─────► FAILED
    │                     └─ expired in queue (>8 s) ─► FAILED (never sent)
    └─ duplicate within idempotency window ─► (returns original result, no new row)
                                                        └─ timeout / 5xx / transport ─► OUTCOME_UNKNOWN
OUTCOME_UNKNOWN ──evidence──► RESOLVED_EXECUTED | RESOLVED_NOT_EXECUTED | NEEDS_REVIEW (after 30 min)
```

| From | To | Condition |
|---|---|---|
| RECEIVED | QUEUED | Persisted. Controller enabled and circuit closed. |
| RECEIVED | FAILED | Controller disabled or circuit open. The device gets an error packet immediately. |
| QUEUED | SENT | Controller lock acquired and request written to the socket |
| QUEUED | FAILED | Waited longer than the queue budget, so the device would already have timed out. **It is not sent late.** |
| SENT | CONFIRMED / FAILED | Controller reply received (`PumpAuthorizeConfirmation`, `Message:"OK"`, or an error packet) |
| SENT | OUTCOME_UNKNOWN | No reliable reply inside `command_timeout_ms` |
| OUTCOME_UNKNOWN | RESOLVED_EXECUTED | **Authorize:** a status showing AUTHORIZED/FILLING on that pump/nozzle, a `fill_event` that starts within 3 min, or a controller transaction with the matching tx number. **Stop:** the pump left the FILLING state within 10 s. |
| OUTCOME_UNKNOWN | RESOLVED_NOT_EXECUTED | **Authorize:** the pump stayed IDLE or NOZZLE_UP with an unchanged meter for 3 min and no matching transaction appeared. |
| OUTCOME_UNKNOWN | NEEDS_REVIEW | Not resolvable after 30 min. A `discrepancy(unknown_command_outcome)` is raised. |

**Hard rules:**
- The mediator **never re-sends** a physical command on its own initiative.
- An unresolved `OUTCOME_UNKNOWN` authorize on a pump adds a warning to the next `PumpAuthorize` reply for that pump. The command is still forwarded: the worker decides, and the mediator never blocks dispensing.
- Stops are never queued behind non-stop work and are never deduplicated.

## 2. Fill event (`fill_events`)

```
OBSERVED ─► MATCHED (confirmed|probable link to an odoo_sale)
        ├─► AMBIGUOUS (≥2 candidate sales or ≥2 candidate fills) ─► MATCHED | UNMATCHED (by review)
        └─► UNMATCHED (no sale after the grace period) ─► MATCHED (a late sale arrives) | ACCEPTED_LOSS (reviewed)
```

- **Sources:**
  - `status_transition`: FILLING → IDLE with `LastVolume>0`, read from the collector's own polls. The same rule FuelPay uses.
  - `report`: controller `ReportGetPumpTransactions`.
  - `authorize_confirmation`: tx number only, enriched later.
- Events from different sources describing the same fill merge on the unique `(controller, pump, nozzle, meter_volume_after)`.
- **Grace period** before UNMATCHED: 30 min (configurable). FuelPay can register sales late from its offline queue.

## 3. Sale observation (`odoo_sales`)

The state mirrors Odoo (`payment_status`, `pos_sync_status`). The mediator adds a **match state**: `UNLINKED → LINKED | NO_FILL_EXPECTED (manual station, D9) | SALE_WITHOUT_FILL (PTS station, grace expired) | DUPLICATE_CANDIDATE`.

## 4. Discrepancy (`discrepancies`)

```
OPEN ─► ACKNOWLEDGED ─► RESOLVED (with note)
  │          └────────► DISMISSED (with note)
  └─► RESOLVED (automatically, when the cause disappears, e.g. a late sale matches)
```

- Automatic resolution is allowed only when the triggering condition is no longer true, and it records `actor=system`.
- A human resolution requires a note.
- A re-run of reconciliation updates the existing row (same `fingerprint`), never a new one.

## 5. Outbox item (`outbox`)

```
PENDING ─claim─► PROCESSING ─ok─► DONE
                     ├─ retryable error ─► PENDING (attempts+1, next_attempt_at = backoff)
                     ├─ attempts ≥ max ─► DEAD (visible on dashboard)
                     └─ lock expired (worker crash) ─► reclaimable as PENDING
```

- Backoff: 30 s × 2^attempt, capped at 15 min, ±20% jitter.
- **Any outbox kind that creates something in Odoo must search by its external reference before creating** (read-before-retry). No such kind is enabled in the observe phase (D8).

## 6. Reconciliation rules

### 6.1 Volume level (complete wherever meters exist)

For each nozzle and each window W (a fixed 15-min bucket, and each POS session):

```
dispensed(W) = meter(end of W) − meter(start of W)    -- from the nearest snapshots bracketing W
sold(W)      = Σ volume of APPROVED odoo_sales for that pump/nozzle with sale_created_at in W
gap(W)       = dispensed(W) − sold(W)
```

- Sales are attributed to the window of their **linked fill's `ended_at`** when a link exists, and to `sale_created_at` otherwise. This avoids penalising late registration.
- **Thresholds:** report when `|gap| > max(0.5 L, 0.5% of dispensed)` over a session, or above 2 L in a single bucket. Both are configurable per station.
  - `gap > 0` → `unsold_volume`, valued at the period's unit price.
  - `gap < 0` → `oversold_volume`, indicating a possible duplicate or a wrong pump on the sale.
- Meter integrity checks:
  - a **decreasing** meter → `meter_regression` (reset or replacement); the window is not reconciled automatically;
  - a gap in snapshot coverage longer than 15 min → `collector_gap`; the window is marked *incomplete*, never "clean".

### 6.2 Fill level (best effort)

Candidate sales for a fill F are on the same station and pump, with nozzle equal (or unknown), within `[F.started_at − 2 min, F.ended_at + grace]`. Rules are applied in order:

1. `odoo_sale.pts_transaction_id == F.pts_transaction_no` → **confirmed** (`tx_id`).
2. Exactly one candidate with amount within ±0.05 SAR **and** volume within ±0.05 L → **confirmed** (`fingerprint`).
3. Exactly one candidate with amount within ±0.05 SAR → **probable**.
4. Two or more candidates → **ambiguous**, review required.
5. None → wait for the grace period, then `unmatched_fill`.

Amount and time alone **never** produce a "confirmed" link (requirement 5.5). Every sale is linked to at most one fill, and every fill to at most one non-duplicate sale.

### 6.3 Duplicate candidates (detect and report only, D2)

A `duplicate_candidate` is raised when **two or more APPROVED sales** link to the same fill. The classic case is a manual sale after a timeout plus a later PTS sale. It is also raised when the oversold volume in a session equals the volume of specific sales there.

- The mediator **never** cancels, merges or edits a sale.
- Card sales with different RRNs are flagged as "two bank charges" (high severity). Odoo itself never merges these.

### 6.4 Session level (the consolidated report, D10)

For each `pos.session`:

- meter-dispensed volume and value;
- sold volume and value (fuel lines);
- tenders by method (cash, card/DigitalPay, other);
- counts of unmatched fills, sales without a fill, duplicate candidates, unknown command outcomes, unsynced/failed sales (`pos_sync_status` failed/pending/blocked);
- balancing orders placed in a following session (Q6). These are identified by pattern only (e.g. a manual sale with a round quantity at session open), and only suggested, never assumed.

A session is reported as **clean** only when every check passes and the meter coverage is complete. Otherwise it is **has_discrepancies** or **incomplete**. The mediator never blocks session closure (D10).
