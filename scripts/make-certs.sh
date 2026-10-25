#!/usr/bin/env bash
# Generates the gateway TLS certificate with mkcert (localhost + LAN IP + CERT_EXTRA_HOSTS).
# Run `mkcert -install` once to trust the local CA (needs your password);
# on a phone, install "$(mkcert -CAROOT)/rootCA.pem" (see docs/deployment.md).
set -euo pipefail
source "$(dirname "$0")/lib/env.sh"
OUT="$UC_ROOT/infra/traefik/certs"
mkdir -p "$OUT"
if [ -f "$OUT/localhost.pem" ] && [ "${FORCE:-0}" != "1" ]; then
  echo "✓ certificate exists (FORCE=1 to regenerate)"; exit 0
fi
LAN_IP="$( (ipconfig getifaddr en0 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}') || true)"
# shellcheck disable=SC2086
mkcert -cert-file "$OUT/localhost.pem" -key-file "$OUT/localhost-key.pem" \
  localhost 127.0.0.1 ::1 "$(hostname)" ${LAN_IP:+$LAN_IP} ${CERT_EXTRA_HOSTS:-}
echo "✓ certificate written to infra/traefik/certs (CA: $(mkcert -CAROOT)/rootCA.pem)"
