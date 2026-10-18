#!/usr/bin/env bash
# shellcheck disable=SC2015  # `test && test || fail` is intended: report UNKNOWN whenever any test fails.
# VASEY.AUDIO: scheduler liveness (and, with --workers, worker liveness). Exits like a Nagios plugin.
#
#   scheduler-liveness-check.sh [--kit production|staging] [--conf FILE] [--workers] [--max-silence SECONDS] [--log-file FILE]
#
# The application has no scheduler heartbeat of its own: routes/console.php runs vasey:publish-scheduled-site-release every
# minute, and nothing it does leaves a durable trace when no release is due. This check therefore combines three signals
# that need no application change:
#   1. supervisor reports <group>:<group>-scheduler RUNNING (not just recently restarted);
#   2. an `artisan schedule:work` process of the application user runs from the served release (`current`), so a scheduler
#      left on an old release after a deploy is caught;
#   3. a tick: `schedule:work` prints "Running [... vasey:publish-scheduled-site-release]" once a minute, and that line was
#      seen within --max-silence seconds (default 180). The source is --log-file (its modification time and last tick), or,
#      without it, the journal: the production workers log through supervisor's stdout_syslog, which tags the program name
#      into each line.
# --workers also requires the media, payments, contracts and default workers RUNNING on the served release.
# A ready-made dead-man's switch for the scheduler would be a reviewed application change (a ping on schedule success);
# this script is what can be done without one. Exit: 0 OK, 1 WARN, 2 CRIT, 3 UNKNOWN.
set -uo pipefail
export LC_ALL=C PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

KIT=production CONF="" WORKERS=0 SILENCE=180 LOGFILE=""
SUPERVISORCTL=${VASEY_SUPERVISORCTL:-supervisorctl} JOURNALCTL=${VASEY_JOURNALCTL:-journalctl} PROC=${VASEY_PROC_ROOT:-/proc}
usage() { sed -n '3,/^set -uo/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//' >&2; exit 3; }
while [ $# -gt 0 ]; do
  case "$1" in
    --kit) KIT=${2:?}; shift 2 ;;
    --conf) CONF=${2:?}; shift 2 ;;
    --workers) WORKERS=1; shift ;;
    --max-silence) SILENCE=${2:?}; shift 2 ;;
    --log-file) LOGFILE=${2:?}; shift 2 ;;
    -h|--help) usage ;;
    *) echo "UNKNOWN unknown option $1"; exit 3 ;;
  esac
done
[[ "$KIT" =~ ^(production|staging)$ ]] || { echo "UNKNOWN --kit must be production or staging"; exit 3; }
[[ "$SILENCE" =~ ^[0-9]{2,5}$ ]] || { echo "UNKNOWN --max-silence must be 10 to 99999 seconds"; exit 3; }
CONF=${CONF:-/etc/vasey-$KIT/$KIT.conf}
conf_value() { [ -f "$CONF" ] && sed -n "s/^$1=//p" "$CONF" | tail -n 1; }
ROOT=$(conf_value VASEY_ROOT); APP_USER=$(conf_value VASEY_APP_USER)
[[ "$ROOT" =~ ^/[A-Za-z0-9/_.-]+$ ]] && [[ "$APP_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || { echo "UNKNOWN $CONF lacks a usable VASEY_ROOT or VASEY_APP_USER"; exit 3; }
GROUP=vasey-$KIT
CURRENT=$(readlink -f "$ROOT/current" 2>/dev/null) || CURRENT=""

worst=0
report() { local level=$1; shift; echo "$([ "$level" = 0 ] && echo OK || { [ "$level" = 1 ] && echo WARN || echo CRIT; }) $*"; [ "$level" -le "$worst" ] || worst=$level; }
program_line() { "$SUPERVISORCTL" status "$GROUP:$1" 2>/dev/null | head -n 1; }
program_pid() { [[ "$1" =~ pid\ ([0-9]+) ]] && echo "${BASH_REMATCH[1]}"; }
uptime_seconds() {  # supervisor prints "uptime H:MM:SS" or "uptime D days, H:MM:SS"
  local days=0 h m s
  if [[ "$1" =~ uptime\ ([0-9]+)\ days?,\ ([0-9]+):([0-9]+):([0-9]+) ]]; then days=${BASH_REMATCH[1]}; h=${BASH_REMATCH[2]}; m=${BASH_REMATCH[3]}; s=${BASH_REMATCH[4]}
  elif [[ "$1" =~ uptime\ ([0-9]+):([0-9]+):([0-9]+) ]]; then h=${BASH_REMATCH[1]}; m=${BASH_REMATCH[2]}; s=${BASH_REMATCH[3]}
  else return 1; fi
  echo $(( days * 86400 + 10#$h * 3600 + 10#$m * 60 + 10#$s ))
}
on_served_release() {  # on_served_release PID: the process runs from the release `current` points at
  local cwd
  [ -n "$CURRENT" ] || return 1
  cwd=$(readlink -f "$PROC/$1/cwd" 2>/dev/null) || return 1
  [ "$cwd" = "$CURRENT" ]
}

# 1 and 2: the scheduler program, then the process behind it.
sched=scheduler
line=$(program_line "$GROUP-$sched")
if [ -z "$line" ]; then report 2 "scheduler: supervisor does not know $GROUP:$GROUP-$sched (is supervisor reachable? run as root)"
elif [[ "$line" == *RUNNING* ]]; then
  up=$(uptime_seconds "$line") || up=""
  if [ -n "$up" ] && [ "$up" -lt 60 ]; then report 1 "scheduler: RUNNING for only ${up}s (a restart or a deploy is under way, or it is crash-looping)"
  else report 0 "scheduler: supervisor reports RUNNING${up:+ for ${up}s}"; fi
  pid=$(program_pid "$line")
  if [ -z "$pid" ]; then report 1 "scheduler: no pid in the supervisor status"
  elif on_served_release "$pid"; then report 0 "scheduler: pid $pid runs from the served release"
  else report 1 "scheduler: pid $pid does not run from the served release (restart the workers after a switch)"; fi
else
  report 2 "scheduler: supervisor reports $(tr -s " " <<<"$line")"
fi

# 3: a tick within --max-silence seconds.
now=$(date +%s)
if [ -n "$LOGFILE" ]; then
  if [ -f "$LOGFILE" ]; then
    age=$(( now - $(stat -c %Y "$LOGFILE") ))
    if [ "$age" -gt "$SILENCE" ]; then report 2 "tick: $LOGFILE was last written ${age}s ago (limit ${SILENCE}s)"
    elif tail -n 200 "$LOGFILE" 2>/dev/null | grep -q 'publish-scheduled-site-release'; then report 0 "tick: the scheduler log was written ${age}s ago and names the every-minute command"
    else report 1 "tick: the scheduler log is fresh but does not name vasey:publish-scheduled-site-release in its last 200 lines"; fi
  else report 2 "tick: $LOGFILE does not exist"; fi
elif command -v "$JOURNALCTL" >/dev/null 2>&1; then
  if hits=$("$JOURNALCTL" -t supervisord --since "-${SILENCE} seconds" -o cat --no-pager -q 2>/dev/null); then
    count=$(grep -c "$GROUP-$sched.*publish-scheduled-site-release" <<<"$hits" || true)
    if [ "${count:-0}" -ge 1 ]; then report 0 "tick: $count scheduler tick(s) in the journal within ${SILENCE}s"
    else report 2 "tick: no scheduler tick in the journal within ${SILENCE}s"; fi
  else report 1 "tick: the journal could not be read (run as root, or pass --log-file)"; fi
else
  report 1 "tick: no journal and no --log-file, so only the process checks apply"
fi

# Optional: the four queue workers.
if [ "$WORKERS" = 1 ]; then
  for w in media payments contracts default; do
    line=$(program_line "$GROUP-$w")
    if [[ "$line" != *RUNNING* ]]; then report 2 "worker $w: supervisor reports $(tr -s " " <<<"${line:-nothing}")"; continue; fi
    pid=$(program_pid "$line")
    if [ -n "$pid" ] && on_served_release "$pid"; then report 0 "worker $w: RUNNING from the served release"
    else report 1 "worker $w: RUNNING but not from the served release"; fi
  done
fi
echo "SUMMARY $([ "$worst" = 0 ] && echo OK || { [ "$worst" = 1 ] && echo WARN || echo CRIT; })"
exit "$worst"
