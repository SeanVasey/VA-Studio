#!/usr/bin/env bash
# Native run of the whole tests/Feature/ProductionSuppression directory against the reviewer's private mysqld :3493.
cd /home/user/VA-Studio-review-supp254d
E=docs/verification/suppression-254-20261007/independent-review/review-evidence/addendum2/native
export APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3493 DB_DATABASE=vaseyaudio_review_supp254d DB_USERNAME=root DB_PASSWORD=review-supp254d-only DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export APP_KEY="$(php -r 'echo "base64:".base64_encode(str_repeat("I",32));')"
start=$(date -u +%FT%TZ)
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --log-junit $E/production-suppression-dir.junit.xml tests/Feature/ProductionSuppression > $E/production-suppression-dir.txt 2>&1
rc=$?
echo "start=$start end=$(date -u +%FT%TZ) exit=$rc" > $E/production-suppression-dir.exit.txt
