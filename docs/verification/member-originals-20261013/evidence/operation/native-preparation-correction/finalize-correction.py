from pathlib import Path
import hashlib,json,subprocess,xml.etree.ElementTree as ET
root=Path('/workspace/VA-Studio-member-original-operation');out=Path('/workspace/runtime/member-original-operation-evidence/native-preparation-correction');diagnostic=out.parent/'native-preparation-diagnostic'
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
commit='9399dd998cc3d972b94f6753e576e2c756438657';root_commit=subprocess.check_output(['git','rev-parse','32f083d2'],cwd=root,text=True).strip()
cache={}
def verify_git(at,values):
 p=subprocess.Popen(['git','cat-file','--batch'],cwd=root,stdin=subprocess.PIPE,stdout=subprocess.PIPE)
 for path,h in values.items():
  k=(at,path)
  if k not in cache:
   p.stdin.write(f'{at}:{path}\n'.encode());p.stdin.flush();line=p.stdout.readline().split();assert line[-1]!=b'missing',(at,path);data=p.stdout.read(int(line[-1]));assert p.stdout.read(1)==b'\n';cache[k]=hashlib.sha256(data).hexdigest()
  assert h==cache[k],(at,path)
 p.stdin.close();assert p.wait()==0
runs={}
for name,n in [('sqlite-owned-composition',22),('sqlite-public-reservation-proof',1),('native-positive-final',1)]:
 s=json.loads((out/(name+'.source.json')).read_text());r=json.loads((out/(name+'.result.json')).read_text());assert s['head']==commit and r['exit']==0 and not r['changed_sources'];values=s.get('source',s.get('files'));verify_git(commit,values);verify_git(root_commit,values)
 assert 'overlay' not in s
 xml=ET.parse(out/(name+'.xml'));cases=list(xml.iter('testcase'));assert len(cases)==n
 assert all(not any(c.find(e) is not None for e in ['error','failure','skipped']) for c in cases)
 runner='run-native-fixed.py' if name.startswith('native-') else 'run-composed.py';assert sha(out/runner)==s['runner_sha256']
 runs[name]={'author_commit':commit,'equivalent_root_commit':root_commit,'complete_input_count':len(values),'all_inputs_match_both_commits':True,'unchanged_during_run':True,'runner':runner,'runner_hash_verified':True,'result':r,'cases':{c.attrib['classname']+'::'+c.attrib['name']:int(c.attrib['assertions']) for c in cases}}
old=json.loads((diagnostic/'native-one-v2.source.json').read_text())['files'];new=json.loads((out/'native-positive-final.source.json').read_text())['files'];assert set(old)==set(new);changed=[p for p in old if old[p]!=new[p]]
assert changed==sorted(changed)
assert set(changed)=={'app/Domain/Grants/Member/MemberOriginalInspection.php','app/Domain/Memberships/Production/MembershipReservationContext.php'},changed
static=json.loads((out/'static-source.json').read_text());verify_git(commit,static['files'])
comparison={'scope':'Same positive/exact-repeat test and supporting fixture bytes: old diagnostic safe refusal at original60s vs uninstrumented corrected success. Diagnostic class overlays are separately retained; timing is not a performance guarantee. Only two application files differ in complete captured native inputs.',
 'same_selected_test_sha256':new['tests/Feature/ProductionMemberOriginals/MemberOriginalPreparationIntegrationTest.php'],'same_fixture_sha256':new['tests/Support/MemberOriginalFixtures.php'],'old_supporting_commit':'2cfd9a0db46a806c0a0bd0c83839b5b584fc3627','fixed_commit':commit,'changed_captured_inputs':{p:{'old':old[p],'fixed':new[p]} for p in changed}}
(out/'source-verification.json').write_text(json.dumps({'runs':runs,'static_four_files_match_commit':True,'native_comparison':comparison},indent=2)+'\n')
files={p.name:{'sha256':sha(p),'bytes':p.stat().st_size} for p in sorted(out.iterdir()) if p.is_file() and p.name!='release-manifest.json'}
(out/'release-manifest.json').write_text(json.dumps({'scope':'Frozen completed correction receipts. Source environment credential fields require established public projection; no .env, keys, databases or raw private rows are included. No production/provider claim. Original164 and diagnostic25 releases remain immutable.', 'author_commit':commit,'equivalent_root_runtime_commit':root_commit,'artifacts':files},indent=2)+'\n')
print(json.dumps({'members':len(files),'runs':{n:r['result']['junit'] for n,r in runs.items()},'native_changed_inputs':changed},indent=2))
