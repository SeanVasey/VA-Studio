#!/usr/bin/env bash
set -uo pipefail
source /workspace/.va-studio-toolchain/activate.sh
cd /workspace/VA-Studio-billing-retrieval-consumers
receipt=/workspace/runtime/membership-c3-consumer-evidence
file=tests/Feature/ProductionMembershipBilling/BillingNativeDatabaseIntervalOwnershipRaceTest.php
git rev-parse HEAD > "$receipt/native-arbitration-static.base"
sha256sum "$file" tests/Support/membership-interval-race-worker.php > "$receipt/native-arbitration-static.before.sha256"
php -l "$file" > "$receipt/native-arbitration-static.syntax.txt" 2>&1
printf '%s\n' "$?" > "$receipt/native-arbitration-static.syntax.exit"
vendor/bin/pint --test "$file" > "$receipt/native-arbitration-static.pint.txt" 2>&1
printf '%s\n' "$?" > "$receipt/native-arbitration-static.pint.exit"
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- "$file" --list-tests > "$receipt/native-arbitration-static.discovery.txt" 2>&1
printf '%s\n' "$?" > "$receipt/native-arbitration-static.discovery.exit"
sha256sum "$file" tests/Support/membership-interval-race-worker.php > "$receipt/native-arbitration-static.after.sha256"
cmp "$receipt/native-arbitration-static.before.sha256" "$receipt/native-arbitration-static.after.sha256"
cat "$receipt/native-arbitration-static.discovery.txt"
