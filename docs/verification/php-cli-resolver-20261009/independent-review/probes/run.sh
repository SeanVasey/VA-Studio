#!/bin/bash
# Independent review evidence wrapper.
# Usage: run.sh NAME CMD...  -> writes evidence/NAME.txt with the command, cwd, UTC date, combined output and rc.
EV=/home/user/rv-m16/docs/verification/php-cli-resolver-20261009/independent-review/evidence
name=$1
shift
{
    printf '$'
    printf ' %q' "$@"
    printf '\n# cwd: %s  date: %s\n' "$(pwd)" "$(date -u +%FT%TZ)"
} > "$EV/$name.txt"
"$@" >> "$EV/$name.txt" 2>&1
rc=$?
echo "# rc=$rc" >> "$EV/$name.txt"
tail -n "${TAIL:-15}" "$EV/$name.txt"
exit 0
