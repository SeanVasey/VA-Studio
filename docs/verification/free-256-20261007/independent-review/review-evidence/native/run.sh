#!/usr/bin/env bash
# usage: run.sh <port> <outprefix> <phpunit args...>   (database rv256 on a private review instance)
port=$1; out=$2; shift 2
cd /home/user/VA-Studio-review-free256
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=$port DB_DATABASE=rv256 DB_USERNAME=root DB_PASSWORD= DB_URL=
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --log-junit "$out.junit.xml" "$@" > "$out.txt" 2>&1
rc=$?
echo "rc=$rc" >> "$out.txt"
echo "rc=$rc" > "$out.rc"
