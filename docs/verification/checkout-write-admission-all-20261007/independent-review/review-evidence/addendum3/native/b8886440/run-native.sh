#!/usr/bin/env bash
# usage: run-native.sh <name> <outdir> <port> <phpunit args...>  (database va_rev_a3)
name=$1; out=$2; port=$3; db=va_rev_a3; shift 3
S=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/review-admission4
rm -rf "$S/tmp-$name"; mkdir -p "$S/tmp-$name" "$out"
cd /home/user/VA-Studio-review-admission4
echo "$name: source=$(git rev-parse HEAD) app_clean=$(git diff --quiet -- app && echo yes || echo no) env: APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=$port DB_DATABASE=$db DB_USERNAME=root DB_PASSWORD='' TMPDIR=$S/tmp-$name; args: $*" >> "$out/commands.txt"
start=$(date +%s.%N)
APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=$port DB_DATABASE="$db" DB_USERNAME=root DB_PASSWORD= DB_URL= TMPDIR="$S/tmp-$name" \
  php -d memory_limit=1G -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- "$@" --log-junit "$out/$name.xml" --do-not-cache-result --colors=never > "$out/$name.raw" 2>&1
rc=$?
end=$(date +%s.%N)
sed -r 's/\x1b\[[0-9;]*m//g' "$out/$name.raw" > "$out/$name.txt"; rm -f "$out/$name.raw"
echo $rc > "$out/$name.exit"
echo "$name db=$db port=$port exit=$rc wall=$(echo "$end - $start" | bc)s" >> "$out/timing.txt"
