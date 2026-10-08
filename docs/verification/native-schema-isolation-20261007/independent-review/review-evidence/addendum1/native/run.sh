#!/usr/bin/env bash
# Reviewer runner: one PHPUnit process per selection, worktree's own Composer autoload,
# private mysqld 127.0.0.1:3613, schema vaseyaudio_review_trigscan.
# Env (password is the throwaway CI-only value, redacted here):
#   APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3613 DB_DATABASE=vaseyaudio_review_trigscan
#   DB_USERNAME=root DB_PASSWORD=<ci-only> DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
# Usage: run.sh <label> <phpunit args...>   (output: $OUT/<label>.txt and .junit.xml)
S=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad
. $S/review-trigscan-a1-mysql/native.env
cd /home/user/VA-Studio-review-trigscan
OUT=${OUT:-/home/user/VA-Studio-review-trigscan/docs/verification/native-schema-isolation-20261007/independent-review/review-evidence/addendum1/native}
n=$1; shift
s=$(date +%s)
timeout 6000 php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- "$@" --log-junit $OUT/$n.junit.xml > $OUT/$n.raw 2>&1
code=$?
sed 's/\x1b\[[0-9;]*m//g' $OUT/$n.raw > $OUT/$n.txt; rm -f $OUT/$n.raw
echo "$(date -u +%FT%TZ) $n exit=$code wall=$(( $(date +%s) - s ))s tree=$(git rev-parse --short HEAD) dirty_app_db=$(git status --short -- app database | wc -l) :: $(grep -E '^(OK \(|Tests:|FAILURES|ERRORS)' $OUT/$n.txt | tail -1)" | tee -a $OUT/summary.txt
exit $code
