# Decisions log

| # | Decision | Status | Date |
|---|---|---|---|
| D1 | **Only FuelPay traffic goes through the mediator** (Odoo's crons keep talking to PTS directly, per Q3). FuelPay's Odoo URL is pointed at the mediator's Odoo-compatible relay. The relay rewrites `pts_connection` in `pts.backend.config.mobile_get_pumps` responses so the device's PTS URL becomes the mediator's per-station jsonPTS endpoint. No Odoo configuration or code change. PTS remote-server/WebSocket upload is a possible later improvement; it is not relied on. | **Accepted (revised)** | 2026-10-11 |
| D2 | The mediator detects, links and surfaces duplicate candidates for review only. Odoo's existing dedupe stays authoritative. **Core objective is reconciliation.** | **Accepted** | 2026-10-11 |
| D3 | Observe-only first: read controller history and Odoo outside the live request path. **Primary objective: no sale is lost.** Every dispensed controller transaction must be accounted for by an Odoo sale, or be reported. | **Accepted** | 2026-10-11 |
| D4 | Use the provided Yii3 app in `core-engine/` (`yiisoft/app` 1.4.0) with the Zuberi layer removed; `yiisoft/db-mysql` + `db-migration`, no ORM | **Accepted** | 2026-10-11 |
| D5 | Credentials and access hardening are **deferred**. Baseline hygiene still applies: no secrets in git or logs. | **Deferred** | 2026-10-11 |
| D6 | Commits on `main`, no pushes without approval | **Accepted** | 2026-10-11 |
| D7 | All scratch/probe code lives in `../fuel-scratpad/` | **Accepted** (user instruction) | 2026-10-11 |
| D8 | PTS and Odoo access limited to reads; nothing pushed | **Accepted** (user instruction) | 2026-10-11 |
| D9 | Non-PTS station = a station whose PTS configuration (`pts.backend.config`) is inactive in Odoo (Q8) | **Accepted** | 2026-10-11 |
| D10 | Session-close discrepancies: no blocking. The mediator provides a consolidated reconciliation report per station/session (Q7). It recognises the current practice of parking balance entries and placing 1–2 balancing orders in a new session (Q6). | **Accepted** | 2026-10-11 |
| D11 | Production target: Ubuntu Linux on a Contabo server (Q12). Journal/audit retention 3 months, configurable (Q13). | **Accepted** | 2026-10-11 |
| D12 | Sizing assumption: 4–6 Android payment terminals per station at peak (Q9) | **Accepted** | 2026-10-11 |
| D13 | Continuous read-only polling of controllers is permitted. All PTS data (status, meters, configuration, transactions) is read from the controller directly, never via Odoo. | **Accepted** | 2026-10-11 |
| D14 | Station mode: a backend config with Active = false means non-PTS (Q17). This is already how `station:import` works. | **Accepted** | 2026-10-11 |
| D15 | The pump/nozzle registry follows the controller's own configuration (Q18). Pumps that exist only in Odoo are kept but flagged `on_controller = 0` and excluded from polling. | **Accepted** | 2026-10-11 |
| D16 | `SetFuelGradesPrices` from devices is forwarded (QUEUE, journaled), not denied (Q19) | **Accepted** | 2026-10-11 |
| D17 | Controller credentials: `controllers.secret_ref` names an environment variable (default `PTS_PASSWORD_<odoo_backend_config_id>`) that is read from `.env` or server env. Passwords are never stored in the DB or git, and are not copied from Odoo. | **Accepted** (interim, until D5) | 2026-10-11 |

## Notes on D1

- Mixed mode is safe. A device still configured with the real Odoo URL keeps working directly; it is simply invisible to the mediator.
- Rollback for a device is a settings change: restore the Odoo URL.
- The relay must be allow-listed (not an open proxy) and must reproduce Odoo's JSON-RPC envelopes exactly. Contract: [discovery/fuelpay-odoo-contract.md](discovery/fuelpay-odoo-contract.md).
- Odoo's own PTS polling continues, so the controller sees the mediator plus Odoo instead of 4–6 devices plus Odoo.
