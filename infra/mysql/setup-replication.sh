#!/usr/bin/env bash
# Creates a MySQL 8.4 primary + read replica (GTID replication) with their own
# datadirs under storage/mysql, starts both under PM2, and creates the app database
# and users. Idempotent: safe to re-run.
set -euo pipefail
source "$(dirname "$0")/../../scripts/lib/env.sh"

BIN="${MYSQL_BIN_DIR:+$MYSQL_BIN_DIR/}"
MYSQLD="${BIN}mysqld"
MYSQL="${BIN}mysql"
DIR="$UC_ROOT/storage/mysql"
mkdir -p "$DIR/primary" "$DIR/replica" "$UC_ROOT/storage/logs"

"$MYSQLD" --version | grep -q " 8\.4\." || { echo "mysqld 8.4 required (found: $("$MYSQLD" --version)). Set MYSQL_BIN_DIR." >&2; exit 1; }

render() { # template port target
  sed -e "s#@ROOT@#$UC_ROOT#g" -e "s#@PORT@#$2#g" -e "s#@BIND@#$DB_BIND_ADDRESS#g" "$1" > "$3"
}
render "$UC_ROOT/infra/mysql/primary.cnf" "$DB_PORT" "$DIR/primary.cnf"
render "$UC_ROOT/infra/mysql/replica.cnf" "$DB_REPLICA_PORT" "$DIR/replica.cnf"

for node in primary replica; do
  if [ ! -d "$DIR/$node/data/mysql" ]; then
    echo "→ initialising $node datadir"
    rm -rf "$DIR/$node/data"
    "$MYSQLD" --defaults-file="$DIR/$node.cnf" --initialize-insecure
  fi
done

echo "→ starting mysql-primary and mysql-replica under PM2"
(cd "$UC_ROOT" && pm2 start ecosystem.config.cjs --only mysql-primary,mysql-replica >/dev/null)

q_primary() { "$MYSQL" --socket="$DIR/primary/mysqld.sock" -uroot "$@"; }
q_replica() { "$MYSQL" --socket="$DIR/replica/mysqld.sock" -uroot "$@"; }

wait_for() { # name fn
  for _ in $(seq 1 60); do
    if "$2" -e 'SELECT 1' >/dev/null 2>&1; then return 0; fi
    sleep 1
  done
  echo "$1 did not start; see storage/logs/mysql-$1.log" >&2
  exit 1
}
wait_for primary q_primary
wait_for replica q_replica

echo "→ configuring primary"
q_primary <<SQL
CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED BY '${DB_REPLICATION_PASSWORD}';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}_testing\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'%';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}_testing\`.* TO '${DB_USERNAME}'@'%';
-- lets the app read replication lag (SHOW REPLICA STATUS) and the System Status page
GRANT REPLICATION CLIENT, PROCESS ON *.* TO '${DB_USERNAME}'@'%';
FLUSH PRIVILEGES;
SQL

if q_replica -N -e "SHOW REPLICA STATUS" | grep -q .; then
  echo "→ replica already configured"
  q_replica -e "START REPLICA" >/dev/null 2>&1 || true
else
  echo "→ configuring replica (source 127.0.0.1:$DB_PORT, GTID auto-position)"
  q_replica <<SQL
CHANGE REPLICATION SOURCE TO
  SOURCE_HOST='127.0.0.1',
  SOURCE_PORT=${DB_PORT},
  SOURCE_USER='repl',
  SOURCE_PASSWORD='${DB_REPLICATION_PASSWORD}',
  SOURCE_AUTO_POSITION=1,
  GET_SOURCE_PUBLIC_KEY=1;
START REPLICA;
SQL
fi

echo -n "→ waiting for the replica to catch up"
for _ in $(seq 1 30); do
  if q_replica -N -e "SELECT COUNT(*) FROM mysql.user WHERE user='${DB_USERNAME}'" 2>/dev/null | grep -q '^1$'; then
    echo " ✓"
    q_replica -e "SHOW REPLICA STATUS\G" | grep -E 'Replica_IO_Running|Replica_SQL_Running:|Seconds_Behind_Source'
    exit 0
  fi
  echo -n "."; sleep 1
done
echo
q_replica -e "SHOW REPLICA STATUS\G" | grep -E 'Running|Error' >&2
exit 1
