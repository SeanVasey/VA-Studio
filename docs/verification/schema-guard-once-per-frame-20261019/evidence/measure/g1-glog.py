import sys,re,collections
# Summarize every owned membership transaction (first SAVEPOINT va_membership_award_*) in a MySQL general log.
lines=open(sys.argv[1],errors='replace').read().split('\n')
m=re.compile(r'^\d{4}-\d\d-\d\dT(\d\d):(\d\d):([\d.]+)Z\s+(\d+)\s+([A-Za-z ]+?)\t(.*)$')
ev=[]; cur=None
for l in lines:
    mm=m.match(l)
    if mm:
        cur=[int(mm.group(1))*3600+int(mm.group(2))*60+float(mm.group(3)),mm.group(4),mm.group(5).strip(),mm.group(6)]
        ev.append(cur)
    elif cur is not None: cur[3]+=' '+l.strip()
seen=set()
def norm(c,t):
    t=re.sub(r"'(?:[^'\\]|\\.)*'","?",t); t=re.sub(r'\b[0-9a-f]{32,}\b','H',t); t=re.sub(r'\b\d+\b','N',t)
    return (c+' '+t)[:120]
for i,e in enumerate(ev):
    if e[2]=='Query' and e[3].startswith('SAVEPOINT va_membership_award_') and e[3] not in seen:
        seen.add(e[3]); th=e[1]
        j=next((k for k in range(i,len(ev)) if ev[k][1]==th and ev[k][2]=='Query' and re.match(r'(COMMIT|ROLLBACK)\b',ev[k][3])),None)
        if j is None: continue
        seg=[x for x in ev[i:j+1] if x[1]==th]
        cnt=collections.Counter(norm(x[2],x[3]) for x in seg)
        cmds=collections.Counter(x[2] for x in seg)
        info=sum(1 for x in seg if 'information_schema' in x[3])
        sp=sum(1 for x in seg if x[2]=='Query' and re.match(r'(RELEASE )?SAVEPOINT ',x[3]))
        print('TXN %.1fs statements=%d end=%s commands=%s information_schema=%d savepoint_ops=%d' % (ev[j][0]-e[0],len(seg),ev[j][3][:8],dict(cmds),info,sp))
        for k,v in cnt.most_common(12): print('   %6d %s'%(v,k))
        ex=[x for x in seg if x[2] in ('Execute','Query')]
        markers={'identity_inspect(SHOW CREATE users)':lambda t:t.startswith('SHOW CREATE TABLE `users`'),
                 'identityrows_guard(SHOW CREATE audit_events)':lambda t:'SHOW CREATE TABLE' in t and 'audit_events' in t,
                 'membership_schema(membership_lookup)':lambda t:'membership_lookup' in t,
                 'select_database':lambda t:t.strip()=='SELECT DATABASE()',
                 'information_schema_any':lambda t:'information_schema' in t,
                 'data_rows(production_membership_*/member_* FROM)':lambda t:re.search(r'FROM\s+`?[a-z_]*`?\.?`?production_(membership|member)_',t) is not None and 'information_schema' not in t}
        for name,f in markers.items(): print('   marker %-50s %d' % (name, sum(1 for x in ex if f(x[3]))))
