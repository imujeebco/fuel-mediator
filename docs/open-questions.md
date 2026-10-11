# Open questions

Full context is in [phase0-discovery-report.md](phase0-discovery-report.md) §7. Items marked ⚠ affect safety, money or security.

1. Where is the source of `odoo_pts_bridge_tx_hotfix`? It is installed on Odoo but missing from `fuel-odoo`.
2. ⚠ PTS controllers are reachable publicly via Cloudflare with default credentials. Can access be restricted?
3. Should Odoo's crons also go through the mediator, or only FuelPay?
4. ⚠ Who approves station-level Odoo config writes (`direct_pts_url`) for the pilot?
5. Can FuelPay's Odoo URL be changed on pilot devices?
6. ⚠ What is the policy for an APPROVED card sale with no RRN (crash during payment)?
7. ⚠ How should POS session closure handle unresolved discrepancies: block, warn, or supervisor override?
8. Which stations are non-PTS, and which have LAN-only PTS?
9. What are the active devices per station at peak, and the latency target for authorize?
10. ⚠ Can a Digital Pay status/reconciliation query be made for SDK transactions?
11. Is the PTS WebSocket upload (port 6600) intended? Is its server live?
12. What is the central hosting target (OS, region, network path)?
13. What is the retention period for journal and audit data?
14. The architecture doc's questions §13 (1–10) remain open, except Q1, which is partly answered: the central server *can* reach PTS through `stationNN.fuel.net.sa`.
