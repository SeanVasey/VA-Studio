#!/bin/bash
# Runs every chunk of g1-chunks.txt not yet recorded as finished in g1-ev/native-progress.txt, one PHPUnit process at a time.
SP=/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad
P=$SP/g1-ev/native-progress.txt
cd /home/user/wt/g1
while read -r id file method; do
  grep -q "^$id finished" $P 2>/dev/null && continue
  head=$(git rev-parse --short=8 HEAD); src=$(git diff HEAD -- app tests composer.lock | sha256sum | cut -c1-12)
  echo "$id started $(date -u +%FT%TZ) head=$head srcdiff=$src load=$(cut -d' ' -f1 /proc/loadavg) cmd=g1-mysql.sh --filter '/::$method\$/' $file" >> $P
  start=$(date +%s)
  $SP/g1-mysql.sh --log-junit $SP/g1-ev/chunk-$id.xml --filter "/::$method\$/" "$file" > $SP/g1-ev/chunk-$id.txt 2>&1; rc=$?
  sum=$(python3 -c "import xml.etree.ElementTree as E,sys;r=E.parse(sys.argv[1]).getroot()[0];print(' '.join(k+'='+r.get(k) for k in ['tests','assertions','failures','errors','skipped']))" $SP/g1-ev/chunk-$id.xml 2>/dev/null)
  echo "$id finished $(date -u +%FT%TZ) exit=$rc seconds=$(( $(date +%s) - start )) $sum $method" >> $P
done < $SP/g1-chunks.txt
echo "all done $(date -u +%FT%TZ)" >> $P
