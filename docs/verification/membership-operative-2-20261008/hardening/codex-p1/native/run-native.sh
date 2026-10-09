#!/usr/bin/env bash
# Codex P1 native runner. Usage: run-native-cp1.sh <label> <phpunit args...>. PHPUnit's own rc is captured on its own line.
W=/home/user/VA-Studio-member-ops2; E=$W/docs/verification/membership-operative-2-20261008/hardening/codex-p1/native
cd $W || exit 90
export APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M= APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3761 DB_DATABASE=vaseyaudio_codex_p1 DB_USERNAME=root DB_PASSWORD= DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
label=$1; shift
echo "$(date -u +%FT%TZ) start $label base $(git rev-parse HEAD) dirty=$(git status --porcelain -- app database config routes tests scripts | wc -l)" >> $E/ledger.txt
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never "$@" --log-junit $E/$label.xml > $E/$label.txt 2>&1
rc=$?
echo "rc=$rc" >> $E/$label.txt
echo "$(date -u +%FT%TZ) end $label phpunit_rc=$rc" >> $E/ledger.txt
exit $rc
