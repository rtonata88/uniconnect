#!/usr/bin/env bash
# One Reverb instance on port REVERB_BASE_PORT + PM2 instance id. Scaling through
# Redis pub/sub is enabled, so instances share channels. The instance registers
# itself in the service registry from inside the Reverb event loop
# (App\Support\Registry\ReverbHeartbeat), reporting its live connection count.
set -uo pipefail
source "$(dirname "$0")/lib/env.sh"

IDX="${NODE_APP_INSTANCE:-0}"
PORT=$((REVERB_BASE_PORT + IDX))
export INSTANCE_SERVICE=reverb INSTANCE_PORT=$PORT REVERB_SCALING_ENABLED=true
export REVERB_SERVER_PORT=$PORT

cd "$UC_ROOT/backend"
echo "[reverb] starting instance $IDX on $APP_ADVERTISE_HOST:$PORT"
exec "$PHP" artisan reverb:start --host=0.0.0.0 --port="$PORT" --no-interaction
