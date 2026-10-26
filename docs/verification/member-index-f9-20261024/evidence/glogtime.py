import collections, re, sys
from datetime import datetime
sys.path.insert(0,'.')
line_re = re.compile(r'^(\S+)\s+(\d+)\s+([A-Za-z ]+?)\t(.*)$')
ev=[]
for raw in open(sys.argv[1], errors='replace'):
    m=line_re.match(raw.rstrip('\n'))
    if m: ev.append([m.group(1),int(m.group(2)),m.group(3).strip(),m.group(4)])
    elif ev: ev[-1][3]+=' '+raw.strip()
ts=lambda s: datetime.fromisoformat(s.replace('Z','+00:00')).timestamp()
s=next(i for i,e in enumerate(ev) if 'va-lat-start' in e[3]); th=ev[s][1]
e=next(i for i,x in enumerate(ev) if 'va-lat-end' in x[3])
seg=[x for x in ev[s:e+1] if x[1]==th and x[2] in ('Query','Execute')]
def kind(q):
    u=q.upper()
    if u.startswith('SHOW CREATE TABLE'): return 'show_create_table'
    if u.startswith('SHOW CREATE TRIGGER'): return 'show_create_trigger'
    if u.startswith('SHOW'): return 'show_other'
    if 'INFORMATION_SCHEMA' in u: return 'information_schema:'+ (re.search(r'INFORMATION_SCHEMA\.(\w+)',u).group(1))
    if u.startswith('SAVEPOINT') or u.startswith('RELEASE'): return 'savepoint'
    if u.startswith('SELECT DATABASE()'): return 'select_database'
    if 'LIMIT 0' in u: return 'pin_limit0'
    if u.startswith('SELECT CONNECTION_ID()'): return 'token_session'
    if u.startswith('SELECT @@'): return 'session_vars'
    if u.startswith('SELECT CASE WHEN'): return 'history_read'
    if u.startswith('SELECT'): return 'data_select'
    return 'other'
t=collections.Counter(); n=collections.Counter()
for a,b in zip(seg,seg[1:]):
    k=kind(a[3]); t[k]+=ts(b[0])-ts(a[0]); n[k]+=1
tot=sum(t.values())
for k,v in t.most_common(): print(f'{k:40s} n={n[k]:6d} s={v:7.2f} ({100*v/tot:4.1f}%) ms/each={1000*v/n[k]:.2f}')
print('total', round(tot,2))
