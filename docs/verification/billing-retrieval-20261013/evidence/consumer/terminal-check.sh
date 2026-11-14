#!/usr/bin/env bash
set -uo pipefail
source /workspace/.va-studio-toolchain/activate.sh
cd /workspace/VA-Studio-billing-retrieval-consumers
receipt=/workspace/runtime/membership-c3-consumer-evidence
run="$1"
case "$run" in terminal-omission-red|terminal-restored-green) ;; *) exit 2;; esac
git rev-parse HEAD > "$receipt/$run.base"
date -u +%Y-%m-%dT%H:%M:%SZ > "$receipt/$run.started"
xargs sha256sum < "$receipt/terminal-source-files.txt" > "$receipt/$run.before.sha256"
TMPDIR=/tmp/va-studio-membership-c3-terminal APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipBillingFreshnessTest.php --filter test_owned_context_carries_the_original_cap_through_precommit_and_committed_admission --log-junit "$receipt/$run.xml" > "$receipt/$run.txt" 2>&1
status=$?
printf '%s\n' "$status" > "$receipt/$run.exit"
xargs sha256sum < "$receipt/terminal-source-files.txt" > "$receipt/$run.after.sha256"
cmp "$receipt/$run.before.sha256" "$receipt/$run.after.sha256"
date -u +%Y-%m-%dT%H:%M:%SZ > "$receipt/$run.finished"
cat "$receipt/$run.txt"
exit "$status"
