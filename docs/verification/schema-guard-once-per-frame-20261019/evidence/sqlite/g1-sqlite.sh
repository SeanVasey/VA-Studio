#!/bin/bash
# Usage: g1-sqlite.sh <phpunit args...>
cd ${DIR:-/home/user/wt/g1} || exit 2
KEY="base64:$(head -c32 /dev/urandom | base64)"
exec nice env APP_ENV=testing APP_DEBUG=false APP_KEY="$KEY" DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning "$@"
