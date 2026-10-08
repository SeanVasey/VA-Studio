#!/usr/bin/env bash
# Reviewer native runner for ONE mutation in the scratch mutation worktree. Usage: run-native-mut.sh <mutation-id> <label> <phpunit args...>
# Applies the mutation with mutate-apply.py, runs, records PHPUnit's own rc, restores with git checkout and checks a clean status.
MW=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-member2-mut
E=/home/user/VA-Studio-review-member2/docs/verification/membership-operative-2-20261008/independent-review/review-evidence/native
cd $MW || exit 90
mid=$1; label=$2; shift 2
python3 -I $E/../mutations/mutate-apply.py $MW $mid > $E/$label.diff || exit 91
export APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M= APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3751 DB_DATABASE=vaseyaudio_review_member2_mut DB_USERNAME=root DB_PASSWORD=review-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
echo "$(date -u +%FT%TZ) start $label mutation=$mid worktree=$MW head $(git rev-parse HEAD) dirty=$(git status --porcelain -- app database config routes tests | wc -l)" >> $E/ledger.txt
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never "$@" --log-junit $E/$label.xml > $E/$label.txt 2>&1
rc=$?
echo "rc=$rc" >> $E/$label.txt
git checkout -q -- app
echo "$(date -u +%FT%TZ) end $label phpunit_rc=$rc restored_dirty=$(git status --porcelain -- app database config routes tests | wc -l)" >> $E/ledger.txt
exit $rc
