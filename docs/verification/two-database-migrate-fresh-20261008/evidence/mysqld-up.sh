#!/usr/bin/env bash
# Disposable private mysqld on 127.0.0.1:3410 (never the shared :3306).
# Usage: MYSQLD_SCRATCH=<parent dir> mysqld-up.sh
#
# Exit status: 0 when the daemon is up with rv256_1 and rv256_2 created; 2 when setup is refused before anything is
# removed or started (a scratch child this script did not create, a daemon from an earlier run still alive, or port
# 3410 already in use); any other nonzero status is a failed step, reported by errexit.
set -euo pipefail
refuse() { echo "refused: $*" >&2; exit 2; }
B=/opt/mysql84/mysql-8.4.11-linux-glibc2.28-x86_64
PORT=3410

# MYSQLD_SCRATCH names a parent; the daemon only ever uses its own child directory. An existing child is removed only
# when it carries the marker this script wrote and no daemon from it is still running, so neither a mistyped path nor
# a repeated invocation can delete unrelated data or a live server's files.
P=${MYSQLD_SCRATCH:?scratch parent directory}
D="$P/va-mysqld-scratch"
MARK="$D/.va-mysqld-scratch"
PIDFILE="$D/mysqld.pid"
if [ -e "$D" ] || [ -L "$D" ]; then
  [ -d "$D" ] && [ ! -L "$D" ] && [ -f "$MARK" ] || refuse "$D is not a marked scratch directory"
  if [ -f "$PIDFILE" ]; then
    pid=$(cat -- "$PIDFILE" 2>/dev/null || true)
    if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null; then
      refuse "mysqld pid $pid from $D is still running; stop it with: $B/bin/mysqladmin -uroot -h127.0.0.1 -P$PORT shutdown"
    fi
  fi
fi
# Any listener on the port means another daemon owns it; starting here would fail to bind after the reset.
if (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then
  refuse "127.0.0.1:$PORT is already in use"
fi
if [ -e "$D" ]; then
  rm -rf -- "$D"
fi
mkdir -p -- "$D"
: > "$MARK"
chown -R mysql:mysql "$D" 2>/dev/null || true
U=$(id -u mysql >/dev/null 2>&1 && echo mysql || echo root)
"$B/bin/mysqld" --no-defaults --user="$U" --initialize-insecure --basedir="$B" --datadir="$D/data" >"$D/init.log" 2>&1
"$B/bin/mysqld" --no-defaults --user="$U" --basedir="$B" --datadir="$D/data" --port="$PORT" --bind-address=127.0.0.1 \
  --socket="$D/mysqld.sock" --mysqlx=OFF --log-error="$D/err.log" --pid-file="$PIDFILE" --daemonize
"$B/bin/mysql" -uroot -h127.0.0.1 -P"$PORT" -e "SELECT VERSION(); CREATE DATABASE rv256_1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE rv256_2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
