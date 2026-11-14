import datetime, hashlib, json, subprocess, zipfile
from pathlib import Path
repo=Path('/workspace/VA-Studio-tax-v2-committed-read')
runtime=Path('/workspace/runtime/account-features-http-evidence-20261015')
out=Path('/workspace/runtime/b4-http-staging/sealed-public-evidence')
assert not out.exists()
out.mkdir()
sha256=lambda b:hashlib.sha256(b).hexdigest()
write=lambda p,x:p.write_text(json.dumps(x,indent=2,sort_keys=True)+'\n')
results=sorted(runtime.glob('*/result.json'))
active=[p for p in runtime.iterdir() if p.is_dir() and (p/'command.json').exists() and not (p/'result.json').exists()]
assert not active, 'Close all original checks before sealing'
index=[]; members={}; source_bindings={}; trees={}; blobs={}; loaders={}
for path in results:
 run=path.parent;result=json.loads(path.read_text());command=json.loads((run/'command.json').read_text())
 assert result['source_unchanged'] and result['autoload_unchanged'] and result['runner_unchanged']
 closure=json.loads((run/'supervisor-os-exit.json').read_text())
 assert closure['identity_matched'] and closure['observed_os_exit']==result['verifier_exit']
 original={p.name:{'sha256':sha256(p.read_bytes()),'bytes':p.stat().st_size} for p in run.iterdir() if p.is_file()}
 index.append({'name':run.name,'head':result['source_after']['head'],'junit':result['junit'],'php_exit':result['process_exit'],'recorder_exit':result['verifier_exit'],'observed_os_exit':closure['observed_os_exit'],'originals':original,'full_failure_logs_location':str(run),'failure_logs_publicly_copied':False})
 for name in ['command.json','result.json','process.exit','verifier.exit','supervisor-os-exit.json']:
  p=run/name
  if p.exists():members['runs/'+run.name+'/'+name]=p.read_bytes()
 # Full failures can contain private runtime object graphs. Keep those originals on host,
 # authenticate their hashes/counts, and never put them into the public source packet.
 if result['process_exit']==0:
  for name in ['junit.xml','log.txt']:
   members['runs/'+run.name+'/'+name]=(run/name).read_bytes()
 bundle=runtime/command['bundle']
 identity=json.loads((bundle/'identity.json').read_text());manifest=json.loads((bundle/'manifest.json').read_text());rt=json.loads((bundle/'runtime.json').read_text())
 head=identity['head']
 if head not in trees:
  tree={}
  for entry in subprocess.check_output(['git','ls-tree','-rz',head],cwd=repo).split(b'\0'):
   if not entry:continue
   descriptor,name=entry.split(b'\t',1);mode,kind,oid=descriptor.decode().split()
   if kind=='blob':tree[name.decode()]=oid
  trees[head]=tree
 bindings={}
 for name,expected in manifest.items():
  oid=trees[head].get(name)
  assert oid, (head,name,'Declared input lacks tracked Git blob')
  if oid not in blobs:blobs[oid]=subprocess.check_output(['git','cat-file','blob',oid],cwd=repo)
  assert sha256(blobs[oid])==expected,(head,name,'Declared input differs')
  bindings[name]={'git_blob':oid,'sha256':expected}
 source_bindings[head]=bindings
 for name,expected in rt['autoload'].items():
  body=(repo/name).read_bytes();assert sha256(body)==expected,(head,name,'Current physical loader differs from retained original')
  loaders[name]={'sha256':expected,'bytes':len(body)};members['physical-loaders/'+name]=body
 for p in bundle.iterdir():
  if p.is_file():members['bundles/'+bundle.name+'/'+p.name]=p.read_bytes()
 for p in runtime.glob('launch-'+run.name+'.*'):members['launches/'+p.name]=p.read_bytes()
for oid,body in blobs.items():members['git-blobs/'+oid]=body
members['source-bindings.json']=(json.dumps(source_bindings,sort_keys=True,indent=2)+'\n').encode()
members['execution-index.json']=(json.dumps(index,sort_keys=True,indent=2)+'\n').encode()
members['physical-loaders-index.json']=(json.dumps(loaders,sort_keys=True,indent=2)+'\n').encode()
for pattern in ['mysql-*.json','*durable*.py','run-focused*.py','close-supervisor.py']:
 for p in runtime.glob(pattern):members['runtime-receipts/'+p.name]=p.read_bytes()
for p in Path('/workspace/runtime/b4-http-staging').glob('*original.json'):
 members['quality/'+p.name]=p.read_bytes()
 log=p.with_suffix('.log')
 if log.exists():members['quality/'+log.name]=log.read_bytes()
for name in ['npm-ci-original.log','typecheck-after-install-original.log','typecheck-b903-tests-original.log','frontend-first-original.log','uncoordinated-http-changes-original.patch','epoch-fix-preserved-head.txt']:
 p=Path('/workspace/runtime/b4-http-staging')/name
 if p.exists():members['preparation/'+name]=p.read_bytes()
archive=out/'evidence.zip'
with zipfile.ZipFile(archive,'x',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
 for name,body in sorted(members.items()):z.writestr(name,body)
record={'created_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'archive_sha256':sha256(archive.read_bytes()),'archive_bytes':archive.stat().st_size,'members':len(members),'completed_invocations':len(index),'tracked_heads':len(source_bindings),'deduplicated_git_blobs':len(blobs),'physical_loader_files':len(loaders),'executions':index,'member_sha256':{name:sha256(body) for name,body in sorted(members.items())},'limits':'Source-bound focused development evidence; full failure logs/XML retained privately by hash, no full matrix/host/provider/commercial acceptance.'}
write(out/'receipt.json',record)
print({k:v for k,v in record.items() if k not in ['executions','member_sha256']})
