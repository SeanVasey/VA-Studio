from pathlib import Path
from collections import Counter
import hashlib,json,subprocess,xml.etree.ElementTree as ET
OUT=Path('/workspace/runtime/member-originals-quality-evidence/native-budget-followup')
OLD=OUT.parent
ROOT=Path('/workspace/VA-Studio-member-originals')
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def write(n,v):(OUT/n).write_text(json.dumps(v,indent=2)+'\n')
def cases(p):return [x.attrib['id'] for x in ET.parse(p).iter() if x.tag.rsplit('}',1)[-1]=='testMethod']
summary=json.loads((OUT/'summary.json').read_text())
before=json.loads((OUT/'source-before.json').read_text())
after=json.loads((OUT/'source-after.json').read_text())
assert before['sources']==after['sources'] and before['local_runner_metadata']==after['local_runner_metadata']
assert summary['head']==subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
assert subprocess.check_output(['git','status','--short'],cwd=ROOT,text=True)==''
source=cases(OUT/'generated/phpunit-ci-mysql-source-tests.xml')
selected=[c for i in range(1,25) for c in cases(OUT/f'generated/phpunit-ci-mysql-{i}-tests.xml')]
assert len(source)==len(set(source))==8478
assert len(selected)==len(set(selected))==2145 and set(selected)<=set(source)
previous_source=set(cases(OLD/'generated/phpunit-ci-mysql-source-tests.xml'))
previous_selected={c for i in range(1,25) for c in cases(OLD/f'generated/phpunit-ci-mysql-{i}-tests.xml')}
added=sorted(set(source)-previous_source)
selected_added=sorted(set(selected)-previous_selected)
assert len(added)==3 and len(selected_added)==2
assert set(added)-set(selected_added)=={'Tests\\Feature\\ProductionMemberOriginals\\MemberOriginalIssuerOwnershipTest::test_a_caller_cannot_select_pending_member_initialization_for_any_context_operation'}
assert all('MemberOriginalCompositionTest::' in c for c in selected_added)
assert not previous_source-set(source) and not previous_selected-set(selected)
write('census-verification.json',{'source_case_identities':len(source),'selected_case_identities':len(selected),'duplicate_source_ids':0,'duplicate_selected_ids':0,'selected_are_subset':True,'source_added_since_prior':added,'native_selected_added_since_prior':selected_added,'native_unselected_additions':sorted(set(added)-set(selected_added)),'removed_source_or_selected_cases':[],'proof_scope':'PHPUnit list-tests discovery only; no test bodies executed'})
oldsource=json.loads((OLD/'source-before.json').read_text())
changed=[p for p,h in oldsource['sources'].items() if before['sources'].get(p)!=h]
comment_files=['.github/workflows/final-verification.yml','.gitlab-ci.yml']
assert sorted(changed)==sorted(before['selected_php']+comment_files)
php_changed=[p for p in changed if p.endswith('.php')]
assert sorted(php_changed)==sorted(before['selected_php'])
for path in comment_files:
 prior=subprocess.check_output(['git','show',oldsource['head']+':'+path],cwd=ROOT,text=True)
 current=(ROOT/path).read_text()
 without_comments=lambda text:[line for line in text.splitlines() if not line.lstrip().startswith('#')]
 assert without_comments(prior)==without_comments(current)
patch=subprocess.check_output(['git','diff',oldsource['head'],summary['head'],'--',*comment_files],cwd=ROOT)
(OUT/'historical-census-comment-delta.patch').write_bytes(patch)
oldpint=json.loads((OLD/'pint-selection.json').read_text())['operative_php']
assert set(before['selected_php'])<=set(oldpint)
policy={p:h for p,h in oldsource['sources'].items() if p.startswith('scripts/ci/')}
assert all(before['sources'][p]==h for p,h in policy.items())
write('prior-check-carry.json',{'prior_head':oldsource['head'],'current_head':summary['head'],'prior_source_count':len(oldsource['sources']),'current_source_count':len(before['sources']),'inventory_difference_note':'Followup inventory includes all tracked resources rather than only the prior resource subset; shared paths are compared exactly.','prior_source_changes':changed,'prior_pint_selected':len(oldpint),'prior_pint_unchanged_files':len(oldpint)-len(php_changed),'current_pint_selected_files':len(php_changed),'historical_comment_only_paths':comment_files,'comment_stripped_workflow_bytes_unchanged':True,'ci_script_and_policy_files_unchanged':len(policy),'prior159_selftests_rerun':False,'prior159_scope':'Historical result retained; no new selftest execution or broadened behavioral claim.'})
artifacts=json.loads((OUT/'generated-artifacts.json').read_text())['files']
assert len(artifacts)==50
for n,h in artifacts.items():assert digest(OUT/'generated'/n)==h and not (ROOT/n).exists()
for n,h in json.loads((OUT/'prior-release-before.json').read_text())['files'].items():assert digest(OLD/n)==h
commands='''# C4b native-budget followup quality receipt

Source: `32f083d28db0609a52cade793849a8767a855ae3`, root `/workspace/VA-Studio-member-originals`. This is formatting and test discovery only, not native or application behavior acceptance. The prior 88-file quality release remains byte-identical; this child directory is a new receipt.

## Exact execution

The retained runner was invoked from the root working directory as:

```bash
source /workspace/.va-studio-toolchain/activate.sh
PYTHONDONTWRITEBYTECODE=1 python3 /workspace/runtime/member-originals-quality-evidence/native-budget-followup/run-quality.py
```

It executed these exact validation argv sequentially, each recorded with head, source/runner hashes, nonsecret environment, exit and elapsed time in its command/result JSON:

```bash
vendor/bin/pint --test app/Domain/Grants/Member/MemberOriginalInspection.php app/Domain/Memberships/Production/MembershipReservationContext.php tests/Feature/ProductionMemberOriginals/MemberOriginalCompositionTest.php tests/Feature/ProductionMemberOriginals/MemberOriginalIssuerOwnershipTest.php
python3 scripts/ci/phpunit-shards.py --shards 24 --prefix phpunit-ci-mysql --timings scripts/ci/phpunit-timings-mysql.json --mysql-native-selection
```

The environment forces `APP_ENV=testing`, MySQL host `127.0.0.1`, port `1`, empty socket/URL/password and synthetic discovery-only database/user. No database operation or test body was requested. The generator invokes PHPUnit only with `--list-tests-xml`. Its native policy requires the `phpunit-ci-mysql` prefix; all 50 exact targets were absent and untracked before execution. Both proxies use the root's own Composer autoload metadata; seven proxy/autoload metadata hashes are retained.

## Actual results

- Pint: all 4 explicitly selected PHP files passed; exit 0, 0.265 seconds.
- Discovery: exit 0, 5.945 seconds; 2,145 distinct selected cases across 210 files, from 8,478 cases across 625 files, in 24 nonempty shards. Independent XML reconciliation finds no duplicate or missing selected identity.
- Compared with the prior discovery, the two new Composition methods are selected natively; the one new IssuerOwnership method is in the complete source census and is not selected natively. Exact identities are in `census-verification.json`.
- The warning for 47 files without measured MySQL timing remains visible. Fallback weights and shard runtime estimates are scheduling metadata, not executed durations or passing tests.
- All 2,360 captured source hashes and seven local runner/autoload hashes are unchanged. The prior 2,254-path source inventory differs on these four PHP files plus two previously changed census-comment files (`.github/workflows/final-verification.yml` and `.gitlab-ci.yml`); all non-comment lines in those two files are identical. The exact comment diff is retained. Otherwise the larger followup inventory additionally covers all tracked resources. The earlier 78-file Pint result carries for its 74 unchanged files. The prior 159 policy selftests were not rerun, as instructed; `prior-check-carry.json` verifies unchanged scripts/policy hashes without claiming a new run.
- All 50 generated configs/listings/manifest were copied, hash-verified, and removed by exact generated filename. No unrelated file was deleted. Root Git status is clean and HEAD is unchanged.
- PHP 8.4.26, Python 3.12.14, PHPUnit 12.5.34. Full version outputs are retained.

Both requested validation commands passed. Initial receipt sealing exited 1 because its historical-delta assertion expected only four changed paths and omitted the two census-comment files. `sealing-initial-error.json` preserves the observed tool output and `seal-receipts-initial.py` preserves that exact verifier. The corrected verifier checks the four PHP paths separately and verifies comment-stripped workflow equality. Pint and discovery were not rerun. No style correction, policy selftest rerun, application/database test execution or hosted dispatch occurred in this followup. No secret/private media/environment file is in the public allowlist. Historical failures in other packets are unaffected.

## Public archive scope

`public-artifacts.json` is the explicit relative-path allowlist for this child directory. `artifact-digests.json` hashes every listed artifact except itself, including the allowlist and this command note. The old parent packet is excluded from this new manifest and remains immutable. `seal-receipts.py` records the exact reconciliation/archive verification procedure.
'''
(OUT/'commands.md').write_text(commands)
files=sorted([str(p.relative_to(OUT)) for p in OUT.rglob('*') if p.is_file()]+['artifact-digests.json','public-artifacts.json'])
assert len(files)==len(set(files))
write('public-artifacts.json',{'files':files,'scope':'Public source hashes, exact nonsecret commands, logs, discovery XML/configs and receipts only. No environment file, credentials or private media.','prior_release_unchanged':True})
digests={name:digest(OUT/name) for name in files if name!='artifact-digests.json'}
write('artifact-digests.json',{'algorithm':'sha256','files':digests})
assert all(digest(OUT/n)==h for n,h in digests.items())
print(json.dumps({'public_files':len(files),'hashed_artifacts':len(digests),'source_hashes':len(before['sources']),'metadata_hashes':len(before['local_runner_metadata']),'unchanged_prior_policy_paths':len(policy),'generated_files':len(artifacts),'old88_unchanged':True,'head':summary['head'],'census':summary['census'],'new_cases':added},indent=2))
