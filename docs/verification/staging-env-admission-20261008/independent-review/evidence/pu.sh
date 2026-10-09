#!/bin/bash
# usage: pu.sh <worktree> <outfile> <phpunit args...>
wt="$1"; out="$2"; shift 2
cd "$wt" || exit 9
{
  echo "# cwd: $wt  HEAD: $(git rev-parse HEAD)  status-dirty-tracked: $(git status --porcelain --untracked-files=no | wc -l)"
  echo "# public/build present: $([ -e public/build ] && echo yes || echo no)"
  echo "# command: php -r '\$GLOBALS[\"_composer_autoload_path\"]=getcwd().\"/vendor/autoload.php\"; require \"vendor/phpunit/phpunit/phpunit\";' -- --colors=never --do-not-cache-result $*"
  echo "# started: $(date -u +%FT%TZ)"
  php -d memory_limit=2G -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --do-not-cache-result "$@" 2>&1
  rc=$?
  echo "# finished: $(date -u +%FT%TZ)"
  echo "rc=$rc"
} > "$out"
tail -3 "$out"
