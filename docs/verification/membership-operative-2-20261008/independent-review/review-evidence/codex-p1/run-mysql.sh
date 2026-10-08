#!/bin/bash
# Review runner: one focused PHPUnit file per run against the private MySQL 8.4.11 instance on 127.0.0.1:3793.
cd /home/user/VA-Studio-review-m54 || exit 9
E=docs/verification/membership-operative-2-20261008/independent-review/review-evidence/codex-p1
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3793 DB_DATABASE=${RDB:-r54p1_review} DB_USERNAME=root DB_PASSWORD= DB_URL= DB_SOCKET= APP_ENV=testing
n=${RSTART:-1}
for f in "$@"; do
  base=$(basename "$f" .php)
  out=$E/mysql-0${n}-${base}.txt
  {
    echo "# head: $(git rev-parse HEAD)  (worktree clean apart from untracked review files: $(git status --porcelain | grep -v '^??' | wc -l) tracked changes)"
    echo "# engine: mysqld 8.4.11 --no-defaults, 127.0.0.1:3793, database ${RDB:-r54p1_review}; DB_CONNECTION=mysql APP_ENV=testing"
    echo "# cmd: php -r '\$GLOBALS[\"_composer_autoload_path\"]=getcwd().\"/vendor/autoload.php\"; require \"vendor/phpunit/phpunit/phpunit\";' -- --colors=never $f"
    echo "# start: $(date -u +%FT%TZ)"
    php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --log-junit $E/mysql-0${n}-${base}.xml "$f" 2>&1
    echo "rc=$?"
    echo "# end: $(date -u +%FT%TZ)"
  } > "$out" 2>&1
  n=$((n+1))
done
