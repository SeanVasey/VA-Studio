#!/usr/bin/env bash
# Disposable private mysqld on 127.0.0.1:3410 (never the shared :3306).
set -euo pipefail
B=/opt/mysql84/mysql-8.4.11-linux-glibc2.28-x86_64
# MYSQLD_SCRATCH names a parent; the daemon only ever uses its own child directory. An existing child is removed only
# when it carries the marker this script wrote, so a mistyped path can never delete unrelated data.
P=${MYSQLD_SCRATCH:?scratch parent directory}
D="$P/va-mysqld-scratch"
MARK="$D/.va-mysqld-scratch"
if [ -e "$D" ]; then
  [ -d "$D" ] && [ ! -L "$D" ] && [ -f "$MARK" ] || { echo "refusing to reuse $D: not a marked scratch directory" >&2; exit 2; }
  rm -rf -- "$D"
fi
mkdir -p -- "$D" && : > "$MARK"
chown -R mysql:mysql "$D" 2>/dev/null || true
U=$(id -u mysql >/dev/null 2>&1 && echo mysql || echo root)
"$B/bin/mysqld" --no-defaults --user=$U --initialize-insecure --basedir="$B" --datadir="$D/data" >"$D/init.log" 2>&1
"$B/bin/mysqld" --no-defaults --user=$U --basedir="$B" --datadir="$D/data" --port=3410 --bind-address=127.0.0.1 \
  --socket="$D/mysqld.sock" --mysqlx=OFF --log-error="$D/err.log" --pid-file="$D/mysqld.pid" --daemonize
"$B/bin/mysql" -uroot -h127.0.0.1 -P3410 -e "SELECT VERSION(); CREATE DATABASE rv256_1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE rv256_2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
