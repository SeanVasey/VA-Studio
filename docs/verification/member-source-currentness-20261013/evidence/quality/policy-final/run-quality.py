#!/usr/bin/env python3
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time

ROOT = Path('/workspace/VA-Studio-member-source-currentness')
OUT = Path('/workspace/runtime/member-source-currentness-quality/policy-final')
BASE = '7b467c3824e2da81174a5445b6ee639cf0a59d93'
REQUESTED = 'ca0fe30b6614d64a8b9050a39d6944256f170d69'

def git(*args):
    return subprocess.check_output(['git', *args], cwd=ROOT).decode().strip()

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def write(name, value):
    (OUT / name).write_text(json.dumps(value, indent=2, sort_keys=True) + '\n')

def snapshot():
    paths = subprocess.check_output(['git', 'ls-files', '-z', '--', 'app', 'database', 'tests', 'scripts', '.github', 'config', 'resources', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml', 'pint.json', '.gitlab-ci.yml'], cwd=ROOT).decode().split('\0')
    return {p: digest(ROOT / p) for p in sorted(paths) if p and (ROOT / p).is_file()}

head = git('rev-parse', 'HEAD')
changed = subprocess.check_output(['git', 'diff', '--name-only', '--diff-filter=ACM', '-z', BASE, '--', '*.php'], cwd=ROOT).decode().split('\0')
changed = [p for p in changed if p]
before = snapshot()
write('sources-before.json', before)
metadata = {
    'head': head,
    'requested_head': REQUESTED,
    'base': BASE,
    'working_directory': str(ROOT),
    'started_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'runner_sha256': digest(Path(__file__)),
    'status_before': git('status', '--short'),
    'changed_php_discovery_argv': ['git', 'diff', '--name-only', '--diff-filter=ACM', '-z', BASE, '--', '*.php'],
    'changed_php_files': changed,
    'changed_php_count': len(changed),
    'source_count': len(before),
    'requested_to_actual_diff': git('diff', '--name-only', REQUESTED, head),
    'python_version': subprocess.check_output(['python3', '--version']).decode().strip(),
    'php_version': subprocess.check_output(['php', '--version']).decode(),
    'pint_proxy_realpath': str((ROOT / 'vendor/bin/pint').resolve()),
    'pint_proxy_sha256': digest(ROOT / 'vendor/bin/pint'),
    'autoload_realpath': str((ROOT / 'vendor/autoload.php').resolve()),
    'autoload_sha256': digest(ROOT / 'vendor/autoload.php'),
    'environment_setup': 'source /workspace/.va-studio-toolchain/activate.sh; PYTHONDONTWRITEBYTECODE=1',
}
write('metadata.json', metadata)
(OUT / 'changed-php-files.txt').write_text('\n'.join(changed) + '\n')
checks = [(name, ['python3', 'scripts/ci/' + name + '.py']) for name in ('test-phpunit-shards', 'test-database-receipts', 'test-ci-scope', 'test-workflow-cadence')]
# Pint already passed eight unchanged PHP files in the original invocation.
results = []
env = os.environ.copy()
env['PYTHONDONTWRITEBYTECODE'] = '1'
for name, argv in checks:
    command = {'argv': argv, 'cwd': str(ROOT), 'head_at_start': git('rev-parse', 'HEAD'), 'started_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'runner_sha256': digest(Path(__file__))}
    write(name + '.command.json', command)
    start = time.monotonic()
    with (OUT / (name + '.log')).open('wb') as log:
        completed = subprocess.run(argv, cwd=ROOT, stdout=log, stderr=subprocess.STDOUT, env=env)
    text = (OUT / (name + '.log')).read_text(errors='replace')
    (OUT / (name + '.exit')).write_text(str(completed.returncode) + '\n')
    count_match = re.search(r'Ran (\d+) tests? in ', text)
    result = {**command, 'exit': completed.returncode, 'duration_seconds': round(time.monotonic() - start, 6), 'completed_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'log_sha256': digest(OUT / (name + '.log')), 'unittest_reported_tests': int(count_match.group(1)) if count_match else None, 'selected_php_files': len(changed) if name == 'pint' else None}
    write(name + '.result.json', result)
    results.append(result)
    print(json.dumps({'check': name, 'exit': completed.returncode, 'tests': result['unittest_reported_tests'], 'php_files': result['selected_php_files'], 'duration_seconds': result['duration_seconds']}), flush=True)
    print('\n'.join(text.splitlines()[-8:]), flush=True)

after = snapshot()
write('sources-after.json', after)
source_check = {'before_count': len(before), 'after_count': len(after), 'changed': [p for p in sorted(set(before) | set(after)) if before.get(p) != after.get(p)], 'head_before': head, 'head_after': git('rev-parse', 'HEAD'), 'status_after': git('status', '--short'), 'runner_sha256': digest(Path(__file__)), 'requested_to_final_diff': git('diff', '--name-only', REQUESTED, 'HEAD')}
write('source-check.json', source_check)
write('results.json', results)
print(json.dumps({'source_check': source_check}), flush=True)
raise SystemExit(0 if all(r['exit'] == 0 for r in results) and not source_check['changed'] else 1)
