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
# shellcheck source=/dev/null
. "$CONF"
: "${VASEY_ROOT:?}" "${VASEY_BUILD_USER:?VASEY_BUILD_USER is not set; re-run provision.sh}"
USER_=$VASEY_BUILD_USER
getent passwd "$USER_" >/dev/null || die "build account $USER_ does not exist; re-run provision.sh"
GID_=$(id -g -- "$USER_") UID_=$(id -u -- "$USER_")
[ -d /run/systemd/system ] && [ -f /sys/fs/cgroup/cgroup.controllers ] && command -v systemd-run >/dev/null \
  || die "systemd as init with cgroup v2 is required"
[ ! -e "/var/lib/systemd/linger/$USER_" ] || die "lingering is enabled for $USER_ (loginctl disable-linger $USER_)"

BUILD=$VASEY_ROOT/build
[ "$(stat -c '%u %g %a' -- "$BUILD")" = "0 $GID_ 770" ] || die "$BUILD must be root:$USER_ 0770"
SCRATCH=$(mktemp -d "$BUILD/smoke.XXXXXXXX")
chown "root:$GID_" "$SCRATCH"; chmod 0770 "$SCRATCH"
cleanup() {
  setpriv --reuid="$UID_" --regid="$GID_" --clear-groups -- kill -KILL -1 2>/dev/null || true
  rm -rf --one-file-system -- "$SCRATCH"
  rm -f -- /dev/shm/vasey-smoke-setid
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

# (ii) No unix socket: logind cannot be asked to enable lingering for the account.
if unit /usr/bin/busctl call org.freedesktop.login1 /org/freedesktop/login1 org.freedesktop.login1.Manager \
     SetUserLinger ubb "$UID_" true false; then
  loginctl disable-linger "$USER_" 2>/dev/null || true
  die "(ii) the unit enabled lingering through logind"
fi
[ ! -e "/var/lib/systemd/linger/$USER_" ] || die "(ii) lingering was enabled"
pass "(ii) logind is unreachable"

# (iii) The exit status reaches the caller.
set +e; unit /bin/bash -c 'exit 3'; rc=$?; set -e
[ "$rc" = 3 ] || die "(iii) exit 3 came back as $rc"
pass "(iii) exit status propagates"

# (iv) Nothing the unit started survives it.
unit /bin/bash -c 'setsid sleep 1000 </dev/null >/dev/null 2>&1 & exit 0'
sleep 1
! pgrep -u "$UID_" >/dev/null || die "(iv) a process of $USER_ survived the unit"
pass "(iv) background processes die with the unit"

# (v) The toolchain is visible and the network (name resolution, IPv4/IPv6 only) works.
unit /bin/bash -c 'composer --version && npm --version && node --version && git --version' \
  || die "(v) composer, npm, node or git is not usable inside the unit"
unit /usr/bin/git ls-remote https://github.com/laravel/framework HEAD >/dev/null \
  || die "(v) git ls-remote over the network failed inside the unit"
pass "(v) toolchain and network work"

echo "smoke-build-unit: all probes passed"
