#!/usr/bin/env bash
# shellcheck disable=SC2015  # `test && test || fail` is intended: report UNKNOWN whenever any test fails.
# VASEY.AUDIO: age of the malware scanner's signatures, from the scanner's own version line. Exits like a Nagios plugin.
#
#   signature-age-check.sh [--scanner /usr/bin/clamdscan|/usr/bin/clamscan] [--as USER] [--warn-hours 36] [--crit-hours 44]
#
# The application refuses signatures older than 48 hours (media.max_signature_age_seconds) and measures the build time of
# the newest daily database, not freshclam's last check, so this alerts well before the limit: 36 and 44 hours by default
# (the guide suggests 24 to 36 hours as a starting point; docs/private-server-readiness.md says no threshold is
# established, so confirm them). It runs `<scanner> --version` the way the application does, with TZ=UTC. A date more than
# an hour in the future is CRIT: a clamd in a time zone east of UTC prints its local time, and the application then
# accepts signatures older than its limit without a word (docs/media-processing.md, "Time zone of clamd").
# Exit: 0 OK, 1 WARN, 2 CRIT, 3 UNKNOWN.
set -uo pipefail
export LC_ALL=C PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

SCANNER=/usr/bin/clamscan AS_USER="" WARN_H=36 CRIT_H=44
usage() { sed -n '3,/^set -uo/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//' >&2; exit 3; }
while [ $# -gt 0 ]; do
  case "$1" in
    --scanner) SCANNER=${2:?}; shift 2 ;;
    --as) AS_USER=${2:?}; shift 2 ;;
    --warn-hours) WARN_H=${2:?}; shift 2 ;;
    --crit-hours) CRIT_H=${2:?}; shift 2 ;;
    -h|--help) usage ;;
    *) echo "UNKNOWN unknown option $1"; exit 3 ;;
  esac
done
[[ "$WARN_H" =~ ^[0-9]{1,3}$ && "$CRIT_H" =~ ^[0-9]{1,3}$ ]] && [ "$WARN_H" -le "$CRIT_H" ] || { echo "UNKNOWN --warn-hours and --crit-hours must be whole numbers, warn not above crit"; exit 3; }
[ -x "$SCANNER" ] || { echo "UNKNOWN $SCANNER is not executable"; exit 3; }
if [ -n "$AS_USER" ] && [ "$AS_USER" != "$(id -un)" ]; then
  [ "$(id -u)" = 0 ] && id "$AS_USER" >/dev/null 2>&1 || { echo "UNKNOWN --as needs root and an existing user"; exit 3; }
  line=$(TZ=UTC runuser -u "$AS_USER" -- "$SCANNER" --version 2>/dev/null | head -n 1)
else
  line=$(TZ=UTC "$SCANNER" --version 2>/dev/null | head -n 1)
fi
if [[ ! "$line" =~ ^ClamAV\ [0-9][0-9.]*/[0-9]+/(.+)$ ]]; then
  echo "CRIT the scanner printed no signature date (daemon down, EnableVersionCommand no, or no database)"
  exit 2
fi
epoch=$(TZ=UTC date -d "${BASH_REMATCH[1]}" +%s 2>/dev/null) || { echo "UNKNOWN the signature date is unreadable"; exit 3; }
now=$(date -u +%s)
age=$(( now - epoch ))
if [ "$age" -lt -3600 ]; then echo "CRIT signature date is $(( -age / 60 )) minutes in the future (clamd time zone east of UTC?)"; exit 2; fi
hours=$(( age / 3600 ))
if [ "$hours" -ge "$CRIT_H" ]; then echo "CRIT signature_age_hours=$hours (application limit 48)"; exit 2; fi
if [ "$hours" -ge "$WARN_H" ]; then echo "WARN signature_age_hours=$hours (application limit 48)"; exit 1; fi
echo "OK signature_age_hours=$hours"
exit 0
