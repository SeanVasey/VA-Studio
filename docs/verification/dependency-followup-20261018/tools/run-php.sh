#!/bin/bash
# Runs each selection file sequentially with the CI-parity SQLite environment and writes JUnit + console logs.
S={scratch}
cd {worktree} || exit 9
for name in "$@"; do
  test -d public/build && { echo "public/build present, abort" >> $S/php-status.txt; exit 8; }
  start=$(date +%s)
  env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true \
    php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
    -- --fail-on-phpunit-warning --log-junit $S/php-$name.xml $(cat $S/sel-$name.txt) > $S/php-$name.txt 2>&1
  rc=$?
  echo "$name exit $rc seconds $(( $(date +%s)-start ))" >> $S/php-status.txt
done
echo ALLDONE >> $S/php-status.txt
