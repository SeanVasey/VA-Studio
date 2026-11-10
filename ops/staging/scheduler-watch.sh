#!/usr/bin/env bash
# VASEY.AUDIO staging scheduler dead-man's switch (FP-028, docs/staff-reporting.md "Scheduler dead-man's switch").
#
# Installed by provision.sh as /usr/local/sbin/vasey-staging-scheduler-watch (root:root 0755) and run as root from
# /etc/cron.d/vasey-staging-scheduler-watch every five minutes. It lives outside the application's own scheduler on purpose: it
# must still run when the scheduler has stopped. It runs `artisan vasey:scheduler-heartbeat-check --notify` as the application
# user on the served release. The command reads the newest `scheduler_heartbeats` row; when it is stale it sends ONE email per
# stale episode, and one when the heartbeat resumes, to REPORTING_SCHEDULER_ALERT_TO through the transactional mail
# configuration. With no recipient set (the default), or mail not admitted, nothing is sent and the command only reports.
#
# Output is one status line per run for the log (no address, secret or row). Exit: 0 heartbeat fresh, 1 stale, 3 could not check
# (no served release, PHP missing, database or state unreadable). A cron job that stops running cannot alert by itself: pair this
# with the queue-age check's heartbeat ping (docs/ops/monitoring-and-host-baseline.md) for an external service watching the host.
# shellcheck disable=SC2015  # `test && test || die` is intended: die whenever any test fails.
set -uo pipefail
export LC_ALL=C PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
CONF=${VASEY_STAGING_CONF:-/etc/vasey-staging/staging.conf}
log() { echo "vasey-staging-scheduler-watch: $(date -u +%FT%TZ) $*"; }
die() { log "UNKNOWN $*"; exit 3; }

[ "$(id -u)" -eq 0 ] || die "must run as root"
[ -f "$CONF" ] && [ ! -L "$CONF" ] && [ "$(stat -c %u "$CONF")" = 0 ] || die "missing or untrusted $CONF"
# shellcheck source=/dev/null
. "$CONF"
: "${VASEY_APP_USER:?}" "${VASEY_ROOT:?}" "${VASEY_PHP:?}"
[ "$(id -u "$VASEY_APP_USER")" -gt 0 ] || die "application account must be nonroot"
[ -L "$VASEY_ROOT/current" ] || die "no served release yet"
cur=$(readlink -f -- "$VASEY_ROOT/current" 2>/dev/null) || cur=''
[ -n "$cur" ] && [ -f "$cur/artisan" ] || die "no served release"

# Never overlap a previous run still waiting on a relay; the command also locks its episode state.
exec 8>"$VASEY_ROOT/.scheduler-watch.lock" || die "cannot open the lock"
flock -n 8 || { log "previous run still active; skipped"; exit 0; }

out=$(cd -- "$cur" && timeout -k 10 120 runuser -u "$VASEY_APP_USER" -- env -i PATH=/usr/bin:/bin LC_ALL=C \
  "$VASEY_PHP" "$cur/artisan" vasey:scheduler-heartbeat-check --notify --no-interaction --no-ansi </dev/null 2>/dev/null 8>&-)
status=$?
log "exit $status: $(printf '%s' "$out" | tr '\n' ' ' | cut -c1-240)"
exit "$status"
