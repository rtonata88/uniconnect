#!/usr/bin/env bash
# NFR-5: one API replica is stopped in the middle of the rest-mixed load.
# Target: error rate < 2 % and recovery (no more errors) within 15 s.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
source "$HERE/../scripts/lib/env.sh"
OUT="$UC_ROOT/docs/testing/results"
mkdir -p "$OUT"
cd "$HERE"
export BASE_URL="${BASE_URL:-$APP_URL}"

k6 run --quiet -e DURATION=60s --summary-export "$OUT/k6-failover.json" --out "csv=$OUT/.failover.csv" rest-mixed.js > "$OUT/k6-failover.log" 2>&1 &
K6=$!
sleep 35   # 15 s ramp-up + 20 s at full load
ID=$(pm2 jlist | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const a=JSON.parse(s).filter(p=>p.name==="api"&&p.pm2_env.status==="online");console.log(a.length?a[a.length-1].pm_id:"")})')
PORT=$(pm2 jlist | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{const p=JSON.parse(s).find(p=>p.pm_id==$ID);console.log(Number(process.env.API_BASE_PORT)+Number(p.pm2_env.NODE_APP_INSTANCE))})")
KILLED_AT=$(node -e 'console.log(Date.now()/1000)')
echo "stopping api pm_id $ID (port $PORT) at $KILLED_AT"
pm2 stop "$ID" >/dev/null
wait $K6
pm2 start "$ID" >/dev/null
echo "restarted api pm_id $ID"

node - "$OUT/.failover.csv" "$KILLED_AT" "127.0.0.1:$PORT" "$OUT/failover.json" <<'JS'
const fs = require('fs')
const [csv, killedAt, instance, out] = process.argv.slice(2)
const lines = fs.readFileSync(csv, 'utf8').trim().split('\n')
const head = lines.shift().split(',')
const ix = (k) => head.indexOf(k)
let total = 0, failed = 0, failedAfter = 0, lastError = null, firstError = null
const after = { requests: 0 }
for (const l of lines) {
  const c = l.split(',')
  if (c[ix('metric_name')] !== 'http_req_failed') continue
  const ts = Number(c[ix('timestamp')]), v = Number(c[ix('metric_value')])
  total++
  if (ts >= killedAt) after.requests++
  if (v === 1) {
    failed++
    if (ts >= killedAt) { failedAfter++; lastError = ts; firstError ??= ts }
  }
}
const result = {
  killed_instance: instance,
  killed_at: new Date(killedAt * 1000).toISOString(),
  requests: total,
  failed,
  error_rate: total ? failed / total : 0,
  requests_after_kill: after.requests,
  failed_after_kill: failedAfter,
  recovery_seconds: lastError ? Math.max(0, lastError - killedAt) : 0,
}
fs.writeFileSync(out, JSON.stringify(result, null, 2))
console.log(JSON.stringify(result))
JS
rm -f "$OUT/.failover.csv"
