#!/usr/bin/env bash
# usage: run.sh <outprefix> <phpunit args...>   (private review instance 127.0.0.1:3797, schema rv256f)
out=$1; shift
cd /home/user/VA-Studio-review-free256b
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3797 DB_DATABASE=rv256f DB_USERNAME=root DB_PASSWORD= DB_URL=
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --log-junit "$out.junit.xml" "$@" > "$out.txt" 2>&1
rc=$?
echo "rc=$rc" >> "$out.txt"
echo "rc=$rc" > "$out.rc"
