#!/usr/bin/env bash
# Serialized native runs, one schema per class, on the reviewer's private mysqld (127.0.0.1:3567).
cd /home/user/VA-Studio-review-tax255 || exit 9
E=docs/verification/tax-255-20261007/independent-review/review-evidence/native
for cls in "$@"; do
  db="review_tax255_${cls,,}"
  if [ -f "$cls" ]; then file=$cls; cls=$(basename $cls .php); db="review_tax255_${cls,,}"; else file=$(ls tests/Feature/ProductionTaxCheckout/*${cls}Test.php | head -1); fi
  for other in $(mysql --no-defaults -h127.0.0.1 -P3567 -uroot -pci-only-password -N -e "SHOW DATABASES LIKE 'review\_tax255\_%'" 2>/dev/null); do mysql --no-defaults -h127.0.0.1 -P3567 -uroot -pci-only-password -e "DROP DATABASE \`$other\`" 2>/dev/null; done
  mysql --no-defaults -h127.0.0.1 -P3567 -uroot -pci-only-password -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) start $cls ($file) db=$db" >> $E/run-ledger.txt
  APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3567 DB_DATABASE=$db DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
    php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --log-junit $E/native-mysql84-$cls.junit.xml "$file" > $E/native-mysql84-$cls.txt 2>&1
  rc=$?
  echo $rc > $E/native-mysql84-$cls.exit
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) end $cls exit=$rc $(tail -1 $E/native-mysql84-$cls.txt)" >> $E/run-ledger.txt
done
