from pathlib import Path
import datetime, hashlib, json, os, re, shutil, subprocess, time
ROOT = Path('/workspace/VA-Studio-member-originals')
OUT = Path('/workspace/runtime/member-originals-quality-evidence/native-budget-followup')
OLD = OUT.parent
HEAD = '32f083d28db0609a52cade793849a8767a855ae3'
FILES = ['app/Domain/Grants/Member/MemberOriginalInspection.php', 'app/Domain/Memberships/Production/MembershipReservationContext.php', 'tests/Feature/ProductionMemberOriginals/MemberOriginalCompositionTest.php', 'tests/Feature/ProductionMemberOriginals/MemberOriginalIssuerOwnershipTest.php']
ENV = {'PYTHONDONTWRITEBYTECODE':'1', 'APP_ENV':'testing', 'APP_DEBUG':'false', 'DB_CONNECTION':'mysql', 'DB_HOST':'127.0.0.1', 'DB_PORT':'1', 'DB_SOCKET':'', 'DB_DATABASE':'vaseyaudio_discovery_only', 'DB_USERNAME':'synthetic_discovery_only', 'DB_PASSWORD':'', 'DB_URL':'', 'CACHE_STORE':'array', 'SESSION_DRIVER':'array', 'QUEUE_CONNECTION':'sync'}
def digest(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def write(name, obj): (OUT/name).write_text(json.dumps(obj,indent=2)+'\n')
def git(*args): return subprocess.check_output(['git',*args],cwd=ROOT,text=True)
assert git('rev-parse','HEAD').strip() == HEAD
assert git('status','--short') == ''
assert sorted(git('diff','HEAD^','HEAD','--name-only').splitlines()) == sorted(FILES)
tracked = git('ls-files','-z').split('\0')
prefixes = ('app/','bootstrap/','config/','database/','scripts/','tests/','.github/','resources/')
paths = sorted(p for p in tracked if p and (p.startswith(prefixes) or p in ['.gitlab-ci.yml','composer.json','composer.lock','phpunit.xml']))
sources = {p:digest(ROOT/p) for p in paths}
metadata_paths=['vendor/bin/phpunit','vendor/bin/pint','vendor/autoload.php','vendor/composer/autoload_classmap.php','vendor/composer/autoload_psr4.php','vendor/composer/autoload_static.php','vendor/composer/autoload_real.php']
metadata={p:digest(ROOT/p) for p in metadata_paths}
oldfiles={str(p.relative_to(OLD)):digest(p) for p in OLD.rglob('*') if p.is_file() and not p.is_relative_to(OUT)}
assert len(oldfiles)==88
write('prior-release-before.json',{'files':oldfiles,'count':len(oldfiles)})
write('source-before.json',{'head':HEAD,'cwd':str(ROOT),'git_status':'','sources':sources,'local_runner_metadata':metadata,'selected_php':FILES,'selected_php_sha256':{p:sources[p] for p in FILES}})
versions=[]
for argv in [['php','--version'],['python3','--version'],['php','vendor/bin/phpunit','--version']]:
 result=subprocess.run(argv,cwd=ROOT,env={**os.environ,**ENV},capture_output=True,text=True)
 versions.append({'argv':argv,'exit':result.returncode,'stdout':result.stdout,'stderr':result.stderr})
 assert result.returncode == 0
write('versions.json',versions)
def check():
 return git('rev-parse','HEAD').strip()==HEAD and {p:digest(ROOT/p) for p in sources}==sources and {p:digest(ROOT/p) for p in metadata}==metadata
assert check()
def run(name,argv):
 assert check()
 command={'head':HEAD,'cwd':str(ROOT),'argv':argv,'environment':ENV,'runner_sha256':digest(Path(__file__)),'source_receipt_sha256':digest(OUT/'source-before.json'),'started_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
 write(name+'.command.json',command)
 started=time.monotonic()
 with (OUT/(name+'.log')).open('wb') as log:
  try: code=subprocess.run(argv,cwd=ROOT,env={**os.environ,**ENV},stdout=log,stderr=subprocess.STDOUT,timeout=600).returncode
  except subprocess.TimeoutExpired: log.write(b'\nRUNNER TIMEOUT after 600 seconds\n');code=124
 (OUT/(name+'.exit')).write_text(str(code)+'\n')
 result={'exit':code,'elapsed_seconds':round(time.monotonic()-started,3),'head_after':git('rev-parse','HEAD').strip(),'source_count':len(sources),'sources_and_metadata_unchanged':check()}
 write(name+'.result.json',result)
 print(name, json.dumps(result), flush=True)
 assert result['sources_and_metadata_unchanged']
 return code
style=run('pint-four',['vendor/bin/pint','--test',*FILES])
names=[f'phpunit-ci-mysql-{i}.xml' for i in range(1,25)]+[f'phpunit-ci-mysql-{i}-tests.xml' for i in range(1,25)]+['phpunit-ci-mysql-source-tests.xml','phpunit-ci-mysql-manifest.json']
assert len(names)==50 and len(set(names))==50
assert all(not (ROOT/n).exists() and n not in tracked for n in names)
write('generated-before.json',{'prefix':'phpunit-ci-mysql','expected_names':names,'all50_absent':True,'all50_untracked':True,'other_prefix_paths':sorted(p.name for p in ROOT.glob('phpunit-ci-mysql*'))})
discovery=run('native-discovery',['python3','scripts/ci/phpunit-shards.py','--shards','24','--prefix','phpunit-ci-mysql','--timings','scripts/ci/phpunit-timings-mysql.json','--mysql-native-selection'])
archive=OUT/'generated';archive.mkdir()
created=[n for n in names if (ROOT/n).is_file()]
artifacts={}
for name in created:
 shutil.copyfile(ROOT/name,archive/name)
 artifacts[name]=digest(ROOT/name)
 assert digest(archive/name)==artifacts[name]
write('generated-artifacts.json',{'expected_count':50,'actual_count':len(created),'discovery_exit':discovery,'files':artifacts})
for name in created:
 assert digest(ROOT/name)==artifacts[name]
 (ROOT/name).unlink()
write('generated-cleanup.json',{'deleted_exact_names':created,'all_deleted_names_absent':all(not (ROOT/n).exists() for n in created),'archived_hashes_verified':True,'unrelated_files_deleted':False,'git_status_after':git('status','--short')})
assert check()
write('source-after.json',{'head':git('rev-parse','HEAD').strip(),'sources':{p:digest(ROOT/p) for p in sources},'local_runner_metadata':{p:digest(ROOT/p) for p in metadata},'unchanged':True})
oldafter={str(p.relative_to(OLD)):digest(p) for p in OLD.rglob('*') if p.is_file() and not p.is_relative_to(OUT)}
write('prior-release-after.json',{'count':len(oldafter),'unchanged':oldafter==oldfiles,'files':oldafter})
assert oldafter==oldfiles
summary={'head':HEAD,'pint_exit':style,'pint_php_files':len(FILES),'discovery_exit':discovery,'generated_archived_and_deleted':len(created),'source_count':len(sources),'sources_and_metadata_unchanged':check(),'old88_release_unchanged':True,'policy_selftests_rerun':False,'application_test_bodies_executed':False}
if discovery==0:
 m=json.loads((archive/'phpunit-ci-mysql-manifest.json').read_text())
 summary['census']={'source_cases':m['source_test_cases'],'source_files':m['source_files'],'selected_cases':m['selection']['test_cases'],'selected_files':m['selection']['files'],'shards':len(m['shards']),'nonempty_shards':sum(x['test_cases']>0 for x in m['shards']),'untimed_files':len(m['weighting']['untimed_files'])}
 assert len(created)==50 and summary['census']['nonempty_shards']==24
write('summary.json',summary)
print(json.dumps(summary,indent=2),flush=True)
raise SystemExit(style or discovery)
