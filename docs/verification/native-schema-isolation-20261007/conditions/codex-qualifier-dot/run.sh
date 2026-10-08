#!/usr/bin/env bash
# usage: run.sh <label> <phpunit args...>   (from the worktree root)
# Private mysqld 8.4.11 on 127.0.0.1:3541, schema vaseyaudio_trigscan_fix, root with an empty password
# (the native.env sourced below is scratchpad-only and removed with the datadir):
#   APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3541 DB_DATABASE=vaseyaudio_trigscan_fix
#   DB_USERNAME=root DB_PASSWORD= DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
S=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad
E=docs/verification/native-schema-isolation-20261007/conditions/codex-qualifier-dot
. $S/trigscan-fix-mysql/native.env
label=$1; shift
start=$(date +%s)
timeout 5000 php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never "$@" --log-junit $E/$label.junit.xml > $E/$label.raw 2>&1
rc=$?
sed 's/\x1b\[[0-9;]*[A-Za-z]//g' $E/$label.raw > $E/$label.txt; rm -f $E/$label.raw
echo "$label rc=$rc wall=$(( $(date +%s) - start ))s tree=$(git rev-parse --short HEAD) dirty_app_db=$(git status --short -- app database | wc -l) $(grep -E '^(OK \(|Tests:)' $E/$label.txt | tail -1)" >> $E/summary.txt
