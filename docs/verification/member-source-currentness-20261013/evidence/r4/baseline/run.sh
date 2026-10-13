#!/usr/bin/env bash
set -u
set -o pipefail
umask 077
probe_directory=/workspace/runtime/member-policy-admission-evidence/baseline
source_root=${1:?exact clean source worktree required}
expected_sha=${2:?exact 40-character source SHA required}
expected_result=${3:?expected result must be fail or pass}
case "$expected_result" in fail|pass) ;; *) exit 2 ;; esac
if ! [[ "$expected_sha" =~ ^[a-f0-9]{40}$ ]]; then exit 2; fi
if test "$expected_result" = fail && test "$expected_sha" != 0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371; then
  printf 'The red baseline must use the exact reviewed pre-fix source.\n' >&2
  exit 2
fi
if test ! -f "$source_root/vendor/autoload.php"; then
  printf 'Private Composer metadata must be prepared in the selected source checkout first.\n' >&2
  exit 2
fi
source /workspace/.va-studio-toolchain/activate.sh
export MEMBER_POLICY_PROBE_OUTPUT="$probe_directory/$expected_result-$expected_sha"
if test -e "$MEMBER_POLICY_PROBE_OUTPUT"; then
  printf 'Preserve existing execution evidence; this runner will not overwrite it.\n' >&2
  exit 2
fi
mkdir -m 700 "$MEMBER_POLICY_PROBE_OUTPUT"
cd "$source_root"
source_receipt() {
  python3 - "$source_root" "$expected_sha" "$probe_directory" "$MEMBER_POLICY_PROBE_OUTPUT" "$1" <<'PY'
from pathlib import Path
import hashlib,json,subprocess,sys
root=Path(sys.argv[1]); expected=sys.argv[2]; programs=Path(sys.argv[3]); evidence=Path(sys.argv[4]); mode=sys.argv[5]
head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=root).decode().strip()
status=subprocess.check_output(['git','status','--porcelain'],cwd=root).decode()
assert head==expected, f'Source HEAD differs from expected {expected}'
assert status=='', 'Source worktree is not clean; no test is authorized on mixed bytes.'
names=subprocess.check_output(['git','ls-files','-z'],cwd=root).decode().rstrip('\0').split('\0')
digest=lambda path: hashlib.sha256(path.read_bytes()).hexdigest()
files={name:digest(root/name) for name in names if (root/name).is_file()}
receipt={'head':head,'status':status,'tracked_file_count':len(files),'files':files,
 'member_grant_policy_sha256':digest(root/'app/Domain/Grants/Member/MemberGrantPolicy.php'),
 'programs':{name:digest(programs/name) for name in ['MemberGrantMixedProvenanceRegressionTest.php','run.sh']},
 'private_autoload_sha256':digest(root/'vendor/autoload.php')}
(evidence/f'source-{mode}.json').write_text(json.dumps(receipt,indent=2)+'\n')
if mode=='after':
 assert receipt==json.loads((evidence/'source-before.json').read_text()), 'Source or probe changed during execution.'
 print(f'All {len(files)} tracked hashes and external probe/runner hashes unchanged.')
PY
}
source_receipt before || exit 2
php -r 'require $argv[1]."/vendor/autoload.php"; $path=(new ReflectionClass(App\Domain\Grants\Member\MemberGrantPolicy::class))->getFileName(); if ($path !== $argv[1]."/app/Domain/Grants/Member/MemberGrantPolicy.php") { throw new LogicException("Autoloader does not resolve the requested source worktree."); } echo "PHP ",PHP_VERSION,PHP_EOL,"MemberGrantPolicy ",$path,PHP_EOL;' "$source_root" > "$MEMBER_POLICY_PROBE_OUTPUT/runtime.txt" || exit 2
php "$source_root/vendor/bin/phpunit" --no-configuration --bootstrap "$source_root/vendor/autoload.php" --do-not-cache-result --colors=never --log-junit "$MEMBER_POLICY_PROBE_OUTPUT/phpunit.xml" "$probe_directory/MemberGrantMixedProvenanceRegressionTest.php" > "$MEMBER_POLICY_PROBE_OUTPUT/phpunit.log" 2>&1
probe_status=$?
printf '%s\n' "$probe_status" > "$MEMBER_POLICY_PROBE_OUTPUT/phpunit.exit"
source_receipt after || exit 2
python3 - "$MEMBER_POLICY_PROBE_OUTPUT" "$expected_result" <<'PY'
from pathlib import Path
import hashlib,json,sys,xml.etree.ElementTree as ET
p=Path(sys.argv[1]); expected=sys.argv[2]
suite=ET.parse(p/'phpunit.xml').getroot().find('testsuite')
assert suite is not None and suite.get('tests')=='1'
assert suite.get('errors')=='0' and suite.get('skipped')=='0'
observed=json.loads((p/'observed.json').read_text())['observed']
code=int((p/'phpunit.exit').read_text())
if expected=='fail':
 assert code==1 and suite.get('failures')=='1'
 assert observed['decision']=='admitted' and len(observed['factory_calls'])==3
 failures=suite.findall('.//failure')
 assert len(failures)==1 and 'Verified member grant configuration must refuse SQLite before any authority factory callback.' in (failures[0].text or '')
else:
 assert code==0 and suite.get('failures')=='0'
 assert observed=={'decision':'provenance_driver','factory_calls':[]}
result={'expected':expected,'expected_behavior_observed':True,'tests':int(suite.get('tests')),'assertions':int(suite.get('assertions')),
 'failures':int(suite.get('failures')),'errors':0,'skips':0,'exit':code,'xml_duration_seconds':suite.get('time'),'observed':observed}
(p/'verification.json').write_text(json.dumps(result,indent=2)+'\n')
files={f.name:{'bytes':f.stat().st_size,'sha256':hashlib.sha256(f.read_bytes()).hexdigest()} for f in sorted(p.iterdir()) if f.is_file() and f.name!='artifacts.json'}
(p/'artifacts.json').write_text(json.dumps(files,indent=2)+'\n')
print(json.dumps(result))
PY
verification_status=$?
cat "$MEMBER_POLICY_PROBE_OUTPUT/runtime.txt" "$MEMBER_POLICY_PROBE_OUTPUT/phpunit.log"
if test "$verification_status" != 0; then exit 2; fi
exit "$probe_status"
