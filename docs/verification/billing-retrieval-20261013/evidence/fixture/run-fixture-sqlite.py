import hashlib,json,os,pathlib,subprocess,sys,time,xml.etree.ElementTree as ET
root=pathlib.Path('/workspace/VA-Studio-billing-retrieval-fixtures')
out=pathlib.Path('/workspace/runtime/billing-retrieval-fixture-evidence')
name=sys.argv[1]
prefix=out/name
files=sys.argv[2:]
paths=[]
for directory in ['app','bootstrap','config','database','resources/contracts','tests']:
 paths += [p for p in (root/directory).rglob('*') if p.is_file() and '/cache/' not in str(p) and (directory=='resources/contracts' or p.suffix=='.php')]
paths += [root/'composer.lock',root/'phpunit.xml']
paths=sorted(set(paths)); hashes=lambda:{str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest() for p in paths}
argv=['php','-d','memory_limit=512M','-r','$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";','--',*files,'--stop-on-error','--stop-on-failure','--log-junit',str(prefix)+'.xml']
env={'TMPDIR':'/tmp/va-c3fix','APP_ENV':'testing','APP_DEBUG':'false','DB_URL':'','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:','DB_SOCKET':''}
pathlib.Path(env['TMPDIR']).mkdir(exist_ok=True)
source={'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'integration_reference':'bea13e9cf4f6d50fe4c51023186afd6e846ccefc','integration_tree_identical':subprocess.run(['git','diff','--quiet','bea13e9cf4f6d50fe4c51023186afd6e846ccefc','HEAD'],cwd=root).returncode==0,'worktree_status':subprocess.check_output(['git','status','--short'],cwd=root,text=True),'argv':argv,'environment':env,'files':hashes(),'runner_sha256':hashlib.sha256(pathlib.Path(__file__).read_bytes()).hexdigest(),'started_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())}
pathlib.Path(str(prefix)+'.source.json').write_text(json.dumps(source,indent=2)+'\n')
start=time.monotonic()
with pathlib.Path(str(prefix)+'.log').open('w') as log:
 try: code=subprocess.run(argv,cwd=root,env={**os.environ,**env},stdout=log,stderr=subprocess.STDOUT,timeout=1800).returncode
 except subprocess.TimeoutExpired: code=124;log.write('\nRunner timeout at1800 seconds.\n')
pathlib.Path(str(prefix)+'.exit').write_text(str(code)+'\n')
after=hashes(); result={'exit':code,'duration_seconds':round(time.monotonic()-start,3),'source_count':len(after),'changed_sources':[p for p,h in source['files'].items() if after.get(p)!=h]}
xml=pathlib.Path(str(prefix)+'.xml')
if xml.exists():
 x=ET.parse(xml).getroot(); result['junit']=x.attrib or list(x)[0].attrib
pathlib.Path(str(prefix)+'.result.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result));sys.exit(code)
