#!/usr/bin/env bash
# shellcheck disable=SC2015  # `test && test || fail` is intended: report UNKNOWN whenever any test fails.
# VASEY.AUDIO: queue-age check and heartbeat. Reads the database queue tables read-only and exits like a Nagios plugin.
#
#   queue-age-check.sh [--kit production|staging] [--conf FILE] [--defaults-file FILE] [--warn SECONDS] [--crit SECONDS]
#                      [--stuck-warn SECONDS] [--failed-hours N] [--failed-warn N] [--failed-crit N]
#                      [--queue-threshold QUEUE=WARN:CRIT]... [--heartbeat-url-file FILE]
#
# For every queue in `jobs` it reports how long the oldest READY job (not reserved, available_at in the past) has waited,
# how long the oldest RESERVED job has been held, and how many rows `failed_jobs` gained in the last --failed-hours.
#   - a ready job waiting past --warn / --crit seconds means no worker is taking that queue;
#   - a job reserved past --stuck-warn (default 1200, the DB_QUEUE_RETRY_AFTER the kit requires) is overdue for release;
#   - failed_jobs rows in the window warn at --failed-warn (default 1) and go critical at --failed-crit (default 10).
# Defaults are starting points: docs/private-server-readiness.md says no threshold is established. Per-queue overrides:
# --queue-threshold media=900:3600 (the media job may legitimately hold a reservation for 900 s).
# Read-only by construction: only SELECT statements, over a session set to READ ONLY, and the payload column is never
# selected. The account needs SELECT on `jobs` and `failed_jobs`; by default it reuses the backup account's defaults file.
# Output is one line per queue plus a SUMMARY line, with counts and ages only: no payloads, no job names, no credentials.
# --heartbeat-url-file names a root-owned 0600 file holding one https URL (a dead-man's-switch ping such as a
# healthchecks.io check). It is requested only when the result is OK or WARN, so a critical result, a dead cron or a dead
# host all end in the silence the receiving service alerts on. The URL is a secret: it is never printed or logged.
# Exit: 0 OK, 1 WARN, 2 CRIT, 3 UNKNOWN (database unreachable or an unreadable answer: never reported as OK).
set -uo pipefail
export LC_ALL=C PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

KIT=production CONF="" DEFAULTS="" WARN=300 CRIT=900 STUCK=1200 FAILED_HOURS=24 FAILED_WARN=1 FAILED_CRIT=10 HEARTBEAT=""
MYSQL_BIN=${VASEY_MYSQL_BIN:-mysql} CURL_BIN=${VASEY_CURL_BIN:-curl}
declare -A QWARN=() QCRIT=()
usage() { sed -n '2,/^set -uo/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//' >&2; exit 3; }
nonneg() { [[ "$1" =~ ^[0-9]{1,9}$ ]]; }
while [ $# -gt 0 ]; do
  case "$1" in
    --kit) KIT=${2:?}; shift 2 ;;
    --conf) CONF=${2:?}; shift 2 ;;
    --defaults-file) DEFAULTS=${2:?}; shift 2 ;;
    --warn) WARN=${2:?}; shift 2 ;;
    --crit) CRIT=${2:?}; shift 2 ;;
    --stuck-warn) STUCK=${2:?}; shift 2 ;;
    --failed-hours) FAILED_HOURS=${2:?}; shift 2 ;;
    --failed-warn) FAILED_WARN=${2:?}; shift 2 ;;
    --failed-crit) FAILED_CRIT=${2:?}; shift 2 ;;
    --queue-threshold)
      [[ "${2:-}" =~ ^([a-z][a-z0-9_-]{0,31})=([0-9]{1,9}):([0-9]{1,9})$ ]] || { echo "UNKNOWN bad --queue-threshold (use queue=warn:crit)"; exit 3; }
      QWARN[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}; QCRIT[${BASH_REMATCH[1]}]=${BASH_REMATCH[3]}; shift 2 ;;
    --heartbeat-url-file) HEARTBEAT=${2:?}; shift 2 ;;
    -h|--help) usage ;;
    *) echo "UNKNOWN unknown option $1"; exit 3 ;;
  esac
done
for v in "$WARN" "$CRIT" "$STUCK" "$FAILED_WARN" "$FAILED_CRIT"; do nonneg "$v" || { echo "UNKNOWN thresholds must be whole numbers"; exit 3; }; done
[[ "$FAILED_HOURS" =~ ^[0-9]{1,4}$ ]] && [ "$FAILED_HOURS" -ge 1 ] || { echo "UNKNOWN --failed-hours must be 1 to 9999"; exit 3; }
[[ "$KIT" =~ ^(production|staging)$ ]] || { echo "UNKNOWN --kit must be production or staging"; exit 3; }
[ "$WARN" -le "$CRIT" ] || { echo "UNKNOWN --warn must not exceed --crit"; exit 3; }

CONF=${CONF:-/etc/vasey-$KIT/$KIT.conf}
conf_value() { [ -f "$CONF" ] && sed -n "s/^$1=//p" "$CONF" | tail -n 1; }
DB=$(conf_value VASEY_DB_NAME)
[ -n "$DB" ] || { echo "UNKNOWN no VASEY_DB_NAME in $CONF"; exit 3; }
[[ "$DB" =~ ^[a-z][a-z0-9_]{0,40}$ ]] || { echo "UNKNOWN the database name in $CONF is not a plain identifier"; exit 3; }
DEFAULTS=${DEFAULTS:-$(conf_value VASEY_BACKUP_MYCNF)}
DEFAULTS=${DEFAULTS:-/etc/vasey-$KIT/backup.my.cnf}
[ -f "$DEFAULTS" ] && [ ! -L "$DEFAULTS" ] || { echo "UNKNOWN the MySQL defaults file is missing"; exit 3; }
case "$(stat -c %a "$DEFAULTS")" in 600|400) ;; *) echo "UNKNOWN the MySQL defaults file must be mode 0600"; exit 3 ;; esac

# SELECT only. Three result kinds: ready, reserved, failed. Column order: kind, queue, count, age_seconds.
SQL="SELECT 'ready', queue, COUNT(*), CAST(UNIX_TIMESTAMP() AS SIGNED) - CAST(MIN(available_at) AS SIGNED) FROM jobs WHERE reserved_at IS NULL AND available_at <= UNIX_TIMESTAMP() GROUP BY queue
UNION ALL SELECT 'reserved', queue, COUNT(*), CAST(UNIX_TIMESTAMP() AS SIGNED) - CAST(MIN(reserved_at) AS SIGNED) FROM jobs WHERE reserved_at IS NOT NULL GROUP BY queue
UNION ALL SELECT 'failed', queue, COUNT(*), 0 FROM failed_jobs WHERE failed_at >= NOW() - INTERVAL $FAILED_HOURS HOUR GROUP BY queue"
if ! rows=$("$MYSQL_BIN" --defaults-file="$DEFAULTS" --no-login-paths --connect-timeout=10 --batch --skip-column-names \
     --init-command='SET SESSION TRANSACTION READ ONLY' -D "$DB" -e "$SQL" 2>/dev/null); then
  echo "UNKNOWN the queue tables could not be read (database unreachable, account unable to SELECT, or schema missing)"
  exit 3
fi

declare -A READY_N=() READY_AGE=() RES_N=() RES_AGE=() FAIL_N=()
declare -A QUEUES=()
while IFS=$'\t' read -r kind queue count age; do
  [ -n "$kind" ] || continue
  [[ "$queue" =~ ^[A-Za-z0-9_.:-]{1,64}$ ]] && nonneg "$count" && [[ "${age:-0}" =~ ^-?[0-9]{1,12}$ ]] \
    || { echo "UNKNOWN an unreadable answer from the database"; exit 3; }
  QUEUES[$queue]=1
  case "$kind" in
    ready) READY_N[$queue]=$count; READY_AGE[$queue]=$age ;;
    reserved) RES_N[$queue]=$count; RES_AGE[$queue]=$age ;;
    failed) FAIL_N[$queue]=$count ;;
    *) echo "UNKNOWN an unreadable answer from the database"; exit 3 ;;
  esac
done <<< "$rows"

worst=0
note() { [ "$1" -le "$worst" ] || worst=$1; }
names=(OK WARN CRIT)
total_failed=0
for queue in $(printf '%s\n' "${!QUEUES[@]}" | sort); do
  rw=${QWARN[$queue]:-$WARN} rc=${QCRIT[$queue]:-$CRIT}
  ready=${READY_N[$queue]:-0} ready_age=${READY_AGE[$queue]:-0} held=${RES_N[$queue]:-0} held_age=${RES_AGE[$queue]:-0} failed=${FAIL_N[$queue]:-0}
  [ "$ready_age" -ge 0 ] || ready_age=0
  [ "$held_age" -ge 0 ] || held_age=0
  level=0; why=""
  if [ "$ready_age" -ge "$rc" ]; then level=2; why="ready jobs have waited ${ready_age}s (critical at ${rc}s)"
  elif [ "$ready_age" -ge "$rw" ]; then level=1; why="ready jobs have waited ${ready_age}s (warning at ${rw}s)"; fi
  if [ "$held_age" -ge $((STUCK * 2)) ]; then level=2; why="${why:+$why; }a job has been reserved ${held_age}s"
  elif [ "$held_age" -ge "$STUCK" ]; then [ "$level" -ge 1 ] || level=1; why="${why:+$why; }a job has been reserved ${held_age}s"; fi
  if [ "$failed" -ge "$FAILED_CRIT" ]; then level=2; why="${why:+$why; }$failed failed in ${FAILED_HOURS}h"
  elif [ "$failed" -ge "$FAILED_WARN" ] && [ "$FAILED_WARN" -gt 0 ]; then [ "$level" -ge 1 ] || level=1; why="${why:+$why; }$failed failed in ${FAILED_HOURS}h"; fi
  total_failed=$((total_failed + failed))
  note "$level"
  echo "${names[$level]} queue=$queue ready=$ready oldest_ready_s=$ready_age reserved=$held oldest_reserved_s=$held_age failed_${FAILED_HOURS}h=$failed${why:+ :: $why}"
done
[ "${#QUEUES[@]}" -gt 0 ] || echo "OK no queued, reserved or recently failed jobs"
echo "SUMMARY ${names[$worst]} queues=${#QUEUES[@]} failed_${FAILED_HOURS}h=$total_failed"

if [ -n "$HEARTBEAT" ] && [ "$worst" -le 1 ]; then
  if [ -f "$HEARTBEAT" ] && [ ! -L "$HEARTBEAT" ] && [ "$(stat -c '%u %a' "$HEARTBEAT")" = '0 600' ] && url=$(head -n 1 "$HEARTBEAT") && [[ "$url" =~ ^https://[^[:space:]]+$ ]]; then
    "$CURL_BIN" -fsS -m 10 --retry 2 -o /dev/null "$url" 2>/dev/null || { echo "WARN the heartbeat ping failed (the receiving service will alert on the silence)"; [ "$worst" -ge 1 ] || worst=1; }
  else
    echo "WARN the heartbeat URL file must be a root-owned 0600 file holding one https URL"; [ "$worst" -ge 1 ] || worst=1
  fi
fi
exit "$worst"
