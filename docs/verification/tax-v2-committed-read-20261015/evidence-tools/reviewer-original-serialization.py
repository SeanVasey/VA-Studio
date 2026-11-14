from pathlib import Path,PurePosixPath
import subprocess,json,tarfile,hashlib,xml.etree.ElementTree as E,datetime,re,collections
repo=Path('/workspace/VA-Studio-tax-v2-committed-read');packet=repo/'docs/verification/tax-v2-committed-read-20261015';head='789ecf4abf23220059d2b4fd83cd7b5f7a77cfd8';base='ebdf56a1a74849133d662dfe19336062b058fb9a';operative='e455c2871ade602653bd0f59b20e151b57afe57d'
def sha(b):return hashlib.sha256(b).hexdigest()
def canonical(v):return json.dumps(v,sort_keys=True,separators=(',',':')).encode()
def git(*args):return subprocess.check_output(['git',*args],cwd=repo)
assert git('rev-parse','HEAD').decode().strip()==head and not git('status','--porcelain=v1').strip()
index=json.loads((packet/'recorded-evidence-index.json').read_text());arc=packet/'recorded-evidence.tar.gz'
with tarfile.open(arc,'r:gz') as t:
 infos=t.getmembers();assert len({m.name for m in infos})==len(infos)
 assert all(m.isfile() and not m.name.startswith('/') and '..' not in PurePosixPath(m.name).parts for m in infos)
 members={m.name:t.extractfile(m).read() for m in infos}
assert len(members)==index['archive_member_count']==2666
assert {n:sha(b) for n,b in members.items()}==index['member_sha256']
assert sha(arc.read_bytes())==index['archive_sha256'] and arc.stat().st_size==index['archive_bytes']
def get(n):return json.loads(members[n])
inventories={};oid_expected={};bindings=0
for bundle in index['source_inventories']:
 ident=get(bundle+'/identity.json');manifest=get(bundle+'/manifest.json');runtime=get(bundle+'/runtime.json');public=get(bundle+'/public-environment.json')
 assert sha(canonical(manifest))==ident['manifest_sha256'] and sha(canonical(runtime))==ident['runtime_sha256'] and sha(canonical(public))==ident['public_environment_sha256'] and sha(members[bundle+'/runner.py'])==ident['runner_sha256']
 tree={}
 for row in git('ls-tree','-r','-z',ident['head']).split(b'\0'):
  if row:
   prefix,path=row.split(b'\t',1);mode,kind,oid=prefix.decode().split();tree[path.decode()]=(kind,oid)
 for path,digest in manifest.items():
  kind,oid=tree[path];assert kind=='blob';assert oid not in oid_expected or oid_expected[oid]==digest;oid_expected[oid]=digest
  assert sha(members['source-blobs/'+digest])==digest;bindings+=1
 inventories[bundle]={'head':ident['head'],'count':len(manifest),'routes':sum(p.startswith('routes/') for p in manifest),'loader_count':len(runtime['autoload']),'manifest':manifest,'identity':ident,'runtime':runtime,'public':public}
assert len(inventories)==index['source_inventory_count']==15
out=git('cat-file','--batch') if not oid_expected else subprocess.run(['git','cat-file','--batch'],cwd=repo,input=''.join(n+'\n' for n in oid_expected).encode(),stdout=subprocess.PIPE,check=True).stdout
pos=0
for oid,digest in oid_expected.items():
 end=out.index(b'\n',pos);hdr=out[pos:end].decode().split();size=int(hdr[2]);b=out[end+1:end+1+size];pos=end+2+size
 assert hdr[0]==oid and hdr[1]=='blob' and sha(b)==digest and b==members['source-blobs/'+digest]
blob_names=[n for n in members if n.startswith('source-blobs/')];assert len(blob_names)==len(set(oid_expected.values()))==index['source_blob_count']==2326
oracle_names={'old-reentrant-admission-gap-valid-fixture','old-late-consumer-gap','old-reopened-root-callback-gap','old-foreign-record-metadata-gap','old-peer-connection-metadata-gap','old-primary-offset-metadata-gap','old-environment-descriptor-gap-activated','old-facade-cache-terminal-gap','old-primary-config-terminal-gap','old-primary-database-coercion-gap','old-primary-prefix-coercion-gap'}
harness_names={'sqlite-expanded-lifecycle','old-reentrant-admission-gap','sqlite-expanded-repair-validation','sqlite-whole-tax-with-real-source-age'}
records=[];cases_by_run={};failures={};skip_count=0
for inv in index['invocations']:
 n=inv['name'];c=get(n+'/command.json');r=get(n+'/result.json');l=get('launch-'+n+'.json');o=get('observed-os-exit-'+n+'.json');bundle=inventories[r['bundle']]
 assert inv['head']==bundle['head']==c['source_before']['head']==l['source']['head']==r['source_after']['head']
 assert l['source']==c['source_before']==r['source_after'] and c['before_git_equal'] and not c['overlays']
 assert all(r[k] for k in ('source_unchanged','source_head_unchanged','source_status_unchanged','runner_unchanged','autoload_unchanged'))
 assert sha(members[n+'/log.txt'])==r['log_sha256'] and int(members[n+'/process.exit'])==r['process_exit']==inv['process_exit']
 assert l['pid']==o['pid'] and str(l['process_start_ticks'])==str(o['start_ticks']) and o['state']=='Z' and all(u==l['uid'] for u in o['process_uids'])
 assert o['exit']==r['verifier_exit']==inv['verifier_exit']==inv['observed_recorder_os_exit'] and o['raw_wait_status']==o['exit']*256
 assert c['before_digest']==r['after_digest']==bundle['identity']['manifest_sha256'] and c['source_input_count']==r['source_input_count']==bundle['count']
 assert l['runner_sha256']==bundle['identity']['runner_sha256']==sha(members['run-focused.py']) and l['launcher_sha256']==sha(members['launch-durable.py'])
 if r['junit'] is None:
  assert n=='old-reentrant-admission-gap' and members[n+'/junit.xml']==b'' and r['process_exit']==255 and r['verifier_exit']==1 and r['junit_error'] is not None
  cases=[];actual=None;skipped=None
 else:
  xml=E.fromstring(members[n+'/junit.xml']);cases=list(xml.iter('testcase'));top=xml if xml.tag=='testsuite' else xml.find('testsuite')
  actual={'tests':len(cases),'assertions':sum(int(v.attrib.get('assertions',0)) for v in cases),'errors':sum(v.find('error') is not None for v in cases),'failures':sum(v.find('failure') is not None for v in cases),'skipped':sum(v.find('skipped') is not None for v in cases)}
  assert actual==r['junit']==inv['counts']=={k:int(top.attrib[k]) for k in actual}
  pol='scripts/ci/database-sqlite-skips.json' if c['public_environment']['DB_CONNECTION']=='sqlite' else 'scripts/ci/database-mysql-skips.json'
  allow={tuple(v) for v in json.loads(members['source-blobs/'+bundle['manifest'][pol]])['methods']}
  skipped=[[v.attrib['class'],v.attrib['name'].split(' with data set ',1)[0]] for v in cases if v.find('skipped') is not None]
  assert skipped==inv['skipped_methods'] and all(tuple(v) in allow for v in skipped);skip_count+=len(skipped)
  if n in oracle_names:assert actual['failures']==1 and actual['errors']==actual['skipped']==0 and r['process_exit']==r['verifier_exit']==1
  elif n in harness_names:assert actual['failures']==1 and actual['errors']==0 and r['process_exit']==r['verifier_exit']==1
  else:assert actual['failures']==actual['errors']==0 and r['process_exit']==r['verifier_exit']==0
  failures[n]=[v.text for v in xml.iter('failure')]
 cases_by_run[n]=cases
 records.append({'name':n,'head':inv['head'],'counts':actual,'php':r['process_exit'],'recorder':r['verifier_exit'],'os':o['exit'],'classification':'behavioral oracle' if n in oracle_names else 'harness/expectation' if n in harness_names else 'completed selection','skipped_methods':skipped})
assert len(records)==index['actual_php_process_launches']==25 and sum(v['counts'] is not None for v in records)==index['executed_testcase_invocations']==24
assert {v['name'] for v in records if v['classification']=='behavioral oracle'}==oracle_names and {v['name'] for v in records if v['classification']=='harness/expectation'}==harness_names
prep=index['preparation_failures'];assert len(prep)==index['pre_php_preparation_failures']==1
p=prep[0];l=get('launch-'+p['name']+'.json');pr=get('preparation-'+p['name']+'.json');assert pr==p and p['launch']==l and sha(members['launch-'+p['name']+'.log'])==p['original_log_sha256'] and p['observed_recorder_os_exit']==1
assert not any(n.startswith(p['name']+'/') for n in members) and b'FileNotFoundError' in members['launch-'+p['name']+'.log'] and b'php' in members['launch-'+p['name']+'.log']
process=get('process-reconciliation-final.json');assert process['all_original_processes_closed'] and len(process['records'])==26
by_name={r['name']:r for r in process['records']}
for r in records:
 x=by_name[r['name']];assert x['php']==r['php'] and x['recorder']==r['recorder'] and x['os']==r['os'] and x['junit']==r['counts'] and x['source_unchanged']
current_inv=next(i for i in index['invocations'] if i['name']=='sqlite-facade-and-container-repair');cm=inventories[current_inv['bundle']]['manifest'];loaders=inventories[current_inv['bundle']]['runtime']['autoload']
assert all(sha((repo/p).read_bytes())==h for p,h in cm.items()) and all(sha((repo/p).read_bytes())==h for p,h in loaders.items())
assert len(cm)==index['current_input_count']==2351 and sum(p.startswith('routes/') for p in cm)==index['route_input_count']==16 and len(loaders)==index['loader_hash_count']==13
assert not git('diff','--name-only',operative,head,'--',*cm).strip()
assert subprocess.run(['git','merge-base','--is-ancestor',index['candidate_head'],head],cwd=repo).returncode==0 and not git('diff','--name-only',index['candidate_head'],head,'--',*cm).strip()
carry=get('e455-narrow-source-carry.json');assert carry['current_source']==operative
carry_records=[]
for c in carry['comparisons']:
 old_inv=next(i for i in index['invocations'] if i['name']==c['earlier_invocation']);om=inventories[old_inv['bundle']]['manifest'];diff={p:[om.get(p),cm.get(p)] for p in sorted(set(om)|set(cm)) if om.get(p)!=cm.get(p)}
 assert diff==c['differences'] and len(om)-len(diff)==c['identical_inputs']==2349 and len(om)==c['declared_inputs']==2351
 patch=members[c['exact_patch']];assert sha(patch)==c['patch_sha256'] and patch==git('diff',c['earlier_source'],operative,'--',*sorted(diff))
 carry_records.append({'invocation':c['earlier_invocation'],'from':c['earlier_source'],'to':operative,'identical':2349,'different_paths':sorted(diff),'exact_patch_sha256':sha(patch)})
skips=get('sqlite-b1-exact-skip-reconciliation.json');whole=cases_by_run['sqlite-whole-tax-final-source'];actual_skips=[{'class':c.attrib['class'],'method':c.attrib['name'].split(' with data set ',1)[0],'reason':c.find('skipped').attrib.get('message','')} for c in whole if c.find('skipped') is not None]
assert skips['skips']==actual_skips and skips['skip_count']==25 and skips['reported_cases']==211 and skips['executed_non_skipped_cases']==186 and skips['all_exact_declared_methods']
age=[c.attrib for c in whole if 'original_source_age' in c.attrib['name']];assert len(age)==1 and float(age[0]['time'])>300 and age[0]['assertions']=='7'
quality=[]
for name in ('pint-e455c287.json','policy-shards-e455c287.json'):
 q=get(name);assert q['head']==operative and q['exit']==0 and q['files_unchanged'] and q['head_unchanged'] and q['status_clean'] and all(sha((repo/p).read_bytes())==h==cm[p] for p,h in q['source_sha256'].items())
 if name.startswith('pint'):assert len(q['source_sha256'])==10 and json.loads(q['stdout'])=={'tool':'pint','result':'passed'} and '--test' in q['argv']
 else:assert 'Ran 52 tests' in q['stderr'] and '\nOK\n' in q['stderr']
 quality.append({'receipt':name,'exit':0,'head':operative,'source_hash_count':len(q['source_sha256']),'sha256':sha(members[name])})
stop=get('mysql-server-stop.json');assert stop['process_exit']==0 and stop['argv']==['docker','stop','va-studio-private-mysql-tax-v2-committed-read-20261015']
review_copies={}
for p in packet.iterdir():
 if p.name.startswith('independent-'):
  digest=sha(p.read_bytes());matches=[n for n,b in members.items() if n.startswith('independent-originals/') and sha(b)==digest];assert matches,(p.name,digest)
  review_copies[p.name]={'sha256':digest,'matching_original_members':matches}
changed=git('diff','--name-only',base,head).decode().splitlines();app_changes=[p for p in changed if p.startswith(('app/','config/','tests/','scripts/ci/'))]
source=(repo/'app/Domain/Commerce/ProductionTaxCheckout/ProductionTaxPaidOrderSourceV2.php').read_text();old_source=git('show',base+':app/Domain/Commerce/ProductionTaxCheckout/ProductionTaxPaidOrderSourceV2.php').decode()
def line_bytes(s):return s[s.index('    public function line('):s.index('    public function proveRetainedCurrent(')]
# Use exact unchanged line/lineCount segment endpoints from the final source.
line_start=source.index('    public function line(');line_end=source.index('    private static function birthContext(',line_start) if '    private static function birthContext(' in source[line_start:] else None
# Diff must show no changes in line/lineCount; compare each method via balanced braces.
def method(s,name):
 start=s.index('    public function '+name+'(');b=s.index('{',start);depth=1;i=b+1
 while depth:
  depth+= (s[i]=='{')-(s[i]=='}');i+=1
 return s[start:i].encode()
assert method(source,'line')==method(old_source,'line') and method(source,'lineCount')==method(old_source,'lineCount')
config=(repo/'config/production-tax-checkout.php').read_text();assert "'committed_read_receipts_enabled' => false" in config and "'enabled' => false" in config and "'provider' => null" in config
for forbidden in ('app/Domain/Commerce/ProductionCheckout/','app/Domain/Commerce/ProductionPolicy/','app/Domain/Customers/','database/migrations/','routes/','.github/workflows/'):
 assert not any(p.startswith(forbidden) for p in changed)
report={'recorded_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'reviewed_head':head,'application_source':operative,'base':base,'decision':'PENDING FINAL WRITTEN DISPOSITION','checks_passed':True,'archive':{'sha256':sha(arc.read_bytes()),'bytes':arc.stat().st_size,'members':len(members),'member_set_and_hashes_match_index':True,'safe_regular_unique_members':True,'index_sha256':sha((packet/'recorded-evidence-index.json').read_bytes()),'index_packaging_head':index['candidate_head'],'index_packaging_head_is_ancestor_with_identical_current_inputs':True},'source':{'inventory_count':len(inventories),'path_bindings':bindings,'git_blob_object_count':len(oid_expected),'sha256_object_count':len(blob_names),'all_blobs_equal_actual_git_bytes':True,'current_input_count':len(cm),'routes':16,'loaders':13,'current_local_and_git_source_carry':True,'inventories':[{k:v for k,v in x.items() if k in ('head','count','routes','loader_count')}|{'bundle':n} for n,x in inventories.items()],'application_changed_paths':app_changes,'line_sha256':sha(method(source,'line')),'line_count_sha256':sha(method(source,'lineCount')),'line_bytes_unchanged':True,'forbidden_surfaces_unchanged':True},'invocations':records,'counts':{'actual_php_process_launches':25,'executed_junit_invocations':24,'behavioral_oracle_failures':11,'harness_expectation_failures':4,'completed_passing_selections':10,'pre_php_setup_failures':1,'original_recorders_closed':26,'all_original_skip_instances_reconciled':skip_count},'pre_php_failure':p,'whole_b1':{'cases':211,'assertions':1523,'skipped':25,'non_skipped':186,'age_case':age[0]},'narrow_carry':carry_records,'quality':quality,'native_ready':get('mysql-server-ready.json'),'native_version':get('mysql-server-version.json'),'native_stop':stop,'original_review_copies':review_copies,'failure_details':failures,'commands_performed':'Read-only Git tree/blob/diff inspection and Python tar/hash/JSON/XML receipt reconciliation only. No app/test/database/CI/host/network operation or tracked edit.'}
path=Path('/workspace/runtime/tax-v2-cr-final-packet-789ecf4a-20261015.working.json')
path.write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps({k:report[k] for k in ('archive','counts','whole_b1','quality')},indent=2));print('SOURCE',bindings,len(oid_expected),len(blob_names),len(cm),len(review_copies));print('WORKING',path,sha(path.read_bytes()))
