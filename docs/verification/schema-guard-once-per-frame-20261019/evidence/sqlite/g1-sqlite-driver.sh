#!/bin/bash
# Runs every chunk of g1-sqlite-chunks.txt not yet finished in g1-ev/sqlite-progress.txt (SQLite in-memory), one process at a time.
SP=/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad
P=$SP/g1-ev/sqlite-progress.txt
cd /home/user/wt/g1
while read -r id files; do
  grep -q "^$id finished" $P 2>/dev/null && continue
  echo "$id started $(date -u +%FT%TZ) head=$(git rev-parse --short=8 HEAD) srcdiff=$(git diff HEAD -- app tests | sha256sum | cut -c1-12) files=$(echo $files | wc -w)" >> $P
  start=$(date +%s)
  $SP/g1-sqlite.sh --log-junit $SP/g1-ev/sqlite-$id.xml $files > $SP/g1-ev/sqlite-$id.txt 2>&1; rc=$?
  sum=$(python3 -c "import xml.etree.ElementTree as E,sys;r=E.parse(sys.argv[1]).getroot()[0];print(' '.join(k+'='+r.get(k) for k in ['tests','assertions','failures','errors','skipped']))" $SP/g1-ev/sqlite-$id.xml 2>/dev/null)
  echo "$id finished $(date -u +%FT%TZ) exit=$rc seconds=$(( $(date +%s) - start )) $sum" >> $P
done < $SP/g1-sqlite-chunks.txt
echo "all done $(date -u +%FT%TZ)" >> $P
