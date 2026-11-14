import xml.etree.ElementTree as ET, collections, os
SP='/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad'
chunks=[l.split() for l in open(SP+'/g1-chunks.txt') if l.strip()]
per=collections.OrderedDict(); bad=[]
for cid,f,m in chunks:
    p=f'{SP}/g1-ev/chunk-{cid}.xml'
    if os.path.exists(f'{SP}/g1-ev/chunk-{cid}-rerun.xml'): p=f'{SP}/g1-ev/chunk-{cid}-rerun.xml'
    try: r=ET.parse(p).getroot()
    except ET.ParseError: bad.append((cid,m,'unparseable junit')); continue
    t=dict(tests=0,assertions=0,failures=0,errors=0,skipped=0)
    for c in r.iter('testcase'):
        t['tests']+=1; t['assertions']+=int(c.get('assertions',0))
        if c.find('failure') is not None: t['failures']+=1
        if c.find('error') is not None: t['errors']+=1
        if c.find('skipped') is not None: t['skipped']+=1
    if t['failures'] or t['errors'] or t['skipped'] or t['tests']!=1: bad.append((cid,m,t))
    a=per.setdefault(f.split('/')[-1],dict(chunks=0,tests=0,assertions=0,failures=0,errors=0,skipped=0))
    for k in t: a[k]+=t[k]
    a['chunks']+=1
for f,a in per.items(): print(f, a)
print('TOTAL', {k:sum(a[k] for a in per.values()) for k in ['chunks','tests','assertions','failures','errors','skipped']})
print('non-clean chunks', bad)
