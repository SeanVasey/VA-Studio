#!/usr/bin/env bash
cd /home/user/VA-Studio-review-member
L=docs/verification/membership-operative-1-20261007/independent-review/review-evidence/native
export APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M= APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3461 DB_DATABASE=vaseyaudio_review_member DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
run(){ label=$1; shift; echo "$(date -u +%FT%TZ) start $label" >> $L/ledger.txt; php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- "$@" --log-junit $L/$label.xml > $L/$label.txt 2>&1; echo "$(date -u +%FT%TZ) end $label exit $? head $(git rev-parse HEAD) dirty=$(git status --porcelain -- app database config tests | wc -l)" >> $L/ledger.txt; }
run f2-coupling tests/Feature/ProductionMemberOriginals/MemberActivationCouplingSchemaTest.php
run probes-f2-billing docs/verification/membership-operative-1-20261007/independent-review/review-evidence/probes/ReviewerOperativeProbeTest.php --filter 'test_f2_|test_billing_'
run billing-dedup-race tests/Feature/ProductionMembershipBilling/BillingNativeDedupRaceTest.php
echo DONE >> $L/ledger.txt
