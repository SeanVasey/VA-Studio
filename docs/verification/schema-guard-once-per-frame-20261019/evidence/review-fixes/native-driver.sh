#!/bin/bash
# Native verification on the merged tree: reserve lifecycle with the general log, then the two G1 files. Resumable.
SP=/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad; N=$SP/g1-fix/native; P=$N/progress.txt
M="/opt/mysql-8.4.11/bin/mysql -uroot -h127.0.0.1 -P3417"
W=/home/user/wt/g1
summ() { python3 -c "import xml.etree.ElementTree as E,sys;r=E.parse(sys.argv[1]).getroot()[0];print(' '.join(k+'='+r.get(k) for k in ['tests','assertions','failures','errors','skipped']))" $1 2>/dev/null; }
hdr() { echo "$1 started $(date -u +%FT%TZ) head=$(git -C $W rev-parse --short=8 HEAD) dirty=$(git -C $W status --porcelain -- app tests composer.lock | wc -l) load=$(cut -d' ' -f1-3 /proc/loadavg) server=$($M -N -e 'select concat(@@version,"/",@@port,"/",@@innodb_flush_log_at_trx_commit,"/",@@log_bin,"/",@@innodb_buffer_pool_size,"/ps=",@@performance_schema,"/",@@transaction_isolation)')" >> $P; }
if ! grep -q "^lifecycle finished" $P 2>/dev/null; then
  hdr lifecycle; G=$SP/g1-mysql/general-g1fix-merged.log; rm -f $G
  $M -e "SET GLOBAL general_log_file='$G'; SET GLOBAL general_log=1"; s=$(date +%s)
  $SP/g1-fix/mysql.sh --log-junit $N/lifecycle.xml --filter '/::test_real_sources_reserve_once_and_exact_retry_and_award_replay_preserve_the_retained_identity$/' tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php > $N/lifecycle.txt 2>&1; rc=$?
  $M -e "SET GLOBAL general_log=0"
  python3 $N/g1-glog.py $G > $N/lifecycle.glog.txt 2>&1; gzip -c $G > $SP/g1-mysql/general-g1fix-merged.log.gz; rm -f $G
  echo "lifecycle finished $(date -u +%FT%TZ) exit=$rc seconds=$(( $(date +%s)-s )) $(summ $N/lifecycle.xml) txns: $(grep ^TXN $N/lifecycle.glog.txt | cut -d' ' -f2-4 | tr '\n' ';')" >> $P
fi
for id in frame observers; do
  grep -q "^$id finished" $P 2>/dev/null && continue
  f=tests/Feature/ProductionMembershipBilling/MembershipSchemaProofFrameTest.php; [ $id = observers ] && f=tests/Feature/ProductionMemberGrants/MemberObserverTerminalProofTest.php
  hdr $id; s=$(date +%s)
  $SP/g1-fix/mysql.sh --log-junit $N/$id.xml $f > $N/$id.txt 2>&1; rc=$?
  echo "$id finished $(date -u +%FT%TZ) exit=$rc seconds=$(( $(date +%s)-s )) $(summ $N/$id.xml)" >> $P
done
echo "all done $(date -u +%FT%TZ)" >> $P
