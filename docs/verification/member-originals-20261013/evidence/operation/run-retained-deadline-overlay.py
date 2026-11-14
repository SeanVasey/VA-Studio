from pathlib import Path
import datetime,hashlib,json,os,subprocess,sys,time,xml.etree.ElementTree as ET
root=Path('/workspace/VA-Studio-member-original-operation');out=Path('/workspace/runtime/member-original-operation-evidence');name=sys.argv[1];tests=sys.argv[2:]
if any(out.glob(name+'.*')): raise SystemExit('Existing evidence retained; choose a new run name.')
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
paths=[]
for directory in ['app','bootstrap','config','database','tests']:
 paths.extend(p for p in (root/directory).rglob('*.php') if '/cache/' not in str(p))
paths.extend(p for p in (root/'resources/contracts').rglob('*') if p.is_file())
paths.extend([root/'scripts/render-member-original.php',root/'composer.json',root/'composer.lock',root/'phpunit.xml']);paths=sorted(set(paths))
hashes=lambda:{str(p.relative_to(root)):sha(p) for p in paths}
av=['php','-d','memory_limit=512M','-r','$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php";require getcwd()."/vendor/autoload.php";require "/workspace/runtime/member-original-operation-evidence/retained-deadline-old-inspection.php.txt";require "vendor/phpunit/phpunit/phpunit";','--','--do-not-cache-result','--colors=never',*tests,'--log-junit',str(out/(name+'.xml'))]
env={'APP_ENV':'testing','APP_DEBUG':'false','DB_URL':'','DB_HOST':'127.0.0.1','DB_PORT':'1','DB_SOCKET':'','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync'}
before=hashes();source={'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'argv':av,'cwd':str(root),'environment':env,'source':before,'runner_sha256':sha(Path(__file__)),'overlay':{'source_commit':'5bbebd776ca6cce2be4fd0d427f3baa926c2676d','class':'App\\Domain\\Grants\\Member\\MemberOriginalInspection','path':'/workspace/runtime/member-original-operation-evidence/retained-deadline-old-inspection.php.txt','sha256':sha(Path('/workspace/runtime/member-original-operation-evidence/retained-deadline-old-inspection.php.txt'))},'started_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()};(out/(name+'.source.json')).write_text(json.dumps(source,indent=2)+'\n')
start=time.monotonic()
with (out/(name+'.log')).open('wb') as log:r=subprocess.run(av,cwd=root,env={**os.environ,**env},stdout=log,stderr=subprocess.STDOUT)
(out/(name+'.exit')).write_text(str(r.returncode)+'\n');after=hashes();result={'exit':r.returncode,'seconds':round(time.monotonic()-start,3),'source_count':len(before),'changed_sources':[p for p in before if before[p]!=after.get(p)]}
x=out/(name+'.xml')
if x.exists():
 try:
  tree=ET.parse(x).getroot();result['junit']=tree.attrib or list(tree)[0].attrib
 except ET.ParseError as error:result['junit_parse_error']=str(error)
(out/(name+'.result.json')).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result));print((out/(name+'.log')).read_text());raise SystemExit(r.returncode)
