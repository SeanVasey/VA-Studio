#!/usr/bin/env python3
"""Account feature HTTP focused runner. Public receipts exclude environment secrets and artifacts."""
import argparse
import base64
import secrets
import datetime
import hashlib
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import time
import xml.etree.ElementTree as ET

p = argparse.ArgumentParser()
p.add_argument('--name', required=True)
p.add_argument('--root', default='/workspace/VA-Studio-tax-v2-committed-read')
p.add_argument('--output', default='/workspace/runtime/account-features-http-evidence-20261015')
p.add_argument('--driver', required=True, choices=['sqlite', 'mysql'])
p.add_argument('--database', default='vaseyaudio_account_features')
p.add_argument('--filter')
p.add_argument('--continue-on-defect', action='store_true')
p.add_argument('--timeout', type=int, default=1800)
p.add_argument('files', nargs='+')
a = p.parse_args()
root, output = Path(a.root).resolve(), Path(a.output).resolve()
output.mkdir(parents=True, exist_ok=True)
run = output / a.name
run.mkdir(exist_ok=False)
runner_bytes = Path(__file__).read_bytes()

def digest(data):
    return hashlib.sha256(data).hexdigest()

def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()

def write_new(path, value):
    data = json.dumps(value, indent=2, sort_keys=True).encode() + b'\n'
    with path.open('xb') as stream:
        stream.write(data)

def git(*args):
    return subprocess.check_output(['git', *args], cwd=root)

def source_state():
    return {'head': git('rev-parse', 'HEAD').decode().strip(),
            'status': git('status', '--porcelain=v1').decode()}

def observed_inputs():
    tracked = set(git('ls-files', '-z', '--cached').decode().split('\0'))
    prefixes = ('app/', 'bootstrap/', 'config/', 'database/', 'resources/contracts/', 'routes/', 'scripts/', 'tests/')
    exact = {'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml'}
    names = {n for n in tracked if n and (n.startswith(prefixes) or n in exact)}
    for directory in prefixes:
        for candidate in (root / directory).rglob('*'):
            if candidate.is_file() and '__pycache__' not in candidate.parts:
                names.add(str(candidate.relative_to(root)))
    paths = sorted({n for n in names if n and (n.startswith(prefixes) or n in exact)
                    and not n.startswith('bootstrap/cache/')})
    # Refuse undeclared local overlays, including ignored files, without reading their bytes.
    return {n: digest((root / n).read_bytes()) if n in tracked and (root / n).is_file() else None for n in paths}

def committed_inputs(head, names):
    result = {}
    proc = subprocess.Popen(['git', 'cat-file', '--batch'], cwd=root,
                            stdin=subprocess.PIPE, stdout=subprocess.PIPE)
    try:
        for name in names:
            proc.stdin.write((head + ':' + name + '\n').encode())
            proc.stdin.flush()
            header = proc.stdout.readline().split()
            if len(header) != 3 or header[1] != b'blob':
                result[name] = None
                continue
            size = int(header[2])
            content = proc.stdout.read(size)
            if len(content) != size or proc.stdout.read(1) != b'\n':
                raise RuntimeError('Incomplete Git blob response')
            result[name] = digest(content)
    finally:
        proc.stdin.close()
        if proc.wait() != 0:
            raise RuntimeError('Git source binding failed')
    return result

def autoload_inputs():
    paths = [root / 'vendor/autoload.php', *sorted((root / 'vendor/composer').glob('*'))]
    return {str(q.relative_to(root)): digest(q.read_bytes()) for q in paths if q.is_file()}

def reconcile_junit(path):
    document = ET.parse(path).getroot()
    top = document if document.tag == 'testsuite' else document.find('testsuite')
    if top is None:
        raise ValueError('No top-level JUnit suite')
    cases = list(document.iter('testcase'))
    if not cases:
        raise ValueError('No executed testcases')
    counts = {k: int(top.attrib[k]) for k in ['tests', 'assertions', 'failures', 'errors', 'skipped']}
    actual = {'tests': len(cases), 'assertions': sum(int(c.get('assertions', '0')) for c in cases),
              'failures': sum(c.find('failure') is not None for c in cases),
              'errors': sum(c.find('error') is not None for c in cases),
              'skipped': sum(c.find('skipped') is not None for c in cases)}
    if counts != actual:
        raise ValueError('Suite/testcase totals differ: ' + repr((counts, actual)))
    return counts

state = source_state()
before = observed_inputs()
expected = committed_inputs(state['head'], before)
if before != expected or any(v is None for v in before.values()):
    write_new(run / 'setup-refusal.json', {'source': state, 'reason': 'Inputs differ from committed source',
              'differences': {k: [before.get(k), expected.get(k)] for k in before if before[k] != expected.get(k)}})
    raise SystemExit('Freeze all declared inputs before execution; refusal recorded.')
for selected in a.files:
    if selected not in before:
        raise SystemExit('Selected test is outside captured committed scope: ' + selected)

tmp = Path('/tmp/va-studio-paid-financial-retrieval-20261015')
tmp.mkdir(mode=0o700, exist_ok=True)
if tmp.is_symlink() or not tmp.is_dir() or tmp.stat().st_uid != os.geteuid():
    raise SystemExit('Owned TMPDIR prerequisite refused')
env_delta = {'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'DB_URL': '', 'DB_CONNECTION': a.driver,
             'DB_DATABASE': ':memory:' if a.driver == 'sqlite' else a.database,
             'DB_HOST': '127.0.0.1', 'DB_PORT': '33520',
             'DB_SOCKET': '' if a.driver == 'sqlite' else '/workspace/runtime/mysql-paid-financial-retrieval-run/mysql.sock',
             'DB_USERNAME': 'root', 'DB_PASSWORD': '', 'CACHE_STORE': 'array',
             'SESSION_DRIVER': 'array', 'QUEUE_CONNECTION': 'sync', 'TMPDIR': str(tmp)}
# Host-only ephemeral testing key; never emit/archive the generated value.
env_delta['APP_KEY'] = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode()
env = {**os.environ, **env_delta}
public_env = {k: v for k, v in env_delta.items() if k not in {'DB_USERNAME', 'DB_PASSWORD', 'APP_KEY'}}
public_env['synthetic_testing_app_key_configured'] = True
autoload_before = autoload_inputs()
runtime = {'php': subprocess.check_output(['php', '-v'], text=True),
           'phpunit': subprocess.check_output(['php', '-r', 'require "vendor/autoload.php"; '
             'exit((new PHPUnit\\TextUI\\Application)->run(["phpunit", "--version"]));'], cwd=root, text=True),
           'autoload': autoload_before, 'composer_lock_sha256': before['composer.lock']}
if a.driver == 'mysql':
    probe = '$p=new PDO("mysql:unix_socket=".getenv("DB_SOCKET").";dbname=".getenv("DB_DATABASE"),'
    probe += 'getenv("DB_USERNAME"),getenv("DB_PASSWORD")); echo json_encode('
    probe += '$p->query("SELECT VERSION() AS version, DATABASE() AS database_name")->fetch(PDO::FETCH_ASSOC));'
    runtime['database'] = json.loads(subprocess.check_output(['php', '-r', probe], env=env, text=True))
else:
    runtime['database'] = {'driver': 'sqlite', 'database_name': ':memory:',
        'version': subprocess.check_output(['php', '-r', 'echo (new PDO("sqlite::memory:"))->query("SELECT sqlite_version()")->fetchColumn();'], text=True)}

identity = {'head': state['head'], 'manifest_sha256': digest(canonical(before)),
            'runner_sha256': digest(runner_bytes), 'runtime_sha256': digest(canonical(runtime)),
            'public_environment_sha256': digest(canonical(public_env))}
bundle = output / ('bundle-' + state['head'][:12] + '-' + digest(canonical(identity))[:16])
if bundle.exists():
    if json.loads((bundle / 'identity.json').read_text()) != identity:
        raise SystemExit('Existing bundle identity differs')
    if (json.loads((bundle / 'manifest.json').read_text()) != before
            or json.loads((bundle / 'runtime.json').read_text()) != runtime
            or json.loads((bundle / 'public-environment.json').read_text()) != public_env
            or (bundle / 'runner.py').read_bytes() != runner_bytes):
        raise SystemExit('Existing bundle contents differ from observed inputs')
else:
    bundle.mkdir()
    write_new(bundle / 'identity.json', identity)
    write_new(bundle / 'manifest.json', before)
    write_new(bundle / 'runtime.json', runtime)
    write_new(bundle / 'public-environment.json', public_env)
    (bundle / 'runner.py').write_bytes(runner_bytes)

phpunit_args = ['phpunit', '--do-not-cache-result', '--colors=never', *([] if a.continue_on_defect else ['--stop-on-defect']),
                '--log-junit', str(run / 'junit.xml'), *a.files]
if a.filter:
    phpunit_args += ['--filter', a.filter]
code = 'define("PHPUNIT_COMPOSER_INSTALL", getcwd()."/vendor/autoload.php"); require "vendor/autoload.php"; exit((new PHPUnit\\TextUI\\Application)->run(json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR)));'
argv = ['php', '-d', 'memory_limit=512M', '-r', code, json.dumps(phpunit_args)]
started = datetime.datetime.now(datetime.timezone.utc).isoformat()
write_new(run / 'command.json', {'cwd': str(root), 'argv': argv, 'bundle': bundle.name,
    'source_before': state, 'selected_files': a.files, 'filter': a.filter, 'started_utc': started,
    'source_input_count': len(before), 'before_digest': identity['manifest_sha256'], 'before_git_equal': True,
    'input_scope': 'Tracked source plus actual directory inventory; undeclared files refused unread. bootstrap/cache and Python bytecode excluded.',
    'public_environment': public_env, 'timeout_seconds': a.timeout,
    'workload_scope': 'Root-serialized focused database workload; other component pure work may continue. No performance claim.',
    'overlays': []})
tick = time.monotonic()
with (run / 'log.txt').open('xb') as log:
    proc = subprocess.Popen(argv, cwd=root, env=env, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
    try:
        process_exit = proc.wait(timeout=a.timeout)
    except subprocess.TimeoutExpired:
        os.killpg(proc.pid, signal.SIGTERM)
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(proc.pid, signal.SIGKILL)
            proc.wait()
        process_exit = 124
        log.write(b'\nRunner timed out; process group terminated.\n')
elapsed = time.monotonic() - tick
ended = datetime.datetime.now(datetime.timezone.utc).isoformat()
(run / 'process.exit').write_text(str(process_exit) + '\n')
after, after_state, autoload_after = observed_inputs(), source_state(), autoload_inputs()
counts, junit_error = None, None
try:
    counts = reconcile_junit(run / 'junit.xml')
except Exception as error:
    junit_error = type(error).__name__ + ': ' + str(error)
source_equal = before == after
runner_after = digest(Path(__file__).read_bytes())
runner_unchanged = runner_after == digest(runner_bytes)
source_head_unchanged = state['head'] == after_state['head']
source_status_unchanged = state['status'] == after_state['status']
verifier_exit = 0 if (process_exit == 0 and junit_error is None and source_equal
    and autoload_before == autoload_after and runner_unchanged
    and source_head_unchanged and source_status_unchanged) else 1
result = {'process_exit': process_exit, 'verifier_exit': verifier_exit, 'junit': counts,
          'junit_error': junit_error, 'started_utc': started, 'ended_utc': ended, 'wall_seconds': elapsed,
          'source_after': after_state, 'bundle': bundle.name, 'source_input_count': len(after),
          'after_digest': digest(canonical(after)), 'source_unchanged': source_equal,
          'source_head_unchanged': source_head_unchanged, 'source_status_unchanged': source_status_unchanged,
          'runner_after_sha256': runner_after, 'runner_unchanged': runner_unchanged,
          'autoload_unchanged': autoload_before == autoload_after,
          'log_sha256': digest((run / 'log.txt').read_bytes())}
if not source_equal:
    result['source_delta'] = {k: [before.get(k), after.get(k)] for k in before.keys() | after.keys() if before.get(k) != after.get(k)}
if autoload_before != autoload_after:
    result['autoload_after'] = autoload_after
write_new(run / 'result.json', result)
print(json.dumps(result, indent=2))
raise SystemExit(verifier_exit)
