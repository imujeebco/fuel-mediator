#!/usr/bin/env bash
# Read-only rehearsal of the device path through a deployed mediator. Sends nothing that changes a pump or Odoo:
#  - /health;
#  - jsonPTS: configuration and status for every pump of the station (served from cache/controller);
#  - Odoo relay: common.version, a denied method (must be refused), and, with device credentials, the pump list
#    (checks the PTS URL rewrite) plus the unpaid/paid fill lists for the station's POS configs.
# Usage:
#   BASE=https://mediator.example.com BACKEND=2 bash deploy/smoke-test.sh
#   ... with ODOO_DB=... ODOO_UID=... ODOO_PASSWORD=... POS_CONFIGS="50 51" for the Odoo checks
set -euo pipefail
: "${BASE:?set BASE=https://host}"
BACKEND=${BACKEND:-2}
fail=0
check() { if eval "$2"; then echo "  ok   $1"; else echo "  FAIL $1"; fail=1; fi; }

echo "== health"
H=$(curl -fsS "$BASE/health" || true)
check "health answers" '[ -n "$H" ]'

echo "== jsonPTS (station backend $BACKEND)"
CFG=$(curl -fsS -m 20 -X POST "$BASE/stations/$BACKEND/jsonPTS" -H 'Content-Type: application/json' \
      -d '{"Protocol":"jsonPTS","Packets":[{"Id":1,"Type":"GetPumpsConfiguration"}]}' || true)
check "pumps configuration" 'echo "$CFG" | grep -q "\"Type\":\"GetPumpsConfiguration\""'
PUMPS=$(echo "$CFG" | grep -o '"Id":[0-9]*,"Port"' | grep -o '[0-9]*' | head -32 || true)
[ -z "$PUMPS" ] && PUMPS=$(seq 1 18)
PKTS=""; i=1
for p in $PUMPS; do PKTS="$PKTS{\"Id\":$i,\"Type\":\"PumpGetStatus\",\"Data\":{\"Pump\":$p}},"; i=$((i+1)); done
START=$(date +%s%N)
ST=$(curl -fsS -m 20 -X POST "$BASE/stations/$BACKEND/jsonPTS" -H 'Content-Type: application/json' \
     -d "{\"Protocol\":\"jsonPTS\",\"Packets\":[${PKTS%,}]}" || true)
MS=$(( ($(date +%s%N) - START) / 1000000 ))
check "status for all pumps (${MS} ms)" 'echo "$ST" | grep -q "Pump.*Status"'
check "status under 3 s" '[ "$MS" -lt 3000 ]'

echo "== Odoo relay"
V=$(curl -fsS -m 20 -X POST "$BASE/odoo/jsonrpc" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":1,"method":"call","params":{"service":"common","method":"version","args":[]}}' || true)
check "common.version relayed" 'echo "$V" | grep -q server_version'
D=$(curl -fsS -m 20 -X POST "$BASE/odoo/jsonrpc" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":2,"method":"call","params":{"service":"object","method":"execute_kw","args":["x",1,"x","res.users","write",[[1],{}],{}]}}' || true)
check "non-listed method refused" 'echo "$D" | grep -q AccessError'
check "monitoring UI not public" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/")" = "404" ]'

if [ -n "${ODOO_UID:-}" ]; then
    rpc() { curl -fsS -m 30 -X POST "$BASE/odoo/jsonrpc" -H 'Content-Type: application/json' \
        -d "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"call\",\"params\":{\"service\":\"object\",\"method\":\"execute_kw\",\"args\":[\"$ODOO_DB\",$ODOO_UID,\"$ODOO_PASSWORD\",$1]}}"; }
    # As FuelPay calls it: station 0 = resolve from the POS config (OdooClient.fetchStationPumps).
    FIRST_CONFIG=$(echo "${POS_CONFIGS:-0}" | awk '{print $1}')
    P=$(rpc "\"pts.backend.config\",\"mobile_get_pumps\",[[0]],{\"pos_config_id\":$FIRST_CONFIG}" || true)
    check "mobile_get_pumps answered" 'echo "$P" | grep -q "\"result\""'
    if echo "$P" | grep -q "direct_pts_url"; then
        echo "  info PTS URL in pump list: $(echo "$P" | grep -o '"direct_pts_url":"[^"]*"')"
    fi
    for c in ${POS_CONFIGS:-}; do
        U=$(rpc "\"pos.config\",\"mobile_get_unpaid_fills\",[[$c],720],{\"force\":true}" || true)
        check "unpaid fills, config $c" 'echo "$U" | grep -q "\"fills\""'
        F=$(rpc "\"pos.config\",\"mobile_get_paid_fills\",[[$c],30],{\"force\":true}" || true)
        check "paid fills, config $c" 'echo "$F" | grep -q "\"fills\""'
    done
fi

[ $fail -eq 0 ] && echo "== all checks passed" || { echo "== FAILURES above"; exit 1; }
