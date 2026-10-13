#!/usr/bin/env bash
set -uo pipefail
source /workspace/.va-studio-toolchain/activate.sh
cd /workspace/VA-Studio-membership-reservations
export TMPDIR=/tmp/va-studio-membership-reservations-20261013 APP_ENV=testing APP_DEBUG=false DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_SOCKET=
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipFirstAwardTest.php tests/Feature/ProductionMembershipBilling/MembershipAwardOwnershipTest.php --log-junit /workspace/runtime/membership-reservation-evidence/award-replay-integration.xml > /workspace/runtime/membership-reservation-evidence/award-replay-integration.log 2>&1
result=$?
printf '%s\n' "$result" > /workspace/runtime/membership-reservation-evidence/award-replay-integration.exit
exit "$result"
