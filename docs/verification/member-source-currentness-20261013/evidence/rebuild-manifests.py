"""Offline C4a source/count/digest validation. Never executes application or tests."""
from pathlib import Path
import argparse,collections,gzip,hashlib,json,subprocess,xml.etree.ElementTree as ET
HERE=Path(__file__).resolve().parent
args=argparse.ArgumentParser();args.add_argument('--git-repo');args=args.parse_args()
if args.git_repo is None:args.git_repo=subprocess.check_output(['git','rev-parse','--show-toplevel'],cwd=HERE,text=True).strip()
sha=lambda b:hashlib.sha256(b).hexdigest()
def raw(name):
 b=(HERE/name).read_bytes();return gzip.decompress(b) if name.endswith('.gz') else b
def load(name):return json.loads(raw(name))
def save(name,v):(HERE/name).write_text(json.dumps(v,indent=2)+'\n')
index=load('archive-index.json')
for x in index['files']:
 b=(HERE/x['path']).read_bytes();assert sha(b)==x['archived_sha256'] and len(b)==x['archived_bytes'],x['path']
 p=raw(x['path']);assert sha(p)==x['public_payload_sha256'] and len(p)==x['public_payload_bytes'],x['path']
 if not x['projection']:assert sha(p)==x['original_sha256'],x['path']
registry=load('runs.json');runs=[]
for r in registry['runs']:
 root=ET.fromstring(raw(r['xml']));leaves=[]
 for c in root.iter('testcase'):
  status=next((n for n in ('error','failure','skipped') if c.find(n) is not None),'passed')
  node=None if status=='passed' else c.find(status)
  leaves.append({'identity':c.get('class',c.get('classname',''))+'::'+c.get('name',''),'assertions':int(c.get('assertions','0')),'status':status,'detail':None if node is None else ''.join(node.itertext()).strip()})
 counts={'tests':len(leaves),'assertions':sum(c['assertions'] for c in leaves),'errors':sum(c['status']=='error' for c in leaves),'failures':sum(c['status']=='failure' for c in leaves),'skipped':sum(c['status']=='skipped' for c in leaves),'exit':int(raw(r['exit']).strip())}
 suite=root.find('testsuite') if root.tag=='testsuites' else root
 for key in ['tests','assertions','errors','failures','skipped']:assert counts[key]==int(suite.get(key,'0')),(r['id'],key)
 assert counts==r['expected'],(r['id'],counts,r['expected']);assert (HERE/r['source']).exists()
 runs.append(r|counts|{'cases':leaves})
save('junit-summary.json',{'scope':'Completed invocations including original failures/errors. Counts overlap; no integrated/full-suite claim.','runs':runs})
# Keep a compact, precisely described input projection; the full original public receipt remains archived.
prefixes=('app/','bootstrap/','config/','database/','public/','resources/','routes/','scripts/','tests/','.github/workflows/')
rootnames={'artisan','composer.json','composer.lock','phpunit.xml','phpunit.xml.dist','package.json','package-lock.json','vite.config.ts','tsconfig.json','.gitlab-ci.yml'}
def selected(name):return name.startswith(prefixes) or name in rootnames or name.startswith('phpunit') and name.endswith('.xml')
projections=[];trees={};blobs={}
for b in registry['source_bindings']:
 doc=load(b['source']);full=doc if b['key'] is None else doc[b['key']];assert isinstance(full,dict)
 keep={p:h for p,h in full.items() if selected(p)};excluded=collections.Counter(p.split('/')[0] for p in full if p not in keep)
 ref=b['reference']
 if ref not in trees:
  out=subprocess.check_output(['git','-C',args.git_repo,'ls-tree','-r','-z',ref]);trees[ref]={}
  for line in out.split(b'\0'):
   if not line:continue
   left,path=line.split(b'\t',1);mode,typ,oid=left.decode().split();
   if typ=='blob':trees[ref][path.decode()]=oid
 mismatches=[];missing=[];matched=0
 proc=subprocess.Popen(['git','-C',args.git_repo,'cat-file','--batch'],stdin=subprocess.PIPE,stdout=subprocess.PIPE)
 try:
  for path,expected in keep.items():
   assert isinstance(expected,str) and len(expected)==64,(b['id'],path)
   if path in b['overrides']:actual=sha(raw(b['overrides'][path]))
   else:
    oid=trees[ref].get(path)
    if not oid:missing.append(path);continue
    if oid not in blobs:
     proc.stdin.write((oid+'\n').encode());proc.stdin.flush();head=proc.stdout.readline().decode().split();size=int(head[2]);data=proc.stdout.read(size);assert proc.stdout.read(1)==b'\n';blobs[oid]=sha(data)
    actual=blobs[oid]
   if actual==expected:matched+=1
   else:mismatches.append({'path':path,'captured_sha256':expected,'reference_sha256':actual})
 finally:proc.stdin.close();proc.wait();assert proc.returncode==0
 exact=not mismatches and not missing
 if b['require_exact']:assert exact,(b['id'],mismatches,missing)
 projection={'id':b['id'],'full_receipt':b['source'],'full_receipt_original_sha256':next(x['original_sha256'] for x in index['files'] if x['path']==b['source']),'captured_head_label':doc.get('head'),'reference':ref,'original_path_count':len(full),'selected_path_count':len(keep),'excluded_path_count':len(full)-len(keep),'excluded_top_level_counts':dict(sorted(excluded.items())),'files':keep,'projection_scope':'All captured application/bootstrap/config/database/public/resources/routes/scripts/tests/workflow inputs and explicitly named root entry/dependency/test configuration files. Excluded unrelated documentation hashes remain available in compressed full receipt; this projection is not an all-tracked-source claim.'}
 name='source-projections/'+b['id']+'.json';(HERE/name).parent.mkdir(exist_ok=True);save(name,projection)
 projections.append(b|{'projection':name,'original_path_count':len(full),'selected_path_count':len(keep),'matched_selected_paths':matched,'all_selected_paths_match':exact,'mismatches':mismatches,'missing':missing})
save('source-verification.json',{'scope':'Compact executable/resource-input comparison against actual Git blobs or exact retained source capsules. Full public original inventories are separately archived, usually compressed. Original head labels may predate uncommitted overlays. No final integration claim.','bindings':projections})
# Baseline stability and external program equality are independently checkable.
stable=[]
for kind,ref in [('fail','0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371'),('pass','a4e37cdc222ccea13ae65fb983c6d5fd89a25e29')]:
 base='r4/baseline/'+kind+'-'+ref;a=load(base+'/source-before.json.gz');z=load(base+'/source-after.json.gz');assert a['files']==z['files'];assert a['programs']==z['programs']
 for n,h in a['programs'].items():assert sha(raw('r4/baseline/'+n))==h,n
 stable.append({'scope':'Independent full before/after equality plus retained external program bytes.','run':'r4/baseline-'+kind,'full_paths':len(a['files']),'programs':a['programs']})
for name in ['pure-initial','pure-quote-boundary','pure-engine-boundary-red','pure-engine-boundary-green','pure-final']:
 a=load('catalog/'+name+'.source.json');z=load('catalog/'+name+'.result.json');assert a['source']==z['after'];assert z['source_unchanged'];assert sha(raw('catalog/run-pure.py'))==a['runner_sha256'];stable.append({'run':'catalog/'+name,'scope':'Independent captured before/after map equality and runner bytes.','paths':len(a['source'])})
for name in ['initial-results.json','sqlite-final-verification.json']:
 stable.append({'receipt':'r4/'+name,'scope':'Retained owner verification sidecar; no separate raw after-map in this release.','asserted_source_unchanged':load('r4/'+name).get('source_unchanged', load('r4/'+name).get('changed') == [])})
native=load('catalog/native-old-source-red.source.json.gz');assert sha(raw('catalog/run-native.py'))==native['runner_sha256'];ov=native['overlay'];assert sha(raw('catalog/native-red-planned-source/app/Domain/Memberships/Production/MembershipCatalogEvidence.php'))==ov['sha256'];native_result=load('catalog/native-old-source-red.result.json');assert native_result['changed_sources']==[]
stable.append({'run':'catalog/native-old-source-red','scope':'Owner unchanged-source sidecar and exact captured native runner/historical preloaded class bytes; setup error before counter mutation.','paths':len(native['source']),'overlay':ov})
r4native=load('r4/native-four.verification.json');assert r4native['source_and_runner_unchanged'];assert load('r4/native-four.source-check.json')['changed']==[];assert sha(raw('r4/run-native.py'))==load('r4/native-runner-start.json')['sha256']
stable.append({'run':'r4/native-four','scope':'Owner reports unchanged full tracked source and runner; captured runner bytes verified independently. No separate raw after-map retained.','full_paths':r4native['source_file_count'],'mysql_version':r4native['mysql_version']})
save('stability-and-programs.json',{'observations':stable})
manifest=load('r4/component-release-artifacts.json');sealed=[]
for name,spec in manifest.items():
 matches=[x for x in index['files'] if x['runtime_path'].endswith('/member-policy-admission-evidence/'+name) and x['original_sha256']==spec['sha256'] and x['original_bytes']==spec['bytes']];assert len(matches)==1,(name,matches);sealed.append({'original_member':name,'archived_path':matches[0]['path'],'original_sha256':spec['sha256']})
save('owner-manifest-verification.json',{'scope':'63 original R4 released hashes/lengths reconciled against full archived receipts, including compressed/projected originals. Earlier command snapshot remains separate.','members':sealed})
# Static receipts are separate from PHPUnit bodies and retain their real outcomes.
static=load('catalog/static-final.json');assert static['before']==static['after'] and static['unchanged'];assert sha(raw('catalog/run-static.py'))==static['runner_sha256']
commands=[]
for c in static['runs']:
 observed=int(raw('catalog/'+c['name']+'.exit').strip());assert observed==c['exit'];commands.append(c|{'owner':'catalog','observed_exit':observed})
for name,expected in [('autoload',0),('pint-initial',1),('pint-fix',0),('pint-final',0),('native-syntax',0),('native-receipt-syntax',0),('native-receipt-pint',0),('native-window-syntax',0),('native-window-pint',0),('native-setup',0)]:
 observed=int(raw('catalog/'+name+'.exit').strip());assert observed==expected;commands.append({'owner':'catalog','name':name,'observed_exit':observed,'receipt':'catalog/'+name+'.exit','scope':'Setup/static receipt, not an application test pass.'})
for name in ['initial','fix','final','terminal-fix','frame-cleanup']:
 content=load('r4/pint-'+name+'.txt');commands.append({'owner':'r4','name':'pint-'+name,'receipt':'r4/pint-'+name+'.txt','tool_result':content['result'],'scope':'Pint JSON result retained; this lane did not retain a standalone exit file for this command.'})
assert load('r4/pint-initial.txt')['result']=='fail'
setup=load('catalog/native-setup.json');assert sha(raw('catalog/setup-native.py'))==setup['runner_sha256']
save('static-command-summary.json',{'scope':'Only observed static/setup receipts; no implicit execution of prepared runners or corrected native tests.','runs':commands})

# Actual counter receipts: error outcome is intentional only when the real mutation and refusal were reached.
import re
counter_source=load('catalog/native-old-source-red-v2.source.json.gz');assert sha(raw('catalog/run-native.py'))==counter_source['runner_sha256'];assert sha(raw('catalog/native-red-planned-source/app/Domain/Memberships/Production/MembershipCatalogEvidence.php'))==counter_source['overlay']['sha256']
events=[json.loads(line.split('CATALOG_CURRENTNESS ',1)[1]) for line in raw('catalog/native-old-source-red-v2.log').decode().splitlines() if line.startswith('CATALOG_CURRENTNESS ')]
mutation=next(x for x in events if x['stage']=='unrelated_insert_committed');refusal=next(x for x in events if x['stage']=='original_snapshot_reproof_refused')
assert mutation['primary_connection_id']!=mutation['other_connection_id'];assert mutation['counter_before']==2 and mutation['counter_after']==3;assert mutation['selected_plans_unchanged'];assert mutation['phase']=='original_snapshot_after_commit';assert refusal['refusal']=='changed_catalog_source'
assert sha(mutation['raw_before'].encode())==mutation['raw_before_sha256'];assert sha(mutation['raw_after'].encode())==mutation['raw_after_sha256']
assert mutation['raw_before'].replace(') ENGINE=InnoDB AUTO_INCREMENT=2 ',') ENGINE=InnoDB AUTO_INCREMENT=3 ',1)==mutation['raw_after']
assert load('catalog/native-old-source-red-v2.source-check.json')['changed_sources']==[]
save('catalog-counter-comparison.json',{'scope':'Completed old-class regression; fixed selection separately verified at the same candidate input hashes. Native raw definitions independently differ only in the table allocation counter. Selected-plan equality is the executed test assertion/logged observation, not an independently archived row replay.','run':'catalog/native-old-source-red-v2','mysql_version':mutation['mysql_version'],'connection_ids':[mutation['primary_connection_id'],mutation['other_connection_id']],'counter_before':2,'counter_after':3,'raw_before_sha256':mutation['raw_before_sha256'],'raw_after_sha256':mutation['raw_after_sha256'],'selected_plans_unchanged':True,'observed_refusal':refusal['refusal'],'junit_errors':1,'junit_assertion_failures':0,'exit':2})

# Released root policy/style checks and discovery only: no application/test bodies executed here.
root_commands=[]
for prefix in ['quality','quality/policy-final']:
 meta=load(prefix+'/metadata.json');assert sha(raw(prefix+'/run-quality.py'))==meta['runner_sha256']
 before=load(prefix+'/sources-before.json.gz');after=load(prefix+'/sources-after.json.gz');assert before==after and len(before)==meta['source_count']==2287
 for c in load(prefix+'/results.json'):
  name=Path(c['argv'][1]).stem if c['argv'][0]=='python3' else 'pint'
  log=raw(prefix+'/'+name+'.log');code=int(raw(prefix+'/'+name+'.exit').strip());assert sha(log)==c['log_sha256'];assert code==c['exit'];assert c['runner_sha256']==meta['runner_sha256'];assert c['head_at_start']==meta['head']
  if name=='pint':assert json.loads(log)['result']=='passed' and c['selected_php_files']==8
  else:
   count=re.search(r'^Ran (\d+) tests? in ',log.decode(),re.MULTILINE);assert count and int(count.group(1))==c['unittest_reported_tests']
   if code==0:assert re.search(r'^OK$',log.decode(),re.MULTILINE)
   else:assert prefix=='quality' and name=='test-phpunit-shards' and code==1 and 'FAILED (failures=1)' in log.decode()
  root_commands.append(c|{'receipt_prefix':prefix+'/'+name,'observed_exit':code})
assert sum(x['unittest_reported_tests'] for x in root_commands if x['receipt_prefix'].startswith('quality/policy-final/'))==154
carry=load('quality/carry.json');assert len(carry['file_hashes'])==8 and set(carry['executed_php_files'])==set(carry['file_hashes'])
for file,h in carry['file_hashes'].items():
 for ref in [carry['pint_source'],carry['current_head']]:assert sha(subprocess.check_output(['git','-C',args.git_repo,'show',ref+':'+file]))==h
actual_diff=subprocess.check_output(['git','-C',args.git_repo,'diff',carry['policy_pass_source'],carry['current_head']],text=True);assert actual_diff==carry['later_diff']
for line in actual_diff.splitlines():
 if line.startswith(('+','-')) and not line.startswith(('+++','---')):assert line[1:].strip().startswith('#'),line
save('root-quality-summary.json',{'scope':'Nine executed local policy/style commands at their exact source; initial shard failure retained. Pint eight inputs carried unchanged; later CI delta is exact comment-only diff, not rerun.','commands':root_commands,'initial_pint_source':carry['pint_source'],'policy_pass_source':carry['policy_pass_source'],'carried_to':carry['current_head'],'pint_unchanged_files':carry['file_hashes'],'policy_tests_passed':154,'comment_only_later_diff_verified':True})
manifest=load('selection/listings/phpunit-ci-mysql-manifest.json');namespace='{https://xml.phpunit.de/testSuite}'
def listing(name):
 document=ET.fromstring(raw('selection/listings/'+name));cases={};groups=collections.Counter();assert document.tag==namespace+'testSuite'
 for cls in document.find(namespace+'tests'):
  file=cls.attrib['file'];base='/workspace/VA-Studio-member-source-currentness/';assert file.startswith(base);file=file.removeprefix(base)
  for method in cls:
   identity=method.attrib['id'];assert identity not in cases;cases[identity]=file
 for group in document.find(namespace+'groups'):
  for case in group:assert case.attrib['id'] in cases;groups[(group.attrib['name'],case.attrib['id'])]+=1
 return cases,groups
complete,complete_groups=listing('phpunit-ci-mysql-source-tests.xml');assert len(complete)==manifest['source_test_cases']==8245 and len(set(complete.values()))==manifest['source_files']==602
assert sha(json.dumps(sorted(complete.items())).encode())==manifest['source_case_identity_sha256'];assert sha(json.dumps(sorted(complete_groups.items())).encode())==manifest['source_group_identity_sha256']
selected_cases={};selected_files=set();selected_groups=collections.Counter();assert len(manifest['shards'])==24
for shard in manifest['shards']:
 cases,groups=listing('phpunit-ci-mysql-'+str(shard['index'])+'-tests.xml');files=set(cases.values());assert cases and len(cases)==shard['test_cases'] and files==set(shard['files']);assert not set(cases).intersection(selected_cases) and not files.intersection(selected_files);assert cases=={key:val for key,val in complete.items() if val in files};assert groups==collections.Counter({pair:count for pair,count in complete_groups.items() if pair[1] in cases});selected_cases.update(cases);selected_files.update(files);selected_groups.update(groups)
assert len(selected_cases)==manifest['selection']['test_cases']==2103 and len(selected_files)==manifest['selection']['files']==202;assert sha(json.dumps(sorted(selected_cases.items())).encode())==manifest['selection']['case_identity_sha256'];assert len(manifest['weighting']['untimed_files'])==39;assert int(raw('selection/discovery.exit').strip())==0
save('discovery-summary.json',{'scope':'Offline reconstruction of released --list-tests-xml artifacts; no test bodies or hosted CI.','source':raw('selection/discovery.base').decode().strip(),'discovered_tests':8245,'discovered_files':602,'selected_tests':2103,'selected_files':202,'nonempty_shards':24,'disjoint_complete_files':True,'case_and_group_hashes_match':True,'fallback_weight_files':39,'test_bodies_executed':False,'database_endpoint':'127.0.0.1:1 (unreachable for bodies)'})
release=load('root-local-release.json');local=[]
for item in release['artifacts']:
 matches=[x for x in index['files'] if x['runtime_path']==item['path'] and x['original_sha256']==item['sha256'] and x['original_bytes']==item['bytes']];assert len(matches)==1;local.append({'original_target':item['target'],'archived_path':matches[0]['path'],'original_sha256':item['sha256']})
assert len(local)==83;save('root-local-release-verification.json',{'scope':'Every explicitly released local check/listing artifact retained; five large source receipts compressed without losing full public maps.','members':local})

cleanup=load('selection/generated-file-cleanup.json');entries=cleanup['generated_files'];assert len(entries)==50 and len({x['path'] for x in entries})==50
archived=[];omitted=[]
for entry in entries:
 if entry['archived']:
  contents=raw('selection/listings/'+entry['path']);assert sha(contents)==entry['sha256'] and len(contents)==entry['bytes'];archived.append(entry)
 else:
  assert re.fullmatch(r'phpunit-ci-mysql-(?:[1-9]|1[0-9]|2[0-4])\.xml',entry['path']);assert not (HERE/'selection/listings'/entry['path']).exists();omitted.append(entry)
assert len(archived)==26 and len(omitted)==24
save('discovery-cleanup-verification.json',{'scope':'50 generated paths removed by root after26 public listings/manifest were archived;24 generated configs intentionally omitted, with original names/digests/lengths retained. No application-source removal.','archived_members':archived,'omitted_configuration_members':omitted})

fixed_source=load('catalog/native-fixed-five.source.json.gz');assert fixed_source['source']==counter_source['source'];assert fixed_source['overlay'] is None;assert sha(raw('catalog/run-native.py'))==fixed_source['runner_sha256'];assert load('catalog/native-fixed-five.source-check.json')['changed_sources']==[]
fixed_events=[json.loads(line.split('CATALOG_CURRENTNESS ',1)[1]) for line in raw('catalog/native-fixed-five.log').decode().splitlines() if line.startswith('CATALOG_CURRENTNESS ')]
fixed_mutation=next(x for x in fixed_events if x['stage']=='unrelated_insert_committed');assert fixed_mutation['primary_connection_id']!=fixed_mutation['other_connection_id'];assert fixed_mutation['selected_plans_unchanged'];assert fixed_mutation['counter_before']==2 and fixed_mutation['counter_after']==3
assert sha(fixed_mutation['raw_before'].encode())==fixed_mutation['raw_before_sha256'];assert sha(fixed_mutation['raw_after'].encode())==fixed_mutation['raw_after_sha256'];assert fixed_mutation['raw_before'].replace(') ENGINE=InnoDB AUTO_INCREMENT=2 ',') ENGINE=InnoDB AUTO_INCREMENT=3 ',1)==fixed_mutation['raw_after']
comparison=load('catalog-counter-comparison.json');comparison['fixed_run']='catalog/native-fixed-five';comparison['captured_candidate_inputs_identical']=len(fixed_source['source']);comparison['fixed_overlay']=None;comparison['fixed_mutation']={k:v for k,v in fixed_mutation.items() if k not in ('raw_before','raw_after')};comparison['fixed_logged_stages']=[x['stage'] for x in fixed_events];comparison['fixed_counts']={'tests':5,'assertions':46,'errors':0,'failures':0,'skipped':0,'exit':0};save('catalog-counter-comparison.json',comparison)

catalog_manifest=load('catalog/component-release-artifacts.json');assert len(catalog_manifest['files'])==catalog_manifest['count']==109
catalog_members=[]
for name,h in catalog_manifest['files'].items():
 matches=[x for x in index['files'] if x['runtime_path'].endswith('/membership-catalog-currentness-evidence/'+name) and x['original_sha256']==h];assert len(matches)==1,(name,matches);catalog_members.append({'original_member':name,'archived_path':matches[0]['path'],'original_sha256':h})
sqlite_source=load('catalog/sqlite-eligibility-whole.source.json.gz');assert sha(raw('catalog/run-sqlite.py'))==sqlite_source['runner_sha256'];assert load('catalog/sqlite-eligibility-whole.result.json')['changed_sources']==[];assert sqlite_source['source']==fixed_source['source']
save('catalog-release-verification.json',{'scope':'109 final component public artifact hashes verified; planned/historical capsules remain non-executed unless separately bound to a registered invocation. SQLite full eligibility source map equals fixed native candidate map.','members':catalog_members,'sqlite_captured_source_count':len(sqlite_source['source']),'sqlite_native_source_maps_equal':True})

# Deliberately narrow successful current-component coverage; overlapping earlier pass runs remain in source map.
precedence=['r4/sqlite-final','catalog/pure-final','r4/native-four','catalog/native-fixed-five','catalog/sqlite-eligibility-whole','root/native-reservation-integration'];union={}
for name in precedence:
 r=next(x for x in runs if x['id']==name);assert r['exit']==0
 for c in r['cases']:
  if c['status']=='passed':union[(r['driver'],c['identity'])]=c|{'run':name,'driver':r['driver']}
save('coverage-summary.json',{'scope':'Completed successful identities by driver+case, with component and final-root sources kept distinct. Frozen root native reservation canary is one case, not a whole suite. No fixture becomes operative grant evidence.','precedence':precedence,'passed_cases':len(union),'assertions':sum(x['assertions'] for x in union.values()),'cases':list(union.values()),'pending':registry['pending']})
# Final root native release, source and full-component carry comparison.
root_release=load('root/release-manifest.json');assert len(root_release['artifacts'])==15;root_members=[]
for item in root_release['artifacts']:
 matches=[x for x in index['files'] if x['runtime_path']==item['path'] and x['original_sha256']==item['sha256'] and x['original_bytes']==item['bytes']];assert len(matches)==1;root_members.append({'original_target':item['target'],'archived_path':matches[0]['path'],'original_sha256':item['sha256']})
root_source=load('root/native-reservation-integration.source.json.gz');assert root_source['head']==registry['final_source'];assert len(root_source['files'])==1275;assert sha(raw('root/run-native-focused.py'))==root_source['runner_sha256'];assert load('root/native-reservation-integration.result.json')['changed_sources']==[]
setup=load('root/native-setup.json');assert setup['version']=='8.4.11';assert sha(raw('root/setup-private-native.py'))==setup['runner_sha256'];assert int(raw('root/native-setup.exit').strip())==0
root_verification=load('root/source-verification.json');assert root_verification['git_mismatches']==root_verification['disk_mismatches']==[] and root_verification['runner_matches']
save('root-native-verification.json',{'scope':'Fifteen released root artifacts; exact frozen source, recorded unchanged-source receipt and independently verified runner/setup bytes. One integrated native case, not full-suite/host/provider acceptance.','members':root_members,'source':registry['final_source'],'captured_inputs':1275,'mysql_version':'8.4.11','tests':1,'assertions':21,'errors':0,'failures':0,'skipped':0,'exit':0,'wall_seconds':load('root/native-reservation-integration.result.json')['duration_seconds']})
ref=registry['final_source'];final_comparisons=[];projection_by_source={x['source']:x for x in projections};run_by_id={x['id']:x for x in runs}
proc=subprocess.Popen(['git','-C',args.git_repo,'cat-file','--batch'],stdin=subprocess.PIPE,stdout=subprocess.PIPE)
try:
 for run_id in precedence:
  binding=projection_by_source[run_by_id[run_id]['source']];projection=load(binding['projection']);differences=[]
  for file,expected in projection['files'].items():
   oid=trees[ref].get(file)
   if oid is None:actual=None
   else:
    if oid not in blobs:
     proc.stdin.write((oid+'\n').encode());proc.stdin.flush();header=proc.stdout.readline().decode().split();data=proc.stdout.read(int(header[2]));assert proc.stdout.read(1)==b'\n';blobs[oid]=sha(data)
    actual=blobs[oid]
   if expected!=actual:
    category='application' if file.startswith('app/') else 'test or fixture' if file.startswith('tests/') else 'tooling/config/resource'
    differences.append({'path':file,'category':category,'captured_sha256':expected,'final_sha256':actual})
  final_comparisons.append({'run':run_id,'component_reference':binding['reference'],'selected_captured_inputs':len(projection['files']),'all_selected_inputs_match_final':not differences,'differences':differences})
finally:proc.stdin.close();proc.wait();assert proc.returncode==0
save('final-source-verification.json',{'scope':'Every selected input in successful-coverage precedence compared with actual frozen root Git blobs. Differences remain visible and do not become reruns; unlisted inputs are not attested. Full original public inventories remain archived separately.','final_source':ref,'comparisons':final_comparisons})

files={}
owned=[HERE.parent/name for name in ['commands.md','source-map.md','coverage-map.md','coverage-review.md'] if (HERE.parent/name).exists()] + list(HERE.rglob('*'))
for f in sorted(owned):
 if f.is_file() and f!=HERE/'artifact-digests.json':
  x=f.read_bytes();files[str(f.relative_to(HERE.parent))]={'bytes':len(x),'sha256':sha(x)}
save('artifact-digests.json',{'scope':'Packet-owned commands/source/coverage/evidence only; excludes this self-digest and separately owned README/DECISION/ledgers. No acceptance decision.','files':files})
print(json.dumps({'archived_artifacts':len(index['files']),'junit_invocations':len(runs),'source_projections':len(projections),'partial_selected_source_bindings':sum(not x['all_selected_paths_match'] for x in projections),'successful_driver_case_identities':len(union)}))
