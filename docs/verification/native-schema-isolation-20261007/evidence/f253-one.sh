#!/usr/bin/env bash
label=$1
S=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad
cd /home/user/VA-Studio-trigscan-f253
export APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3410 DB_DATABASE=vaseyaudio_trigscan_f253 DB_USERNAME=root DB_PASSWORD= DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
timeout 3000 php $S/phpunit-own-autoload.php --filter test_regular_begin_commit_and_aftercommit_callback_order_is_preserved tests/Feature/ProductionFeatures > $S/f253-one-$label.txt 2>&1
echo "$label exit=$? $(sed 's/\x1b\[[0-9;]*m//g' $S/f253-one-$label.txt | grep -E 'DIAGNOSTIC|^OK|^Tests:|Exception:' | tr '\n' ' ')"
