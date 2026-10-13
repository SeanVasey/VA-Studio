#!/usr/bin/env bash
set -uo pipefail
source /workspace/.va-studio-toolchain/activate.sh
cd /workspace/VA-Studio-member-original-facts
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
php -r 'require "vendor/autoload.php"; exit((new PHPUnit\TextUI\Application)->run(["phpunit", "--colors=never", "--stop-on-failure", "--stop-on-error", "--log-junit", "/workspace/runtime/member-original-facts-evidence/sqlite-authoring-facts-first.xml", "tests/Feature/ProductionMemberOriginals/MemberGrantDefinitionsTest.php", "tests/Feature/ProductionMemberOriginals/MemberGrantFactsTest.php"]));'
status=$?
printf '%s\n' "$status" > /workspace/runtime/member-original-facts-evidence/sqlite-authoring-facts-first.exit
exit "$status"
