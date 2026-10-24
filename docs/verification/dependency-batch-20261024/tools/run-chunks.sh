#!/bin/bash
# Usage: run-chunks.sh <worker-name> <chunk numbers...>. Resumable: a chunk with a .done marker is skipped.
S=<scratch directory>   # as run: the session scratchpad, m4-ev
O=$S/out
cd /home/user/wt/m4 || exit 9
w=$1; shift
for n in "$@"; do
  c=$(printf 'c%02d' $n)
  [ -f $O/$c.done ] && continue
  if [ -d public/build ]; then echo "$(date -u +%T) [$w] public/build present, abort" >> $S/PROGRESS.md; exit 8; fi
  start=$(date +%s)
  echo "$(date -u +%T) [$w] start $c ($(wc -l < $O/sel-$c.txt) files)" >> $S/PROGRESS.md
  env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:$(head -c32 /dev/urandom | base64) DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
    CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 \
    nice -n 10 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
    -- --colors=never --fail-on-phpunit-warning --log-junit $O/$c.xml $(cat $O/sel-$c.txt) > $O/$c.txt 2>&1
  rc=$?
  echo "$c exit $rc seconds $(( $(date +%s)-start ))" > $O/$c.done
  echo "$(date -u +%T) [$w] done $c exit $rc in $(( $(date +%s)-start ))s: $(tail -3 $O/$c.txt | tr '\n' ' ')" >> $S/PROGRESS.md
done
echo "$(date -u +%T) [$w] ALL ASSIGNED CHUNKS DONE" >> $S/PROGRESS.md
