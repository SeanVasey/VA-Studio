#!/bin/bash
SP=/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad; D=$SP/g1-fix/sq; P=$D/progress.txt
while read -r id files; do
  grep -q "^$id finished" $P 2>/dev/null && continue
  echo "$id started $(date -u +%FT%TZ) head=$(git -C /home/user/wt/g1 rev-parse --short=8 HEAD) dirty=$(git -C /home/user/wt/g1 status --porcelain -- app tests composer.lock | wc -l)" >> $P
  start=$(date +%s)
  $SP/g1-fix/sqlite.sh /home/user/wt/g1 --log-junit $D/$id.xml $files > $D/$id.txt 2>&1; rc=$?
  sum=$(python3 -c "import xml.etree.ElementTree as E,sys;r=E.parse(sys.argv[1]).getroot()[0];print(' '.join(k+'='+r.get(k) for k in ['tests','assertions','failures','errors','skipped']))" $D/$id.xml 2>/dev/null)
  echo "$id finished $(date -u +%FT%TZ) exit=$rc seconds=$(( $(date +%s) - start )) $sum" >> $P
done < $D/chunks.txt
echo "all done $(date -u +%FT%TZ)" >> $P
