from pathlib import Path
import hashlib,json,subprocess
root=Path('/workspace/VA-Studio-member-original-operation')
out=Path('/workspace/runtime/member-original-operation-evidence')
commits={
 'composition-canary-initial':'9d8abd4501b75a2577dc6f72a6a2adb4b6afbf5a',
 'reservation-wrappers-initial':'9d8abd4501b75a2577dc6f72a6a2adb4b6afbf5a',
 'composition-canary-timestamp-fixed':'43e58632d87977b467f58fde0feeef47b6a89dcc',
 'retained-deadline-old-bridge-red':'a082d3b394d071538cc65057074c1d06a7f6c994',
 'composition-fifteen-initial':'a082d3b394d071538cc65057074c1d06a7f6c994',
 'composition-facts-callback-corrected':'2cfd9a0db46a806c0a0bd0c83839b5b584fc3627',
 'composition-fifteen-final':'2cfd9a0db46a806c0a0bd0c83839b5b584fc3627',
 'reservation-clock-wrapper-final':'2cfd9a0db46a806c0a0bd0c83839b5b584fc3627',
}
def blob_hashes(commit, paths):
 p=subprocess.Popen(['git','cat-file','--batch'],cwd=root,stdin=subprocess.PIPE,stdout=subprocess.PIPE)
 result={}
 for path in paths:
  p.stdin.write(f'{commit}:{path}\n'.encode());p.stdin.flush()
  header=p.stdout.readline().decode().strip().split()
  if header[-1]=='missing':result[path]=None;continue
  data=p.stdout.read(int(header[2]));assert p.stdout.read(1)==b'\n'
  result[path]=hashlib.sha256(data).hexdigest()
 p.stdin.close();assert p.wait()==0
 return result
result={}
for name,commit in commits.items():
 receipt=out/(name+'.result.json')
 if not receipt.exists():continue
 s=json.loads((out/(name+'.source.json')).read_text())
 runner=('run-retained-deadline-overlay.py' if 'overlay' in s else ('run-focused.py' if name in ['composition-canary-initial','reservation-wrappers-initial'] else 'run-composed.py'))
 assert hashlib.sha256((out/runner).read_bytes()).hexdigest()==s['runner_sha256']
 expected=blob_hashes(commit,s['source'])
 differences=[p for p,h in s['source'].items() if expected.get(p)!=h]
 assert not differences,(name,differences)
 r=json.loads(receipt.read_text());assert not r['changed_sources']
 entry={'captured_head':s['head'],'matching_git_commit':commit,'files':len(s['source']),
        'all_captured_inputs_match_git':True,'unchanged_during_run':True,'runner':runner,'runner_matches_captured_hash':True,
        'scope':'Complete captured input inventory. The native/runtime environment and unlisted dependencies are not supplied by this Git comparison.'}
 if s['head']!=commit:
  entry['precommit_overlay']='Only MemberOriginalCompositionTest.php differed from captured HEAD; exact tested bytes match this later test-only commit.'
 if 'overlay' in s:
  overlay=s['overlay'];path=Path(overlay['path']);actual=hashlib.sha256(path.read_bytes()).hexdigest()
  old=blob_hashes(overlay['source_commit'],['app/Domain/Grants/Member/MemberOriginalInspection.php'])
  assert actual==overlay['sha256']==old['app/Domain/Grants/Member/MemberOriginalInspection.php']
  entry['executed_class_overlay']={**overlay,'verified_against_git':True,'scope':'One exact old class preloaded over the supporting current tree; not a whole old-application run.'}
 result[name]=entry
(out/'composition-source-bindings.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps({k:{'files':v['files'],'commit':v['matching_git_commit']} for k,v in result.items()},indent=2))
