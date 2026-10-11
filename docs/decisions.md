# Decisions log

| # | Decision | Status | Date |
|---|---|---|---|
| D1 | PTS insertion point: rewrite `pts.backend.config.direct_pts_url` per station to the mediator's jsonPTS endpoint | **Proposed** — awaiting approval | 2026-10-11 |
| D2 | Mediator detects, links and surfaces duplicate candidates; it never auto-suppresses or alters sales (initially) | **Proposed** | 2026-10-11 |
| D3 | First pilot runs in observe-only mode (read controller history and Odoo; not in request path) | **Proposed** | 2026-10-11 |
| D4 | `yiisoft/app-api` 1.4.0 + `yiisoft/db-mysql` + `yiisoft/db-migration`, no ORM | **Proposed** | 2026-10-11 |
| D5 | Mediator-issued, hashed per-station (later per-device) credentials; real PTS credentials never leave the mediator | **Proposed** | 2026-10-11 |
| D6 | Scaffold commits on `main`, then one branch per phase; no pushes without approval | **Proposed** | 2026-10-11 |
| D7 | All scratch/probe code lives in `../fuel-scratpad/` | **Accepted** (user instruction) | 2026-10-11 |
| D8 | PTS and Odoo access limited to reads; nothing pushed | **Accepted** (user instruction) | 2026-10-11 |
