#!/usr/bin/env bash
# Runs a long-lived command (e.g. horizon, schedule:work) and heartbeats it in the
# service registry as <service> (no URL). Usage: run-with-heartbeat.sh <service> <cmd...>
set -uo pipefail
source "$(dirname "$0")/lib/env.sh"

SERVICE="$1"; shift
export INSTANCE_SERVICE="$SERVICE"
cd "$UC_ROOT/backend"

"$@" &
CHILD=$!
"$PHP" artisan registry:heartbeat "$SERVICE" --pid="$CHILD" &
HEARTBEAT=$!

shutdown() {
  kill -TERM "$HEARTBEAT" 2>/dev/null
  wait "$HEARTBEAT" 2>/dev/null
  kill -TERM "$CHILD" 2>/dev/null
  wait "$CHILD" 2>/dev/null
  exit 0
}
trap shutdown TERM INT

wait "$CHILD"
CODE=$?
kill -TERM "$HEARTBEAT" 2>/dev/null
wait "$HEARTBEAT" 2>/dev/null
exit $CODE
