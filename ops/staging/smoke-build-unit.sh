#!/usr/bin/env bash
# VASEY.AUDIO staging: smoke test of the isolated build unit on the real host (docs/ops/staging-runbook.md section 8,
# "Before the first isolated deploy"). Run as root after provision.sh has created vasey-staging-build, before the first
# deploy that builds with the site up. Changes nothing outside a scratch directory under the build area, which it removes.
#
# Each probe runs in a transient unit with the same properties as build_in_unit in vasey-staging-ctl, except that
# ReadWritePaths names a scratch directory in place of a release slot. Exit 0 only when every probe behaves as required.
set -euo pipefail
export LC_ALL=C PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

die() { echo "smoke-build-unit: FAIL: $*" >&2; exit 1; }
pass() { echo "smoke-build-unit: pass: $*"; }

[ "$(id -u)" -eq 0 ] || die "run as root"
CONF=/etc/vasey-staging/staging.conf
# The configuration is trusted only when root owns it and nobody else can write it (as vasey-staging-ctl requires).
[ -f "$CONF" ] && [ ! -L "$CONF" ] || die "missing $CONF (run provision.sh)"
[ "$(stat -c %u "$CONF")" = 0 ] || die "$CONF must be owned by root"
case "$(stat -c %a "$CONF")" in 600|640|644|400|440|444) ;; *) die "$CONF must not be group/world writable" ;; esac
# shellcheck source=/dev/null
. "$CONF"
: "${VASEY_ROOT:?}" "${VASEY_APP_USER:?}" "${VASEY_BUILD_USER:?VASEY_BUILD_USER is not set; re-run provision.sh}"
USER_=$VASEY_BUILD_USER

# The account this script will kill(-1) as must be the dedicated build account and nothing else (the checks of
# build_account in vasey-staging-ctl): a root or application uid here would take processes of the live site with it.
[[ "$USER_" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "invalid build account name"
getent passwd "$USER_" >/dev/null || die "build account $USER_ does not exist; re-run provision.sh"
GID_=$(id -g -- "$USER_") UID_=$(id -u -- "$USER_")
APP_UID=$(id -u -- "$VASEY_APP_USER") APP_GID=$(id -g -- "$VASEY_APP_USER")
[[ "$UID_" =~ ^[1-9][0-9]*$ ]] && [[ "$GID_" =~ ^[1-9][0-9]*$ ]] || die "the build account must be nonroot"
[ "$UID_" != "$APP_UID" ] && [ "$GID_" != "$APP_GID" ] || die "the build account must not be the application account or share its group"
[ "$(id -G -- "$USER_")" = "$GID_" ] || die "the build account must belong to its own group only"
[ -z "$(getent group "$GID_" | cut -d: -f4)" ] || die "the build group must have no members"
[ -z "$(getent passwd | awk -F: -v n="$USER_" -v u="$UID_" -v g="$GID_" '$1 != n && ($3 == u || $4 == g)')" ] \
  || die "another account shares the build account's uid or group"
if ! { [ -d /run/systemd/system ] && [ -f /sys/fs/cgroup/cgroup.controllers ] && command -v systemd-run >/dev/null; }; then
  die "systemd as init with cgroup v2 is required"
fi
[ ! -e "/var/lib/systemd/linger/$USER_" ] || die "lingering is enabled for $USER_ (loginctl disable-linger $USER_)"

# Never while a deploy or any other ctl action runs: the cleanup kills every process of the build account.
LOCK=/etc/vasey-staging/ctl.lock
[ -f "$LOCK" ] && [ ! -L "$LOCK" ] && [ "$(stat -c '%u %a' "$LOCK")" = "0 600" ] || die "$LOCK must be root-owned 0600"
exec 8>>"$LOCK"
flock -xn 8 || die "a vasey-staging-ctl action (a deploy?) holds $LOCK; run this when it has finished"
[ ! -e /etc/vasey-staging/operation-in-progress ] || die "an operation marker is present (a deploy stopped mid-way); recover first"

BUILD=$VASEY_ROOT/build
[ "$(stat -c '%u %g %a' -- "$BUILD")" = "0 $GID_ 770" ] || die "$BUILD must be root:$USER_ 0770"
SCRATCH=$(mktemp -d "$BUILD/smoke.XXXXXXXX") || die "cannot create a scratch directory under $BUILD"
chown "root:$GID_" "$SCRATCH"; chmod 0770 "$SCRATCH"
cleanup() {
  if [[ "$UID_" =~ ^[1-9][0-9]*$ ]] && [[ "$GID_" =~ ^[1-9][0-9]*$ ]] && [ "$UID_" != "$APP_UID" ]; then
    setpriv --reuid="$UID_" --regid="$GID_" --clear-groups -- kill -KILL -1 2>/dev/null || true
  fi
  rm -rf --one-file-system -- "$SCRATCH"
  [ "$(stat -c %u /dev/shm/vasey-smoke-setid 2>/dev/null)" != "$UID_" ] || rm -f -- /dev/shm/vasey-smoke-setid
}
trap cleanup EXIT

unit() {  # unit COMMAND...: the build_in_unit properties, scratch in place of the release slot
  systemd-run --quiet --pipe --wait --collect --service-type=exec --unit="vasey-staging-smoke-$$-$RANDOM" \
    --uid="$USER_" --gid="$GID_" \
    -p NoNewPrivileges=yes -p RestrictSUIDSGID=yes -p ProtectSystem=strict \
    -p "ReadWritePaths=$SCRATCH $BUILD" \
    -p PrivateTmp=yes -p PrivateDevices=yes -p ProtectHome=yes -p RestrictNamespaces=yes -p LockPersonality=yes \
    -p ProtectKernelTunables=yes -p ProtectKernelModules=yes -p ProtectControlGroups=yes \
    -p "RestrictAddressFamilies=AF_INET AF_INET6" -p PrivateIPC=yes -p RemoveIPC=yes \
    -p KillMode=control-group -p TasksMax=1024 -p MemoryMax=60% -p CPUQuota=150% -p RuntimeMaxSec=300 \
    -p WorkingDirectory=/ -E PATH=/usr/local/bin:/usr/bin:/bin -E LC_ALL=C -E "HOME=$SCRATCH" \
    -- "$@" </dev/null
}

# (i) No set-id file can be created, not even in /dev/shm: the file is created, then the chmod must fail with EPERM.
# Exit 0 means refused as required, 9 means the bit was set, anything else means the probe itself could not run.
set +e
unit /usr/bin/python3 -c "
import os, sys
p = '/dev/shm/vasey-smoke-setid'
open(p, 'w').close()
try:
    os.chmod(p, 0o4755)
except PermissionError:
    sys.exit(0)
sys.exit(9)"
rc=$?
set -e
[ "$rc" != 9 ] || die "(i) the unit created a set-id file in /dev/shm"
[ "$rc" = 0 ] || die "(i) the probe could not run (exit $rc): /dev/shm not writable inside the unit?"
[ ! -u /dev/shm/vasey-smoke-setid ] || die "(i) a set-id file exists in /dev/shm"
pass "(i) set-id files are refused"

# (ii) No unix socket can be created at all (RestrictAddressFamilies): exit 0 means socket(AF_UNIX) failed with
# EAFNOSUPPORT as required, 9 means it succeeded, anything else means the probe itself could not run. logind, D-Bus and
# MySQL are then out of reach; a SetUserLinger attempt is made as well and must leave no linger file.
set +e
unit /usr/bin/python3 -c "
import errno, socket, sys
try:
    socket.socket(socket.AF_UNIX, socket.SOCK_STREAM).close()
except OSError as error:
    sys.exit(0 if error.errno == errno.EAFNOSUPPORT else 8)
sys.exit(9)"
rc=$?
set -e
[ "$rc" != 9 ] || die "(ii) the unit could open a unix socket"
[ "$rc" = 0 ] || die "(ii) the probe could not run (exit $rc)"
unit /usr/bin/busctl call org.freedesktop.login1 /org/freedesktop/login1 org.freedesktop.login1.Manager \
  SetUserLinger ubb "$UID_" true false >/dev/null 2>&1 || true
if [ -e "/var/lib/systemd/linger/$USER_" ]; then
  loginctl disable-linger "$USER_" 2>/dev/null || true
  die "(ii) the unit enabled lingering through logind"
fi
pass "(ii) unix sockets are refused; logind is unreachable"

# (iii) The exit status reaches the caller.
set +e; unit /bin/bash -c 'exit 3'; rc=$?; set -e
[ "$rc" = 3 ] || die "(iii) exit 3 came back as $rc"
pass "(iii) exit status propagates"

# (iv) Nothing the unit started survives it.
unit /bin/bash -c 'setsid sleep 1000 </dev/null >/dev/null 2>&1 & exit 0' || die "(iv) the probe unit did not run"
sleep 1
! pgrep -u "$UID_" >/dev/null && ! pgrep -U "$UID_" >/dev/null || die "(iv) a process of $USER_ survived the unit"
pass "(iv) background processes die with the unit"

# (v) The toolchain is visible and the network (name resolution, IPv4/IPv6 only) works.
unit /bin/bash -c 'composer --version && npm --version && node --version && git --version' \
  || die "(v) composer, npm, node or git is not usable inside the unit"
unit /usr/bin/git ls-remote https://github.com/laravel/framework HEAD >/dev/null \
  || die "(v) git ls-remote over the network failed inside the unit"
pass "(v) toolchain and network work"

echo "smoke-build-unit: all probes passed"
