#!/usr/bin/env bash
set -euo pipefail

# Pure renderer: sourcing this file exposes only repository configuration, never setup.
render_related_ubuntu_sources() {
  if [[ $# != 1 || ! $1 =~ ^[a-z][a-z0-9]*$ ]]; then
    return 1
  fi
  cat <<EOF
Types: deb
URIs: https://archive.ubuntu.com/ubuntu
Suites: $1 $1-updates $1-backports
Components: main restricted universe multiverse
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg

Types: deb
URIs: https://security.ubuntu.com/ubuntu
Suites: $1-security
Components: main restricted universe multiverse
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg
EOF
}
if [[ ${BASH_SOURCE[0]} != "$0" ]]; then return; fi

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

# The hosted Azure mirror repeatedly downloaded these packages too slowly for the
# existing install budget. Use official Ubuntu HTTPS origins for only these APT
# calls, retaining the distribution keyring and leaving runner source files alone.
if [[ ! -f /usr/share/keyrings/ubuntu-archive-keyring.gpg ]]; then
  echo 'The supported Ubuntu archive keyring is required for related-track setup.' >&2
  exit 1
fi
related_sources_directory=$(mktemp -d)
trap 'rm -rf -- "$related_sources_directory"' EXIT
mkdir "$related_sources_directory/parts"
related_codename=$(/usr/bin/sed -n 's/^VERSION_CODENAME=//p' /etc/os-release)
if ! render_related_ubuntu_sources "$related_codename" > "$related_sources_directory/ubuntu.sources"; then
  echo 'The supported Ubuntu release codename could not be verified.' >&2
  exit 1
fi
related_apt_sources=(
  -o "Dir::Etc::sourcelist=$related_sources_directory/ubuntu.sources"
  -o "Dir::Etc::sourceparts=$related_sources_directory/parts"
)

# Package signatures and supported FreshClam updates establish the real tools/databases.
# No direct signature download, daemon, retry loop or altered application scanner budget.
run_bounded 180 sudo -n apt-get "${related_apt_sources[@]}" update
run_bounded 300 sudo -n env DEBIAN_FRONTEND=noninteractive \
  apt-get "${related_apt_sources[@]}" install -y --no-install-recommends clamav clamav-freshclam ffmpeg util-linux
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
