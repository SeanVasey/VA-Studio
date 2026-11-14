from pathlib import Path
import datetime,hashlib,json,os,subprocess,time,xml.etree.ElementTree as ET
root=Path('/workspace/VA-Studio-member-catalog-currentness');out=Path('/workspace/runtime/membership-catalog-currentness-evidence');name='sqlite-eligibility-whole'
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def save(suffix,data):(out/(name+suffix)).write_text(json.dumps(data,indent=2,sort_keys=True)+'\n')
paths=[]
for directory in ['app','bootstrap','config','database','resources/contracts','tests']:
 paths.extend(p for p in (root/directory).rglob('*') if p.is_file() and '/cache/' not in str(p) and (directory=='resources/contracts' or p.suffix=='.php'))
paths.extend([root/'composer.lock',root/'phpunit.xml']);paths=sorted(set(paths));hashes=lambda:{str(p.relative_to(root)):sha(p) for p in paths}
av=['php','-d','memory_limit=512M','-r','$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php";require "vendor/phpunit/phpunit/phpunit";','--','tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php','--stop-on-error','--stop-on-failure','--log-junit',str(out/(name+'.xml'))]
env={'TMPDIR':'/tmp/va-member-catalog-sqlite-20261013','APP_ENV':'testing','APP_DEBUG':'false','DB_URL':'','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:','DB_SOCKET':''};Path(env['TMPDIR']).mkdir(exist_ok=True)
before=hashes();source={'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'argv':av,'environment':env,'cwd':str(root),'source':before,'runner_sha256':sha(Path(__file__)),'started_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()};save('.source.json',source)
start=time.monotonic()
with (out/(name+'.log')).open('wb') as log:r=subprocess.run(av,cwd=root,env={**os.environ,**env},stdout=log,stderr=subprocess.STDOUT,timeout=1800)
(out/(name+'.exit')).write_text(str(r.returncode)+'\n');after=hashes();result={'exit':r.returncode,'seconds':round(time.monotonic()-start,3),'source_count':len(before),'changed_sources':[p for p in before if before[p]!=after.get(p)],'completed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
x=out/(name+'.xml')
if x.exists() and x.stat().st_size:
 tree=ET.parse(x).getroot();result['junit']=tree.attrib or list(tree)[0].attrib;result['leaves']=[dict(t.attrib,failures=len(t.findall('failure')),errors=len(t.findall('error')),skips=len(t.findall('skipped'))) for t in tree.iter('testcase')]
save('.result.json',result);print(json.dumps(result));raise SystemExit(r.returncode)
