#!/usr/bin/env bash
set -u
set -o pipefail
cd /workspace/VA-Studio-billing-c3-baseline
source /workspace/.va-studio-toolchain/activate.sh
probe_evidence=/workspace/runtime/billing-retrieval-records-evidence/baseline
export APP_BASE_PATH=/workspace/VA-Studio-billing-c3-baseline
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
export XDEBUG_MODE=off
python3 "$probe_evidence/source_receipt.py" before
php -r 'require "vendor/autoload.php"; echo "PHP ", PHP_VERSION, PHP_EOL; echo "SQLite ", (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(), PHP_EOL; foreach ([App\Domain\Memberships\Billing\BillingLedger::class, App\Domain\Memberships\Billing\BillingReconciliation::class, Tests\Support\BillingStripeFixtures::class, Tests\Support\FinalizationDatabaseMigrations::class] as $class) { echo $class, " ", (new ReflectionClass($class))->getFileName(), PHP_EOL; }' > "$probe_evidence/runtime.txt"
php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --colors=never --log-junit "$probe_evidence/phpunit.xml" "$probe_evidence/BillingPreC3RegressionTest.php" > "$probe_evidence/phpunit.log" 2>&1
probe_status=$?
printf '%s\n' "$probe_status" > "$probe_evidence/phpunit.exit"
python3 "$probe_evidence/source_receipt.py" after
cat "$probe_evidence/runtime.txt" "$probe_evidence/phpunit.log"
exit "$probe_status"
