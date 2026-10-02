#!/usr/bin/env bash
set -euo pipefail
# Never mutate a developer machine or a persistent shell runner.
if [[ $# != 0 || ${GITLAB_CI:-} != true || ${CI_DISPOSABLE_ENVIRONMENT:-} != true || $EUID != 0 ]]; then
    echo 'Related-track scanner setup requires a disposable GitLab container running as root.' >&2
    exit 1
fi
if [[ ! -f /etc/os-release ]] || ! grep -q '^ID=debian$' /etc/os-release || ! grep -q '^VERSION_CODENAME=bookworm$' /etc/os-release; then
    echo 'The signed Debian bookworm package installation is required.' >&2
    exit 1
fi
related_started=$SECONDS
run_bounded() {
    local related_limit=$1 related_left=$((600 - SECONDS + related_started))
    shift
    if (( related_left <= 0 )); then
        echo 'Related-track tool setup exceeded its bounded ten-minute stage.' >&2
        exit 1
    fi
    if (( related_left < related_limit )); then related_limit=$related_left; fi
    /usr/bin/timeout --signal=TERM --kill-after=15s "${related_limit}s" "$@"
}
# This Docker image has no service manager and no FreshClam daemon. Suppress
# package maintainer daemon startup during install, restoring the prior policy.
related_policy_directory=$(mktemp -d)
related_policy=/usr/sbin/policy-rc.d
if [[ -e $related_policy ]]; then cp -a "$related_policy" "$related_policy_directory/policy-rc.d"; fi
restore_policy() {
    if [[ -e $related_policy_directory/policy-rc.d ]]; then
        cp -a "$related_policy_directory/policy-rc.d" "$related_policy"
    else
        rm -f -- "$related_policy"
    fi
    rm -rf -- "$related_policy_directory"
}
trap restore_policy EXIT
printf '#!/bin/sh\nexit 101\n' > "$related_policy"
chmod 755 "$related_policy"
run_bounded 180 apt-get update
run_bounded 300 env DEBIAN_FRONTEND=noninteractive apt-get install --yes --no-install-recommends clamav clamav-freshclam ffmpeg util-linux
# Debian security currently supplies supported ClamAV 1.4 LTS; refuse an older
# package rather than allow stale or unsupported signatures into acceptance.
scanner_version=$(dpkg-query --show --showformat='${Version}' clamav)
if ! dpkg --compare-versions "$scanner_version" ge 1.4 || ! dpkg --compare-versions "$scanner_version" lt 1.5; then
    echo 'The supported Debian ClamAV 1.4 LTS package is required.' >&2
    exit 1
fi
run_bounded 300 /usr/bin/freshclam --quiet --stdout
run_bounded 30 /usr/bin/dpkg-query --show --showformat='${Package} ${Version}\n' clamav clamav-freshclam ffmpeg util-linux
run_bounded 30 /usr/bin/clamscan --version
# Unchanged prepare-related-tracks.php proves signature freshness, real EICAR
# rejection, package identity and every actual source/output scan separately.
