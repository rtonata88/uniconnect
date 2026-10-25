#!/usr/bin/env bash
# Installs UniConnect prerequisites on Ubuntu 24.04. No Docker.
set -euo pipefail
SUDO=${SUDO:-sudo}
ARCH=$(dpkg --print-architecture)   # amd64 | arm64

$SUDO apt-get update
$SUDO apt-get install -y software-properties-common curl gnupg ca-certificates unzip git make lsb-release libnss3-tools

# PHP 8.4 (ondrej PPA)
$SUDO add-apt-repository -y ppa:ondrej/php
$SUDO apt-get update
$SUDO apt-get install -y php8.4-cli php8.4-mysql php8.4-redis php8.4-intl php8.4-zip php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-bcmath php8.4-gd php8.4-sqlite3 php8.4-gmp

# Composer
if ! command -v composer >/dev/null; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp
  $SUDO mv /tmp/composer.phar /usr/local/bin/composer
fi

# Node 22 + PM2
if ! command -v node >/dev/null; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | $SUDO -E bash -
  $SUDO apt-get install -y nodejs
fi
command -v pm2 >/dev/null || $SUDO npm install -g pm2

# MySQL 8.4 LTS (official APT repo). The OS service is not used by UniConnect:
# infra/mysql/setup-replication.sh runs its own primary + replica datadirs.
if ! command -v mysqld >/dev/null; then
  curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2023 | $SUDO gpg --dearmor -o /usr/share/keyrings/mysql.gpg
  echo "deb [signed-by=/usr/share/keyrings/mysql.gpg] http://repo.mysql.com/apt/ubuntu $(lsb_release -sc) mysql-8.4-lts" | $SUDO tee /etc/apt/sources.list.d/mysql.list
  $SUDO apt-get update
  DEBIAN_FRONTEND=noninteractive $SUDO apt-get install -y mysql-server
  # Free port 3306 for UniConnect's own primary.
  $SUDO systemctl disable --now mysql || true
fi
# AppArmor blocks mysqld from using datadirs outside /var/lib/mysql; allow the project storage dir.
if [ -f /etc/apparmor.d/usr.sbin.mysqld ]; then
  $SUDO ln -sf /etc/apparmor.d/usr.sbin.mysqld /etc/apparmor.d/disable/ && $SUDO apparmor_parser -R /etc/apparmor.d/usr.sbin.mysqld || true
fi

# Redis 7
$SUDO apt-get install -y redis-server
$SUDO systemctl enable --now redis-server

# MinIO + mc
if ! command -v minio >/dev/null; then
  $SUDO curl -fsSL -o /usr/local/bin/minio "https://dl.min.io/server/minio/release/linux-${ARCH}/minio"
  $SUDO chmod +x /usr/local/bin/minio
fi
if ! command -v mc >/dev/null; then
  $SUDO curl -fsSL -o /usr/local/bin/mc "https://dl.min.io/client/mc/release/linux-${ARCH}/mc"
  $SUDO chmod +x /usr/local/bin/mc
fi

# Traefik v3
if ! command -v traefik >/dev/null; then
  TV=v3.7.16
  curl -fsSL "https://github.com/traefik/traefik/releases/download/${TV}/traefik_${TV}_linux_${ARCH}.tar.gz" | tar -xz -C /tmp traefik
  $SUDO mv /tmp/traefik /usr/local/bin/traefik
  # allow binding 80/443 without root
  $SUDO setcap 'cap_net_bind_service=+ep' /usr/local/bin/traefik
fi

# mkcert
if ! command -v mkcert >/dev/null; then
  $SUDO curl -fsSL -o /usr/local/bin/mkcert "https://dl.filippo.io/mkcert/latest?for=linux/${ARCH}"
  $SUDO chmod +x /usr/local/bin/mkcert
fi

# k6
if ! command -v k6 >/dev/null; then
  $SUDO gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
    --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
  echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | $SUDO tee /etc/apt/sources.list.d/k6.list
  $SUDO apt-get update && $SUDO apt-get install -y k6
fi

echo
echo "Done. Run 'mkcert -install' once, then: make setup && make up && make seed"
