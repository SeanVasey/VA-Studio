from pathlib import Path
import argparse,datetime,hashlib,json,os,subprocess,time,xml.etree.ElementTree as ET
p=argparse.ArgumentParser();p.add_argument('--name',required=True);p.add_argument('--old-source',action='store_true');a=p.parse_args()
root=Path('/workspace/VA-Studio-member-catalog-currentness');out=Path('/workspace/runtime/membership-catalog-currentness-evidence');setup=json.loads((out/'native-setup.json').read_text());database='vaseyaudio_member_catalog_20261013'
assert setup['database']==database and setup['database_created'] and setup['version']=='8.4.11'
assert setup['host_port']==33413 and setup['host_socket']=='/workspace/runtime/mysql-policy-run/mysql.sock'
def save(suffix,data):(out/(a.name+suffix)).write_text(json.dumps(data,indent=2,sort_keys=True)+'\n')
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
paths=[]
for directory in ['app','bootstrap','config','database','resources/contracts','tests']:
 paths.extend(x for x in (root/directory).rglob('*') if x.is_file() and '/cache/' not in str(x) and (directory=='resources/contracts' or x.suffix=='.php'))
paths.extend([root/'composer.lock',root/'phpunit.xml']);paths=sorted(set(paths));hashes=lambda:{str(x.relative_to(root)):sha(x) for x in paths}
code='$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/autoload.php"; '
overlay=None
if a.old_source:
 f=out/'native-red-planned-source/app/Domain/Memberships/Production/MembershipCatalogEvidence.php'
 old=subprocess.check_output(['git','show','0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371:app/Domain/Memberships/Production/MembershipCatalogEvidence.php'],cwd=root)
 assert f.read_bytes()==old
 code+='require '+json.dumps(str(f))+'; '
 overlay={'class':'App\\Domain\\Memberships\\Production\\MembershipCatalogEvidence','file':str(f),'sha256':sha(f),'exact_source_commit':'0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371','scope':'Single historical class preloaded, all other source uses candidate. New helper unused by this old class.'}
code+='require "vendor/phpunit/phpunit/phpunit";'
files=['tests/Feature/ProductionMembershipBilling/MembershipNativeCatalogCurrentnessTest.php']
argv=['php','-d','memory_limit=512M','-r',code,'--',*files]
if a.old_source:argv+=['--filter','test_unrelated_auto_allocated_insert_preserves_original_proof_but_selected_row_change_refuses']
else:argv+=['tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php','--filter','test_unrelated_auto_allocated_insert_preserves_original_proof_but_selected_row_change_refuses|test_real_table_comment_change_is_not_erased_by_counter_projection_in_committed_read_only_frame|test_identical_row_local_temporary_shadow_remains_refused_after_a_positive_original_proof|test_private_bytes_are_rechecked_without_minting_a_new_readiness_deadline|test_catalog_snapshot_is_verified_nonlocking_inside_committed_read_only_frame']
argv+=['--stop-on-error','--stop-on-failure','--log-junit',str(out/(a.name+'.xml'))]
env={'TMPDIR':'/tmp/va-member-catalog-20261013','APP_ENV':'testing','APP_DEBUG':'false','DB_URL':'','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','DB_CONNECTION':'mysql','DB_DATABASE':database,'DB_SOCKET':setup['host_socket'],'DB_HOST':'127.0.0.1','DB_PORT':'33413','DB_USERNAME':'root','DB_PASSWORD':''}
Path(env['TMPDIR']).mkdir(exist_ok=True)
before=hashes();record={'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'status':subprocess.check_output(['git','status','--short'],cwd=root,text=True),'argv':argv,'cwd':str(root),'environment':env,'source':before,'overlay':overlay,'mysql_setup_sha256':sha(out/'native-setup.json'),'runner_sha256':sha(Path(__file__)),'started_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()};save('.source.json',record)
start=time.monotonic()
with (out/(a.name+'.log')).open('wb') as log:
 try:r=subprocess.run(argv,cwd=root,env={**os.environ,**env},stdout=log,stderr=subprocess.STDOUT,timeout=5400);exitcode=r.returncode
 except subprocess.TimeoutExpired:log.write(b'\nRunner reached 5400 second bound.\n');exitcode=124
(out/(a.name+'.exit')).write_text(str(exitcode)+'\n');after=hashes();result={'exit':exitcode,'duration_seconds':round(time.monotonic()-start,3),'source_count':len(before),'changed_sources':[k for k in set(before)|set(after) if before.get(k)!=after.get(k)],'head_after':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'completed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
x=out/(a.name+'.xml')
if x.exists() and x.stat().st_size:
 tree=ET.parse(x).getroot();result['junit']=tree.attrib or list(tree)[0].attrib;result['leaves']=[dict(t.attrib,failures=len(t.findall('failure')),errors=len(t.findall('error')),skips=len(t.findall('skipped'))) for t in tree.iter('testcase')]
save('.result.json',result);save('.source-check.json',{'before_count':len(before),'after_count':len(after),'changed_sources':result['changed_sources'],'overlay_unchanged':not overlay or sha(Path(overlay['file']))==overlay['sha256']});print(json.dumps(result));raise SystemExit(exitcode)
