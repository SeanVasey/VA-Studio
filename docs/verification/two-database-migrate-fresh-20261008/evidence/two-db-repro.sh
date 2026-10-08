#!/usr/bin/env bash
# Two complete application schemas on one private mysqld (:3410), migrated one after the other.
# Usage: [DBS="db1 db2 db1"] two-db-repro.sh <checkout dir>
# Databases must already exist on the private daemon (see mysqld-up.sh).
set -uo pipefail
cd "$1"
# Every migrate:fresh status and the final catalog dump are accumulated; the script exits nonzero when any one failed.
status=0
MYSQL=/opt/mysql84/mysql-8.4.11-linux-glibc2.28-x86_64/bin/mysql
# The selected database names drive both catalog predicates. Names are restricted to the identifier characters the
# predicates quote, so an override cannot widen the query or dump an unrelated schema.
DBS=${DBS:-rv256_1 rv256_2 rv256_1}
IN=""
for db in $DBS; do
  case "$db" in *[!A-Za-z0-9_]*|"") echo "refusing database name: $db" >&2; exit 2;; esac
  case ",$IN," in *",'$db',"*) ;; *) IN="${IN:+$IN,}'$db'";; esac
done
export APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3410 DB_USERNAME=root DB_PASSWORD=
echo "source: $(git rev-parse HEAD)  php: $(php -r 'echo PHP_VERSION;')  mysqld: $("$MYSQL" -N -uroot -h127.0.0.1 -P3410 -e 'SELECT VERSION()')"
for db in $DBS; do
  echo "== migrate:fresh --force on $db"
  DB_DATABASE=$db php artisan migrate:fresh --force > /tmp/mf-$db.log 2>&1; rc=$?; [ "$rc" -eq 0 ] || status=1
  grep -E "238000|FAIL|Unexpected" /tmp/mf-$db.log | head -5
  echo "exit=$rc migrations_done=$(grep -cE "^  [0-9]{4}_[0-9_]+_.* DONE" /tmp/mf-$db.log)"
done
"$MYSQL" -uroot -h127.0.0.1 -P3410 -e "SELECT TRIGGER_SCHEMA, COUNT(*) triggers FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ($IN) GROUP BY TRIGGER_SCHEMA; SELECT TRIGGER_SCHEMA, TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ($IN) AND TRIGGER_NAME='ptp_packet_insert';" || status=1
exit $status
