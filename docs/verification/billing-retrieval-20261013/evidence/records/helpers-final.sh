#!/usr/bin/env bash
set -o pipefail
cd /workspace/VA-Studio-membership-ledger || exit 1
source /workspace/.va-studio-toolchain/activate.sh
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
php vendor/bin/phpunit tests/Unit/BillingDatabaseClockTest.php tests/Unit/BillingRetrievalRecordsTest.php --log-junit /workspace/runtime/billing-retrieval-records-evidence/helpers-final.xml > /workspace/runtime/billing-retrieval-records-evidence/helpers-final.txt 2>&1
result=$?
printf '%s\n' "$result" > /workspace/runtime/billing-retrieval-records-evidence/helpers-final.exit
cat /workspace/runtime/billing-retrieval-records-evidence/helpers-final.txt
exit "$result"
