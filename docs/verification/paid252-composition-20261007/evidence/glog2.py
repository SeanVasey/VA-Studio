import sys, collections, datetime
path=sys.argv[1]; windows=[w.split(',') for w in sys.argv[2:]]
def cls(q):
    u=q.upper()
    if u.startswith('SELECT DATABASE()'): return 'select_database'
    if 'INFORMATION_SCHEMA' in u: return 'information_schema'
    if u.startswith('SHOW CREATE'): return 'show_create'
    if 'SQLITE' in u: return 'other'
    return 'data_or_tx'
res={w[0]:collections.Counter() for w in windows}
first={}
with open(path, errors='replace') as f:
    for line in f:
        if not line.startswith('2026'): continue
        parts=line.rstrip('\n').split('\t')
        if len(parts)<3: continue
        cmd=parts[1].split()[-1]
        if cmd not in ('Execute','Query'): continue
        ts=parts[0]
        for name,a,b in windows:
            if a<=ts<=b: res[name][cls(parts[2])]+=1
        if ts>max(w[2] for w in windows): break
for k,c in res.items(): print(k, sum(c.values()), dict(c))
