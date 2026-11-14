#!/bin/bash
# usage: g1-mysqld-start.sh [ps]   private MySQL 8.4.11 for lane G1 on port 3371 (ps = performance_schema ON for MDL probes)
S=/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad/g1-mysql
B=/opt/mysql-8.4.11/bin
$B/mysqladmin -uroot -h127.0.0.1 -P3371 shutdown 2>/dev/null
for i in $(seq 1 60); do pgrep -f "port=3371" >/dev/null || break; sleep 1; done
if [ ! -d $S/data/mysql ]; then rm -rf $S/data; $B/mysqld --no-defaults --initialize-insecure --user=root --datadir=$S/data --log-error=$S/init.err || exit 3; fi
rm -f $S/run/*
ps=OFF; [ "$1" = ps ] && ps=ON
setsid nohup $B/mysqld --no-defaults --user=root --datadir=$S/data --port=3371 --bind-address=127.0.0.1 --socket=$S/run/mysqld.sock --mysqlx=OFF --pid-file=$S/run/mysqld.pid --log-error=$S/server.err --tmpdir=$S/tmp --skip-log-bin --innodb-flush-log-at-trx-commit=2 --innodb-buffer-pool-size=512M --sync-binlog=0 --performance-schema=$ps > /dev/null 2>&1 < /dev/null &
for i in $(seq 1 120); do $B/mysql -uroot -h127.0.0.1 -P3371 -e "select 1" >/dev/null 2>&1 && break; sleep 1; done
$B/mysql -uroot -h127.0.0.1 -P3371 -e "CREATE DATABASE IF NOT EXISTS vaseyaudio_g1; SELECT @@version v,@@innodb_flush_log_at_trx_commit f,@@log_bin lb,@@sync_binlog sb,@@innodb_buffer_pool_size bp,@@performance_schema ps,@@transaction_isolation iso"
