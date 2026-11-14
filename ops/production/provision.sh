#!/usr/bin/env bash
# VASEY.AUDIO production: provisioning entry point. Run as root on the Forge-provisioned production server, from the Forge
# site checkout (the deploy mirror), exactly as the staging kit is run:
#
#   sudo bash /home/forge/<PRODUCTION_HOST>/ops/production/provision.sh --host <PRODUCTION_HOST> [options of the derived script]
#
# This file only assembles the production kit from the reviewed staging kit (ops/production/build-kit.py), runs the
# assembled provision.sh from that private directory and removes the directory afterwards. All the work, and every option,
# belongs to the assembled script (`--app-user`, `--root`, `--mirror`, `--db-name`, `--mysql-admin-defaults`, `--skip-packages`;
# `--app-env` accepts only production). The one option handled here is --keep-build, which keeps the assembled kit in
# /root/vasey-production-kit.* for inspection (`build-kit.py --delta` shows what differs from staging without root).
#
# Nothing in the repository runs this: it is inert until an operator starts it on a host chosen under U-02.
set -euo pipefail
die() { echo "provision (production): ERROR: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || die "run as root (sudo bash $0 ...)"
command -v python3 >/dev/null || die "python3 is required"

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
KEEP=0
ARGS=()
for argument in "$@"; do
  if [ "$argument" = --keep-build ]; then KEEP=1; else ARGS+=("$argument"); fi
done

BUILD=$(mktemp -d /root/vasey-production-kit.XXXXXXXX) || die "cannot create a private build directory"
chmod 0700 "$BUILD"
cleanup() { if [ "$KEEP" = 1 ]; then echo "provision (production): kept the assembled kit in $BUILD" >&2; else rm -rf --one-file-system -- "$BUILD"; fi; }
trap cleanup EXIT

python3 -I "$HERE/build-kit.py" "$BUILD/kit" || die "the production kit could not be assembled from the staging kit (see above); nothing was provisioned"
bash "$BUILD/kit/provision.sh" ${ARGS[@]+"${ARGS[@]}"}
