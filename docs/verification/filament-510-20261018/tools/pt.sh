#!/bin/bash
# usage: pt.sh <phpunit args...>  (run from /home/user/wt/m3)
cd /home/user/wt/m3
export APP_KEY="${APP_KEY:-base64:$(head -c32 /dev/urandom | base64)}"
env APP_ENV=testing APP_DEBUG=false APP_KEY="$APP_KEY" DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --fail-on-phpunit-warning "$@"
