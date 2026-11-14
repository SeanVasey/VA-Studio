#!/bin/bash
# Usage: mysql.sh <phpunit args...>  (G1 fix pass, private MySQL 8.4.11 on 3417; DIR overrides the tree, DBNAME the database)
cd ${DIR:-/home/user/wt/g1} || exit 2
KEY="base64:$(head -c32 /dev/urandom | base64)"
S=/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad/g1-mysql
exec nice env TMPDIR=$S/tmp APP_ENV=testing APP_DEBUG=false APP_KEY="$KEY" VA_LOCAL_DISPOSABLE_MYSQL=${DBNAME:-vaseyaudio_g1fix} DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3417 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=${DBNAME:-vaseyaudio_g1fix} DB_URL= DB_SOCKET=$S/run/mysqld.sock CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning "$@"
