import sys, re, collections
path, start, end = sys.argv[1], sys.argv[2], sys.argv[3]
cnt = collections.Counter(); tim = collections.Counter(); prev=None; prevkey=None
norm = lambda q: re.sub(r"'[^']*'", "?", re.sub(r"\b\d+\b", "N", q))[:150]
with open(path, errors='replace') as f:
    for line in f:
        if not line.startswith('2026'): continue
        ts = line[:27]
        if ts < start: continue
        if ts > end: break
        parts = line.rstrip('\n').split('\t')
        if len(parts) < 3: continue
        cmd = parts[1].split()[-1]; arg = parts[2]
        if cmd != 'Execute' and cmd != 'Query': continue
        key = norm(arg.strip())
        cnt[key]+=1
total=sum(cnt.values()); print('total',total)
for k,v in cnt.most_common(40): print(v, k)
