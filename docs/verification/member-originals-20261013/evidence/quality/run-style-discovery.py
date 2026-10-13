from pathlib import Path
import hashlib,json,os,shutil,subprocess,sys
r=Path('/workspace/VA-Studio-member-originals');o=Path('/workspace/runtime/member-originals-quality-evidence');frozen=json.loads((o/'source-before.json').read_text());runner=str(o/'run-check.py')
operative=[p for p in frozen['changed_php'] if p.startswith(('app/','bootstrap/','config/','database/','scripts/','tests/'))]
excluded=[p for p in frozen['changed_php'] if p not in operative]
(o/'pint-selection.json').write_text(json.dumps({'base':frozen['base'],'head':frozen['head'],'operative_php':operative,'excluded_historical_php':excluded,'scope':'Parent clarified operative source paths before execution; no archived source normalization.'},indent=2)+'\n')
style=subprocess.run(['python3',runner,'--name','pint-operative','--','vendor/bin/pint','--test',*operative],cwd=r)
prefix='phpunit-ci-mysql';names=[f'{prefix}-{i}.xml' for i in range(1,25)]+[f'{prefix}-{i}-tests.xml' for i in range(1,25)]+[f'{prefix}-source-tests.xml',f'{prefix}-manifest.json']
assert len(names)==50 and len(set(names))==50
tracked=set(subprocess.check_output(['git','ls-files','-z'],cwd=r).decode().split('\0'))
assert all(not (r/name).exists() and name not in tracked for name in names)
(o/'generated-before.json').write_text(json.dumps({'required_prefix':prefix,'unique_requested_prefix':'phpunit-ci-member-originals','reason':'Actual script CLI requires mysql prefix for native selection. Parent authorized required prefix after exact absence check.','expected_names':names,'all50_absent':True,'all50_untracked':True,'other_prefix_paths':sorted(p.name for p in r.glob(prefix+'*'))},indent=2)+'\n')
discovery=subprocess.run(['python3',runner,'--name','native-discovery','--','python3','scripts/ci/phpunit-shards.py','--shards','24','--prefix',prefix,'--timings','scripts/ci/phpunit-timings-mysql.json','--mysql-native-selection'],cwd=r)
created=[name for name in names if (r/name).is_file()];archive=o/'generated';archive.mkdir(exist_ok=True)
hashes={}
for name in created:
 source=r/name;target=archive/name
 assert not target.exists();shutil.copyfile(source,target)
 digest=hashlib.sha256(source.read_bytes()).hexdigest();assert hashlib.sha256(target.read_bytes()).hexdigest()==digest
 hashes[name]=digest
(o/'generated-artifacts.json').write_text(json.dumps({'invocation_exit':discovery.returncode,'expected_count':50,'created_count':len(created),'files':hashes},indent=2)+'\n')
for name in created:
 assert hashlib.sha256((r/name).read_bytes()).hexdigest()==hashes[name]
 (r/name).unlink()
(o/'generated-cleanup.json').write_text(json.dumps({'deleted_exact_names':created,'all_deleted_names_absent':all(not (r/n).exists() for n in created),'archived_bytes_verified':True,'unrelated_files_deleted':False,'root_git_status_after':subprocess.check_output(['git','status','--short'],cwd=r,text=True)},indent=2)+'\n')
result={'pint_exit':style.returncode,'pint_files':len(operative),'historical_php_excluded':len(excluded),'discovery_exit':discovery.returncode,'generated_count':len(created)}
m=archive/(prefix+'-manifest.json')
if m.exists():
 data=json.loads(m.read_text());result['census']={'source_cases':data['source_test_cases'],'source_files':data['source_files'],'selected_cases':data['selection']['test_cases'],'selected_files':data['selection']['files'],'shards':len(data['shards']),'untimed_files':len(data['weighting']['untimed_files'])};assert len(created)==50 and len(data['shards'])==24 and all(x['test_cases']>0 for x in data['shards'])
(o/'style-discovery-result.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2));raise SystemExit(style.returncode or discovery.returncode)
