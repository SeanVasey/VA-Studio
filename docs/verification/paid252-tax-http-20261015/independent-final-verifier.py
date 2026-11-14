import datetime, hashlib, json, pathlib, re, subprocess, tarfile, xml.etree.ElementTree as ET
REPO=pathlib.Path('/workspace/VA-Studio-tax-v2-committed-read')
PACKET=REPO/'docs/verification/paid252-tax-http-20261015'
TARGET='2a382a505c3145f709cede4110dbcd0b57196a3c'
APP='bb3d29983d6d225cfb62d9607beaa54874f3aa03'
BASE='d95e4a74606ee257a2cda06d0793c1cedf165137'
OUTPUT=pathlib.Path('/workspace/runtime/paid252-tax-http-final-working-report-2a382a50-20261015.json')
def sha(b): return hashlib.sha256(b).hexdigest()
def canonical(o): return json.dumps(o,sort_keys=True,separators=(',',':')).encode()
def git(*a): return subprocess.check_output(['git','-C',str(REPO),*a])
def check(ok,label):
    if not ok: raise AssertionError(label)
def tree(head):
    result={}
    for row in git('ls-tree','-rz','--full-tree',head).split(b'\0'):
        if not row: continue
        fields,path=row.split(b'\t',1); mode,kind,oid=fields.decode().split()
        if kind=='blob': result[path.decode()]=oid
    return result
check(git('rev-parse','HEAD').decode().strip()==TARGET,'exact candidate')
check(git('status','--porcelain=v1')==b'','clean candidate')
idx=json.loads((PACKET/'final-recorded-evidence-index.json').read_text())
archive=PACKET/idx['archive']; archive_body=archive.read_bytes()
check(sha(archive_body)==idx['archive_sha256']=='e72a5383d387a23048fc350bbfdc824b426e42380f362050d374956d49327c87','archive SHA')
check(len(archive_body)==idx['archive_bytes']==8323164,'archive size')
with tarfile.open(archive,'r:gz') as t:
    members=t.getmembers()
    check(len(members)==idx['members']==2546,'member count')
    check(len(set(m.name for m in members))==len(members),'no duplicate members')
    check(all(m.isfile() and not pathlib.PurePosixPath(m.name).is_absolute() and '..' not in pathlib.PurePosixPath(m.name).parts for m in members),'regular safe members')
    data={m.name:t.extractfile(m).read() for m in members}
check(set(data)==set(idx['digests']),'exact indexed member set')
for n,b in data.items(): check(sha(b)==idx['digests'][n],'member digest '+n)
def j(n): return json.loads(data[n])
bindings=j('git-source-bindings.json'); loaders=j('current-loader-bindings.json')
check(sorted(bindings)==sorted(idx['heads']) and len(bindings)==8,'eight source heads')
check(all(len(v)==2423 for v in bindings.values()),'2423 inputs each')
blobnames={n.removeprefix('source-blobs/'):n for n in data if n.startswith('source-blobs/')}
check(len(blobnames)==idx['source_blob_count']==2383,'source blob count')
check(set(blobnames)=={s for v in bindings.values() for s in v.values()},'complete exact source blob set')
for s,n in blobnames.items(): check(sha(data[n])==s,'blob identity '+n)
check(len(loaders)==idx['loader_count']==13,'loader count')
for p,s in loaders.items():
    check(data.get('loader-blobs/'+s) is not None and sha(data['loader-blobs/'+s])==s,'archive loader '+p)
    check(sha((REPO/p).read_bytes())==s,'actual current loader '+p)
heads={h:tree(h) for h in set(bindings)|{TARGET,BASE}}
oid_sha={}
for h,paths in bindings.items():
    for p,s in paths.items():
        oid=heads[h].get(p); check(oid is not None,'Git path '+h+':'+p)
        check(oid not in oid_sha or oid_sha[oid]==s,'consistent Git blob SHA')
        oid_sha[oid]=s
protected=j('execution/protected-source-equality-domains-ae1cfe04.json')
check(protected['base']==BASE and protected['count']==len(protected['digests'])==239 and not protected['differences'],'protected receipt')
for p,s in protected['digests'].items():
    check(heads[BASE].get(p)==heads[TARGET].get(p),'protected Git equality '+p)
    check(sha((REPO/p).read_bytes())==s,'protected actual bytes '+p)
    oid=heads[BASE][p]; check(oid not in oid_sha or oid_sha[oid]==s,'protected blob consistency'); oid_sha[oid]=s
query=''.join(oid+'\n' for oid in oid_sha).encode()
stream=subprocess.run(['git','-C',str(REPO),'cat-file','--batch'],input=query,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True).stdout
at=0
for oid,s in oid_sha.items():
    end=stream.index(b'\n',at); header=stream[at:end].decode().split(); at=end+1
    check(header[:2]==[oid,'blob'],'Git batch header'); size=int(header[2]); body=stream[at:at+size]; at+=size
    check(stream[at:at+1]==b'\n','Git batch terminator'); at+=1
    check(sha(body)==s,'reachable Git exact bytes '+oid)
    if s in blobnames: check(body==data[blobnames[s]],'Git/archive equality '+oid)
check(at==len(stream),'Git batch exact consumption')
for p,s in bindings[APP].items():
    check(heads[APP][p]==heads[TARGET].get(p),'current source Git carry '+p)
    check(sha((REPO/p).read_bytes())==s,'current actual source carry '+p)
route_count=sum(p.startswith('routes/') for p in bindings[APP])
check(route_count==17,'route inventory actual count')
bundles={}
for n in data:
    if n.startswith('execution/bundle-') and n.endswith('/identity.json'):
        prefix=n.rsplit('/',1)[0]; ident=j(n); h=ident['head']; manifest=j(prefix+'/manifest.json'); runtime=j(prefix+'/runtime.json'); public=j(prefix+'/public-environment.json')
        check(manifest==bindings[h],'bundle/Git manifest '+h)
        check(sha(canonical(manifest))==ident['manifest_sha256'],'canonical manifest '+h)
        check(sha(canonical(runtime))==ident['runtime_sha256'],'canonical runtime '+h)
        check(sha(canonical(public))==ident['public_environment_sha256'],'canonical public environment '+h)
        check(sha(data[prefix+'/runner.py'])==ident['runner_sha256'],'runner bundle '+h)
        check(runtime['autoload']==loaders,'recorded/current loader identity '+h)
        check(runtime['composer_lock_sha256']==manifest['composer.lock'],'runtime/lock binding '+h)
        check(public.get('synthetic_testing_app_key_configured') is True and not {'APP_KEY','DB_PASSWORD','DB_USERNAME'}&set(public),'public key descriptor '+h)
        bundles[prefix.removeprefix('execution/')]=(ident,manifest,runtime,public)
check(len(bundles)==8,'eight captured runtime inventories')
run_index=j('execution/reconciled-execution-index.json'); indexed={r['name']:r for r in run_index['runs']}
names=sorted(n.split('/')[1] for n in data if re.fullmatch(r'execution/[^/]+/result\.json',n))
check(set(names)==set(indexed) and len(names)==11,'eleven actual invocations')
runs=[]; case_carries={}
for name in names:
    prefix='execution/'+name; r=j(prefix+'/result.json'); c=j(prefix+'/command.json'); launch=j('execution/launch-'+name+'.json'); os=j(prefix+'/os-exit.json'); ident,manifest,runtime,public=bundles[r['bundle']]
    check(c['source_before']==r['source_after']=={'head':ident['head'],'status':''},'run exact source '+name)
    check(c['source_input_count']==r['source_input_count']==len(manifest)==2423,'run input count '+name)
    check(c['before_digest']==r['after_digest']==ident['manifest_sha256'],'run source digest '+name)
    check(c['before_git_equal'] and c['overlays']==[],'original clean no-overlay '+name)
    check(all(r[k] for k in ['autoload_unchanged','runner_unchanged','source_head_unchanged','source_status_unchanged','source_unchanged']),'run stability '+name)
    check(r['runner_after_sha256']==launch['runner_sha256']==ident['runner_sha256'],'run runner '+name)
    check(c['public_environment']==public,'run environment '+name)
    check(int(data[prefix+'/process.exit'].strip())==r['process_exit'],'original PHP exit '+name)
    check(sha(data[prefix+'/log.txt'])==r['log_sha256'],'original log '+name)
    check((launch['pid'],str(launch['process_start_ticks']),launch['uid'])==(os['pid'],str(os['process_start_ticks']),os['uid']) and os['identity_matched'] and os['state']=='Z','launch/OS witness '+name)
    check(os['observed_os_exit']==r['verifier_exit'] and os['raw_exit_status']==os['observed_os_exit']<<8,'recorder OS exit '+name)
    check(launch['source']==c['source_before'],'launch source '+name)
    check(indexed[name]['result']==r and indexed[name]['source']==c['source_before'] and indexed[name]['argv']==c['argv'] and indexed[name]['selected']==c['selected_files'] and indexed[name]['observed_os']==os,'reconciled index originals '+name)
    counts=None; components={}; cases=[]
    try:
        xml=ET.fromstring(data[prefix+'/junit.xml']); cases=xml.findall('.//testcase')
    except ET.ParseError:
        check(r['junit'] is None and r['junit_error'] is not None and name=='root-whole-query-observation','retained empty JUnit classification '+name)
    else:
        counts={'tests':len(cases),'assertions':sum(int(t.attrib.get('assertions',0)) for t in cases),'failures':sum(t.find('failure') is not None for t in cases),'errors':sum(t.find('error') is not None for t in cases),'skipped':sum(t.find('skipped') is not None for t in cases)}
        check(counts==r['junit'],'JUnit recomputation '+name)
        for t in cases:
            cl=t.attrib['class']; comp=components.setdefault(cl,dict.fromkeys(counts,0)); comp['tests']+=1; comp['assertions']+=int(t.attrib.get('assertions',0))
            for tag,key in [('failure','failures'),('error','errors'),('skipped','skipped')]: comp[key]+=t.find(tag) is not None
        check(components==indexed[name]['components'],'component recomputation '+name)
    good=r['process_exit']==r['verifier_exit']==os['observed_os_exit']==0
    if good: check(counts is not None and counts['tests']>0 and not counts['errors'] and not counts['failures'],'passing invocation '+name)
    runs.append({'name':name,'source':ident['head'],'counts':counts,'PHP_exit':r['process_exit'],'recorder_exit':r['verifier_exit'],'observed_recorder_OS_exit':os['observed_os_exit'],'components':components,'pass':good})
    case_carries[name]=[{'name':t.attrib['name'],'class':t.attrib['class'],'assertions':int(t.attrib.get('assertions',0)),'error':t.find('error') is not None,'failure':t.find('failure') is not None} for t in cases]
check(sum(r['pass'] for r in runs)==run_index['passing_invocations']==5,'five actual passing invocations')
check(sum(not r['pass'] for r in runs)==run_index['nonzero_invocations']==6,'six original nonzero invocations')
cheap=[]
for n in sorted(n for n in data if n.startswith('cheap/')):
    c=j(n); matches=re.findall(r'Ran (\d+) tests? in',c['stdout']+'\n'+c['stderr'])
    check(c['exit']==0 and c['head']=='760c9388b8f07f250fc67d5489f93feb85420051' and len(matches)==1 and re.search(r'\nOK\s*\Z',c['stderr']+'\n'+c['stdout']) is not None,'cheap original receipt '+n)
    cheap.append({'member':n,'count':int(matches[0]),'exit':c['exit'],'argv':c['argv']})
check(len(cheap)==10 and sum(c['count'] for c in cheap)==263,'263 cheap tests')
cheapcarry=j('execution/cheap-source-carry-bb3d2998.json')
check(cheapcarry['exit']==0 and cheapcarry['stdout']=='' and cheapcarry['stderr']=='','cheap carry receipt')
check(git('diff','--name-only',cheapcarry['base'],TARGET,'--',*cheapcarry['scope'])==b'','actual current cheap scope carry')
pint=j('execution/final-pint-bb3d2998.json')
check(pint['head']==APP and pint['exit']==0 and pint['file_count']==20 and pint['argv'][:2]==['vendor/bin/pint','--test'] and len(pint['argv'][2:])==20,'Pint exact20')
check(json.loads(pint['stdout'])=={'tool':'pint','result':'passed'} and pint['stderr']=='','native agent-format Pint receipt')
for p in pint['argv'][2:]: check(sha((REPO/p).read_bytes())==bindings[APP][p],'Pint current file carry '+p)
imports={
 'independent-design-original-REVISE.md':'paid252-tax-http-design-review-d8ecca68-20261015.md',
 'independent-design-original-REVISE.json':'paid252-tax-http-design-review-d8ecca68-20261015.json',
 'independent-design-DECISION.md':'paid252-tax-http-design-review-9d1a67db-20261015.md',
 'independent-design-DECISION.json':'paid252-tax-http-design-review-9d1a67db-20261015.json',
 'independent-source-original-REVISE.md':'paid252-tax-http-source-review-a565e54a-20261015.md',
 'independent-source-original-REVISE.json':'paid252-tax-http-source-review-a565e54a-20261015.json',
 'independent-source-ae1-residual-REVISE.md':'paid252-tax-http-source-review-ae1cfe04-20261015.md',
 'independent-source-ae1-residual-REVISE.json':'paid252-tax-http-source-review-ae1cfe04-20261015.json',
 'independent-source-e3-DECISION.md':'paid252-tax-http-source-review-e3f06b08-20261015.md',
 'independent-source-e3-report.json':'paid252-tax-http-source-review-e3f06b08-20261015.json',
 'independent-source-DECISION.md':'paid252-tax-http-source-correction-bb3d2998-20261015.md',
 'independent-source-report.json':'paid252-tax-http-source-correction-bb3d2998-20261015.json'}
import_report=[]
for dest,origin in imports.items():
    b=(PACKET/dest).read_bytes(); check(b==(pathlib.Path('/workspace/runtime')/origin).read_bytes(),'unchanged independent original '+dest); import_report.append({'path':dest,'sha256':sha(b)})
doc_delta=git('diff','--name-only',APP,TARGET).decode().splitlines()
check(all(p.startswith('docs/') for p in doc_delta),'final doc-only delta')
check(git('diff','--name-only',BASE,TARGET,'--','database/','contracts/','resources/','scripts/','.github/','composer.json','composer.lock','bootstrap/providers.php')==b'','frozen domain/profile/dependency/policy scope')
check(b"'http_mounted' => false" in (REPO/'config/production-tax-checkout.php').read_bytes(),'producer default off')
check(b"'http_mounted' => false" in (REPO/'config/paid-tax-grants.php').read_bytes(),'consumer default off')
check(git('status','--porcelain=v1')==b'' and git('rev-parse','HEAD').decode().strip()==TARGET,'candidate remains frozen clean')
report={'verification':'PASS','reviewed_sha':TARGET,'app_source_sha':APP,'base_actual_merge_sha':BASE,'created_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'archive':{'sha256':idx['archive_sha256'],'bytes':len(archive_body),'members':len(data),'all_indexed_digests_valid':True,'exact_safe_regular_member_set':True},'source':{'heads':sorted(bindings),'inventories':len(bundles),'inputs_each':2423,'total_path_bindings':sum(map(len,bindings.values())),'distinct_archive_source_blobs':len(blobnames),'reachable_Git_objects_verified':len(oid_sha),'actual_current_inputs_equal_to_APP':2423,'actual_route_inputs':route_count,'current_and_recorded_loaders':13,'protected_original_files':239,'protected_actual_base_Git_and_current_bytes_equal':True,'final_doc_delta':doc_delta},'actual_invocations':runs,'actual_pass_count':5,'actual_nonzero_count':6,'empty_JUnit_original':'root-whole-query-observation; partial counts remain null','case_coverage':case_carries,'cheap_263':cheap,'cheap_carry_scope':cheapcarry['scope'],'Pint':{'head':APP,'files':20,'exit':0,'format':'native installed Pint agent JSON','current_file_bytes_equal':True},'original_independent_imports':import_report,'reviewer_execution_boundary':{'app_tests_DB_network_hosted_operations':0,'tracked_edits':0},'limits':['Prior-source narrow case/component carry, not a passing current whole Tax12/selection48 invocation.','No new native execution/census/SQL/concurrency claim.','No host cache rebuild/session/distributed/FPM/provider/live/full-suite or activation claim.']}
check(not OUTPUT.exists(),'do not overwrite report')
OUTPUT.write_text(json.dumps(report,indent=2,sort_keys=True)+'\n')
print(json.dumps({'report':str(OUTPUT),'sha256':sha(OUTPUT.read_bytes()),'archive_members':len(data),'source_Git_bindings':sum(map(len,bindings.values())),'source_blobs':len(blobnames),'actual_invocations':len(runs),'passes':5,'nonzero':6,'current_routes':route_count,'loaders':len(loaders),'cheap_tests':263,'Pint_files':20}))
