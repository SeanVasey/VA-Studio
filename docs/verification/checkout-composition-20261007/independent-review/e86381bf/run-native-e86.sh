#!/usr/bin/env bash
cd /home/user/VA-Studio-review
E=review-evidence/e86381bf; R=review-evidence/run-phpunit.sh
M="mysql -h127.0.0.1 -uroot -pci-only-password -N"
quiet=0; start=$(date +%s)
while :; do
  n=$($M -e "SELECT (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA NOT IN ('sys','vaseyaudio_review','vaseyaudio_features253','vaseyaudio_paid252')) + (SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB LIKE 'vaseyaudio%' AND DB<>'vaseyaudio_review')" 2>/dev/null)
  echo "$(date -u +%FT%TZ) busy=$n" >> $E/native-wait.log
  if [ "$n" = "0" ]; then quiet=$((quiet+1)); else quiet=0; fi
  [ $quiet -ge 6 ] && break
  [ $(( $(date +%s) - start )) -ge 3000 ] && { echo TIMEOUT >> $E/native-wait.log; exit 3; }
  sleep 10
done
$M -e "DROP DATABASE IF EXISTS vaseyaudio_review; CREATE DATABASE vaseyaudio_review" 2>/dev/null
$M -e 'SELECT VERSION(), @@version_comment' 2>/dev/null > $E/native-server.txt
export APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=vaseyaudio_review DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
$R review-evidence/ReviewAdversarialCompositionTest.php --log-junit $E/native-adversarial.xml > $E/native-adversarial.txt 2>&1; echo $? > $E/native-adversarial.exit
$M -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='vaseyaudio_review'" 2>/dev/null > $E/native-schema-tablecount-after.txt
echo done > $E/native.done
