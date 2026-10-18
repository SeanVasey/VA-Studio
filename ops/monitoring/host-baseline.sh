#!/usr/bin/env bash
# shellcheck disable=SC2015  # `test && test || fail` is intended: refuse whenever any test fails.
# VASEY.AUDIO: host baseline check for the firewall, unattended security upgrades and SSH, with opt-in convergence.
#
#   host-baseline.sh check [--ssh-port 22]                       report only; changes nothing (the default)
#   host-baseline.sh apply-unattended-upgrades [--yes]           install and configure unattended security upgrades
#   host-baseline.sh apply-firewall --ssh-port N [--yes]         ufw: deny incoming, allow outgoing, allow SSH/80/443
#
# Both apply actions print their plan and stop unless --yes is given. A Laravel Forge server already runs ufw from Forge's
# Firewall tab (provisioning step 1 leaves it at 22/80/443), so `check` is the normal use there and apply-firewall exists
# for hosts Forge does not manage; ufw rules added outside Forge do not appear in its UI.
#
# `check` verifies, and prints OK, WARN or CRIT for each:
#   - ufw is active, denies incoming by default, and allows only the SSH port, 80 and 443;
#   - nothing but those ports listens on a non-loopback address, and MySQL (3306) never does;
#   - unattended-upgrades is installed, enabled, and covers the security pocket, and apt-daily.timer and apt-daily-upgrade.timer
#     are both enabled and active (without them the periodic settings never run);
#   - a reboot is not pending (kernel and libc updates need one; Automatic-Reboot stays false, so the reboot is a decision);
#   - sshd refuses password logins and root password logins.
# The unattended-upgrades drop-in blocks automatic upgrades of the database server and PHP packages (mysql-*, php8.4-*),
# because each restarts a service that production queues and deploys depend on: upgrade them in a maintenance window with
# `vasey-<kit>-ctl quiesce` first. Remove those two lines if you prefer otherwise; that is an operator decision.
# Exit: 0 all OK, 1 any WARN, 2 any CRIT (check); apply actions exit 0 on success and 2 when a step fails.
set -uo pipefail
export LC_ALL=C PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

APT_DROPIN=${VASEY_APT_DROPIN:-/etc/apt/apt.conf.d/52vasey-unattended-upgrades}
UFW=${VASEY_UFW:-ufw} SS=${VASEY_SS:-ss} APT_CONFIG=${VASEY_APT_CONFIG:-apt-config} SSHD=${VASEY_SSHD:-sshd}
REBOOT_FLAG=${VASEY_REBOOT_FLAG:-/var/run/reboot-required}
DPKG=${VASEY_DPKG:-dpkg} APT_GET=${VASEY_APT_GET:-apt-get} SYSTEMCTL=${VASEY_SYSTEMCTL:-systemctl}
usage() { sed -n '3,/^set -uo/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//' >&2; exit 2; }
die() { echo "host-baseline: $*" >&2; exit 2; }

ACTION=${1:-check}; [ $# -eq 0 ] || shift
SSH_PORT="" YES=0
while [ $# -gt 0 ]; do
  case "$1" in
    --ssh-port) SSH_PORT=${2:?}; shift 2 ;;
    --yes) YES=1; shift ;;
    -h|--help) usage ;;
    *) die "unknown option $1" ;;
  esac
done
[ -z "$SSH_PORT" ] || { [[ "$SSH_PORT" =~ ^[0-9]{1,5}$ ]] && [ "$SSH_PORT" -ge 1 ] && [ "$SSH_PORT" -le 65535 ] || die "--ssh-port must be a port number"; }

# shellcheck disable=SC2016  # ${distro_id} and ${distro_codename} are apt variables, not shell
DROPIN_BODY='// Managed by ops/monitoring/host-baseline.sh. Security updates only; reboots stay a decision.
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
Unattended-Upgrade::Allowed-Origins {
        "${distro_id}:${distro_codename}-security";
        "${distro_id}ESMApps:${distro_codename}-apps-security";
        "${distro_id}ESM:${distro_codename}-infra-security";
};
Unattended-Upgrade::Package-Blacklist {
        "mysql-.*";
        "php8\.4-.*";
};
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Remove-Unused-Dependencies "true";'

worst=0
report() { local level=$1; shift; echo "$([ "$level" = 0 ] && echo OK || { [ "$level" = 1 ] && echo WARN || echo CRIT; }) $*"; [ "$level" -le "$worst" ] || worst=$level; }

check_firewall() {
  local ports_expected=(80 443 "${SSH_PORT:-22}") status ports p extra=() missing=()
  if ! command -v "$UFW" >/dev/null 2>&1; then report 1 "firewall: ufw is not installed (Forge normally provides it)"; return; fi
  status=$("$UFW" status verbose 2>/dev/null) || { report 1 "firewall: ufw status needs root"; return; }
  if ! grep -q '^Status: active' <<<"$status"; then report 2 "firewall: ufw is not active"; return; fi
  report 0 "firewall: ufw is active"
  if grep -q '^Default: deny (incoming), allow (outgoing)' <<<"$status"; then report 0 "firewall: default deny incoming, allow outgoing"
  else report 2 "firewall: the default policy is not 'deny (incoming), allow (outgoing)'"; fi
  ports=$(awk '/ALLOW IN/ {split($1, a, "/"); print a[1]}' <<<"$status" | sort -un)
  for p in $ports; do
    case " ${ports_expected[*]} " in *" $p "*) ;; *) extra+=("$p") ;; esac
  done
  for p in "${ports_expected[@]}"; do grep -qx "$p" <<<"$ports" || missing+=("$p"); done
  if [ "${#extra[@]}" -gt 0 ]; then report 1 "firewall: also allows ${extra[*]} (expected only ${ports_expected[*]})"; fi
  if [ "${#missing[@]}" -gt 0 ]; then report 1 "firewall: does not allow ${missing[*]}"; fi
  [ "${#extra[@]}" -gt 0 ] || [ "${#missing[@]}" -gt 0 ] || report 0 "firewall: allows exactly ${ports_expected[*]}"
}

check_listeners() {
  local listing addr port seen=() bad=()
  command -v "$SS" >/dev/null 2>&1 || { report 1 "listeners: ss is not available"; return; }
  listing=$("$SS" -H -ltn 2>/dev/null) || { report 1 "listeners: ss failed"; return; }
  while read -r _ _ _ local _; do
    [ -n "$local" ] || continue
    addr=${local%:*}; port=${local##*:}
    case "$addr" in 127.*|"[::1]"|"::1"|localhost) continue ;; esac
    if [ "$port" = 3306 ]; then report 2 "listeners: MySQL (3306) listens on $addr; it must stay on 127.0.0.1"; fi
    seen+=("$port")
  done <<<"$listing"
  for port in $(printf '%s\n' "${seen[@]}" | sort -un); do
    case " 80 443 ${SSH_PORT:-22} 3306 " in *" $port "*) ;; *) bad+=("$port") ;; esac
  done
  if [ "${#bad[@]}" -gt 0 ]; then report 1 "listeners: unexpected non-loopback ports ${bad[*]}"; else report 0 "listeners: only SSH, 80 and 443 listen beyond loopback"; fi
}

check_upgrades() {
  local periodic origins timer stopped=()
  if ! "$DPKG" -s unattended-upgrades >/dev/null 2>&1; then report 1 "upgrades: unattended-upgrades is not installed"; return; fi
  periodic=$("$APT_CONFIG" dump 2>/dev/null | sed -n 's/^APT::Periodic::Unattended-Upgrade "\(.*\)";$/\1/p')
  if [ "$periodic" = 1 ]; then report 0 "upgrades: daily unattended upgrades are enabled"; else report 1 "upgrades: APT::Periodic::Unattended-Upgrade is '${periodic:-unset}', not 1"; fi
  origins=$("$APT_CONFIG" dump 2>/dev/null | grep -c 'Unattended-Upgrade::Allowed-Origins.*-security' || true)
  if [ "${origins:-0}" -ge 1 ]; then report 0 "upgrades: the security pocket is allowed"; else report 1 "upgrades: no -security origin in Unattended-Upgrade::Allowed-Origins"; fi
  # APT::Periodic does nothing unless systemd runs it: apt-daily.timer fetches the lists, apt-daily-upgrade.timer runs the upgrade.
  for timer in apt-daily.timer apt-daily-upgrade.timer; do
    [ "$("$SYSTEMCTL" is-enabled "$timer" 2>/dev/null)" = enabled ] && [ "$("$SYSTEMCTL" is-active "$timer" 2>/dev/null)" = active ] || stopped+=("$timer")
  done
  if [ "${#stopped[@]}" -eq 0 ]; then report 0 "upgrades: apt-daily.timer and apt-daily-upgrade.timer are enabled and active"
  else report 1 "upgrades: not enabled and active: ${stopped[*]} (no unattended upgrade runs without both; systemctl enable --now ${stopped[*]})"; fi
  if [ -e "$REBOOT_FLAG" ]; then report 1 "upgrades: a reboot is pending ($(tr '\n' ' ' < "${REBOOT_FLAG}.pkgs" 2>/dev/null | cut -c1-80)); schedule it with a quiesce"; else report 0 "upgrades: no reboot is pending"; fi
}

check_ssh() {
  local config
  if ! config=$("$SSHD" -T 2>/dev/null); then report 1 "ssh: sshd -T needs root"; return; fi
  if grep -qi '^passwordauthentication no' <<<"$config"; then report 0 "ssh: password authentication is off"; else report 1 "ssh: password authentication is on"; fi
  if grep -qi '^permitrootlogin yes' <<<"$config"; then report 2 "ssh: root may log in with a password"; else report 0 "ssh: root cannot log in with a password"; fi
}

apply_unattended_upgrades() {
  echo "plan: apt-get install -y unattended-upgrades; write $APT_DROPIN:"
  printf '  | %s\n' "${DROPIN_BODY//$'\n'/$'\n'  | }"
  echo "plan: systemctl enable --now apt-daily.timer apt-daily-upgrade.timer"
  if [ "$YES" != 1 ]; then echo "host-baseline: dry run; repeat with --yes to apply"; return 0; fi
  [ "$(id -u)" = 0 ] || die "run as root"
  DEBIAN_FRONTEND=noninteractive "$APT_GET" install -y -q unattended-upgrades || die "could not install unattended-upgrades"
  printf '%s\n' "$DROPIN_BODY" > "$APT_DROPIN.new" && chmod 0644 "$APT_DROPIN.new" && mv -f "$APT_DROPIN.new" "$APT_DROPIN" || die "could not write $APT_DROPIN"
  # Without both timers the drop-in above never runs, so this is a failed apply, not a warning.
  "$SYSTEMCTL" enable --now apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1 \
    || die "could not enable and start apt-daily.timer and apt-daily-upgrade.timer; $APT_DROPIN is written but nothing will run it (inspect with: systemctl status apt-daily.timer apt-daily-upgrade.timer)"
  echo "host-baseline: applied; verify with: host-baseline.sh check   and   unattended-upgrade --dry-run --debug"
}

apply_firewall() {
  [ -n "$SSH_PORT" ] || die "apply-firewall needs --ssh-port (a wrong port locks you out)"
  if [ -n "${SSH_CONNECTION:-}" ]; then
    local connected; connected=$(awk '{print $4}' <<<"$SSH_CONNECTION")
    [ "$connected" = "$SSH_PORT" ] || die "this session connected on port $connected, not $SSH_PORT; refusing to apply a firewall that would drop it"
  fi
  echo "plan: ufw default deny incoming; ufw default allow outgoing; ufw allow $SSH_PORT/tcp; ufw allow 80/tcp; ufw allow 443/tcp; ufw --force enable"
  echo "rollback: ufw disable   (rules added here are not visible in Forge's Firewall tab)"
  if [ "$YES" != 1 ]; then echo "host-baseline: dry run; repeat with --yes to apply"; return 0; fi
  [ "$(id -u)" = 0 ] || die "run as root"
  command -v "$UFW" >/dev/null 2>&1 || DEBIAN_FRONTEND=noninteractive "$APT_GET" install -y -q ufw || die "could not install ufw"
  "$UFW" allow "$SSH_PORT/tcp" && "$UFW" allow 80/tcp && "$UFW" allow 443/tcp \
    && "$UFW" default deny incoming && "$UFW" default allow outgoing && "$UFW" --force enable || die "ufw configuration failed; inspect with: ufw status verbose"
  echo "host-baseline: firewall applied"
}

case "$ACTION" in
  check)
    check_firewall; check_listeners; check_upgrades; check_ssh
    echo "SUMMARY $([ "$worst" = 0 ] && echo OK || { [ "$worst" = 1 ] && echo WARN || echo CRIT; })"
    exit "$worst" ;;
  apply-unattended-upgrades) apply_unattended_upgrades ;;
  apply-firewall) apply_firewall ;;
  *) usage ;;
esac
