#!/usr/bin/env bash
# Creates the private MinIO bucket (no anonymous access). Requires the minio
# process to be running (setup starts it under PM2). Idempotent.
set -euo pipefail
source "$(dirname "$0")/../../scripts/lib/env.sh"
mc() { "${MINIO_BIN_DIR:+$MINIO_BIN_DIR/}mc" "$@"; }

mkdir -p "$UC_ROOT/storage/minio"
(cd "$UC_ROOT" && pm2 start ecosystem.config.cjs --only minio >/dev/null)

# mc reads the alias from MC_HOST_<alias>, so nothing is written to ~/.mc
export MC_HOST_uc="http://${AWS_ACCESS_KEY_ID}:${AWS_SECRET_ACCESS_KEY}@127.0.0.1:${MINIO_PORT}"
for _ in $(seq 1 30); do
  mc ready uc >/dev/null 2>&1 && break
  sleep 1
done
mc mb --ignore-existing "uc/${AWS_BUCKET}"
mc anonymous set none "uc/${AWS_BUCKET}" >/dev/null
echo "✓ bucket ${AWS_BUCKET} is private"
