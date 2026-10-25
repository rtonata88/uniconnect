#!/usr/bin/env bash
# Creates backend/.env from .env.example (once) and fills in generated secrets:
# APP_KEY, Reverb app key/secret and the VAPID key pair. Never overwrites existing values.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV="$ROOT/backend/.env"
PHP="${PHP_BIN:-$(grep -E '^PHP_BIN=' "$ENV" 2>/dev/null | cut -d= -f2)}"
PHP="${PHP:-php}"

[ -f "$ENV" ] || { cp "$ROOT/backend/.env.example" "$ENV"; echo "created backend/.env"; }

get() { grep -E "^$1=" "$ENV" | head -1 | cut -d= -f2- ; }
set_val() { # key value
  if grep -qE "^$1=" "$ENV"; then
    # portable in-place edit (BSD + GNU sed)
    sed -i.bak "s#^$1=.*#$1=$2#" "$ENV" && rm -f "$ENV.bak"
  else
    echo "$1=$2" >> "$ENV"
  fi
}

cd "$ROOT/backend"
[ -n "$(get APP_KEY)" ] || "$PHP" artisan key:generate --force >/dev/null
[ -n "$(get REVERB_APP_KEY)" ] || set_val REVERB_APP_KEY "$(openssl rand -hex 10)"
[ -n "$(get REVERB_APP_SECRET)" ] || set_val REVERB_APP_SECRET "$(openssl rand -hex 20)"
if [ -z "$(get VAPID_PUBLIC_KEY)" ]; then
  "$PHP" artisan webpush:vapid --force >/dev/null
fi
echo "✓ backend/.env ready"
