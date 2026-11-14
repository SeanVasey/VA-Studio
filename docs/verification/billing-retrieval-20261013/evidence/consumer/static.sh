#!/usr/bin/env bash
set -uo pipefail
source /workspace/.va-studio-toolchain/activate.sh
cd /workspace/VA-Studio-billing-retrieval-consumers
receipt=/workspace/runtime/membership-c3-consumer-evidence
date -u +%Y-%m-%dT%H:%M:%SZ > "$receipt/static.started"
git rev-parse HEAD > "$receipt/static.base"
xargs sha256sum < "$receipt/owned-files.txt" > "$receipt/static.before.sha256"
while IFS= read -r file; do php -l "$file" || exit $?; done < "$receipt/owned-files.txt" > "$receipt/static.syntax.txt" 2>&1
printf '%s\n' "$?" > "$receipt/static.syntax.exit"
xargs vendor/bin/pint --test < "$receipt/owned-files.txt" > "$receipt/static.pint.txt" 2>&1
printf '%s\n' "$?" > "$receipt/static.pint.exit"
git diff --check > "$receipt/static.diff-check.txt" 2>&1
printf '%s\n' "$?" > "$receipt/static.diff-check.exit"
xargs sha256sum < "$receipt/owned-files.txt" > "$receipt/static.after.sha256"
cmp "$receipt/static.before.sha256" "$receipt/static.after.sha256"
date -u +%Y-%m-%dT%H:%M:%SZ > "$receipt/static.finished"
