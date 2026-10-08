#!/usr/bin/env bash
# Addendum 1 native runner. Usage: runner.sh <label> <phpunit args...>
# Records PHPUnit's own exit status: rc is captured on its own line straight after php returns.
cd /home/user/VA-Studio-review-member || exit 90
L=docs/verification/membership-operative-1-20261007/independent-review/review-evidence/addendum1/native
export APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M= APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3531 DB_DATABASE=vaseyaudio_review_member DB_USERNAME=root DB_PASSWORD=review-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
label=$1; shift
head=$(git rev-parse HEAD)
dirty=$(git status --porcelain -- app database config routes tests | wc -l)
echo "$(date -u +%FT%TZ) start $label head $head dirty=$dirty" >> $L/ledger.txt
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never "$@" --log-junit $L/$label.xml > $L/$label.txt 2>&1
rc=$?
echo "rc=$rc" >> $L/$label.txt
echo "$(date -u +%FT%TZ) end $label phpunit_rc=$rc" >> $L/ledger.txt
exit $rc
