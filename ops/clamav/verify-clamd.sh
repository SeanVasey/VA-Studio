#!/usr/bin/env bash
# VASEY.AUDIO: run the automatable part of the clamd acceptance list (docs/media-processing.md, "Acceptance list",
# steps 1 to 4) against the running daemon, as the media worker's user, with the binary and options the application uses.
#
#   sudo bash ops/clamav/verify-clamd.sh --as <worker user>      (or run it directly as the worker user)
# Options:
#   --as USER        run the scanner as this user (default: the current user). Needs root when it is another user.
#   --clamdscan BIN  default /usr/bin/clamdscan
#   --clamscan BIN   default /usr/bin/clamscan (used only to compare signature dates; skipped when absent)
#   --max-age SECS   signature age limit (default 172800, the application's media.max_signature_age_seconds)
#
# It checks, and prints one PASS or FAIL line for each:
#   1. `clamdscan --version` prints ClamAV <version>/<number>/<date>, the date is within the limit and equals the date
#      `TZ=UTC clamscan --version` prints (a daemon in another time zone, or one that has not reloaded yet, differs)
#   2. a clean file reads OK and the EICAR test file is FOUND (exit 1)
#   3. a ZIP over 25 MiB with an EICAR member is FOUND (catches the packaged limits and ScanArchive no)
#   4. a sparse file over the 1280M limit is FOUND as Heuristics.Limits.Exceeded.MaxFileSize at once (AlertExceedsMax)
# Steps 5 to 8 (deflate bomb, largest stems end to end, stopped daemon, vasey:doctor) stay with the operator: they take
# minutes, need the application, or stop the daemon. Exit 0 only when every automated check passed. Nothing is changed
# on the host except a scratch directory that is removed at the end. The EICAR text is assembled here at run time.
set -uo pipefail
export LC_ALL=C

AS_USER="" CLAMDSCAN=/usr/bin/clamdscan CLAMSCAN=/usr/bin/clamscan MAX_AGE=172800
while [ $# -gt 0 ]; do
  case "$1" in
    --as) AS_USER=${2:?}; shift 2 ;;
    --clamdscan) CLAMDSCAN=${2:?}; shift 2 ;;
    --clamscan) CLAMSCAN=${2:?}; shift 2 ;;
    --max-age) MAX_AGE=${2:?}; shift 2 ;;
    *) echo "verify-clamd: unknown option $1" >&2; exit 2 ;;
  esac
done
[[ "$MAX_AGE" =~ ^[0-9]+$ ]] || { echo "verify-clamd: --max-age must be whole seconds" >&2; exit 2; }
[ -x "$CLAMDSCAN" ] || { echo "verify-clamd: $CLAMDSCAN is not executable" >&2; exit 2; }
if [ -n "$AS_USER" ] && [ "$AS_USER" != "$(id -un)" ]; then
  [ "$(id -u)" = 0 ] || { echo "verify-clamd: --as another user needs root" >&2; exit 2; }
  id "$AS_USER" >/dev/null 2>&1 || { echo "verify-clamd: no such user $AS_USER" >&2; exit 2; }
else
  AS_USER=$(id -un)
fi

FAILED=0
pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAILED=1; }
as_user() { if [ "$(id -un)" = "$AS_USER" ]; then "$@"; else runuser -u "$AS_USER" -- "$@"; fi; }

SCRATCH=$(mktemp -d /var/tmp/vasey-clamd-verify.XXXXXX) || { echo "verify-clamd: cannot create a scratch directory" >&2; exit 2; }
trap 'rm -rf --one-file-system -- "$SCRATCH"' EXIT
chmod 0700 "$SCRATCH"
[ "$(id -un)" = "$AS_USER" ] || chown "$AS_USER" "$SCRATCH"
scan() { as_user "$CLAMDSCAN" --fdpass --no-summary "$@" 2>&1; }

# 1. signature date: present, fresh, and the same instant the UTC clamscan reports.
version_line=$(as_user "$CLAMDSCAN" --version 2>/dev/null | head -n 1)
if [[ "$version_line" =~ ^ClamAV\ [0-9][0-9.]*/[0-9]+/(.+)$ ]]; then
  daemon_date=${BASH_REMATCH[1]}
  daemon_epoch=$(TZ=UTC date -d "$daemon_date" +%s 2>/dev/null) || daemon_epoch=""
  now=$(date -u +%s)
  if [ -z "$daemon_epoch" ]; then fail "1 the daemon's signature date is unreadable"
  elif [ $((now - daemon_epoch)) -lt -3600 ]; then fail "1 the daemon's signature date is $(( (daemon_epoch - now) / 60 )) minutes in the future (is clamd's time zone east of UTC? install systemd/clamav-daemon.service.d/timezone.conf)"
  elif [ $((now - daemon_epoch)) -ge "$MAX_AGE" ]; then fail "1 signatures are $(( (now - daemon_epoch) / 3600 )) hours old, over the application's limit"
  else pass "1 signature date is fresh ($(( (now - daemon_epoch) / 3600 )) h old)"; fi
  if [ -x "$CLAMSCAN" ]; then
    scan_line=$(TZ=UTC as_user "$CLAMSCAN" --version 2>/dev/null | head -n 1)
    if [[ "$scan_line" =~ ^ClamAV\ [0-9][0-9.]*/[0-9]+/(.+)$ ]]; then
      scan_epoch=$(TZ=UTC date -d "${BASH_REMATCH[1]}" +%s 2>/dev/null) || scan_epoch=""
      if [ -n "$daemon_epoch" ] && [ "$scan_epoch" = "$daemon_epoch" ]; then pass "1 the daemon and TZ=UTC clamscan report the same signature time"
      else fail "1 the daemon and TZ=UTC clamscan report different signature times (time zone drop-in missing, or a reload is still pending: wait for SelfCheck and rerun)"; fi
    else
      echo "SKIP 1 clamscan printed no signature date; the time-zone comparison was not made"
    fi
  else
    echo "SKIP 1 $CLAMSCAN is absent; the time-zone comparison was not made"
  fi
else
  fail "1 'clamdscan --version' printed no signature date (daemon not running, or EnableVersionCommand no)"
fi

# 2. clean file OK, EICAR FOUND. The test string is assembled from pieces so this file is not itself a detection.
printf 'a clean file\n' > "$SCRATCH/clean.txt"
# shellcheck disable=SC2016  # the $ in the test string is literal
eicar() { printf '%s%s%s' 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD' '-ANTIVIRUS-TEST-FILE!' '$H+H*'; }
eicar > "$SCRATCH/eicar.com"
chmod 0644 "$SCRATCH/clean.txt" "$SCRATCH/eicar.com"
out=$(scan "$SCRATCH/clean.txt"); rc=$?
if [ "$rc" = 0 ] && grep -q ': OK$' <<<"$out"; then pass "2 a clean file reads OK"; else fail "2 a clean file did not read OK (exit $rc)"; fi
out=$(scan "$SCRATCH/eicar.com"); rc=$?
if [ "$rc" = 1 ] && grep -q 'FOUND$' <<<"$out"; then pass "2 the EICAR test file is FOUND ($(sed -n 's/^.*: \(.*\) FOUND$/\1/p' <<<"$out" | head -n 1))"; else fail "2 the EICAR test file was not FOUND (exit $rc)"; fi

# 3. a ZIP over 25 MiB whose last member is EICAR. Written without zip64 (ClamAV skipped the members of force_zip64 archives).
python3 -I - "$SCRATCH/big-eicar.zip" "$SCRATCH/eicar.com" <<'PY' || fail "3 could not build the test ZIP"
import os, sys, zipfile
target, eicar = sys.argv[1:3]
with zipfile.ZipFile(target, "w", zipfile.ZIP_STORED) as archive:
    archive.writestr("filler.bin", os.urandom(26 * 1024 * 1024))
    archive.write(eicar, "eicar.com")
PY
if [ -s "$SCRATCH/big-eicar.zip" ]; then
  chmod 0644 "$SCRATCH/big-eicar.zip"
  out=$(scan "$SCRATCH/big-eicar.zip"); rc=$?
  if [ "$rc" = 1 ] && grep -q 'FOUND$' <<<"$out"; then pass "3 a ZIP over 25 MiB with an EICAR member is FOUND"; else fail "3 a ZIP over 25 MiB with an EICAR member was not FOUND (exit $rc): the packaged limits, or ScanArchive no"; fi
fi

# 4. a file over the limit must be refused, not skipped.
if truncate -s 1300M "$SCRATCH/big.bin" 2>/dev/null; then
  chmod 0644 "$SCRATCH/big.bin"
  out=$(scan "$SCRATCH/big.bin"); rc=$?
  if [ "$rc" = 1 ] && grep -q 'Heuristics.Limits.Exceeded.MaxFileSize FOUND' <<<"$out"; then pass "4 a file over 1280M is refused as Heuristics.Limits.Exceeded.MaxFileSize"; else fail "4 a file over 1280M was not refused (exit $rc): MaxFileSize and MaxScanSize 1280M and AlertExceedsMax yes are required"; fi
else
  echo "SKIP 4 the filesystem cannot make a 1300M sparse file here"
fi

[ "$FAILED" = 0 ] || echo "verify-clamd: hint: a Permission denied or exit 2 usually means the user is not in the group clamav (restart the process after usermod -aG clamav) or AppArmor refused a path (dmesg | grep DENIED)"
echo "verify-clamd: automated acceptance steps 1-4 $([ "$FAILED" = 0 ] && echo passed || echo FAILED). Steps 5-8 of the guide remain: deflate bomb, largest stems end to end, stopped daemon, vasey:doctor media_scanner_limits."
exit "$FAILED"
