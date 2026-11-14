#!/bin/bash
# usage: g1-mut-run.sh <mutation> <sqlite|mysql> [filter]   runs the new test file in the mutation copy with one mutation applied
SP=/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad
m=$1; drv=$2; f=${3:-.}; L=$SP/g1-ev/mutations.log
cd $SP && python3 g1-mutate.py apply $m >/dev/null || { echo "$m apply failed" >> $L; exit 2; }
out=$SP/g1-ev/mut-$m-$drv.txt
if [ $drv = sqlite ]; then DIR=$SP/g1-mut $SP/g1-sqlite.sh --filter "$f" tests/Feature/ProductionMembershipBilling/MembershipSchemaProofFrameTest.php > $out 2>&1; rc=$?
else DIR=$SP/g1-mut DBNAME=vaseyaudio_mutg1 $SP/g1-mysql.sh --filter "$f" tests/Feature/ProductionMembershipBilling/MembershipSchemaProofFrameTest.php > $out 2>&1; rc=$?; fi
python3 g1-mutate.py restore $m >/dev/null
diff -rq /home/user/wt/g1/app $SP/g1-mut/app >/dev/null && clean=clean || clean=DIRTY
echo "$(date -u +%FT%TZ) $m $drv filter=$f exit=$rc restore=$clean :: $(grep -E '^(OK|Tests:|FAILURES)' $out | tr '\n' ' ') :: $(grep -E '^[0-9]+\) ' $out | sed 's/.*:://' | tr '\n' ' ')" >> $L
