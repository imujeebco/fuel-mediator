# Reconciliation feasibility — Station 11, 2026-10-09 (read-only)

Scripts: `fuel-scratpad/phase0/feasibility/{pts_vs_odoo_day,meter_vs_sales,meter_events}.py`. They only send `ReportGetPumpTransactions` to PTS and `search_read`/`fields_get` to Odoo.

## Results

| Source | Observation |
|---|---|
| PTS `ReportGetPumpTransactions` (18 pumps, full day) | **29 transactions**, SAR 2,561.90 |
| Odoo `pts.terminal.sale` (station 11, same local day) | **1,415 APPROVED sales**, SAR 81,079.84. **0** carry a `pts_transaction_id`. 18 terminal ids, all worker names. |
| Odoo `pts.order.meter.event` (after-pay meter snapshots) | Present for each sale, but `meter_volume = 0`, `source = manual`. **Not usable as a meter record.** |

### Per pump: controller meter vs. Odoo sold volume

Window = first to last controller transaction of the day. The meter delta comes from the controller totalizers.

| Pump | Controller tx in window | Controller tx volume (L) | Meter delta (L) | Odoo sales | Odoo volume (L) | Unexplained (L) |
|---|---|---|---|---|---|---|
| 5 | 2 (3087–3088) | 237.60 | 1,127.28 | 19 | 1,110.53 | 16.75 |
| 7 | 3 | 123.94 | 123.94 | 3 | 123.94 | 0.00 |
| 8 | 2 (10003–10004) | 22.93 | 2,098.71 | 65 | 2,017.19 | 81.52 |
| 9 | 5 | 125.19 | 1,509.64 | 56 | 1,491.29 | 18.35 |
| 10 | 2 | 42.92 | 42.92 | 2 | 42.92 | 0.00 |
| 14 | 3 | 168.47 | 168.47 | 3 | 168.47 | 0.00 |
| 18 | 7 (11547–11553) | 193.82 | 3,119.38 | 110 | 3,066.17 | 53.21 |

## Conclusions

1. **Controller transaction history is not a complete record of dispensing.** Consecutive controller transaction numbers (for example pump 5: 3087 → 3088) span hundreds of litres of dispensing that the controller never recorded as transactions.
   - This corrects the Phase 0 statement that `ReportGetPumpTransactions` is the key recovery primitive. It can confirm a specific authorized fill, but it cannot prove completeness.
2. **The nozzle totalizer (meter) is the only complete record of physical dispensing.** Where every fill was a controller transaction (pumps 7, 10, 14), meter, controller and Odoo agree exactly.
3. **Meter-vs-sales reconciliation finds unsold volume.** In this sample it is about 0.8–3.9% per busy pump. Some of it may be window-edge effects: a sale registered more than 5 min after the last controller transaction, or a fill that straddles the window. Proper bucketing (by session/shift, continuous meter capture) is needed before treating any of it as loss.
4. **Station 11 terminals appear to run FuelPay in manual mode.** All sales are `manual_sale=True`, none has a PTS transaction id, and meter events are `source=manual`. If confirmed, the PTS-compatible endpoint would see little traffic from this station today. **Meter-based reconciliation is where the value is.**
5. **Odoo already stores session-level meters** (`pts.pos.pump.counter`, 36k rows; `pts.petrol.pump.counter`, 126k rows). They are captured by cron (every 10 min) or back-filled from paid lines. That makes them a secondary source for session reconciliation, but their provenance (`source`) must be checked.
6. **Cloudflare in front of the controller returns 403 for some client signatures** (Python's default User-Agent). curl with its default User-Agent works. The mediator's PTS client must send an explicit User-Agent and treat 403 as a distinct, alertable failure.

## Design consequences (input to Phase 1)

- The observe-mode collector polls each controller **read-only** and records **meter snapshots** plus derived fill events:
  - `PumpGetStatus`, which gives `LastTotalVolume`/`LastTotalAmount`, `LastTransaction` and the `Last*` fill fields;
  - periodic `PumpGetTotals` per nozzle.
- Reconciliation hierarchy:
  1. **Volume level** (always available): meter delta per nozzle per period/session vs. sold volume in Odoo. This reports unsold or oversold litres and their value.
  2. **Fill level** (best effort): fill events derived from status transitions or controller transactions, matched to sales by pump, nozzle, amount/volume and time. This reports unmatched fills and sales without a fill.
  3. **Session level**: Odoo POS session totals vs. the two levels above. This is the consolidated report (D10).
- Load: one batched request per station every 10–15 s (18 pumps = 3 requests of ≤ 8 packets) adds one more client to each controller. The interval must be configurable per station, and the collector must back off on errors.

## Questions raised

- **Q15.** Are FuelPay devices at Station 11 (and others) operating with PTS switched off, i.e. in manual entry mode?
- **Q16.** Why does the controller not record most fills as transactions? Possible causes: pumps in standalone/auto-authorize mode, or a controller configuration issue. This matters for whether fill-level matching can ever be complete.

## Addendum: live status sampling, 2026-10-11 06:41–06:43 (Station 11, read-only)

Script: `fuel-scratpad/phase0/status_sampler.py`. `PumpGetStatus` for all 18 pumps, every 10 s.

1. **Status sees every fill, with a transaction number.**
   - Pump 9 went `PumpFillingStatus` (`Transaction 4520`, live volume) → `PumpIdleStatus` (`LastTransaction 4520`, `LastVolume 3.25`).
   - `LastTotalVolume` rose by exactly 3.25 L (238,680.73 → 238,683.98).
   - So the per-pump transaction counter increments on every fill; only the controller's stored *report* (`ReportGetPumpTransactions`) is incomplete.
   - **Transaction-number continuity per pump therefore proves completeness.** A jump of `k > 1` means `k − 1` fills happened between polls. Their combined volume is the meter delta minus the last fill's volume.
2. **Idle packets intermittently omit `LastTotalVolume`.** Pump 1 alternated between present and missing. A missing total means *unknown*; it is never a change and never zero.
3. **`PumpGetTotals` is asynchronous and occupies the pump.**
   - It returns only `"Message":"OK"`. An immediate second request fails with `Code 41 JSONPTS_ERROR_PUMP_BUSY_OTHER_REQUEST_EXECUTED`; the result surfaces later in status (`LastReceivedTotal*`).
   - **The collector must not send it.** It could collide with a worker's authorize. Status `LastTotalVolume` is sufficient, because meters only move during fills.
4. Pump 19 (present in Odoo) returns `Code 52 JSONPTS_ERROR_PUMP_IS_NOT_CONFIGURED`. This confirms Q18/D15.

**Collector design (Phase 3a), updated:**
- `PumpGetStatus` only, batched ≤ 8 per request, for pumps present in the controller's own `GetPumpsConfiguration`.
- A fill event is recorded when an idle status shows a new `LastTransaction`. Missed fills are counted from transaction gaps.
- A meter snapshot is written when `LastTotalVolume` changes, plus a 5-minute heartbeat to prove coverage.

## Addendum 2: first live reconciliation against production Odoo (2026-10-11)

Production Odoo: `https://fuelksa.odoo.com`, db `iwesabe-fuel-main-11731627`. Access was read-only with user approval. The staging copy stopped receiving sales on 2026-10-10.

### Finding 1: Odoo tags other companies' sales with Station 11

`pts.terminal.sale.station_id` = Fuel Station 11 is set on sales whose POS config belongs to another company:

| POS config | Company / station | Tagged "Station 11" today |
|---|---|---|
| 50 "F011 Answer" | F011 / Station 11 | 62 (correct) |
| 66 "F024 Abrhem slem" | F024 / Station 24 | 55 |
| 211 "Telak" | F038 (no PTS station) | 71 |
| 213 "Jhanger" | F037 / Station 37 | 82 |

Over one day, 823 sales from other companies were tagged Station 11, against 797 genuine F011 sales. A likely cause is Odoo's fallback `_pts_active_backend_for_company` (`odoo_pts_bridge/models/pts_backend_config.py:327-337`), which picks *any* active backend when a company has none. A device's stored station setting is another candidate. **The mediator attributes sales by the POS config's company (D18).**

### Finding 2: with PTS off, the sale's pump number is the fuel grade

Almost all manual sales are keyed as pump 1 (A 91) or pump 2 (A 95), whichever physical pump was used. Per-pump reconciliation is therefore meaningless at these stations. **The mediator reconciles and matches by fuel grade (D19).**

### Result: Station 11, 07:05–07:45 local, grade level

| Grade | Fills | Dispensed L | Sales | Sold L | Gap L |
|---|---|---|---|---|---|
| A 91 | 11 | 274.330 | 8 | 219.267 | +55.063 (unsold) |
| A 95 | 8 | 375.960 | 8 | 375.965 | −0.005 |
| Diesel | 1 | 64.250 | 1 | 67.039 | −2.789 |

Fill-level matching:
- 15 confirmed links, 1 probable. Sales typically arrive 10–60 s after the fill ends.
- Diesel: a 64.25 L fill (SAR 115.00) pairs with a SAR 120.00 sale registered 3 minutes before the fill ended. This is a likely mis-recorded amount.
- An A 91 pump-18 fill (SAR 50.00, 07:26:41) had no sale after 10 minutes.
