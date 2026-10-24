#!/bin/bash
# Private MySQL 8.4.11 for the G1 fix pass: port 3417, datadir scratchpad/g1-mysql/data. Refuses if the port is in use.
S=/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad/g1-mysql
B=/opt/mysql-8.4.11/bin
if ss -ltn | grep -qE ':3417\b'; then
  $B/mysql -uroot -h127.0.0.1 -P3417 -N -e "select @@datadir" | grep -q "g1-mysql/data" && echo "already running" && exit 0
  echo "port 3417 is used by another server" >&2; exit 2
fi
pgrep -f -- "--datadir=$S/data" && { echo "datadir in use" >&2; exit 2; }
rm -f $S/run/*
setsid nohup $B/mysqld --no-defaults --user=root --datadir=$S/data --port=3417 --bind-address=127.0.0.1 --socket=$S/run/mysqld.sock --mysqlx=OFF --pid-file=$S/run/mysqld.pid --log-error=$S/server.err --tmpdir=$S/tmp --skip-log-bin --innodb-flush-log-at-trx-commit=2 --innodb-buffer-pool-size=512M --sync-binlog=0 --performance-schema=OFF > /dev/null 2>&1 < /dev/null &
for i in $(seq 1 120); do $B/mysql -uroot -h127.0.0.1 -P3417 -e "select 1" >/dev/null 2>&1 && break; sleep 1; done
$B/mysql -uroot -h127.0.0.1 -P3417 -e "CREATE DATABASE IF NOT EXISTS vaseyaudio_g1fix; SELECT @@version v,@@datadir d,@@port p,@@log_bin lb,@@performance_schema ps,@@transaction_isolation iso"
