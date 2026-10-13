#!/usr/bin/env bash
set -o pipefail
cd /workspace/VA-Studio-membership-award
source /workspace/.va-studio-toolchain/activate.sh
export TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_SOCKET=
sha256sum app/Domain/Memberships/Production/MembershipSchema.php app/Domain/Memberships/Production/MembershipSchemaCatalog.php app/Domain/Memberships/Billing/BillingSchema.php > /workspace/runtime/membership-award-evidence/sqlite-schema-batch.sources
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembership/MembershipSchemaPreparationTest.php tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php tests/Feature/ProductionMembershipBilling/MembershipPaidInvoiceProofTest.php tests/Feature/ProductionMembershipBilling/MembershipFirstAwardTest.php tests/Feature/ProductionMembershipBilling/MembershipAwardOwnershipTest.php --log-junit /workspace/runtime/membership-award-evidence/sqlite-schema-batch.xml 2>&1 | tee /workspace/runtime/membership-award-evidence/sqlite-schema-batch.txt
result=${PIPESTATUS[0]}
printf '%s\n' "$result" > /workspace/runtime/membership-award-evidence/sqlite-schema-batch.exit
sha256sum -c /workspace/runtime/membership-award-evidence/sqlite-schema-batch.sources > /workspace/runtime/membership-award-evidence/sqlite-schema-batch-source-check.txt
exit "$result"
