#!/usr/bin/env bash
# Reviewer addendum 1 native runner: private mysqld 127.0.0.1:3767, one fresh schema per class, stream-local schema prefix.
# Usage: review-tax255b-native.sh <worktree> <stream> <class-or-file>[::filter] ...
W=$1; stream=$2; shift 2
cd "$W" || exit 9
E=/home/user/VA-Studio-review-tax255b/docs/verification/tax-255-20261007/independent-review/review-evidence/addendum1/native
M="mysql --no-defaults -h127.0.0.1 -P3767 -uroot -pci-only-password"
for spec in "$@"; do
  cls=${spec%%::*}; filter=; [ "$spec" != "$cls" ] && filter=${spec#*::}
  if [ -f "$cls" ]; then file=$cls; label=$(basename $cls .php); else file=$(ls tests/Feature/ProductionTaxCheckout/*${cls}Test.php | head -1); label=$cls; fi
  [ -n "$filter" ] && label="$label-$(echo "$filter" | tr -c 'A-Za-z0-9_' '_' | cut -c1-40)"
  label="$stream-$label"
  db="rv_${stream}_$(echo "${label,,}" | tr -c 'a-z0-9_' '_' | cut -c1-40)"
  for other in $($M -N -e "SHOW DATABASES LIKE 'rv\_${stream}\_%'" 2>/dev/null); do $M -e "DROP DATABASE \`$other\`" 2>/dev/null; done
  $M -e "CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) start $label worktree=$W head=$(git rev-parse --short HEAD) dirty_app=$(git status --porcelain -- app database | wc -l) file=$file filter=${filter:-none} db=$db" >> $E/run-ledger.txt
  args=(--colors=never --log-junit $E/$label.junit.xml); [ -n "$filter" ] && args+=(--filter "$filter")
  APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3767 DB_DATABASE=$db DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
    php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- "${args[@]}" "$file" > $E/$label.txt 2>&1
  rc=$?
  echo $rc > $E/$label.exit
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) end $label exit=$rc $(grep -E '^(OK|Tests:)' $E/$label.txt | tail -1)" >> $E/run-ledger.txt
  $M -e "DROP DATABASE IF EXISTS \`$db\`" 2>/dev/null
done
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) stream $stream DONE" >> $E/run-ledger.txt
