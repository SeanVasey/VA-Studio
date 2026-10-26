import collections, re, sys
line_re = re.compile(r'^(\S+)\s+(\d+)\s+([A-Za-z ]+?)\t(.*)$')
ev = []
for raw in open(sys.argv[1], errors='replace'):
    m = line_re.match(raw.rstrip('\n'))
    if m: ev.append([m.group(1), int(m.group(2)), m.group(3).strip(), m.group(4)])
    elif ev: ev[-1][3] += ' ' + raw.strip()
start = next(i for i,e in enumerate(ev) if 'va-lat-start' in e[3]); th = ev[start][1]
end = next(i for i,e in enumerate(ev) if 'va-lat-end' in e[3])
seg = [e for e in ev[start+1:end] if e[1]==th and e[2] in ('Query','Execute')]
def kind(s):
    u = s.upper()
    if u.startswith('SHOW CREATE TABLE'): return 'show_create_table'
    if u.startswith('SHOW CREATE TRIGGER'): return 'show_create_trigger'
    if u.startswith('SHOW'): return 'show_other'
    if 'INFORMATION_SCHEMA' in u: return 'information_schema'
    if u.startswith('SAVEPOINT') or u.startswith('RELEASE'): return 'savepoint'
    if u.startswith('SELECT DATABASE()'): return 'select_database'
    if 'LIMIT 0' in u: return 'pin_limit0'
    if u.startswith('SELECT CONNECTION_ID()'): return 'token_session'
    if u.startswith('SELECT @@'): return 'session_vars'
    if 'PRAGMA' in u: return 'pragma'
    if u.startswith('SELECT'): return 'data_select'
    return 'other:'+u[:30]
phase = 0; phases = collections.defaultdict(collections.Counter); order=[]
name = 'pre-tx'
for e in seg:
    u = e[3].upper()
    if u.startswith('START TRANSACTION') or u.startswith('BEGIN'):
        phase += 1; name = f'tx{phase}'; 
    phases[name][kind(e[3])] += 1
    phases[name]['TOTAL'] += 1
for n,c in phases.items():
    print(n, dict(c.most_common()))
tot = collections.Counter()
for c in phases.values(): tot.update(c)
print('ALL', dict(tot.most_common()))
sct = collections.Counter(re.sub(r'\s+',' ',e[3])[:70] for e in seg if e[3].upper().startswith('SHOW CREATE TABLE'))
print('top show create', sct.most_common(12))
info = collections.Counter(re.sub(r"'[^']*'","?",re.sub(r'\s+',' ',e[3]))[:110] for e in seg if 'information_schema' in e[3].lower())
print('top info', info.most_common(8))
ds = collections.Counter(re.sub(r"'[^']*'","?",re.sub(r'\s+',' ',e[3]))[:90] for e in seg if kind(e[3])=='data_select')
print('top data', ds.most_common(10))
