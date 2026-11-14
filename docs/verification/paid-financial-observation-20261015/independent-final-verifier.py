import pathlib,json,hashlib,subprocess,tarfile,re,datetime,xml.etree.ElementTree as ET
R=pathlib.Path('/workspace/VA-Studio-tax-v2-committed-read');P=R/'docs/verification/paid-financial-observation-20261015';H='10e0d86177603f5a0c41f2c1a9011c4eee634592';A='ae6d109581bac6b685360a5941d6c7819cd5616f';B='d8817316558b3d5965203b548d75cd635e7cde92';O=pathlib.Path('/workspace/runtime/paid-financial-observation-final-working-report-10e0d861-20261015.json')
def sha(b):return hashlib.sha256(b).hexdigest()
def can(o):return json.dumps(o,sort_keys=True,separators=(',',':')).encode()
def git(*a):return subprocess.check_output(['git','-C',str(R),*a])
def check(v,s):
 if not v:raise AssertionError(s)
def tree(h):
 d={}
 for row in git('ls-tree','-rz','--full-tree',h).split(b'\0'):
  if row:
   f,p=row.split(b'\t',1); mode,kind,oid=f.decode().split()
   if kind=='blob':d[p.decode()]=oid
 return d
check(git('rev-parse','HEAD').decode().strip()==H and git('status','--porcelain=v1')==b'','frozen clean head')
ix=P/'tested-ae6d1095-recorded-evidence-index.json';i=json.loads(ix.read_text());archive=P/i['archive'];ab=archive.read_bytes()
check(sha(ab)==i['archive_sha256']=='bd681b5f192e87b7f1f1ec9708682e5a87a91b5797009bbdbd8514aba6bba2fb' and len(ab)==i['archive_bytes']==7073062,'archive exact')
with tarfile.open(archive,'r:gz') as t:
 m=t.getmembers();check(len(m)==i['members']==2460 and len(set(x.name for x in m))==len(m),'member count/set');check(all(x.isfile() and not pathlib.PurePosixPath(x.name).is_absolute() and '..' not in pathlib.PurePosixPath(x.name).parts for x in m),'safe regular');d={x.name:t.extractfile(x).read() for x in m}
check(set(d)==set(i['digests']),'index set')
for n,b in d.items():check(sha(b)==i['digests'][n],'member '+n)
def j(n):return json.loads(d[n])
bind=j('git-source-bindings.json');load=j('current-loader-bindings.json');blobs={n.split('/')[1]:b for n,b in d.items() if n.startswith('source-blobs/')}
check(len(bind)==3 and sorted(bind)==sorted(i['heads']) and all(len(x)==2430 for x in bind.values()),'heads/inputs')
check(len(blobs)==i['source_blob_count']==2377 and set(blobs)=={s for v in bind.values() for s in v.values()},'source blob set')
for s,b in blobs.items():check(sha(b)==s,'source blob hash')
check(len(load)==i['loader_count']==13,'loaders')
for p,s in load.items():check(sha((R/p).read_bytes())==s and d.get('loader-blobs/'+s) is not None and sha(d['loader-blobs/'+s])==s,'current/archive loader '+p)
trees={h:tree(h) for h in set(bind)|{H,B}};oids={}
for h,paths in bind.items():
 for p,s in paths.items():
  oid=trees[h].get(p);check(oid is not None and (oid not in oids or oids[oid]==s),'Git binding '+p);oids[oid]=s
raw=subprocess.run(['git','-C',str(R),'cat-file','--batch'],input=''.join(o+'\n' for o in oids).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True).stdout;at=0
for oid,s in oids.items():
 end=raw.index(b'\n',at);header=raw[at:end].decode().split();at=end+1;check(header[:2]==[oid,'blob'],'Git blob header');n=int(header[2]);body=raw[at:at+n];at+=n;check(raw[at:at+1]==b'\n','Git terminator');at+=1;check(sha(body)==s and body==blobs[s],'Git exact byte object')
check(at==len(raw),'Git complete')
for p,s in bind[A].items():check(trees[A][p]==trees[H].get(p) and sha((R/p).read_bytes())==s,'current actual source '+p)
bundles={}
for n in d:
 if n.startswith('execution/bundle-') and n.endswith('/identity.json'):
  pre=n.rsplit('/',1)[0];id=j(n);man=j(pre+'/manifest.json');rt=j(pre+'/runtime.json');env=j(pre+'/public-environment.json')
  check(man==bind[id['head']] and sha(can(man))==id['manifest_sha256'],'bundle manifest');check(sha(can(rt))==id['runtime_sha256'] and sha(can(env))==id['public_environment_sha256'] and sha(d[pre+'/runner.py'])==id['runner_sha256'],'bundle runtime/runner');check(rt['autoload']==load and rt['composer_lock_sha256']==man['composer.lock'],'runtime loader/lock');check(env.get('synthetic_testing_app_key_configured') is True and not {'APP_KEY','DB_PASSWORD','DB_USERNAME'}&set(env),'secret-excluded public descriptor');bundles[pre.split('/',1)[1]]=(id,man,rt,env)
check(len(bundles)==3,'three runtime inventories')
names=sorted(n.split('/')[1] for n in d if re.fullmatch(r'execution/[^/]+/result\.json',n));check(len(names)==4,'four actual PHP invocations');runs=[]
for name in names:
 pre='execution/'+name;r=j(pre+'/result.json');c=j(pre+'/command.json');l=j('execution/launch-'+name+'.json');os=j(pre+'/supervisor-os-exit.json');id,man,rt,env=bundles[r['bundle']];cases=ET.fromstring(d[pre+'/junit.xml']).findall('.//testcase');cnt={'tests':len(cases),'assertions':sum(int(x.attrib.get('assertions',0)) for x in cases),'failures':sum(x.find('failure') is not None for x in cases),'errors':sum(x.find('error') is not None for x in cases),'skipped':sum(x.find('skipped') is not None for x in cases)}
 check(cnt==r['junit'],'JUnit '+name);check(c['source_before']==r['source_after']==l['source']=={'head':id['head'],'status':''},'source '+name);check(c['source_input_count']==r['source_input_count']==len(man)==2430 and c['before_digest']==r['after_digest']==id['manifest_sha256'],'manifest '+name);check(c['before_git_equal'] and c['overlays']==[],'clean/nooverlay '+name);check(all(r[k] for k in ['autoload_unchanged','runner_unchanged','source_head_unchanged','source_status_unchanged','source_unchanged']),'stability '+name);check(r['runner_after_sha256']==l['runner_sha256']==id['runner_sha256'] and c['public_environment']==env,'runner/environment '+name);check(int(d[pre+'/process.exit'].strip())==r['process_exit'] and sha(d[pre+'/log.txt'])==r['log_sha256'],'original process/log '+name);check((l['pid'],str(l['process_start_ticks']),l['uid'])==(os['pid'],str(os['process_start_ticks']),os['uid']) and os['identity_matched'] and os['state']=='Z','original OS identity '+name);check(os['observed_os_exit']==r['verifier_exit'] and os['raw_exit_status']==os['observed_os_exit']<<8,'OS result '+name)
 phpunit=json.loads(c['argv'][-1]);check(all(p in phpunit for p in c['selected_files']),'selected actual argv '+name)
 errors=[{'case':x.attrib['name'],'message':x.find('error').text.split('\n')[1] if x.find('error').text and len(x.find('error').text.split('\n'))>1 else '', 'reached_normalizer':'FinancialObservation.php:40' in (x.find('error').text or '')} for x in cases if x.find('error') is not None]
 good=r['process_exit']==r['verifier_exit']==os['observed_os_exit']==0
 runs.append({'name':name,'source':id['head'],'counts':cnt,'PHP_exit':r['process_exit'],'recorder_exit':r['verifier_exit'],'observed_recorder_OS_exit':os['observed_os_exit'],'source_runner_loader_HEAD_status_stable':True,'selected_files':c['selected_files'],'filter':c['filter'],'errors':errors,'pass':good,'launch':{'pid':l['pid'],'start_ticks':str(l['process_start_ticks']),'uid':l['uid']}})
by={x['name']:x for x in runs};check(sum(x['pass'] for x in runs)==3,'three passes');check(by['reviewer-regressions-red']['counts']=={'tests':3,'assertions':7,'failures':1,'errors':2,'skipped':0} and [by['reviewer-regressions-red'][k] for k in ['PHP_exit','recorder_exit','observed_recorder_OS_exit']]==[2,1,1],'original red retained');check(all(x['reached_normalizer'] for x in by['reviewer-regressions-red']['errors']),'genuine async original errors');check(by['candidate-whole-repaired']['counts']=={'tests':13,'assertions':351,'failures':0,'errors':0,'skipped':0},'whole current13')
check(by['affected-financial-3e6f8922']['counts']=={'tests':69,'assertions':291,'failures':0,'errors':0,'skipped':0},'whole legacy69')
check(git('diff','--name-only','3e6f89222c0832c08bf0205ee79077935b0c4e28','f9fda036ad483cafbe524437fcf0c56435a96376','--','app','config')==b'','red unchanged defective bodies')
carry=j('execution/narrow-evidence-carry-ae6d1095.json');check(carry['current_tested_head']==A and carry['protected_unchanged_input_count']==len(carry['unchanged_bindings'])==261,'carry261')
for p,s in carry['unchanged_bindings'].items():check(trees[carry['prior_tested_head']][p]==trees[A][p]==trees[H][p] and sha((R/p).read_bytes())==s,'actual narrow carry '+p)
check(git('diff','--name-only',carry['prior_tested_head'],A).decode().splitlines()==carry['changed_paths'],'carry exact delta')
protected=j('execution/protected-inputs-3e6f8922.json');check(protected['original_inputs_unchanged'] and len(protected['base']['paths'])==16,'protected16')
for p,s in protected['base']['paths'].items():
 check(sha((R/p).read_bytes())==s,'protected actual '+p)
 if not p.startswith('vendor/'):check(trees[B][p]==trees[H][p],'protected actualbase Git '+p)
check(git('diff','--name-status',B,'3e6f89222c0832c08bf0205ee79077935b0c4e28').decode()==protected['actual_merge_delta'],'actual base merge delta')
cheap=[]
for n in sorted(n for n in d if n.startswith('cheap/') and n.endswith('.json') and not n.endswith('/summary.json')):
 x=j(n);check(x['source_before']==x['source_after'] and x['source_before']['status']=='','cheap source stable '+n);text=x['stderr']+'\n'+x['stdout'];ms=re.findall(r'Ran (\d+) tests? in',text);cnt=int(ms[0]) if ms else None
 if 'cheap-continuation' in n:check(x['exit']==0 and x['source_before']['head']==A,'continuation '+n)
 elif '/07-' in n:check(x['exit']==1 and cnt==52 and 'FAILED (failures=1)' in text,'failed cheap original')
 else:check(x['exit']==0 and x['source_before']['head']=='3e6f89222c0832c08bf0205ee79077935b0c4e28','prior cheap '+n)
 if cnt is not None and x['exit']==0:check(re.search(r'\nOK\s*\Z',text),'cheap pass count '+n)
 cheap.append({'member':n,'argv':x['argv'],'source':x['source_before']['head'],'exit':x['exit'],'Python_test_count':cnt})
check(len(cheap)==15,'fifteen actual cheap commands (failed rerun retained)')
prior=[x for x in cheap if 'cheap-continuation' not in x['member'] and x['exit']==0];current=[x for x in cheap if 'cheap-continuation' in x['member'] and x['Python_test_count'] is not None]
check(len(prior)==7 and sum(x['Python_test_count'] for x in prior)==196 and len(current)==3 and sum(x['Python_test_count'] for x in current)==67,'196+67=263')
summary=j('cheap/paid-financial-cheap-continuation-20261015/summary.json');check(len(summary['results'])==7 and all(x['exit']==0 and x['source_stable'] for x in summary['results']) and summary['source']=={'head':A,'status':''},'continuation seven summary')
audit=j('cheap/paid-financial-cheap-continuation-20261015/04-audit.json');check(json.loads(audit['stdout'])=={'advisories':[],'abandoned':[]},'audit exact no advisories');validate=j('cheap/paid-financial-cheap-continuation-20261015/03-validate.json');check(validate['argv']==['composer','validate','--strict'] and 'is valid' in validate['stdout'],'strict validate');platform=j('cheap/paid-financial-cheap-continuation-20261015/05-check-platform-reqs.json');check(platform['argv']==['composer','check-platform-reqs'],'platform argv');pint=j('cheap/paid-financial-cheap-continuation-20261015/06---test.json');check(pint['argv']==['vendor/bin/pint','--test','app/Domain/Commerce/ProductionFinancialInspection','config/production-financial-inspection.php','tests/Feature/ProductionFinancialInspection/FinancialCandidateTest.php'] and json.loads(pint['stdout'])=={'tool':'pint','result':'passed'},'Pint argv/output');pint_paths=[str(p.relative_to(R)) for p in (R/pint['argv'][2]).glob('*.php')]+pint['argv'][3:];check(len(pint_paths)==7,'Pint seven PHP files')
for p in pint_paths:check(sha((R/p).read_bytes())==bind[A][p],'Pint current carry')
imports={'design-review-ef74c3cb-original':'paid-financial-observation-design-review-ef74c3cb-20261015','design-review-b6a8b72c-original':'paid-financial-observation-design-review-b6a8b72c-20261015','source-review-3e6f8922-original':'paid-financial-observation-source-review-3e6f8922-20261015','source-review-ae6d1095-original':'paid-financial-observation-source-review-ae6d1095-20261015'};notes=[]
for dest,origin in imports.items():
 for ext in ['.md','.json']:
  body=(P/(dest+ext)).read_bytes();check(body==(pathlib.Path('/workspace/runtime')/(origin+ext)).read_bytes(),'original review unchanged');notes.append({'path':dest+ext,'sha256':sha(body)})
docs=[]
for name in ['charge','dispute','refund','refund-direct-path']:
 p=P/'documentation-preparation'/f'{name}.json';x=json.loads(p.read_text());check(x['exit']==1 and x['stdout']=='' and 'Forbidden' in x['stderr'],'documentation original failure');docs.append({'path':str(p.relative_to(R)),'sha256':sha(p.read_bytes()),'exit':1,'scope':'Failed public documentation only; not provider interoperability'})
prep=json.loads((P/'documentation-preparation/stripe-docs-tool-preparation-20261015.json').read_text());check(prep['exit']==0 and prep['actual_sha256']==prep['expected_sha256'] and 'stripe version 1.53.1' in prep['stdout'],'recorded CLI preparation facts')
check(all(p.startswith('docs/') for p in git('diff','--name-only',A,H).decode().splitlines()),'final docs-only source carry');check(git('diff','--name-only',B,H,'--','database','routes','resources','contracts','scripts','.github','composer.json','composer.lock','bootstrap','app/Providers')==b'','frozen whole scoped paths');check("'enabled' => false" in (R/'config/production-financial-inspection.php').read_text(),'defaultfalse');check(git('rev-parse','HEAD').decode().strip()==H and git('status','--porcelain=v1')==b'','final head clean')
report={'verification':'PASS','reviewed_sha':H,'operative_source_sha':A,'actual_base_merge_sha':B,'created_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'archive':{'sha256':i['archive_sha256'],'bytes':len(ab),'members':len(d),'index_sha256':sha(ix.read_bytes()),'all_digests_and_safe_exact_member_set':True},'source':{'heads':sorted(bind),'inventories':3,'inputs_each':2430,'Git_path_bindings':7290,'distinct_source_blobs':2377,'reachable_Git_objects_checked':len(oids),'actual_current_inputs_equal':2430,'current_and_recorded_loaders':13,'route_inputs':sum(p.startswith('routes/') for p in bind[A]),'narrow_carry_exact_bindings':261,'protected_original_refs_including_local_SDK':16,'all_scoped_original_schema_routes_profile_SDK_pins_provider_workflow_body_paths_unchanged':True},'original_PHP_invocations':runs,'pass_invocations':3,'red_nonzero_invocations':1,'cheap_originals':cheap,'prior_cheap_pass_tests':196,'current_cheap_pass_tests':67,'combined_narrow_cheap_tests':263,'original_failed_cheap':{'tests':52,'failures':1,'exit':1},'current_quality':{'strict_validate':0,'audit':0,'platform':0,'Pint':{'exit':0,'PHP_files':7}},'original_review_imports':notes,'documentation_failures':docs,'documentation_limit':'Declared unavailable connector link_id and real provider/current official documentation remain unsupported; no connector/provider execution proof is inferred.','narrow_carry_limit':'Legacy69/291 and cheap7/196 remain original3e source evidence; no fresh69/current full10-command or native/full-suite claim.','reviewer_actions':{'application_test_DB_network_CI_hosted_operations':0,'tracked_edits':0},'reviewer_preparation_errors':['Read-only cat guessed docs/ops/paid-financial-observation-runtime.md and returned no-such-file exit1. Corrected to README-linked paid-financial-candidate-runtime.md; no app/DB/test failure.']}
check(not O.exists(),'no working report overwrite');O.write_text(json.dumps(report,indent=2,sort_keys=True)+'\n');print(json.dumps({'report':str(O),'sha256':sha(O.read_bytes()),'members':2460,'Git_bindings':7290,'blobs':2377,'original_PHP_invocations':4,'passes':3,'red':1,'cheap_original_commands':15,'narrow_python_tests':263,'Pint_PHP_files':7}))
