#!/usr/bin/env bash
# make loadtest: seed the load-test data set, issue tokens, run every k6 script and the
# failover / scale / Lighthouse checks. Results (k6 --summary-export JSON etc.) go to
# docs/testing/results/, then `php artisan report:testing` writes TEST_REPORT.md.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
source "$HERE/../scripts/lib/env.sh"
OUT="$UC_ROOT/docs/testing/results"
mkdir -p "$OUT"
export BASE_URL="${BASE_URL:-$APP_URL}"
step() { printf '\n\033[1m== %s\033[0m\n' "$1"; }

step "load-test data (500 students, 10 courses, 1000 messages) and tokens"
(cd "$UC_ROOT/backend" && "$PHP" artisan db:seed --class=LoadTestSeeder --force && "$PHP" artisan loadtest:tokens) || exit 1

cd "$HERE"
step "NFR-1 rest-mixed (100 VUs)"
k6 run --summary-export "$OUT/k6-rest-mixed.json" rest-mixed.js 2>&1 | tee "$OUT/k6-rest-mixed.log" | grep -E "✓|✗|http_req_duration\.|http_reqs\."

step "NFR-2 send-messages (50 senders)"
k6 run --summary-export "$OUT/k6-send-messages.json" send-messages.js 2>&1 | tee "$OUT/k6-send-messages.log" | grep -E "✓|✗|http_req_duration\{|http_reqs\."

step "NFR-3/4 ws-latency (200 receivers + 300 holders, 2 min)"
# sample the per-instance socket counts from the System Status endpoint while the test runs
TOKEN=$(cd "$UC_ROOT/backend" && "$PHP" artisan tinker --execute 'echo App\Models\User::where("role","admin")->first()->createToken("loadtest-monitor")->plainTextToken;' 2>/dev/null | tail -1)
( for _ in $(seq 1 14); do
    sleep 10
    curl -sk -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' "$BASE_URL/api/admin/system" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{const j=JSON.parse(s);console.log(JSON.stringify({t:new Date().toISOString(),total:j.websockets.connections,per_instance:j.websockets.per_instance}))}catch{}})'
  done ) > "$OUT/ws-instances.jsonl" &
SAMPLER=$!
k6 run --summary-export "$OUT/k6-ws-latency.json" ws-latency.js 2>&1 | tee "$OUT/k6-ws-latency.log" | grep -E "✓|✗|ws_e2e_latency|ws_connected\."
wait $SAMPLER

step "NFR-6 sync after 50 offline messages"
k6 run --summary-export "$OUT/k6-sync-offline.json" sync-offline.js 2>&1 | tee "$OUT/k6-sync-offline.log" | grep -E "✓|✗|msg="

step "NFR-5 failover"
"$HERE/failover.sh"

step "NFR-7 scaling 2 → 3 API replicas"
"$HERE/scale.sh"

step "NFR-8 Lighthouse + installability"
"$HERE/lighthouse.sh"

step "test report"
(cd "$UC_ROOT/backend" && "$PHP" artisan report:testing)
