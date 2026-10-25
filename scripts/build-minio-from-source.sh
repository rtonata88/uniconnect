#!/usr/bin/env bash
# Builds minio + mc from source into ~/.local/bin (or $1).
# Why: MinIO no longer publishes binaries (dl.min.io returns 410), and the Homebrew
# builds crash at start-up on Apple M5 CPUs inside github.com/shoenig/go-m1cpu v0.1.6.
# Upgrading that dependency to v0.2.x fixes it. Requires Go (brew install go).
set -euo pipefail
DEST="${1:-$HOME/.local/bin}"
GO="${GO:-$(command -v go || echo /opt/homebrew/opt/go/bin/go)}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$DEST"

build() { # repo binary
  git clone --depth 1 "https://github.com/minio/$1" "$WORK/$1"
  (cd "$WORK/$1" && "$GO" get github.com/shoenig/go-m1cpu@v0.2.4 && "$GO" mod tidy >/dev/null \
    && CGO_ENABLED=1 "$GO" build -trimpath -o "$DEST/$2" .)
  echo "✓ built $DEST/$2"
}
build minio minio
build mc mc
echo "Set MINIO_BIN_DIR=$DEST in backend/.env (or put it first on PATH)."
