#!/usr/bin/env bash
set -euo pipefail

# This modifies only a disposable hosted runner, never a developer or self-hosted installation.
if [[ $# != 0 || ${GITHUB_ACTIONS:-} != true || ${RUNNER_ENVIRONMENT:-} != github-hosted || ${RUNNER_OS:-} != Linux ]]; then
  echo 'Related-track scanner setup requires a disposable GitHub-hosted Linux runner.' >&2
  exit 1
fi
if [[ ! -f /etc/os-release ]] || ! /usr/bin/grep -q '^ID=ubuntu$' /etc/os-release; then
  echo 'Related-track scanner setup requires the supported Ubuntu package installation.' >&2
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

# Package signatures and supported FreshClam updates establish the real tools/databases.
# No direct signature download, daemon, retry loop or altered application scanner budget.
run_bounded 180 sudo -n apt-get update
run_bounded 300 sudo -n env DEBIAN_FRONTEND=noninteractive \
  apt-get install -y --no-install-recommends clamav clamav-freshclam ffmpeg util-linux
run_bounded 30 sudo -n systemctl stop clamav-freshclam.service
if related_service_status=$(run_bounded 10 systemctl is-active clamav-freshclam.service); then
  echo 'The signature updater must stop before the bounded one-shot update.' >&2
  exit 1
elif [[ $related_service_status != inactive ]]; then
  echo 'The stopped signature updater state could not be verified.' >&2
  exit 1
fi
run_bounded 300 sudo -n /usr/bin/freshclam --quiet --stdout
run_bounded 30 /usr/bin/dpkg-query --show --showformat='${Package} ${Version}\n' clamav clamav-freshclam ffmpeg util-linux
run_bounded 30 /usr/bin/clamscan --version

# The preparer, not this setup output, verifies signature freshness, EICAR rejection
# and every actual source/output scan through the unchanged MalwareScanner.
