#!/usr/bin/env bash
# NFR-7: the same load at 2 and at 3 API replicas; throughput is recorded for both.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
source "$HERE/../scripts/lib/env.sh"
OUT="$UC_ROOT/docs/testing/results"
mkdir -p "$OUT"
cd "$HERE"
export BASE_URL="${BASE_URL:-$APP_URL}"

routed() { redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" -n "${REDIS_REGISTRY_DB:-0}" --scan --pattern 'traefik/http/services/api/loadBalancer/servers/*' | wc -l | tr -d ' '; }
wait_for() { # replicas
  for _ in $(seq 1 60); do [ "$(routed)" = "$1" ] && sleep 3 && return 0; sleep 1; done
  echo "gateway did not reach $1 api servers" >&2; return 1
}

for n in 2 3; do
  pm2 scale api "$n" >/dev/null 2>&1 || true
  wait_for "$n" || exit 1
  echo "running throughput test with $n API replicas"
  k6 run --quiet --summary-export "$OUT/k6-scale-$n.json" rest-throughput.js > "$OUT/k6-scale-$n.log" 2>&1
  sleep 5
done
pm2 scale api 2 >/dev/null 2>&1 || true

node - "$OUT" <<'JS'
const fs = require('fs')
const out = process.argv[2]
const read = (n) => JSON.parse(fs.readFileSync(`${out}/k6-scale-${n}.json`, 'utf8')).metrics
const row = (n) => { const m = read(n); return { replicas: n, rps: m.http_reqs.rate, p95_ms: m.http_req_duration['p(95)'], error_rate: m.http_req_failed.value } }
const r2 = row(2), r3 = row(3)
const result = { two: r2, three: r3, throughput_increase_percent: ((r3.rps - r2.rps) / r2.rps) * 100, valid: r2.error_rate < 0.01 && r3.error_rate < 0.01 }
fs.writeFileSync(`${out}/scale.json`, JSON.stringify(result, null, 2))
console.log(JSON.stringify(result))
JS
