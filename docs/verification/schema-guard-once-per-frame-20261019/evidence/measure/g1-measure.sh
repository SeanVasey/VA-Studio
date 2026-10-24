#!/bin/bash
# usage: g1-measure.sh <label>   measures the existing reserve test with the general log; source = $DIR or the g1 worktree
SP=/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad
label=$1; dir=${DIR:-/home/user/wt/g1}
P=$SP/g1-ev/measure-progress.txt
M="/opt/mysql-8.4.11/bin/mysql -uroot -h127.0.0.1 -P3371"
G=$SP/g1-mysql/general-$label.log; rm -f $G
echo "$label started $(date -u +%FT%TZ) head=$(git -C $dir rev-parse --short=8 HEAD) dirty=$(git -C $dir status --porcelain -- app tests | wc -l) load=$(cut -d' ' -f1-3 /proc/loadavg) other_mysqld=$(pgrep -fa mysqld | grep -v 3371 | grep -c port) server=$($M -N -e 'select concat(@@version,"/",@@innodb_flush_log_at_trx_commit,"/",@@log_bin,"/",@@sync_binlog,"/",@@innodb_buffer_pool_size,"/ps=",@@performance_schema)')" >> $P
$M -e "SET GLOBAL general_log_file='$G'; SET GLOBAL general_log=1"
start=$(date +%s)
DIR=$dir $SP/g1-mysql.sh --log-junit $SP/g1-ev/measure-$label.xml --filter '/::test_real_sources_reserve_once_and_exact_retry_and_award_replay_preserve_the_retained_identity$/' tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php > $SP/g1-ev/measure-$label.txt 2>&1; rc=$?
$M -e "SET GLOBAL general_log=0"
python3 $SP/g1-glog.py $G > $SP/g1-ev/measure-$label.glog.txt 2>&1
gzip -c $G > $SP/g1-mysql/general-$label.log.gz; rm -f $G
sum=$(python3 -c "import xml.etree.ElementTree as E,sys;r=E.parse(sys.argv[1]).getroot()[0];print(' '.join(k+'='+r.get(k) for k in ['tests','assertions','failures','errors']))" $SP/g1-ev/measure-$label.xml 2>/dev/null)
echo "$label finished $(date -u +%FT%TZ) exit=$rc seconds=$(( $(date +%s)-start )) $sum txns: $(grep ^TXN $SP/g1-ev/measure-$label.glog.txt | cut -d' ' -f2-4 | tr '\n' ';')" >> $P
