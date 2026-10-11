# Open questions

Full context is in [phase0-discovery-report.md](phase0-discovery-report.md) §7. Items marked ⚠ affect safety, money or security.

1. Where is the source of `odoo_pts_bridge_tx_hotfix`? It is installed on Odoo but missing from `fuel-odoo`. 
Prehaps it is error.
2. ⚠ PTS controllers are reachable publicly via Cloudflare with default credentials. Can access be restricted?
It is not needed at moment
3. Should Odoo's crons also go through the mediator, or only FuelPay?
Only FUel Pay. What Odoo is managing let it manage accordingly. 
4. ⚠ Who approves station-level Odoo config writes (`direct_pts_url`) for the pilot?
The Admin
5. Can FuelPay's Odoo URL be changed on pilot devices?
Yes it can be changed and configurable
6. ⚠ What is the policy for an APPROVED card sale with no RRN (crash during payment)?
Not expected. but at moment if somehting is not recoreded then at end of sessoin close a new session is opened, balance entries are parked 1 to 2 order are placed to ablance the qunatity and cash and bank collectios
7. ⚠ How should POS session closure handle unresolved discrepancies: block, warn, or supervisor override?
We need to have a consolidated report on the mediator about it. 
8. Which stations are non-PTS, and which have LAN-only PTS?
All the stations on Odoo who have PTS set to Inactive are No Pts stations
9. What are the active devices per station at peak, and the latency target for authorize?
4 to 6 devices Android Payment Terminals
10. ⚠ Can a Digital Pay status/reconciliation query be made for SDK transactions?
Possible.Perhaps. I am not sure. But if needed i have SDK from developer.
11. Is the PTS WebSocket upload (port 6600) intended? Is its server live?
If possible then OK but I would want to get into this after all tests and success. 
12. What is the central hosting target (OS, region, network path)?
Ubuntu, Linux on a COntabo server
13. What is the retention period for journal and audit data?
3 months. but configurable.
14. The architecture doc's questions §13 (1–10) remain open, except Q1, which is partly answered: the central server *can* reach PTS through `stationNN.fuel.net.sa`.
Dont know but proceed
---
**Status (2026-10-11):** answers above are recorded as decisions D1, D9–D12 in [decisions.md](decisions.md).
Still open: Q10 (Digital Pay SDK status query; the user has the SDK if needed), Q11 (WebSocket upload, deferred until after tests) and Q14.
Q1: treated as a probable error; not pursued.

15. Are FuelPay devices at Station 11 (and others) running with PTS switched off (manual entry)? Evidence: 100% `manual_sale`, no PTS tx ids, meter events `source=manual`. See [discovery/reconciliation-feasibility.md](discovery/reconciliation-feasibility.md).
16. Why does the PTS controller record only a small fraction of fills as transactions (e.g. 29 vs 1,415 on 2026-10-09)? Pump standalone/auto-authorize mode or a configuration issue?
17. D9 says non-PTS stations are those with "PTS set to Inactive". Which flag is meant?
    - the `pts.backend.config` **Active** checkbox, or
    - the POS config **Enable fuel sale (PTS)** (`pts_enable_fuel_sale`)?

    The import currently uses the active backend config. As a result, Station 36's config (named "Station 36 NO PTS") is active and has a URL, so it is classified as **pts**. The stations classified manual today are Fuel Station 38, Fuel Station 41 and Station40. A per-station `mode_override` column exists for corrections.
18. Odoo lists 19 pumps for Station 11, but the controller reports 18. Should the registry follow the controller configuration (the source of truth for dispensing) and flag the difference?
19. FuelPay's price list screen sends `SetFuelGradesPrices`. Should the mediator forward it, or deny it so that prices stay with Odoo's price push? See `api-compatibility-matrix.md` §1.
