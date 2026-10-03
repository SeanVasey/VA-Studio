#!/usr/bin/env bash
set -euo pipefail
# Package installation is restricted to a disposable native GitLab container.
if [[ $# != 0 || ${GITLAB_CI:-} != true || ${CI_DISPOSABLE_ENVIRONMENT:-} != true || $EUID != 0 ]]; then
    echo 'Node setup requires a disposable GitLab container running as root.' >&2
    exit 1
fi
apt-get update
apt-get install --yes --no-install-recommends curl ca-certificates xz-utils git python3
node_version=24.21.0
case $(dpkg --print-architecture) in
    amd64) node_arch=x64 ;;
    arm64) node_arch=arm64 ;;
    *) echo 'Unsupported native Node architecture.' >&2; exit 1 ;;
esac
node_directory=$(mktemp -d)
trap 'rm -rf -- "$node_directory"' EXIT
node_archive="node-v$node_version-linux-$node_arch.tar.xz"
node_origin="https://nodejs.org/dist/v$node_version"
curl --fail --silent --show-error --proto '=https' --tlsv1.2 "$node_origin/$node_archive" -o "$node_directory/$node_archive"
curl --fail --silent --show-error --proto '=https' --tlsv1.2 "$node_origin/SHASUMS256.txt" -o "$node_directory/SHASUMS256.txt"
# Require exactly one matching official checksum before extracting executable bytes.
awk -v file="$node_archive" '$2 == file { print; found++ } END { if (found != 1) exit 1 }' "$node_directory/SHASUMS256.txt" > "$node_directory/archive.sha256"
(cd "$node_directory" && sha256sum --check archive.sha256)
tar --extract --xz --file="$node_directory/$node_archive" --directory=/usr/local --strip-components=1 --no-same-owner
node --version
npm --version
node -e 'const [major, minor] = process.versions.node.split(".").map(Number); if (major !== 24 || minor < 15) process.exit(1);'
