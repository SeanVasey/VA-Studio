#!/usr/bin/env bash
set -uo pipefail
source /workspace/.va-studio-toolchain/activate.sh
cd /workspace/VA-Studio-billing-retrieval-consumers
receipt=/workspace/runtime/membership-c3-consumer-evidence
date -u +%Y-%m-%dT%H:%M:%SZ > "$receipt/unit.started"
git rev-parse HEAD > "$receipt/unit.base"
git diff --binary > "$receipt/unit.overlay.patch"
xargs sha256sum < "$receipt/owned-files.txt" > "$receipt/unit.before.sha256"
sha256sum app/Domain/Memberships/Billing/BillingDatabaseInstant.php app/Domain/Memberships/Billing/BillingDatabaseClock.php app/Domain/Memberships/Billing/BillingRetrievalInterval.php >> "$receipt/unit.before.sha256"
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Unit/BillingFreshnessWindowTest.php --log-junit "$receipt/unit.xml" > "$receipt/unit.txt" 2>&1
status=$?
printf '%s\n' "$status" > "$receipt/unit.exit"
xargs sha256sum < "$receipt/owned-files.txt" > "$receipt/unit.after.sha256"
sha256sum app/Domain/Memberships/Billing/BillingDatabaseInstant.php app/Domain/Memberships/Billing/BillingDatabaseClock.php app/Domain/Memberships/Billing/BillingRetrievalInterval.php >> "$receipt/unit.after.sha256"
cmp "$receipt/unit.before.sha256" "$receipt/unit.after.sha256"
date -u +%Y-%m-%dT%H:%M:%SZ > "$receipt/unit.finished"
cat "$receipt/unit.txt"
exit "$status"
