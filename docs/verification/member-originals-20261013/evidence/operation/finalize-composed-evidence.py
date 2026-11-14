from pathlib import Path
import hashlib,json,subprocess,xml.etree.ElementTree as ET
out=Path('/workspace/runtime/member-original-operation-evidence')
root=Path('/workspace/VA-Studio-member-original-operation')
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
subprocess.run(['python3',str(out/'bind-composed-receipts.py')],check=True)
def cases(name):
 tree=ET.parse(out/(name+'.xml'))
 return {x.attrib['classname']+'::'+x.attrib['name']:{'assertions':int(x.attrib.get('assertions',0)),
  'outcome':next((v for v in ['error','failure','skipped'] if x.find(v) is not None),'passed')}
  for x in tree.iter('testcase')}
red=cases('retained-deadline-old-bridge-red');green=cases('composition-fifteen-final')
assert len(red)==2 and len(green)==15
assert all(x['outcome']=='failure' for x in red.values())
assert all(x['outcome']=='passed' for x in green.values())
old=json.loads((out/'retained-deadline-old-bridge-red.source.json').read_text())
new=json.loads((out/'composition-fifteen-final.source.json').read_text())
matched={}
for path in ['tests/Feature/ProductionMemberOriginals/MemberOriginalRetainedDeadlineTest.php','tests/Support/MemberOriginalCommittedClock.php']:
 assert old['source'][path]==new['source'][path]
 matched[path]=new['source'][path]
comparison={'scope':'Two same-byte tests against one exact old MemberOriginalInspection class preloaded over the supporting current tree, then the fixed current class in the complete 15-case invocation. No whole-old-source, physical-elapsed-time or native-MySQL claim.',
 'red_source':old['overlay'],'fixed_supporting_commit':new['head'],'fixed_inspection_sha256':new['source']['app/Domain/Grants/Member/MemberOriginalInspection.php'],
 'identical_test_inputs':matched,'cases':{k:{'old':v,'fixed':green[k]} for k,v in red.items()}}
(out/'retained-deadline-red-green-comparison.json').write_text(json.dumps(comparison,indent=2)+'\n')
expected={'composition-fifteen-final':15,'reservation-wrappers-initial':17,'reservation-clock-wrapper-final':1}
summary={}
for name,n in expected.items():
 result=json.loads((out/(name+'.result.json')).read_text());actual=cases(name)
 assert len(actual)==n and result['exit']==0 and not result['changed_sources']
 assert all(x['outcome']=='passed' for x in actual.values())
 summary[name]={'result':result,'cases':actual}
(out/'composition-final-coverage.json').write_text(json.dumps({'scope':'Three focused migration-backed SQLite invocations on their stated source boundaries, not one suite run or native acceptance. Earlier wrapper sources have an explicit carry comparison; current compose15 and portable clock1 match final author source.', 'invocations':summary},indent=2)+'\n')
checkpoint=json.loads((out/'checkpoint-5bbebd77-artifacts.json').read_text())
for name,entry in checkpoint['artifacts'].items():
 assert sha(out/name)==entry['sha256'],name
excluded={'build-composition.py','final-artifacts.json'}
files={p.name:{'sha256':sha(p),'bytes':p.stat().st_size} for p in sorted(out.iterdir()) if p.is_file() and p.name not in excluded}
manifest={'scope':'Frozen completed operation-lane receipts; all historical failures and expected reds retained. Native I/O source environment has synthetic credential fields and should be projected by the packet allowlist; no .env, keys, DB files or private row captures are members. No production/host/provider claims.',
 'final_author_commit':new['head'],'artifacts':files,'excluded':sorted(excluded)}
(out/'final-artifacts.json').write_text(json.dumps(manifest,indent=2)+'\n')
print(json.dumps({'members':len(files),'same_test_red_green_cases':len(red),'final_composed_cases':len(green),'focused_sqlite_cases_by_invocation':expected},indent=2))
