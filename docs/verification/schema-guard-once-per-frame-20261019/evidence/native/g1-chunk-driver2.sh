#!/bin/bash
# Second native runner: walks g1-chunks.txt from the end in the byte-identical copy g1-mut (own storage) on database
# vaseyaudio_mutg1, sharing g1-ev/native-progress.txt; stops at the first chunk the first driver has started.
SP=/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad
P=$SP/g1-ev/native-progress.txt
tac $SP/g1-chunks.txt | while read -r id file method; do
  grep -q "^$id finished" $P && continue
  grep -q "^$id started" $P && break
  same=$(diff -rq /home/user/wt/g1/app $SP/g1-mut/app >/dev/null && diff -rq /home/user/wt/g1/tests $SP/g1-mut/tests >/dev/null && echo identical || echo DIFFERENT)
  echo "$id started $(date -u +%FT%TZ) head=$(git -C /home/user/wt/g1 rev-parse --short=8 HEAD) runner=copy-g1-mut copy_app_tests=$same db=vaseyaudio_mutg1 load=$(cut -d' ' -f1 /proc/loadavg) cmd=g1-mysql.sh --filter '/::$method\$/' $file" >> $P
  start=$(date +%s)
  DIR=$SP/g1-mut DBNAME=vaseyaudio_mutg1 $SP/g1-mysql.sh --log-junit $SP/g1-ev/chunk-$id.xml --filter "/::$method\$/" "$file" > $SP/g1-ev/chunk-$id.txt 2>&1; rc=$?
  sum=$(python3 -c "import xml.etree.ElementTree as E,sys;r=E.parse(sys.argv[1]).getroot()[0];print(' '.join(k+'='+r.get(k) for k in ['tests','assertions','failures','errors','skipped']))" $SP/g1-ev/chunk-$id.xml 2>/dev/null)
  echo "$id finished $(date -u +%FT%TZ) exit=$rc seconds=$(( $(date +%s) - start )) $sum $method runner=copy" >> $P
done
echo "driver2 done $(date -u +%FT%TZ)" >> $P
