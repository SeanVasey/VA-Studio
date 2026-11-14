#!/usr/bin/env bash
set -o pipefail
cd /workspace/VA-Studio-membership-ledger || exit 1
source /workspace/.va-studio-toolchain/activate.sh
export APP_BASE_PATH=/workspace/VA-Studio-membership-ledger
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
php vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/ProductionMembershipBilling/BillingIssuedRetrievalTest.php tests/Unit/BillingDatabaseClockTest.php tests/Unit/BillingRetrievalRecordsTest.php --log-junit /workspace/runtime/billing-retrieval-records-evidence/issuer-full.xml > /workspace/runtime/billing-retrieval-records-evidence/issuer-full.txt 2>&1
result=$?
printf '%s\n' "$result" > /workspace/runtime/billing-retrieval-records-evidence/issuer-full.exit
cat /workspace/runtime/billing-retrieval-records-evidence/issuer-full.txt
exit "$result"
