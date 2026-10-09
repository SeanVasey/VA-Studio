#!/bin/bash
# usage: run.sh <srcdir> <outfile> <testfile...>
src=$1; out=$2; shift 2
cd "$src" || exit 99
{
  echo "# cwd=$src"; echo "# files=$*"; echo "# date=$(date -u +%FT%TZ)"
  env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
    php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress "$@" 2>&1
  echo "# rc=$?"
} > "$out"
tail -n 25 "$out"
