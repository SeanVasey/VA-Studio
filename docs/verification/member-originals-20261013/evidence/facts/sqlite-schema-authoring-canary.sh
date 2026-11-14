#!/usr/bin/env bash
set -uo pipefail
source /workspace/.va-studio-toolchain/activate.sh
cd /workspace/VA-Studio-member-original-facts
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
php -r 'require "vendor/autoload.php"; printf("PHP %s\nApp source %s\n", PHP_VERSION, (new ReflectionClass(App\Domain\Grants\Member\MemberGrantDefinitions::class))->getFileName());'
php -r 'require "vendor/autoload.php"; exit((new PHPUnit\TextUI\Application)->run(["phpunit", "--colors=never", "--stop-on-failure", "--stop-on-error", "--log-junit", "/workspace/runtime/member-original-facts-evidence/sqlite-schema-authoring-canary.xml", "--filter", "MemberGrantCurrentSchemaTest|MemberGrantDefinitionsTest::test_real_enrolled_staff_authors_and_deduplicates_without_customer_gate_or_grant_writes", "tests/Feature/ProductionMemberOriginals/MemberGrantCurrentSchemaTest.php", "tests/Feature/ProductionMemberOriginals/MemberGrantDefinitionsTest.php"]));'
status=$?
printf '%s\n' "$status" > /workspace/runtime/member-original-facts-evidence/sqlite-schema-authoring-canary.exit
exit "$status"
