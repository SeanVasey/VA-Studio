#!/usr/bin/env bash
# usage: run.sh <filter> [old]
cd /home/user/wt-fu-importer
PRE='$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php";'
if [ "${2:-}" = old ]; then PRE="$PRE require getcwd().\"/vendor/autoload.php\"; require \"$(dirname $0)/OldEvidence.php\";"; fi
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33177 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_catalog_import DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
 php -d memory_limit=512M -r "$PRE require \"vendor/phpunit/phpunit/phpunit\";" -- --filter "$1" tests/Feature/PersistentCatalogDraftImportTest.php
