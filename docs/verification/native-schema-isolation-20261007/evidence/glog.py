import sys,re,collections
# Usage: glog.py <general.log> <start HH:MM:SS> <end HH:MM:SS>; ranks prepared/queried SQL in the window and tags the owning component.
log,start,end=sys.argv[1],sys.argv[2],sys.argv[3]
cnt=collections.Counter(); tot=0
for line in open(log,errors='replace'):
    m=re.match(r'\S+T(\d\d:\d\d:\d\d)\S*Z\s+(\d+)\s+(\w+)\t(.*)',line)
    if not m: continue
    ts,tid,cmd,sql=m.groups()
    if not (start<=ts<end) or cmd not in ('Prepare','Query'): continue
    tot+=1
    cnt[re.sub(r"'[^']*'","?",sql)[:140]]+=1
print('statements',tot)
for k,v in cnt.most_common(int(sys.argv[4]) if len(sys.argv)>4 else 45): print(v,k)
