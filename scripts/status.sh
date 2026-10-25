#!/usr/bin/env bash
# Prints gateway health and the System Status payload (/api/admin/system).
# Logs in as the seeded admin unless ADMIN_TOKEN is set.
set -uo pipefail
source "$(dirname "$0")/lib/env.sh"
BASE="${APP_URL%/}"
CURL=(curl -sk --max-time 5)

echo "== GET $BASE/api/health"
"${CURL[@]}" -i "$BASE/api/health" | grep -iE '^(HTTP|x-served-by)|"status"' ; echo

TOKEN="${ADMIN_TOKEN:-}"
if [ -z "$TOKEN" ]; then
  TOKEN=$("${CURL[@]}" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"email\":\"${ADMIN_EMAIL:-admin@uniconnect.test}\",\"password\":\"${ADMIN_PASSWORD:-Password123!}\",\"device_name\":\"make-status\"}" \
    "$BASE/api/auth/login" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{console.log(JSON.parse(s).token||"")}catch{console.log("")}})')
fi
if [ -z "$TOKEN" ]; then echo "(could not log in as admin — run make seed)"; exit 0; fi

echo "== GET $BASE/api/admin/system"
"${CURL[@]}" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' "$BASE/api/admin/system" \
  | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{const j=JSON.parse(s);
      for (const [svc, list] of Object.entries(j.instances||{})) for (const i of list)
        console.log(`${svc.padEnd(10)} ${String(i.host+":"+(i.port??"-")).padEnd(22)} ${i.status.padEnd(8)} up ${i.uptime_seconds}s  ${i.connections!=null?("conns "+i.connections):""}`);
      for (const [k,v] of Object.entries(j.checks||{})) console.log(`${k.padEnd(14)} ${v.ok?"ok":"DOWN"} ${v.detail??""}`);
    }catch(e){console.log(s)}})'
