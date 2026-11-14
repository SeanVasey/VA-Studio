import sys,re,gzip,collections
"""Compare the non-schema ("business") statement multiset of the n-th owned membership transaction in two general logs."""
def txns(path):
    lines=gzip.open(path,'rt',errors='replace').read().split('\n')
    m=re.compile(r'^\d{4}-\d\d-\d\dT(\d\d):(\d\d):([\d.]+)Z\s+(\d+)\s+([A-Za-z ]+?)\t(.*)$')
    ev=[];cur=None
    for l in lines:
        mm=m.match(l)
        if mm: cur=[mm.group(4),mm.group(5).strip(),mm.group(6)]; ev.append(cur)
        elif cur is not None: cur[2]+=' '+l.strip()
    seen=set();out=[]
    for i,e in enumerate(ev):
        if e[1]=='Query' and e[2].startswith('SAVEPOINT va_membership_award_') and e[2] not in seen:
            seen.add(e[2]); th=e[0]
            j=next((k for k in range(i,len(ev)) if ev[k][0]==th and ev[k][1]=='Query' and re.match(r'(COMMIT|ROLLBACK)\b',ev[k][2])),None)
            if j is None: continue
            out.append([x for x in ev[i:j+1] if x[0]==th])
    return out
SCHEMA=re.compile(r"information_schema|^SHOW |SELECT DATABASE\(\)|SAVEPOINT|LIMIT 0$|@@SESSION|CONNECTION_ID\(\)|^SELECT id FROM `production_identity_\w+` LIMIT 1$",re.I)
def norm(t):
    t=re.sub(r"'(?:[^'\\]|\\.)*'","?",t); t=re.sub(r'\b[0-9a-f]{32,}\b','H',t); t=re.sub(r'\b\d+\b','N',t); return t
def business(seg):
    return collections.Counter(norm(x[2]) for x in seg if x[1] in ('Query','Execute') and not SCHEMA.search(x[2].strip()))
a=txns(sys.argv[1]); b=txns(sys.argv[2])
for n in range(min(len(a),len(b))):
    ca,cb=business(a[n]),business(b[n])
    print('txn %d: business statements base=%d head=%d identical_multiset=%s' % (n, sum(ca.values()), sum(cb.values()), ca==cb))
    if ca!=cb:
        for k in set(ca)|set(cb):
            if ca[k]!=cb[k]: print('   diff base=%d head=%d %s' % (ca[k],cb[k],k[:160]))
if len(sys.argv) > 3:
    n=int(sys.argv[3]); ca,cb=business(a[n]),business(b[n])
    over={k:(ca[k],cb[k]) for k in ca if ca[k]>cb[k] and not re.match(r'(COMMIT|ROLLBACK)',k)}
    print('txn %d: base business multiset contained in head (excluding the end statement): %s; base-only=%s' % (n, not over, over))
