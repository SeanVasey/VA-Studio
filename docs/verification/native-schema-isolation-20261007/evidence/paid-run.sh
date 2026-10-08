#!/usr/bin/env bash
# Usage: paid-run.sh <label> <worktree> <schema>. Runs PaidGrantDownloadJourneyTest on the private 3410 daemon and records statement totals.
label=$1; W=$2; DB=$3
S=/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad
M() { mysql -h127.0.0.1 -P3410 -uroot -N -e "$1" 2>/dev/null; }
total() { M "SELECT SUM(COUNT_STAR) FROM performance_schema.events_statements_summary_global_by_event_name"; }
cd $W
export APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3410 DB_DATABASE=$DB DB_USERNAME=root DB_PASSWORD= DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
{
echo "$label source=$(git rev-parse HEAD) staged=$(git diff --cached --name-only | wc -l) start=$(date -u +%FT%TZ) server_statements_before=$(total)"
start=$(date +%s)
timeout 7000 php $S/phpunit-own-autoload.php tests/Feature/PaidGrantDownloadJourneyTest.php ${PAID_ARGS:-} --log-junit $S/paid-$label.xml > $S/paid-$label.txt 2>&1
echo "exit=$? wall=$(( $(date +%s) - start ))s end=$(date -u +%FT%TZ) server_statements_after=$(total)"
sed 's/\x1b\[[0-9;]*m//g' $S/paid-$label.txt | grep -E '^(OK \(|Tests:)|^[0-9]+\) ' 
} > $S/paid-$label-summary.txt 2>&1
