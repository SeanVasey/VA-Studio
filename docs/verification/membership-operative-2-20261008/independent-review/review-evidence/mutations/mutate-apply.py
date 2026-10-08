#!/usr/bin/env python3
"""Apply one mutation from mutate.py's table (no test run). Usage: mutate-apply.py <worktree> <id>. Prints the git diff."""
import os, subprocess, sys
sys.argv_saved = list(sys.argv)
W, mid = sys.argv[1], sys.argv[2]
src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'mutate.py')).read()
table = src[src.index('MUTS = ['):src.index('\n]\n', src.index('MUTS = [')) + 3]
B = 'app/Domain/Memberships/Billing/'; T = 'tests/Feature/ProductionMembershipBilling/'
ns = {'B': B, 'T': T}
exec(table, ns)
for m, path, old, new, _ in ns['MUTS']:
    if m == mid:
        p = os.path.join(W, path); s = open(p).read()
        assert s.count(old) == 1
        open(p, 'w').write(s.replace(old, new))
        print(subprocess.run(['git', '-C', W, 'diff', '--', path], capture_output=True, text=True).stdout)
        sys.exit(0)
sys.exit('unknown mutation '+mid)
