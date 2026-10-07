from pathlib import Path
import base64
import datetime
import hashlib
import json
import os
import subprocess
import time


evidence = Path('docs/verification/pdf-seam-composed-review-20261007')
source_sha = subprocess.check_output(['git', 'rev-parse', 'HEAD']).decode().strip()
assert source_sha == '83fe24882d0d1f85e9452ae07dbcd19288cb74c3'
baseline = subprocess.check_output(['git', 'rev-parse', 'HEAD~2']).decode().strip()
assert baseline == '7827a86d405167c15b5c6a04970a74d49111b36a'
approved = sorted(set(subprocess.check_output(['git', 'diff', '--name-only',
    '4f1c5b0^', '4f1c5b0']).decode().splitlines() + ['app/Domain/Contracts/ContractRenderProfile.php']))
assert len(approved) == 19
for name in approved:
    assert Path(name).read_bytes() == subprocess.check_output(['git', 'show', '7501ef9:' + name])


def tree_map(sha):
    result = {}
    for row in subprocess.check_output(['git', 'ls-tree', '-r', '-z', sha]).split(b'\0'):
        if row:
            header, path = row.split(b'\t', 1)
            result[path.decode()] = header.decode()
    return result


old, new = tree_map(baseline), tree_map(source_sha)
modified = [name for name, identity in old.items() if new.get(name) != identity]
unapproved = [name for name in modified if name not in approved]
assert not unapproved, unapproved
v1_assets = [name for name in old if name.startswith('resources/contracts/test-v1/')]
ci_paths = [name for name in old if name.startswith('.github/workflows/') or name.startswith('scripts/ci/')]
assert all(old[name] == new[name] for name in v1_assets + ci_paths)
for name in ['AGENTS.md', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    'tests/fixtures/contracts/retained-render-profile-v1.json',
    'app/Domain/Contracts/TcpdfContractRenderer.php', 'app/Domain/Contracts/ContractText.php']:
    assert old[name] == new[name]

source = {'executed_source': source_sha,
    'executed_tree': subprocess.check_output(['git', 'rev-parse', 'HEAD^{tree}']).decode().strip(),
    'baseline': baseline, 'approved_leaf': '7501ef9f2f1245493a9ca5a584da8be849dd3d12',
    'approved_leaf_source_sha256': {name: hashlib.sha256(Path(name).read_bytes()).hexdigest() for name in approved},
    'baseline_paths': len(old), 'unchanged_baseline_paths': len(old) - len(modified),
    'modified_baseline_paths': modified, 'unapproved_modified_or_missing_baseline_paths': unapproved,
    'unchanged_ci_paths': len(ci_paths), 'unchanged_v1_assets': len(v1_assets),
    'locks_policy_exceptions_v1_pins_preserved': True,
    'selection': ['tests/Unit/ContractRenderProfileRegistryTest.php', 'tests/Unit/TcpdfContractRendererTest.php',
        'tests/Feature/TestContractIssuanceTest.php', 'tests/Feature/TestContractStorageTest.php',
        'tests/Feature/TestIsolatedContractRendererTest.php', 'tests/Feature/TestContractProfileSuccessorTest.php'],
    'started_at': datetime.datetime.now(datetime.timezone.utc).isoformat()}
(evidence / 'source.json').write_text(json.dumps(source, indent=2) + '\n')
environment = dict(os.environ)
environment['APP_KEY'] = 'base64:' + base64.b64encode(os.urandom(32)).decode()
environment['APP_ENV'] = 'testing'
command = ['/workspace/scratch/0c039e9e0645/runtime-recovery/bin/php', '-d', 'memory_limit=512M',
    'vendor/bin/phpunit', '--configuration', 'phpunit.pdf-seam-composition.xml', '--log-junit',
    str(evidence / 'junit.xml'), '--fail-on-empty-test-suite', '--fail-on-warning']
started = time.monotonic()
with (evidence / 'phpunit.txt').open('w') as output:
    result = subprocess.run(command, env=environment, stdout=output, stderr=subprocess.STDOUT)
(evidence / 'exit.json').write_text(json.dumps({'source': source_sha, 'exit_code': result.returncode,
    'duration_seconds': time.monotonic() - started,
    'finished_at': datetime.datetime.now(datetime.timezone.utc).isoformat()}, indent=2) + '\n')
raise SystemExit(result.returncode)
