#!/bin/bash
# usage: pu-export.sh <export-dir> <outfile> <phpunit args...>   (export = `git archive 5513e3a`, not a git repo)
wt="$1"; out="$2"; shift 2
cd "$wt" || exit 9
{
  echo "# source: git archive 5513e3aee07746734b708f860117252595ec2452 | tar -x -C $wt (vendor rsync'd from /home/user/rv-b2/vendor, composer dump-autoload)"
  echo "# public/build present: $([ -e public/build ] && echo yes || echo no)"
  echo "# command: php -r '\$GLOBALS[\"_composer_autoload_path\"]=getcwd().\"/vendor/autoload.php\"; require \"vendor/phpunit/phpunit/phpunit\";' -- --colors=never --do-not-cache-result $*"
  echo "# started: $(date -u +%FT%TZ)"
  php -d memory_limit=2G -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --do-not-cache-result "$@" 2>&1
  rc=$?
  echo "# finished: $(date -u +%FT%TZ)"
  echo "rc=$rc"
} > "$out"
grep -E "^(OK|Tests:|FAILURES|ERRORS|rc=)" "$out"
