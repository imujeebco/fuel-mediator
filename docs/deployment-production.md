# Production deployment and go-live (Ubuntu, Contabo)

Target (D11): one Ubuntu 22.04/24.04 server running nginx, PHP-FPM 8.3, MySQL 8 and two background units. The
devices of the served stations reach it over HTTPS. Files: `deploy/`.

| Piece | What it does |
|---|---|
| nginx `deploy/nginx/fuel-mediator.conf` | HTTPS. Public paths: `/odoo/jsonrpc`, `/stations/{id}/jsonPTS`, `/health`. Everything else returns 404; the monitoring UI is on `127.0.0.1:8081` (SSH tunnel). |
| PHP-FPM pool `deploy/php-fpm/fuel-mediator.conf` | 24 static workers, so a slow controller call never blocks the other terminals. |
| `fuel-mediator-collector.service` | `yii collector:run`: read-only status polling, fill detection, command outcome resolution. |
| `fuel-mediator-sync.timer` (every minute) | `yii sync:run`: Odoo sales and POS sessions for every active station, then fill matching where the mediator collects. Keeps the fill↔sale links that D21 relies on current. |

## 0. Variant: CWP / CloudLinux account (`server.fuel.net.sa`, user `fuelne`)
The provided server is a shared CloudLinux account managed by CentOS Web Panel (Apache, MariaDB, CageFS), not a root
Ubuntu VPS, so `install.sh` (apt, nginx, systemd) does not apply there. Inspection on 2026-10-12 (read-only): SSH port
22 closed, panel on 2083, MariaDB on 3306 (remote access refused), Apache with Let's Encrypt. The account also hosts
other content (`public_html`, `odoo-files`); the mediator lives only in `~/fuel-mediator`.

With SSH enabled for the account (jailed shell is enough):
1. Code in `~/fuel-mediator` (shipped with `push.sh`, which only needs `ssh`/`tar`), `composer install --no-dev`
   there (or upload `vendor/` built locally with the same PHP version).
2. In CWP: a MariaDB database and user, a subdomain (e.g. `mediator.fuel.net.sa`) with document root
   `~/fuel-mediator/core-engine/public` and a certificate, PHP 8.2+ with `bcmath`, `curl`, `pdo_mysql`, `mbstring`.
3. `cp deploy/apache/htaccess core-engine/public/.htaccess` (same public allow-list as nginx).
4. `.env` as in `install.sh`, `php yii migrate:up`, registry (§3).
5. Cron instead of systemd (no long-running processes on CloudLinux):
   ```
   * * * * * cd ~/fuel-mediator/core-engine && php yii collector:run --max-seconds=55 >> ~/fuel-mediator/logs/collector.log 2>&1
   * * * * * cd ~/fuel-mediator/core-engine && php yii sync:run >> ~/fuel-mediator/logs/sync.log 2>&1
   ```
   The collector's lease prevents two runs overlapping; between runs status is still served to devices on demand.
6. Limits to watch: CloudLinux caps concurrent requests per account (entry processes), shared with everything else on
   the account; outbound HTTPS to `station11.fuel.net.sa` must be tested from the server before the switch.

## 1. Server prerequisites
- SSH access as root (or sudo) and a public hostname whose DNS A record points at the server.
- Outbound HTTPS to `fuelksa.odoo.com` and `station11.fuel.net.sa`. The controller sits behind Cloudflare; the
  mediator sends an explicit User-Agent, which Cloudflare accepts.

## 2. Install
On the workstation, from the repository root (deploys the committed HEAD, nothing is pushed to GitHub):
```
SERVER=root@<ip> bash deploy/push.sh
```
On the server:
```
MEDIATOR_HOST=<host> CERTBOT_EMAIL=<email> bash /opt/fuel-mediator/deploy/install.sh
# first run writes core-engine/.env and stops: fill ODOO_USER, ODOO_PASSWORD, PTS_PASSWORD_2, run again
```
Later updates: `SERVER=root@<ip> bash deploy/push.sh --apply` (composer, migrations, cache clear, restarts).

## 3. Station registry (once)
```
cd /opt/fuel-mediator/core-engine
sudo -u fuelmed php yii station:import --dry-run
sudo -u fuelmed php yii station:import
sudo -u fuelmed php yii controller sync-config 2      # or the dashboard: Read pump setup
sudo -u fuelmed php yii controller enable 2 --poll-ms=10000   # or the dashboard: Start collection
systemctl restart fuel-mediator-collector
```
**Stop the laptop collector** once the server collector runs, so the controller is not polled twice.

## 4. Rehearsal (before switching any device)
```
BASE=https://<host> BACKEND=2 ODOO_DB=iwesabe-fuel-main-11731627 ODOO_UID=<device uid> ODOO_PASSWORD=<device password> \
  POS_CONFIGS="50 51" bash deploy/smoke-test.sh
```
Read-only: health, pump configuration and status for all pumps (must be under 3 s), relay of `common.version`,
refusal of a non-listed method, UI not public, pump list, unpaid/paid fill lists. Also check that the collector is
writing snapshots (`/health/stations` through the tunnel) and that `journalctl -u fuel-mediator-sync` shows sales.

## 5. Go-live switch (all Station 11 devices)
1. Set `MEDIATOR_PUBLIC_URL=https://<host>` in `core-engine/.env`, then `rm -rf runtime/cache && systemctl reload php8.3-fpm`.
   From now on, a device that loads its pump list through the mediator gets the mediator as its PTS URL.
2. On every device: set the Odoo URL to `https://<host>/odoo` (FuelPay appends `/jsonrpc`), log in again, reload the
   station/pump list. In settings, the PTS URL must now be `https://<host>/stations/2/jsonPTS`.
3. First sale on each device: watch `relay_calls` and `commands` (§6). One authorize, one fill, one sale.

## 6. Watching the day
The admin dashboard (`/`, through the SSH tunnel) shows every station: PTS or manual mode from Odoo, collection
status, today's sales, open sessions, devices, pumped vs sold where collected, issues. Buttons: *Sync stations from
Odoo*, *Run sync now*, and per station *Sync*, *Read pump setup*, *Start/Stop collection* (audited in `audit_events`).
Controller passwords come from Odoo with *Sync stations from Odoo* and are stored encrypted with `MEDIATOR_SECRET_KEY`
(D23; `install.sh` generates the key). To collect a station: *Read pump setup*, then *Start collection*.

Through the tunnel or `mysql fuel_mediator`:
```sql
-- relay health, last 15 min
SELECT method, outcome, COUNT(*), MAX(latency_ms) FROM relay_calls WHERE created_at > NOW() - INTERVAL 15 MINUTE GROUP BY 1,2;
-- commands sent to pumps and their outcome
SELECT packet_type, state, COUNT(*) FROM commands WHERE created_at > NOW() - INTERVAL 1 HOUR GROUP BY 1,2;
-- D21: fills reported to devices as already paid
SELECT * FROM fill_settlements ORDER BY last_applied_at DESC LIMIT 20;
```
Logs: `/var/log/fuel-mediator/{collector,sync,php-fpm}.log`, `core-engine/runtime/logs/app.log`, nginx logs.

## 7. Rollback
- **One device:** set its Odoo URL back to `https://fuelksa.odoo.com` and reload the pump list. Sales queued on the
  device are kept (same uuids) and sent to Odoo directly.
- **All devices, server still up:** empty `MEDIATOR_PUBLIC_URL` and reload PHP-FPM; each device returns to the
  controller for PTS the next time it reloads its pump list, but its Odoo traffic still goes through the mediator
  until its Odoo URL is restored.
- **Server down:** devices lose Odoo and PTS (pumps show offline, FuelPay falls back to manual sales and keeps them
  queued). Restore each device's Odoo URL.
- **D21 only:** `SETTLED_FILL_FILTER=0` and reload PHP-FPM; devices then see Odoo's unpaid list unchanged.

## 8. Known limits at go-live
- No device authentication on the mediator yet (D5); the pump list still carries the controller credentials.
- `PumpGetTotals` is forwarded on every request (not cached).
- Commands and forwarded packets wait the full timeout when the controller is unreachable (status uses the circuit
  breaker). An authorize that cannot get the controller lock within 3 s is reported "not sent"; FuelPay then falls back
  to a manual sale, which D21 can later settle.
- Retention purge is not scheduled yet; the journal grows until it is (Phase 7).
