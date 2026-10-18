#!/usr/bin/env bash
# shellcheck disable=SC2015  # `test && test || die` is intended: die whenever any test fails.
# VASEY.AUDIO: opt-in installer for the documented production malware scanner (clamd + clamdscan --fdpass).
#
#   sudo bash ops/clamav/install-clamd.sh --worker-user forge [--media-workers 1] [--root /srv/vasey-production] [--dry-run]
#
# Nothing in the staging or production kit runs this: provision.sh keeps clamscan, the documented fallback, and a host
# changes only when an operator runs this file. It follows docs/media-processing.md, "Production malware scanner":
#   1. packages: clamav-daemon, clamdscan (a separate Ubuntu package that clamav-daemon only Recommends, so the kit's
#      --no-install-recommends installs miss it and every scan would fail with "command not found"), clamav-freshclam
#   2. /etc/clamav/clamd.conf from ops/clamav/clamd.conf (the packaged one fails open); the original is kept once
#   3. systemd drop-ins: TZ=UTC for the service, and SocketMode=0660 on the socket (the packaged socket unit is 0666)
#   4. TemporaryDirectory /var/tmp/clamd, owned by clamav
#   5. AppArmor: clamd's packaged profile does not allow /var/tmp/clamd or the private storage tree; a marked block goes
#      into /etc/apparmor.d/local/usr.sbin.clamd (only if that profile exists) and the profile is reloaded
#   6. the worker's user joins the group clamav (a running worker keeps its old groups: restart it afterwards)
#   7. the daemon is restarted, the socket's owner and mode are checked, then verify-clamd.sh runs acceptance steps 1-4
# It prints what is left for the operator: set MEDIA_CLAMSCAN=/usr/bin/clamdscan, restart PHP-FPM and the workers, run
# `vasey:doctor`, and finish acceptance steps 5-8. It never edits the application's .env and never restarts a worker.
# `--dry-run` prints every change without making it.
set -euo pipefail
umask 022
export LC_ALL=C DEBIAN_FRONTEND=noninteractive

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
die()  { echo "install-clamd: ERROR: $*" >&2; exit 1; }
info() { echo "install-clamd: $*"; }
DRY=0
run() { if [ "$DRY" = 1 ]; then echo "install-clamd: [dry-run] $*"; else "$@"; fi; }

# The MaxThreads the installed file must carry: the template's value, raised to twice the media workers when larger.
desired_max_threads() {  # desired_max_threads TEMPLATE WORKERS
  local template=$1 workers=$2 base want
  base=$(sed -n 's/^MaxThreads \([0-9][0-9]*\)$/\1/p' "$template")
  [[ "$base" =~ ^[0-9]+$ ]] || return 1
  want=$((workers * 2))
  [ "$want" -gt "$base" ] && echo "$want" || echo "$base"
}

render_conf() {  # render_conf TEMPLATE WORKERS -> stdout
  local template=$1 workers=$2 threads
  threads=$(desired_max_threads "$template" "$workers") || return 1
  sed -E "s/^MaxThreads [0-9]+\$/MaxThreads $threads/" "$template"
}

apparmor_block() {  # apparmor_block KIT_ROOT -> stdout
  printf '%s\n' '# BEGIN vasey-clamd (ops/clamav/install-clamd.sh; replaced on every run)' \
    '/var/tmp/clamd/ r,' '/var/tmp/clamd/** krw,' \
    "$1/private/** r," "$1/releases/*/storage/app/private/** r," \
    '# END vasey-clamd'
}

main() {
  local worker="" workers=1 root="" template="$HERE/clamd.conf" threads sock_state
  while [ $# -gt 0 ]; do
    case "$1" in
      --worker-user) worker=${2:?}; shift 2 ;;
      --media-workers) workers=${2:?}; shift 2 ;;
      --root) root=${2:?}; shift 2 ;;
      --dry-run) DRY=1; shift ;;
      *) die "unknown option $1" ;;
    esac
  done
  [ "$(id -u)" = 0 ] || die "run as root (sudo bash $0 ...)"
  [ -n "$worker" ] && [ "$(id -u "$worker" 2>/dev/null || echo 0)" -gt 0 ] || die "--worker-user must name the existing nonroot user that runs the media worker"
  [[ "$workers" =~ ^[0-9]+$ ]] && [ "$workers" -ge 1 ] && [ "$workers" -le 16 ] || die "--media-workers must be 1 to 16"
  if [ -z "$root" ]; then
    local conf
    for conf in /etc/vasey-production/production.conf /etc/vasey-staging/staging.conf; do
      [ -f "$conf" ] || continue
      root=$(sed -n 's/^VASEY_ROOT=//p' "$conf" | head -n 1); [ -z "$root" ] || break
    done
  fi
  [[ "$root" =~ ^/[A-Za-z0-9/_.-]+$ ]] && [[ "$root" != *..* ]] || die "--root must name the kit root (for example /srv/vasey-production); none was found in /etc/vasey-*/"
  [ -f "$template" ] || die "missing $template"
  # shellcheck source=/dev/null
  . /etc/os-release
  [ "${VERSION_ID:-}" = 24.04 ] || info "WARNING: Ubuntu ${VERSION_ID:-?}; the figures in the guide were measured on 24.04"

  info "1/7 packages"
  run apt-get update -q
  run apt-get install -y -q --no-install-recommends clamav-daemon clamdscan clamav-freshclam

  info "2/7 /etc/clamav/clamd.conf (MaxThreads $(desired_max_threads "$template" "$workers") for $workers media worker(s))"
  if [ -f /etc/clamav/clamd.conf ] && ! grep -q '^# VASEY.AUDIO clamd profile' /etc/clamav/clamd.conf && [ ! -e /etc/clamav/clamd.conf.vasey-orig ]; then
    run cp -p /etc/clamav/clamd.conf /etc/clamav/clamd.conf.vasey-orig
  fi
  if [ "$DRY" = 1 ]; then echo "install-clamd: [dry-run] install the rendered $template as /etc/clamav/clamd.conf (0644 root:root)"
  else
    render_conf "$template" "$workers" > /etc/clamav/clamd.conf.vasey-new
    chmod 0644 /etc/clamav/clamd.conf.vasey-new; chown root:root /etc/clamav/clamd.conf.vasey-new
    mv -f /etc/clamav/clamd.conf.vasey-new /etc/clamav/clamd.conf
  fi

  info "3/7 systemd drop-ins (TZ=UTC; socket mode 0660) and 4/7 /var/tmp/clamd"
  run install -d -m 0755 /etc/systemd/system/clamav-daemon.service.d /etc/systemd/system/clamav-daemon.socket.d
  run install -m 0644 "$HERE/systemd/clamav-daemon.service.d/timezone.conf" /etc/systemd/system/clamav-daemon.service.d/timezone.conf
  run install -m 0644 "$HERE/systemd/clamav-daemon.socket.d/vasey-socket.conf" /etc/systemd/system/clamav-daemon.socket.d/vasey-socket.conf
  run install -d -m 0700 -o clamav -g clamav /var/tmp/clamd

  info "5/7 AppArmor"
  if [ -f /etc/apparmor.d/usr.sbin.clamd ]; then
    if [ "$DRY" = 1 ]; then echo "install-clamd: [dry-run] replace the vasey-clamd block in /etc/apparmor.d/local/usr.sbin.clamd and reload the profile"; apparmor_block "$root" | sed 's/^/install-clamd: [dry-run]   /'
    else
      install -d -m 0755 /etc/apparmor.d/local
      touch /etc/apparmor.d/local/usr.sbin.clamd
      sed -i '/^# BEGIN vasey-clamd/,/^# END vasey-clamd/d' /etc/apparmor.d/local/usr.sbin.clamd
      apparmor_block "$root" >> /etc/apparmor.d/local/usr.sbin.clamd
      apparmor_parser -r /etc/apparmor.d/usr.sbin.clamd || info "WARNING: could not reload the clamd AppArmor profile; scans may fail with Permission denied (dmesg | grep DENIED)"
    fi
  else
    info "no clamd AppArmor profile on this host; nothing to add"
  fi

  info "6/7 group membership"
  if id -nG "$worker" | tr ' ' '\n' | grep -qx clamav; then info "$worker is already in the group clamav"
  else run usermod -aG clamav "$worker"; fi

  info "7/7 restart the daemon and check it"
  run systemctl daemon-reload
  run systemctl enable clamav-daemon.socket clamav-daemon.service
  run systemctl restart clamav-daemon.socket
  run systemctl restart clamav-daemon.service
  if [ "$DRY" = 0 ]; then
    local waited=0
    while [ ! -S /run/clamav/clamd.ctl ] && [ "$waited" -lt 120 ]; do sleep 2; waited=$((waited + 2)); done
    [ -S /run/clamav/clamd.ctl ] || die "no clamd socket after 120 s (systemctl status clamav-daemon; the daemon needs 11-18 s to load signatures and the unit allows 420 s)"
    sock_state=$(stat -c '%a %U:%G' /run/clamav/clamd.ctl)
    [ "$sock_state" = '660 clamav:clamav' ] || die "the clamd socket is '$sock_state', not '660 clamav:clamav': check the socket drop-in and systemctl daemon-reload"
    info "socket /run/clamav/clamd.ctl is $sock_state"
    bash "$HERE/verify-clamd.sh" --as "$worker" || die "acceptance steps 1-4 failed; clamdscan is not ready to be configured as MEDIA_CLAMSCAN"
  else
    echo "install-clamd: [dry-run] bash $HERE/verify-clamd.sh --as $worker"
  fi

  if [ "$DRY" = 1 ]; then info "dry run complete: nothing was changed"; return 0; fi
  cat <<NEXT

install-clamd: done. What remains is yours:
  - set MEDIA_CLAMSCAN=/usr/bin/clamdscan in the application's environment and refresh it through the deploy path;
  - restart PHP-FPM and the supervised workers so they pick up the new group (a running process keeps its groups);
  - run: sudo -u $worker php artisan vasey:doctor   (media_scanner_limits must pass);
  - finish acceptance steps 5-8 of docs/media-processing.md (deflate bomb, largest stems end to end, stopped daemon);
  - freshclam will log 'Clamd was NOT notified: The RELOAD command is disabled'. That is expected: clamd reloads itself
    within SelfCheck (10 minutes) of an update. Keep the signature-age check (ops/monitoring) alerting before 48 hours.
NEXT
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then main "$@"; fi
