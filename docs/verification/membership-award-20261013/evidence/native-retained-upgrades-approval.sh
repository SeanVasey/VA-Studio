#!/usr/bin/env bash
set -o pipefail
cd /workspace/VA-Studio-membership-award
source /workspace/.va-studio-toolchain/activate.sh
export TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33413 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_membership_award DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/BillingAwardFenceTest.php tests/Feature/ProductionMembershipBilling/BillingIntervalOwnershipTest.php tests/Feature/ProductionMembershipBilling/BillingSubscriptionApprovalTest.php --filter 'test_each_missing_guard_and_empty_owned_seed|test_exact_legacy_upgrade_and_each_interrupted_guard|test_current_staff_approves_policy_derived_terms' --stop-on-error --stop-on-failure --log-junit /workspace/runtime/membership-award-evidence/native-retained-upgrades-approval.xml 2>&1 | tee /workspace/runtime/membership-award-evidence/native-retained-upgrades-approval.txt
result=${PIPESTATUS[0]}
printf '%s\n' "$result" > /workspace/runtime/membership-award-evidence/native-retained-upgrades-approval.exit
exit "$result"
