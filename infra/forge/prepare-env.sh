#!/usr/bin/env bash
# Run by infra/forge/deploy.sh. Starts from Forge's default site Environment ($SITE/.env) and adds
# what UniConnect needs on a single Forge server, so nothing has to be pasted by hand:
#   * Forge database defaults: database `uniconnect`, user `forge` (MySQL root only allows socket login)
#   * settings the architecture depends on (Redis queues for Horizon, Reverb, local disk, ports), forced
#   * secrets (APP_KEY, Reverb key/secret, VAPID keys), generated once and never overwritten
# Usage: prepare-env.sh <site-path> <php-binary>
set -euo pipefail
SITE="$1"
PHP="$2"
ENV="$SITE/.env"   # Forge's Environment; backend/.env is a symlink to it

[ -f "$ENV" ] || { touch "$ENV"; echo "created $ENV (Site → Environment shows it)"; }

get() { grep -E "^$1=" "$ENV" | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' ; }
set_val() { # key value; edits the file in place without replacing it (keeps the symlink target)
    if grep -qE "^$1=" "$ENV"; then
        local tmp; tmp="$(mktemp)"
        awk -v k="$1" -v v="$2" 'BEGIN{FS=OFS="="} $1==k {print k "=" v; next} {print}' "$ENV" > "$tmp"
        cat "$tmp" > "$ENV" && rm -f "$tmp"
    else
        echo "$1=$2" >> "$ENV"
    fi
}
default() { [ -n "$(get "$1")" ] || set_val "$1" "$2"; }   # only if missing or empty

# --- app -----------------------------------------------------------------------------------------
default APP_NAME UniConnect
default APP_ENV production
default APP_DEBUG false
default APP_URL "https://$(basename "$SITE")"

# --- database: Forge defaults ----------------------------------------------------------------------
set_val DB_CONNECTION mysql
default DB_HOST 127.0.0.1
default DB_PORT 3306
[ -n "$(get DB_DATABASE)" ] && [ "$(get DB_DATABASE)" != laravel ] || set_val DB_DATABASE uniconnect
[ -n "$(get DB_USERNAME)" ] && [ "$(get DB_USERNAME)" != root ] || set_val DB_USERNAME forge
# one MySQL server: the read connection and System Status's replica check use the primary
set_val DB_REPLICA_HOST "$(get DB_HOST)"
set_val DB_REPLICA_PORT "$(get DB_PORT)"

# --- processes, ports and services (must match infra/forge/nginx-locations.conf) ------------------
set_val PHP_BIN "$PHP"
set_val APP_ADVERTISE_HOST 127.0.0.1
set_val API_BASE_PORT 8001
set_val API_INSTANCES 1
set_val REVERB_BASE_PORT 8101
set_val REVERB_INSTANCES 1
set_val SESSION_DRIVER array
set_val QUEUE_CONNECTION redis
set_val CACHE_STORE redis
set_val BROADCAST_CONNECTION reverb
set_val FILESYSTEM_DISK local
default REDIS_CLIENT phpredis
default REDIS_HOST 127.0.0.1
default REDIS_PORT 6379
set_val REVERB_HOST 127.0.0.1
set_val REVERB_PORT 8101
set_val REVERB_SCHEME http
set_val REVERB_SCALING_ENABLED true
default REVERB_SCALING_CHANNEL uniconnect-reverb
default TRUSTED_PROXIES 127.0.0.1,::1

# --- secrets (generated once) ---------------------------------------------------------------------
default REVERB_APP_ID uniconnect
default REVERB_APP_KEY "$(openssl rand -hex 10)"
default REVERB_APP_SECRET "$(openssl rand -hex 20)"
default VAPID_SUBJECT "mailto:admin@$(basename "$SITE")"
cd "$SITE/backend"
# both commands only replace an existing line
for k in APP_KEY VAPID_PUBLIC_KEY VAPID_PRIVATE_KEY; do grep -qE "^$k=" "$ENV" || echo "$k=" >> "$ENV"; done
[ -n "$(get APP_KEY)" ] || "$PHP" artisan key:generate --force --no-interaction
[ -n "$(get VAPID_PUBLIC_KEY)" ] || "$PHP" artisan webpush:vapid --force --no-interaction

if [ -z "$(get DB_PASSWORD)" ]; then
    echo "DB_PASSWORD is empty. In Forge: Site → Environment, set DB_PASSWORD to the 'forge' database"
    echo "user's password (from the server's provisioning e-mail, or reset it under Server → Database),"
    echo "then deploy again."
    exit 1
fi
echo "✓ .env ready (database $(get DB_DATABASE) as $(get DB_USERNAME)@$(get DB_HOST))"
