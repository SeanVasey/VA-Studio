#!/usr/bin/env bash
# usage: native.sh <rootdir> <outfile> <schema> <label> <phpunit args...>; serialized on the private mysqld 127.0.0.1:3721
root=$1; out=$2; db=$3; label=$4; shift 4
M="mysql --no-defaults -h127.0.0.1 -P3721 -uroot -pci-only-password"
for other in $($M -N -e "SHOW DATABASES LIKE 'h255\_%'" 2>/dev/null); do $M -e "DROP DATABASE \`$other\`" 2>/dev/null; done
$M -e "CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
cd "$root" || exit 9
{
echo "# $label"
echo "# native MySQL 8.4.11 private instance 127.0.0.1:3721 schema $db root=$root start $(date -u +%FT%TZ)"
APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3721 DB_DATABASE=$db DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never "$@"
rc=$?
echo "rc=$rc"
echo "# end $(date -u +%FT%TZ)"
} > "$out" 2>&1
