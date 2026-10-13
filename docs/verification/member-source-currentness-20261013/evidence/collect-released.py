"""Copy only released hash-pinned public receipts; no application or DB execution."""
from pathlib import Path
import argparse,gzip,hashlib,json
HERE=Path(__file__).resolve().parent
args=argparse.ArgumentParser();args.add_argument('--new-only',action='store_true');args=args.parse_args()
sha=lambda b:hashlib.sha256(b).hexdigest()
allowed={'APP_ENV','APP_DEBUG','DB_CONNECTION','DB_DATABASE','DB_HOST','DB_PORT','DB_SOCKET','CACHE_STORE','SESSION_DRIVER','QUEUE_CONNECTION','TMPDIR'}
def project(v,omitted,path=''):
 if isinstance(v,dict):
  out={}
  for k,x in v.items():
   child=path+'/'+k
   if k in ('environment','env') and isinstance(x,dict):
    out[k]={a:b for a,b in x.items() if a in allowed};omitted.extend(child+'/'+a for a in x if a not in allowed)
   else:out[k]=project(x,omitted,child)
  return out
 if isinstance(v,list):return [project(x,omitted,path+'/'+str(i)) for i,x in enumerate(v)]
 return v
prior={}
if args.new_only and (HERE/'archive-index.json').exists():prior={x['path']:x for x in json.loads((HERE/'archive-index.json').read_text())['files']}
index=[]
for a in json.loads((HERE/'release-plan.json').read_text())['artifacts']:
 source=Path(a['runtime_path']);target=HERE/a['path'];assert target.resolve().is_relative_to(HERE)
 assert source.suffix not in ('.sqlite','.db','.env') and source.name!='connection.json'
 if a['path'] in prior:
  x=prior[a['path']];b=target.read_bytes();assert sha(b)==x['archived_sha256'] and len(b)==x['archived_bytes'];assert a['released_sha256']==x['original_sha256'];index.append(x);continue
 raw=source.read_bytes();assert sha(raw)==a['released_sha256'],source
 omitted=[];plain=raw
 if source.suffix=='.json':
  safe=project(json.loads(raw),omitted)
  if omitted:plain=(json.dumps(safe,indent=2)+'\n').encode()
 archived=gzip.compress(plain,mtime=0) if a.get('gzip') else plain
 target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(archived)
 index.append({'path':a['path'],'runtime_path':str(source),'original_sha256':sha(raw),'original_bytes':len(raw),'public_payload_sha256':sha(plain),'public_payload_bytes':len(plain),'archived_sha256':sha(archived),'archived_bytes':len(archived),'compression':'gzip mtime=0' if a.get('gzip') else None,'projection':'environment allowlist' if omitted else None,'omitted_json_paths':omitted})
(HERE/'archive-index.json').write_text(json.dumps({'scope':'Public released artifact copies. Compressed full source receipts preserve original public inventory; credential environment fields are projected with original/copy hashes. No private DB/key/row files.','files':index},indent=2)+'\n')
print(json.dumps({'archived':len(index),'gzip_full_receipts':sum(x['compression'] is not None for x in index),'environment_projections':sum(x['projection'] is not None for x in index)}))
