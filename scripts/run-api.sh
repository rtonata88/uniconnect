#!/usr/bin/env bash
# One API instance: Octane + FrankenPHP on port API_BASE_PORT + PM2 instance id,
# plus a heartbeat sidecar that registers it in the Redis service registry.
set -uo pipefail
source "$(dirname "$0")/lib/env.sh"

IDX="${NODE_APP_INSTANCE:-0}"
PORT=$((API_BASE_PORT + IDX))
export INSTANCE_SERVICE=api INSTANCE_PORT=$PORT
export OCTANE_STATE_FILE="$UC_ROOT/backend/storage/logs/octane-state-$PORT.json"

cd "$UC_ROOT/backend"
echo "[api] starting instance $IDX on $APP_ADVERTISE_HOST:$PORT"

"$PHP" artisan octane:start --server=frankenphp --host=0.0.0.0 --port="$PORT" \
  --admin-port=$((PORT + 10000)) --workers="${API_WORKERS:-4}" --max-requests=1000 &
SERVER=$!

"$PHP" artisan registry:heartbeat api --port="$PORT" --pid="$SERVER" \
  --health="http://127.0.0.1:$PORT/api/health" &
HEARTBEAT=$!

shutdown() {
  echo "[api] SIGTERM: deregistering $PORT"
  kill -TERM "$HEARTBEAT" 2>/dev/null
  wait "$HEARTBEAT" 2>/dev/null
  kill -TERM "$SERVER" 2>/dev/null
  wait "$SERVER" 2>/dev/null
  exit 0
}
trap shutdown TERM INT

wait "$SERVER"
CODE=$?
kill -TERM "$HEARTBEAT" 2>/dev/null
wait "$HEARTBEAT" 2>/dev/null
exit $CODE
