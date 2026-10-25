#!/usr/bin/env bash
# Installs UniConnect prerequisites on macOS with Homebrew. No Docker.
# Safe to re-run: already-installed packages are skipped.
set -euo pipefail

if ! command -v brew >/dev/null; then
  echo "Homebrew is required: https://brew.sh" >&2
  exit 1
fi

brew_install() {
  for f in "$@"; do
    if brew list --versions "$f" >/dev/null 2>&1; then
      echo "✓ $f already installed"
    else
      # a keg-only/link conflict (e.g. mysql@8.4 next to mariadb) must not abort the script
      brew install "$f" || brew list --versions "$f" >/dev/null || { echo "failed to install $f" >&2; exit 1; }
    fi
  done
}

# PHP 8.4 (skip if a php 8.4 binary is already available, e.g. Laravel Herd's php84)
if command -v php84 >/dev/null || php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' 2>/dev/null; then
  echo "✓ PHP 8.4 available"
else
  brew_install php@8.4
  pecl install redis || true
fi
command -v composer >/dev/null || brew_install composer
command -v node >/dev/null || brew_install node@22

# mysql@8.4 is keg-only, so it can coexist with another MySQL/MariaDB install.
# UniConnect starts its own primary + replica with --defaults-file (see infra/mysql).
brew_install mysql@8.4 redis traefik minio minio-mc mkcert k6

command -v pm2 >/dev/null || npm install -g pm2

cat <<EOF

Done. Next steps:
  * Add mysql@8.4 to PATH (or set MYSQL_BIN_DIR in .env):
      export PATH="$(brew --prefix)/opt/mysql@8.4/bin:\$PATH"
  * Start Redis:   brew services start redis
  * Trust the local CA once (asks for your password):   mkcert -install
  * Then:  make setup && make up && make seed
EOF
