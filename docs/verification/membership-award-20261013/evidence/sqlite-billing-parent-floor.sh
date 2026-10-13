#!/usr/bin/env bash
set -o pipefail
cd /workspace/VA-Studio-membership-award
source /workspace/.va-studio-toolchain/activate.sh
export TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_SOCKET=
sha256sum app/Domain/Memberships/Production/MembershipSchema.php app/Domain/Memberships/Production/MembershipSchemaCatalog.php app/Domain/Memberships/Billing/BillingSchema.php /workspace/runtime/membership-award-evidence/BillingParentFloorCompatibilityTest.php > /workspace/runtime/membership-award-evidence/sqlite-billing-parent-floor.sources
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- /workspace/runtime/membership-award-evidence/BillingParentFloorCompatibilityTest.php tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php --filter 'test_missing_identity_guard_retains_the_billing_parent_floor_refusal_without_repair|test_an_unrelated_membership_refusal_keeps_its_class_and_reason|test_fresh_schema_is_owned_without_identity_child_dependencies_and_retry_is_idempotent' --log-junit /workspace/runtime/membership-award-evidence/sqlite-billing-parent-floor.xml 2>&1 | tee /workspace/runtime/membership-award-evidence/sqlite-billing-parent-floor.txt
result=${PIPESTATUS[0]}
printf '%s\n' "$result" > /workspace/runtime/membership-award-evidence/sqlite-billing-parent-floor.exit
sha256sum -c /workspace/runtime/membership-award-evidence/sqlite-billing-parent-floor.sources > /workspace/runtime/membership-award-evidence/sqlite-billing-parent-floor-source-check.txt
exit "$result"
