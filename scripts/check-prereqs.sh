#!/usr/bin/env bash
# Verifies every native prerequisite (no Docker) and warns about busy ports.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[ -f "$ROOT/backend/.env" ] || cp "$ROOT/backend/.env.example" "$ROOT/backend/.env"
source "$ROOT/scripts/lib/env.sh"

FAIL=0
ok()   { printf "  \033[32m✓\033[0m %s\n" "$1"; }
bad()  { printf "  \033[31m✗\033[0m %s\n" "$1"; FAIL=1; }
warn() { printf "  \033[33m!\033[0m %s\n" "$1"; }

echo "Checking prerequisites"
if command -v "$PHP" >/dev/null && "$PHP" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
  ok "PHP $("$PHP" -r 'echo PHP_VERSION;') ($PHP)"
  MODULES="$("$PHP" -m)"   # captured once: `php -m | grep -q` can SIGPIPE under pipefail
  for ext in pdo_mysql redis pcntl intl zip; do
    grep -qix "$ext" <<<"$MODULES" && ok "  ext-$ext" || bad "  ext-$ext missing"
  done
else
  bad "PHP 8.4+ not found as '$PHP' (set PHP_BIN in backend/.env)"
fi
command -v composer >/dev/null && ok "Composer" || bad "Composer"
if command -v node >/dev/null && [ "$(node -p 'process.versions.node.split(".")[0]')" -ge 22 ]; then ok "Node $(node -v)"; else bad "Node 22+"; fi
command -v pm2 >/dev/null && ok "PM2 $(pm2 -v 2>/dev/null)" || bad "PM2 (npm i -g pm2)"
MYSQLD="${MYSQL_BIN_DIR:+$MYSQL_BIN_DIR/}mysqld"
if "$MYSQLD" --version 2>/dev/null | grep -q " 8\.4\."; then ok "MySQL 8.4 ($MYSQLD)"; else bad "MySQL 8.4 mysqld (set MYSQL_BIN_DIR, found: $("$MYSQLD" --version 2>/dev/null | head -c 80))"; fi
if command -v redis-server >/dev/null; then
  RV=$(redis-server --version | sed -E 's/.*v=([0-9]+).*/\1/'); [ "$RV" -ge 7 ] && ok "Redis $RV.x" || bad "Redis 7+"
  redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping >/dev/null 2>&1 && ok "  Redis reachable on $REDIS_HOST:$REDIS_PORT" || bad "  Redis not running on $REDIS_HOST:$REDIS_PORT"
else bad "Redis"; fi
MINIO="${MINIO_BIN_DIR:+$MINIO_BIN_DIR/}minio"; MC="${MINIO_BIN_DIR:+$MINIO_BIN_DIR/}mc"
"$MINIO" --version >/dev/null 2>&1 && ok "MinIO ($MINIO)" || bad "MinIO ($MINIO does not run; on Apple M5 use scripts/build-minio-from-source.sh)"
"$MC" --version >/dev/null 2>&1 && ok "mc ($MC)" || bad "mc ($MC)"
if command -v traefik >/dev/null && traefik version | grep -qE 'Version:\s+3\.'; then ok "Traefik v3"; else bad "Traefik v3"; fi
command -v mkcert >/dev/null && ok "mkcert" || bad "mkcert"
command -v k6 >/dev/null && ok "k6" || warn "k6 missing (only needed for make loadtest)"

echo "Checking ports (from backend/.env)"
pm2_owned=$(pm2 jlist 2>/dev/null | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{console.log(JSON.parse(s).map(p=>p.pid).join(" "))}catch{}})')
# true when the pid or any of its ancestors is a PM2-managed process
owned_by_pm2() {
  local cur="$1"
  while [ -n "$cur" ] && [ "$cur" -gt 1 ]; do
    echo " $pm2_owned " | grep -q " $cur " && return 0
    cur=$(ps -o ppid= -p "$cur" 2>/dev/null | tr -d ' ')
  done
  return 1
}
for p in "$GATEWAY_HTTP_PORT" "$GATEWAY_HTTPS_PORT" "$GATEWAY_DASHBOARD_PORT" "$GATEWAY_INTERNAL_PORT" \
         "$DB_PORT" "$DB_REPLICA_PORT" "$MINIO_PORT" "$MINIO_CONSOLE_PORT" "$WEB_PORT" \
         "$API_BASE_PORT" $((API_BASE_PORT+1)) "$REVERB_BASE_PORT" $((REVERB_BASE_PORT+1)); do
  pid=$(lsof -nP -iTCP:"$p" -sTCP:LISTEN -t 2>/dev/null | head -1)
  if [ -z "$pid" ]; then ok "port $p free"
  elif owned_by_pm2 "$pid"; then ok "port $p used by UniConnect (PM2)"
  else warn "port $p is in use by $(ps -p "$pid" -o comm= 2>/dev/null) — change it in backend/.env"
  fi
done

[ $FAIL = 0 ] && echo "All prerequisites found." || { echo "Missing prerequisites. Run scripts/install-deps-macos.sh or scripts/install-deps-ubuntu.sh"; exit 1; }
