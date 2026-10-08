#!/usr/bin/env bash
# Reviewer SQLite runner. Usage: run-sqlite.sh <label> <phpunit args...>; PHPUnit's own rc on its own line.
W=/home/user/VA-Studio-review-member2; E=$W/docs/verification/membership-operative-2-20261008/independent-review/review-evidence/sqlite
cd $W || exit 90
export APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M=
label=$1; shift
echo "head $(git rev-parse HEAD) dirty=$(git status --porcelain -- app database config routes tests | wc -l) start $(date -u +%FT%TZ)" > $E/$label.txt
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never "$@" --log-junit $E/$label.xml >> $E/$label.txt 2>&1
rc=$?
echo "rc=$rc" >> $E/$label.txt
echo "end $(date -u +%FT%TZ)" >> $E/$label.txt
exit $rc
