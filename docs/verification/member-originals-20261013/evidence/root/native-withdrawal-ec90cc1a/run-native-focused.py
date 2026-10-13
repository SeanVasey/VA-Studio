import argparse,hashlib,json,os,pathlib,subprocess,sys,time,xml.etree.ElementTree as ET
p=argparse.ArgumentParser(); p.add_argument('--name',required=True);p.add_argument('--driver',choices=['sqlite','mysql'],required=True);p.add_argument('--filter');p.add_argument('--overlay');p.add_argument('--database',default='vaseyaudio_member_originals_root');p.add_argument('files',nargs='+');a=p.parse_args()
root=pathlib.Path('/workspace/VA-Studio-member-originals'); out=pathlib.Path('/workspace/runtime/member-originals-root-evidence'); out.mkdir(exist_ok=True); prefix=out/a.name
if any(out.glob(a.name+'.*')): raise SystemExit('Existing evidence retained; choose a new label after diagnosis.')
paths=[]
for d in ['app','bootstrap','config','database','resources/contracts','scripts','tests/Support']:
 paths.extend(x for x in (root/d).rglob('*') if x.is_file() and '/cache/' not in str(x))
paths.extend(root/x for x in a.files);paths.extend(root/x for x in ['composer.lock','phpunit.xml'])
paths=sorted(set(paths)); hashes=lambda:{str(x.relative_to(root)):hashlib.sha256(x.read_bytes()).hexdigest() for x in paths}
source={'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'files':hashes()}
source['git_status']=subprocess.check_output(['git','status','--porcelain'],cwd=root,text=True)
source['git_bound_files']={}
for path,digest in source['files'].items():
 try: blob=subprocess.check_output(['git','show',source['head']+':'+path],cwd=root,stderr=subprocess.DEVNULL);source['git_bound_files'][path]=hashlib.sha256(blob).hexdigest()==digest
 except subprocess.CalledProcessError: source['git_bound_files'][path]=False
if not all(source['git_bound_files'].values()): raise SystemExit('Input differs from exact committed source; freeze before root verification.')
source['autoload_sha256']=hashlib.sha256((root/'vendor/autoload.php').read_bytes()).hexdigest()
code='$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php";'
if a.overlay:
 code+='require "vendor/autoload.php"; require '+json.dumps(a.overlay)+';'
 source['overlay']={'path':a.overlay,'sha256':hashlib.sha256(pathlib.Path(a.overlay).read_bytes()).hexdigest()}
code+='require "vendor/phpunit/phpunit/phpunit";'
cmd=['php','-d','memory_limit=512M','-r',code,'--',*a.files,'--log-junit',str(prefix)+'.xml']
if a.filter: cmd+=['--filter',a.filter]
cmd+=['--stop-on-defect']
env_delta={'TMPDIR':'/tmp/va-studio-member-originals-20261013','APP_ENV':'testing','APP_DEBUG':'false','DB_URL':'','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','DB_CONNECTION':a.driver,'DB_DATABASE':':memory:' if a.driver=='sqlite' else a.database,'DB_SOCKET':'' if a.driver=='sqlite' else '/workspace/runtime/mysql-policy-run/mysql.sock','DB_HOST':'127.0.0.1','DB_PORT':'33413','DB_USERNAME':'root','DB_PASSWORD':''}
env_delta['VA_MEMBERSHIP_RESERVATION_RACE_EVIDENCE_DIRECTORY']='/workspace/runtime/member-originals-root-evidence/native-reservation-workers'
env_delta['VA_MEMBERSHIP_DATABASE_INTERVAL_RACE_EVIDENCE_DIRECTORY']=str(out/(a.name+'-interval-workers'))
source['runner_sha256']=hashlib.sha256(pathlib.Path(__file__).read_bytes()).hexdigest()
source.update({'argv':cmd,'environment':env_delta,'started_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())})
pathlib.Path(env_delta['TMPDIR']).mkdir(exist_ok=True,mode=0o700)
pathlib.Path(str(prefix)+'.source.json').write_text(json.dumps(source,indent=2)+'\n')
started=time.monotonic()
with pathlib.Path(str(prefix)+'.log').open('w') as log:
 try: result=subprocess.run(cmd,cwd=root,env={**os.environ,**env_delta},stdout=log,stderr=subprocess.STDOUT,timeout=1800);code=result.returncode
 except subprocess.TimeoutExpired: code=124;log.write('\nRunner timeout after1800 seconds.\n')
pathlib.Path(str(prefix)+'.exit').write_text(str(code)+'\n')
after=hashes();summary={'exit':code,'duration_seconds':round(time.monotonic()-started,3),'changed_sources':[x for x in source['files'] if source['files'][x]!=after.get(x)]}
xml=pathlib.Path(str(prefix)+'.xml')
if xml.exists():
 tree=ET.parse(xml);suites=tree.getroot();summary['junit']=suites.attrib if suites.attrib else (list(suites)[0].attrib if len(suites) else {})
pathlib.Path(str(prefix)+'.result.json').write_text(json.dumps(summary,indent=2)+'\n');print(json.dumps(summary));sys.exit(code)
