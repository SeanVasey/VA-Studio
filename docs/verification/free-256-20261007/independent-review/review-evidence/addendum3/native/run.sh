#!/usr/bin/env bash
# usage: run.sh <outprefix> <phpunit args...>   (private review instance 127.0.0.1:3751, schema rv256c)
out=$1; shift
cd /home/user/VA-Studio-review-free256b
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3751 DB_DATABASE=rv256c DB_USERNAME=root DB_PASSWORD= DB_URL=
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --log-junit "$out.junit.xml" "$@" > "$out.txt" 2>&1
rc=$?
echo "rc=$rc" >> "$out.txt"
echo "rc=$rc" > "$out.rc"
