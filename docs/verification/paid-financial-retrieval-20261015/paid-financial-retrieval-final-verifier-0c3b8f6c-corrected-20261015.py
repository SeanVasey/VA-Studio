#!/usr/bin/env python3
"""Independent read-only archive/Git/current-input/receipt verifier; never executes archive/app tools."""
import argparse,hashlib,json,re,subprocess,tarfile,xml.etree.ElementTree as ET
from pathlib import Path,PurePosixPath
parser=argparse.ArgumentParser();parser.add_argument('--head',default='d172b006c376902bd84ce6d7fa5893ed42c5b7f5');parser.add_argument('--output',required=True);args=parser.parse_args()
repo=Path('/workspace/VA-Studio-tax-v2-committed-read'); packet=repo/'docs/verification/paid-financial-retrieval-20261015'; tested='f77f5c12e590b2d9295ebff582b142b4091ff8c2'
def sha(v):return hashlib.sha256(v).hexdigest()
def canonical(v):return json.dumps(v,sort_keys=True,separators=(',',':')).encode()
def git(*a):return subprocess.check_output(['git','-C',str(repo),*a])
def require(v,message):
 if not v:raise AssertionError(message)
require(git('rev-parse','HEAD').decode().strip()==args.head,'actual HEAD mismatch')
require(not git('status','--porcelain').decode().strip(),'candidate not clean')
index_path=packet/'tested-f77f5c12-recorded-evidence-index.json'; index=json.loads(index_path.read_text()); archive=packet/index['archive']; raw=archive.read_bytes()
require(len(raw)==index['archive_bytes']==7787263 and sha(raw)==index['archive_sha256']=='07d49cc84657e24018f91f36aa2fb831a7c2d1b41bfa7467af2aacccf2315e37','archive identity')
with tarfile.open(archive) as tar:
 members=tar.getmembers(); names=[m.name for m in members]
 require(len(names)==len(set(names))==index['members']==2571,'member count/duplicates')
 for m in members:
  pp=PurePosixPath(m.name);require(m.isfile() and not pp.is_absolute() and '..' not in pp.parts,'unsafe/nonregular member')
 data={m.name:tar.extractfile(m).read() for m in members}
 require(set(data)==set(index['digests']),'index coverage')
 for n,d in data.items():require(sha(d)==index['digests'][n],f'member SHA {n}')
def js(n):return json.loads(data[n])
bindings=js('git-source-bindings.json'); require(set(bindings)==set(index['heads']) and len(bindings)==5,'source heads')
source_members={n for n in data if n.startswith('source-blobs/')}; loader_members={n for n in data if n.startswith('loader-blobs/')}
require(len(source_members)==index['source_blob_count']==2392,'source object count')
# Map immutable Git paths to unique blob IDs, then hash each Git byte body once.
objects={}; refs={}
for h,paths in bindings.items():
 require(len(h)==40,'short source head')
 tree={}
 for record in git('ls-tree','-rz','--full-tree',h).split(b'\0'):
  if not record:continue
  meta,path=record.split(b'\t',1);mode,kind,oid=meta.decode().split();tree[path.decode()]=(kind,oid)
 for p,wanted in paths.items():
  require(p in tree and tree[p][0]=='blob',f'Git source missing {h}:{p}')
  oid=tree[p][1];refs[(h,p)]=oid;objects[oid]=None
  require('source-blobs/'+wanted in source_members,f'archive source missing {p}')
unique=sorted(objects); result=subprocess.run(['git','-C',str(repo),'cat-file','--batch'],input=('\n'.join(unique)+'\n').encode(),stdout=subprocess.PIPE,check=True).stdout;pos=0
for oid in unique:
 end=result.index(b'\n',pos);header=result[pos:end].decode().split();require(header[0]==oid and header[1]=='blob','Git batch blob')
 length=int(header[2]);body=result[end+1:end+1+length];require(len(body)==length and result[end+1+length:end+2+length]==b'\n','Git batch length')
 objects[oid]=sha(body);pos=end+2+length
require(pos==len(result),'Git batch trailing bytes')
for (h,p),oid in refs.items():require(objects[oid]==bindings[h][p],f'Git blob SHA {h}:{p}')
require(set('source-blobs/'+v for paths in bindings.values() for v in paths.values())==source_members,'unreferenced source object')
require(len(bindings[tested])==2438,'tested input count')
for p,d in bindings[tested].items():require(sha((repo/p).read_bytes())==d,f'current input {p}')
loaders=js('current-loader-bindings.json');require(len(loaders)==index['loader_count']==13,'loader count')
for p,d in loaders.items():
 require('loader-blobs/'+d in loader_members and sha((repo/p).read_bytes())==d,'actual loader '+p)
 require(sha(data['loader-blobs/'+d])==d,'archived loader '+p)
bundles={};
for n in data:
 if re.fullmatch(r'execution/bundle-[^/]+/identity.json',n):
  prefix=n.rsplit('/',1)[0];identity=js(n); manifest=js(prefix+'/manifest.json');runtime=js(prefix+'/runtime.json');env=js(prefix+'/public-environment.json')
  require(manifest==bindings[identity['head']],'bundle manifest Git equality')
  require(identity['manifest_sha256']==sha(canonical(manifest)) and identity['runtime_sha256']==sha(canonical(runtime)) and identity['public_environment_sha256']==sha(canonical(env)),'bundle identity')
  require(identity['runner_sha256']==sha(data[prefix+'/runner.py'])==sha(data['execution/run-focused.py']),'runner body')
  require(runtime['autoload']==loaders,'runtime13loader equality')
  require('APP_KEY' not in env and env['synthetic_testing_app_key_configured'] is True,'secret-safe env')
  require('PHP 8.4.26' in runtime['php'] and '12.5.34' in runtime['phpunit'],'runtime version')
  bundles[prefix.split('/')[-1]]=(identity,manifest,runtime,env)
require(len(bundles)==6,'runtime inventories')
expected={
'retrieval-whole-initial':(22,144,9,2,0,2,1,1),
'retrieval-whole-repaired-toolchain':(57,474,4,0,2,1,1,1),
'recursive-list-regression-red':(1,3,1,0,0,1,1,1),
'retrieval-whole-final':(64,549,1,0,2,1,1,1),
'partial-custody-clock-control':(2,38,0,0,0,0,0,0),
'retrieval-whole-clock-fixture':(64,572,0,0,2,0,0,0),
'retrieval-native-original-controls':(None,None,None,None,None,-15,1,1),
'retrieval-native-positive':(2,40,0,0,0,0,0,0),
'retrieval-native-reopened-frame':(2,18,0,0,0,0,0,0),
'retrieval-native-source-caps':(2,28,0,0,0,0,0,0)}
records={};skip_id='test_native_older_source_deadline_cannot_be_renewed_by_a_late_caller_or_sdk_callback'
for name,wanted in expected.items():
 prefix='execution/'+name; command=js(prefix+'/command.json');rs=js(prefix+'/result.json');os=js(prefix+'/supervisor-os-exit.json');launch=js('execution/launch-'+name+'.json')
 identity,manifest,runtime,env=bundles[rs['bundle']]
 require(command['bundle']==rs['bundle'] and command['cwd']==str(repo) and command['source_before']==rs['source_after']=={'head':identity['head'],'status':''},'run source/cwd '+name)
 require(command['before_git_equal'] and not command['overlays'] and command['selected_files']==['tests/Feature/ProductionFinancialRetrieval/FinancialReadPlanTest.php'],'run selection '+name)
 require(command['source_input_count']==rs['source_input_count']==len(manifest) and command['before_digest']==rs['after_digest']==identity['manifest_sha256'],'run input SHA '+name)
 require(command['public_environment']==env,'run env '+name)
 require(all(rs[k] for k in ['source_unchanged','source_head_unchanged','source_status_unchanged','runner_unchanged','autoload_unchanged']),'run stability '+name)
 require(rs['runner_after_sha256']==launch['runner_sha256']==identity['runner_sha256'] and launch['launcher_sha256']==sha(data['execution/launch-durable.py']),'run tool identity '+name)
 require(rs['log_sha256']==sha(data[prefix+'/log.txt']),'run log SHA '+name)
 require(int(data[prefix+'/process.exit'])==rs['process_exit']==wanted[5] and rs['verifier_exit']==wanted[6],'run exits '+name)
 require(os['observed_os_exit']==wanted[7]==rs['verifier_exit'] and os['identity_matched'] and os['state']=='Z' and os['raw_wait_status']==wanted[7]*256,'OS witness '+name)
 require(os['pid']==launch['pid'] and os['uid']==launch['uid'] and str(os['process_start_ticks'])==str(launch['process_start_ticks']),'launch-bound OS '+name)
 php_argv=json.loads(command['argv'][-1]);require('--do-not-cache-result' in php_argv and command['selected_files'][0] in php_argv,'actual PHPUnit argv '+name)
 if command['filter'] is not None:require(php_argv[php_argv.index('--filter')+1]==command['filter'],'actual filter '+name)
 if wanted[0] is None:
  require(rs['junit'] is None and rs['junit_error'].startswith('ParseError') and data[prefix+'/junit.xml']==b'','interrupted null counts')
  case_rows=[]
 else:
  root=ET.fromstring(data[prefix+'/junit.xml']);cases=root.findall('.//testcase');counts={'tests':len(cases),'assertions':sum(int(x.attrib.get('assertions',0)) for x in cases),'failures':sum(len(x.findall('failure')) for x in cases),'errors':sum(len(x.findall('error')) for x in cases),'skipped':sum(len(x.findall('skipped')) for x in cases)}
  require(tuple(counts[k] for k in ['tests','assertions','failures','errors','skipped'])==wanted[:5] and counts==rs['junit'] and rs['junit_error'] is None,'JUnit '+name)
  skipped=[x.attrib['name'] for x in cases if x.findall('skipped')];require(all(x.startswith(skip_id) for x in skipped),'unexpected skipped identity '+name)
  case_rows=[{'name':x.attrib['name'],'assertions':int(x.attrib.get('assertions',0)),'seconds':float(x.attrib.get('time',0))} for x in cases]
  if name=='retrieval-native-source-caps':require(all(x['seconds']>=300 for x in case_rows),'actual source-age duration')
 records[name]={'head':identity['head'],'counts':rs['junit'],'php_exit':rs['process_exit'],'recorder_exit':rs['verifier_exit'],'observed_os_exit':os['observed_os_exit'],'filter':command['filter'],'driver':runtime['database'],'wall_seconds':rs['wall_seconds'],'cases':case_rows,'source_inputs':len(manifest)}
require(sum(x['recorder_exit']==0 for x in records.values())==5,'five successful original invocations')
setup=js('execution/retrieval-whole-repaired/setup-reconciliation.json');setup_os=js('execution/retrieval-whole-repaired/supervisor-os-exit.json');setup_launch=js('execution/launch-retrieval-whole-repaired.json')
require(setup['application_tests']=='unexecuted' and not setup['junit_exists'] and not setup['result_file_exists'] and setup_os['observed_os_exit']==1,'prePHP preparation')
require(setup_os['pid']==setup_launch['pid'] and setup_os['uid']==setup_launch['uid'] and str(setup_os['process_start_ticks'])==str(setup_launch['process_start_ticks']),'setup launch OS')
require(setup['original_supervisor_log_sha256']==sha(data['execution/launch-retrieval-whole-repaired.log']) and b'FileNotFoundError' in data['execution/launch-retrieval-whole-repaired.log'],'original setup trace')
cheap=[];python_success={};repeat=0
for n in data:
 if n.startswith('cheap/') and n.endswith('.json') and not n.endswith('summary.json'):
  z=js(n);require(z['cwd']==str(repo) and z['source_before']==z['source_after'] and z['source_before']['status']=='','quality source stability')
  entry={'member':n,'argv':z['argv'],'head':z['source_before']['head'],'exit':z['exit']}
  if z['argv'][0]=='python3':
   match=re.search(r'Ran (\d+) tests?',z['stderr']);require(match,'Python test marker');entry['tests']=int(match.group(1))
   if z['exit']==0:
    require(re.search(r'\nOK\s*$',z['stderr']),'Python success marker')
    script=z['argv'][1]
    if script in python_success:repeat+=entry['tests']
    else:python_success[script]=entry['tests']
   else:require('FAILED' in z['stderr'],'failed Python original marker')
  elif z['argv'][:2]==['composer','audit']:
   audit=json.loads(z['stdout']);require(audit['advisories']==[] and audit['abandoned']==[],'current audit')
  elif z['argv'][0]=='vendor/bin/pint':
   pint=json.loads(z['stdout']);require(pint=={'tool':'pint','result':'passed'},'native Pint agent output')
  cheap.append(entry)
require(len(cheap)==17 and sum(x['exit']==0 for x in cheap)==16 and len(python_success)==10 and sum(python_success.values())==263 and repeat==52,'quality counts')
# Verify narrow immutable application carry and separately preserved physical originals.
carry=js('execution/narrow-evidence-carry-f77f5c12.json');require(carry['current_head']==tested and carry['unchanged_count']==len(carry['unchanged_bindings'])==265 and carry['protected_count']==len(carry['original_protected_physical_bindings'])==16,'carry counts')
require(set(carry['explicit_changed_policy_exclusions'])=={'scripts/ci/database-mysql-selection.json','scripts/ci/database-sqlite-skips.json'},'carry exclusions')
for p,wanted in carry['unchanged_bindings'].items():
 require(sha((repo/p).read_bytes())==wanted,'actual carried file '+p)
 for old in [carry['prior_candidate_tested_head'],tested]:require(sha(git('show',old+':'+p))==wanted,'candidate immutable carry '+p)
legacy_exclusions={'app/Domain/Commerce/ProductionFinancialInspection/FinancialExpectation.php','app/Domain/Commerce/ProductionFinancialInspection/FinancialObservation.php','tests/Feature/ProductionFinancialInspection/FinancialCandidateTest.php'}
legacy_bound=0
for p,wanted in carry['unchanged_bindings'].items():
 actual=sha(git('show',carry['prior_sdk_tested_head']+':'+p))
 if p in legacy_exclusions:require(actual!=wanted,'expected prior candidate repair difference '+p)
 else:require(actual==wanted,'legacy immutable carry '+p);legacy_bound+=1
require(legacy_bound==262,'legacy scoped binding count')
for p,wanted in carry['original_protected_physical_bindings'].items():
 require(sha((repo/p).read_bytes())==wanted,'current protected physical '+p)
 if not p.startswith('vendor/'):
  for old in [carry['actual_base_merge'],carry['prior_sdk_tested_head'],tested]:require(sha(git('show',old+':'+p))==wanted,'immutable protected '+p)
old_sqlite=json.loads(git('show',carry['actual_base_merge']+':scripts/ci/database-sqlite-skips.json'))['methods'];current_sqlite=json.loads((repo/'scripts/ci/database-sqlite-skips.json').read_text())['methods'];require(len(old_sqlite)==259 and current_sqlite[:-1]==old_sqlite and current_sqlite[-1]==['Tests\\Feature\\ProductionFinancialRetrieval\\FinancialReadPlanTest',skip_id],'append-only skip')
# Byte-original independent reviewer imports remain distinct from author reconciliation.
imports={
'design-review-aa3d01ac-original':'paid-financial-retrieval-design-review-aa3d01ac-20261015',
'design-review-fd06135d-original':'paid-financial-retrieval-design-review-fd06135d-20261015',
'source-review-3b4d34e6-original':'paid-financial-retrieval-source-review-3b4d34e6-20261015',
'source-review-487f2461-original':'paid-financial-retrieval-source-review-487f2461-20261015',
'source-review-a7963f4e-original':'paid-financial-retrieval-source-review-a7963f4e-20261015',
'partial-custody-review-f77f5c12-original':'paid-financial-retrieval-partial-custody-review-f77f5c12-20261015'}
import_hashes={}
for target,origin in imports.items():
 for suffix in ['md','json']:
  x=packet/(target+'.'+suffix);o=Path('/workspace/runtime')/(origin+'.'+suffix);require(x.read_bytes()==o.read_bytes(),'review original copy '+str(x));import_hashes[x.name]=sha(x.read_bytes())
for n in data:
 if n.startswith('execution/reviewer-originals/'):
  leaf=n.split('/')[-1];copied=packet/(leaf.replace('.md','-original.md').replace('.json','-original.json'));require(data[n]==copied.read_bytes(),'archive reviewer original '+leaf)
server=js('execution/mysql-server-inspected.json')['selected_metadata'];stop=js('execution/mysql-server-stop.json');stopped=js('execution/mysql-server-stopped-state.json');state=json.loads(stopped['stdout']);version=js('execution/mysql-server-version.json')
require(stop['process_exit']==0 and stopped['process_exit']==0 and state['ExitCode']==0 and state['Running'] is False and stop['owned_container_id']==server['id'],'owned server stop')
require(version['process_exit']==0 and '8.4.11\tvaseyaudio_paid_financial_retrieval' in version['stdout'] and server['ports']['3306/tcp']==[{'HostIp':'127.0.0.1','HostPort':'33520'}],'owned server version/port')
# Exact source carry to final docs-only candidate.
current_delta=git('diff','--name-only',tested,args.head).decode().splitlines();require(all(p.startswith('docs/') for p in current_delta),'non-doc tested-source successor')
require(not git('diff','--name-only',tested,args.head,'--','app','bootstrap','config','database','contracts','routes','scripts','tests','resources','.github','composer.json','composer.lock','phpunit.xml').decode().strip(),'operative final source carry')
report={'status':'PASS','reviewed_sha':args.head,'tested_source':tested,'archive_sha256':sha(raw),'archive_bytes':len(raw),'archive_members':len(data),'index_sha256':sha(index_path.read_bytes()),'source_heads':list(bindings),'source_bindings':len(refs),'deduplicated_source_objects':len(source_members),'runtime_inventories':len(bundles),'current_source_inputs':len(bindings[tested]),'route_inputs':sum(p.startswith('routes/') for p in bindings[tested]),'loader_inputs':len(loaders),'original_invocations':records,'original_passes':5,'original_nonzero':5,'prePHP_setup':'separate failed original, no app counts','quality':cheap,'quality_commands':17,'quality_passes':16,'quality_failures':1,'unique_python_tests':sum(python_success.values()),'helper_python_repeat':repeat,'carry_bindings':265,'legacy_sdk_carry_bindings':legacy_bound,'legacy_sdk_scope_exclusions':sorted(legacy_exclusions),'protected_physical_originals':16,'carried_policy_exclusions':carry['explicit_changed_policy_exclusions'],'review_original_hashes':import_hashes,'docs_only_final_delta':current_delta,'owned_mysql':{'version':'8.4.11','port':33520,'container_id':server['id'],'stop_exit':0},'limits':['No broad/native20 prefix or whole native file claim','Only native positive/reopened/sourcecaps6/86; other native controls untested','Current full SQLite64/572 with2 exact native-only skips','V1 partial-custody synthetic new-wrapper real-clock forwarding; Tax genuine decrypt','No current fresh prior13/351 or69/291 or all10 cheap source-independent whole-run claim','Source/loader hashes do not establish whole historical vendor environment or provider/host authentication'],'document_corrections_requested_and_resolved_in_successor':['Numerical CPU observation lacks sealed raw CPU receipt; remove or explicitly label unsealed console observation','f77 carry observed_utc inherited from a796 and predates actual f77 commit; disclose it is not a fresh f77 observation; hashes independently reverified now'],'reviewer_actions':'Offline read-only archive/Git/hash/JUnit/receipt verification; runtime report only; no application/test/DB/network/hosted execution'}
require(git('rev-parse','HEAD').decode().strip()==args.head and not git('status','--porcelain').decode().strip(),'candidate moved during verification')
Path(args.output).write_text(json.dumps(report,indent=2)+'\n');print(json.dumps({k:report[k] for k in ['status','reviewed_sha','archive_members','source_bindings','deduplicated_source_objects','runtime_inventories','current_source_inputs','route_inputs','loader_inputs','original_passes','original_nonzero','quality_commands','quality_passes','quality_failures','unique_python_tests','helper_python_repeat','carry_bindings','protected_physical_originals']}))
