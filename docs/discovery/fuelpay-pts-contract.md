# FuelPay → PTS client contract (as implemented)

Source: `fuelpay/` branch `staging-odoo` @ `91848ff` (app 0.2.23). Read-only static analysis.
Paths are relative to `fuelpay/app/src/main/java/sa/fuel/pos/terminal/`.
**Status: derived from client source code. Not yet verified with captured device traffic.**

## 1. Where the PTS URL comes from

- One PTS URL per app install (`SecureSettings` key `pts_url`, `data/prefs/SecureSettings.kt:263-269`). It is used by a single `PtsClient` (`App.kt:118-119`).
- **Odoo supplies it per station.** `pts.backend.config.mobile_get_pumps` returns `pts_connection{integration_mode, auth_mode, verify_ssl, direct_pts_url, backend_url, username, password, request_policy{…}}` (`data/odoo/OdooClient.kt:2004-2033`).
- `applyMobilePtsHints` overwrites the local URL, user, password, auth and SSL settings (`OdooClient.kt:2100-2168`). URL precedence:
  1. If `integration_mode=="backend"` and `backend_url` is set: use `backend_url`.
  2. Otherwise use `direct_pts_url`.
  3. Otherwise use `backend_url`.
- It runs on pump-list load, settings save, shift preview/capture and `ShiftLifecycle`.
- **Consequence:** setting a station's `pts.backend.config` to `integration_mode=backend` with `backend_url=<mediator>` redirects every terminal at that station. No APK change is needed. This is an Odoo config write and needs approval.
- `/jsonPTS` is appended unless present (`data/pts/PtsClient.kt:571-575`). Digest `uri` is the URL path, default `/jsonPTS` (`:577-580`).
- PTS mode requires `ptsEnabled && ptsUrl && ptsUser`, so the username must be non-blank even if the mediator ignores it (`SecureSettings.kt:130-140`).
- `PtsBackendEntry` (local `pts_backends` pref) is used only for price-push fan-out. It is saved as empty.

## 2. HTTP layer

| Aspect | Behaviour | Evidence |
|---|---|---|
| Method / body | `POST`, `{"Protocol":"jsonPTS","Packets":[{"Id":1..n,"Type":…,"Data":{…}}]}` | `PtsClient.kt:481-493` |
| Headers | `Content-Type: application/json; charset=utf-8`, `Accept: application/json, text/javascript, */*; q=0.01`, `Authorization` | `PtsClient.kt:522-588` |
| Timeouts | connect 5 s, **read 15 s**, write 5 s | `PtsClient.kt:70-72` |
| Retries | OkHttp `retryOnConnectionFailure(true)` only. **No app retry for authorize/stop.** | `PtsClient.kt:73` |
| Connections | **New `OkHttpClient` per request**, so no keep-alive | `PtsClient.kt:516` |
| Concurrency | One mutex per device: at most one in-flight PTS request per terminal | `PtsClient.kt:46, 376, 475` |
| TLS | `verifySsl=false` → trust-all plus any hostname. Cleartext allowed app-wide. | `PtsClient.kt:74-85`, `res/xml/network_security_config.xml` |
| Basic | `Authorization: Basic …` on every request | `PtsClient.kt:522-530` |
| Digest | Shared nonce state. With no nonce, the first POST goes **without** auth (a non-401 reply is accepted as is). On 401, parse the challenge and resend once; a second 401 throws. MD5, `qop=auth`, `nc` counter, random cnonce, no `algorithm=`, `stale` ignored. | `PtsClient.kt:532-654` |
| Response | Non-2xx throws `PTS HTTP <code>`. The body must be a JSON **object**. `Protocol` is not checked. Packets are matched by **`Type` and `Data.Pump`, not `Id`**. | `PtsClient.kt:495-499, 582-585` |
| Chunking | More than `maxPacketsPerRequest` packets (default 8, set from Odoo `request_policy.max_packets`) are sent serially in chunks and renumbered | `PtsClient.kt:340-365, 435-460` |
| Priority | Authorize/Stop/EmergencyStop/Suspend/Resume make the poll loop yield (≤ 2 s) | `PtsClient.kt:61-66`, `PtsRepository.kt:366-370` |

## 3. Request packet types sent (13)

| Type | Data | Use |
|---|---|---|
| `GetPumpsConfiguration` | `{}` | Pump ids at poll start; shift helpers |
| `GetPumpNozzlesConfiguration` | `{}` | Catalog (cached 15 s) |
| `GetFuelGradesConfiguration` | `{}` | Catalog and price list |
| `PumpGetStatus` | `{"Pump":N}` per pump, batched | Forecourt poll; meter capture at shift open/close |
| `ProbeGetMeasurements` | `{"Probe":1..3}` fixed | Every 30th poll cycle |
| `PumpAuthorize` | `{"Pump","Nozzle","Type":"Amount"\|"Volume"\|"FullTank","Dose":"12.34"?,"Price":"2.33"?}` | Pre-authorize (`PtsRepository.kt:736-825`) |
| `PumpStop` / `PumpEmergencyStop` / `PumpSuspend` / `PumpResume` | `{"Pump":N}` | Control (`PtsRepository.kt:843-863`) |
| `PumpGetTotals` | `{"Pump":N}` or `{"Pump":N,"Nozzle":n}` | Meters; "is PTS back?" probe |
| `SetFuelGradesPrices` | `{"FuelGradesPrices":[{"FuelGradeId","Price":<number>}]}` | Price push, 3 attempts 1.5 s apart (`ui/pricelist/PricelistViewModel.kt:267-288`) |

**Never sent:** `PumpCloseTransaction`, `ReportGetPumpTransactions`, `GetConfigurationIdentifier`.

## 4. Response parsing rules the mediator must reproduce

- **State** (`PtsRepository.kt:684-697`), in priority order: SUSPENDED → FILLING (`PumpFillingStatus`) → OFFLINE (`PumpOfflineStatus` or no packet) → AUTHORIZED (`PumpAuthorizedStatus`) → NOZZLE_UP (`Nozzle`/`NozzleUp` > 0) → IDLE.
  - `PumpEndOfTransactionStatus` is not named. With `Nozzle>0` it reads as NOZZLE_UP and **is not a completion**.
- **Fields read:**
  - Live and last amount/volume use the first positive value of several key aliases.
  - Also read: `LastNozzle`, `LastPrice`, `LastFuelGradeName`, `Transaction|LastTransaction|TransactionId`, `LastDateTime` (`yyyy-MM-dd'T'HH:mm:ss`, device time zone), and `Prices[]` or `NozzlePrices[]`.
- **Completion** (`PtsRepository.kt:507-556`): the previous poll showed FILLING and the current one shows IDLE with `LastAmount > 0`.
  - A fill that starts and ends between polls is **missed**.
  - Amounts above 20,000 SAR or volumes above 2,000 L are discarded as totalizers.
  - `manualSale = !authorizedByApp`. `reportedAfterReconnect` is set on the first poll after a failure.
- **Authorize reply:** success means any non-empty `Packets` with no error. The tx is read from `PumpAuthorizeConfirmation.Data.Transaction`. A confirmation packet is not required.
- **Control reply:** success means no error and `Message` either absent or `"OK"`.
- **Error packet:** a truthy `Error` value becomes the user message. `"Error":true` shows the text "true", so the mediator should put readable text in `Error` as a string (`PtsRepository.kt:828-840`). The status and totals parsers skip error packets.
- **Totals:** read only from `Data.Nozzles[]`/`Data.Totals[]` with `VolumeTotal|TotalVolume|…` and `AmountTotal|TotalAmount|…`. Plain `Volume`/`Amount` are **not** read.
- **Fuel grades:** the catalog reads `Id`/`Name`; the price list reads `FuelGradeId`/`FuelGradeName`/`Price`. Return both.

## 5. Authorization, stop and timeout behaviour

- Before authorizing:
  - A prepaid sale must already be APPROVED.
  - Authorize is skipped if the last poll shows AUTHORIZED/FILLING.
  - An Odoo permit `lock=true` is requested; if Odoo is unreachable this **fails open** (`ui/fueling/FuelingViewModel.kt:171-245`).
- **One authorize call, no retry.** Any exception (including the 15 s read timeout) is shown as ERROR "Failed to authorize pump", or `FuelSaleViewModel` falls back to manual mode (`FuelingViewModel.kt:264-286`, `FuelSaleViewModel.kt:383-384`).
  - **This is the exact path that produces the "timeout → manual sale → late PTS sale" duplicate.**
- After success, `mobile_claim_pump_auth` is sent to Odoo with the PTS tx (best effort).
- `FuelSaleViewModel` waits up to 10 minutes for completion, then reports "No End-Of-Transaction" and falls back to manual.
- Stop / E-stop / Suspend / Resume: no retry; E-stop asks for confirmation.

## 6. Duplicate and lock handling already in the app

- **Deterministic sale uuid** for PTS fills: `UUID.nameUUIDFromBytes("pts-tx-$tx-p$pump")`. Every terminal derives the same uuid (`ui/payment/PaymentViewModel.kt:712-716`, `ui/pumps/PumpListViewModel.kt:233-291`).
  - **This requires PTS transaction ids to be stable and identical across terminals**, which the mediator must preserve.
  - Because PTS tx counters are per pump and can wrap, the uuid can in principle collide after a counter reset.
- Paid-elsewhere check: Odoo `mobile_get_paid_fills` every 15 s, matching by uuid, then pump+tx (never amount).
- If the controller time is ≥ 2 min old or the fill came after a reconnect, the app asks Odoo `mobile_is_fill_paid` before queueing (`data/pts/PtsCompletionPolicy.kt`).
- The **"permit" lock lives in Odoo** (`pts.mobile.pump.permit`, TTL 90 s, extended every 30 s while paying, released on exit). It is not a PTS feature.

## 7. Polling load

- Interval: default `pollMs=2000`, adaptive per state:
  - FILLING/AUTH: base
  - NOZZLE_UP: base/2
  - COMPLETED: 2× base
  - all idle: 8× base, up to 45 s
- **No error backoff.**
- Each terminal polls independently. `request_policy.max_concurrent` and `digest_serial` are parsed but **not enforced**.
- With N terminals plus Odoo's own 15 s poll, the controller sees N+1 independent pollers, each opening a new TCP (and TLS) connection per request.

## 8. Compatibility checklist for a PTS-compatible mediator endpoint

1. Accept `POST <base>/jsonPTS` with Digest (MD5, qop=auth, nonce reuse) or Basic. The mediator's own device auth must fit inside this, because FuelPay cannot send custom headers.
2. Reply 200 with a JSON object. Every pump packet carries `Data.Pump`.
3. Status: `PumpFillingStatus` while dispensing, visible for at least one poll. Then an idle-type packet with `Nozzle`/`NozzleUp` = 0 plus the `Last*` fields.
4. Authorize: return `PumpAuthorizeConfirmation{Pump, Transaction}`. Control commands: no `Message`, or `"OK"`.
5. Totals: `Nozzles[]` with `VolumeTotal`/`AmountTotal`. Fuel grades with both key sets.
6. Answer within **15 s** (the client read timeout). If a real controller command is still pending, the mediator must reply before then with a well-formed error, never silently re-send the command.
7. Preserve the controller's `Transaction` numbers unchanged, because the app's deterministic uuids depend on them.
