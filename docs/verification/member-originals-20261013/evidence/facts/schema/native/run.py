#!/usr/bin/env python3
"""Execute only with the coordinator's exclusive private-native slot; no repository edits."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
OLD_SHA = '15a65e0761382a9ad50c4b279605a644a4eeb285'
BASE_SHA = '7edd614497b930c5bc02141537cc7e24dce6fb27'
SCHEMA = 'vaseyaudio_member_original_facts'
SOCKET = '/workspace/runtime/mysql-policy-run/mysql.sock'
TEST = HERE / 'MemberGrantNativeCurrentSchemaTest.php'
RACE = 'test_current_definitions_refuse_changes_hidden_by_an_older_rr_dictionary_snapshot'
REFUSAL = 'Changed current member definition was admitted from the old RR dictionary snapshot.'

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--phase', choices=['old', 'fixed'], required=True)
parser.add_argument('--root', type=Path, required=True)
parser.add_argument('--sha', required=True)
parser.add_argument('--label', required=True)
args = parser.parse_args()
if not re.fullmatch('[a-f0-9]{40}', args.sha) or not re.fullmatch('[a-z0-9-]+', args.label):
    raise SystemExit('Invalid exact source or evidence label.')
root = args.root.resolve()
if args.phase == 'old' and args.sha != OLD_SHA:
    raise SystemExit('The old phase is pinned to the reviewed pre-fix checkout.')
out = HERE / args.label
if out.exists():
    raise SystemExit('Evidence already exists; choose a new label and preserve the initial result.')


def git(*arguments):
    return subprocess.check_output(['git', *arguments], cwd=root)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def snapshot():
    head = git('rev-parse', 'HEAD').decode().strip()
    status = git('status', '--porcelain').decode()
    if head != args.sha or status:
        raise RuntimeError('The selected source must remain clean at its exact approved SHA.')
    names = git('ls-files', '-z').decode().rstrip('\0').split('\0')
    files = {name: digest(root / name) for name in names if (root / name).is_file()}
    metadata = [root / 'vendor/autoload.php', *sorted((root / 'vendor/composer').glob('*.php'))]
    return {'head': head, 'status': status, 'root': str(root), 'tracked_file_count': len(files),
            'files': files, 'private_composer': {str(path.relative_to(root)): digest(path) for path in metadata},
            'programs': {path.name: digest(path) for path in [Path(__file__).resolve(), TEST]}}


before = snapshot()
if not stat.S_ISSOCK(os.stat(SOCKET).st_mode):
    raise SystemExit('The private native socket is absent; no instance is started by this runner.')
out.mkdir(mode=0o700)
(out / 'source-before.json').write_text(json.dumps(before, indent=2) + '\n')
paths = ['app/Domain/Grants/Member/MemberGrantSchema.php',
         'app/Domain/Memberships/Production/MembershipRows.php',
         'tests/Support/FinalizationDatabaseMigrations.php', 'database/migrations', 'composer.json', 'composer.lock']
if args.phase == 'old':
    delta = git('diff', OLD_SHA, BASE_SHA, '--', *paths)
    (out / 'old-base-relevant.diff').write_bytes(delta)
    if delta:
        raise SystemExit('Relevant baseline sources differ from the C4a merged base.')
    blobs = {}
    for path in paths:
        if path == 'database/migrations':
            continue
        old = git('show', OLD_SHA + ':' + path)
        base = git('show', BASE_SHA + ':' + path)
        blobs[path] = {'old_sha256': hashlib.sha256(old).hexdigest(),
                       'base_sha256': hashlib.sha256(base).hexdigest(), 'identical': old == base}
    (out / 'old-base-identity.json').write_text(json.dumps({'old_head': OLD_SHA, 'merged_base': BASE_SHA,
                                                         'empty_relevant_diff': True, 'blobs': blobs}, indent=2) + '\n')

env = os.environ.copy()
env.update({'APP_BASE_PATH': str(root), 'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'DB_URL': '',
            'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'QUEUE_CONNECTION': 'sync',
            'MAIL_MAILER': 'array', 'BROADCAST_CONNECTION': 'null',
            'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1', 'DB_PORT': '33413',
            'DB_USERNAME': 'root', 'DB_PASSWORD': '', 'DB_DATABASE': SCHEMA, 'DB_SOCKET': SOCKET,
            'XDEBUG_MODE': 'off', 'MEMBER_SCHEMA_BASELINE': '1' if args.phase == 'old' else '0'})
setup = r'''
require 'vendor/autoload.php';
$paths = [App\Domain\Grants\Member\MemberGrantSchema::class => '/app/Domain/Grants/Member/MemberGrantSchema.php',
 App\Domain\Memberships\Production\MembershipRows::class => '/app/Domain/Memberships/Production/MembershipRows.php',
 Tests\Support\FinalizationDatabaseMigrations::class => '/tests/Support/FinalizationDatabaseMigrations.php'];
if (getenv('MEMBER_SCHEMA_BASELINE') !== '1') {
    $paths[App\Domain\Grants\Member\MemberGrantCurrentSchema::class] = '/app/Domain/Grants/Member/MemberGrantCurrentSchema.php';
}
foreach ($paths as $class => $relative) {
    if ((new ReflectionClass($class))->getFileName() !== getcwd().$relative) {
        throw new LogicException('Private Composer metadata resolves a different source.');
    }
}
if (getenv('DB_SOCKET') !== '/workspace/runtime/mysql-policy-run/mysql.sock'
 || getenv('DB_DATABASE') !== 'vaseyaudio_member_original_facts'
 || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '33413') {
    throw new LogicException('Unexpected private native target.');
}
$pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET'), 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$metadata = $pdo->query('SELECT VERSION() mysql_version,@@port server_port,@@socket server_socket,@@datadir datadir')->fetch(PDO::FETCH_ASSOC);
if ($metadata['mysql_version'] !== '8.4.11' || (int) $metadata['server_port'] !== 3306
 || $metadata['server_socket'] !== '/run/mysql-policy/mysql.sock' || $metadata['datadir'] !== '/var/lib/mysql/') {
    throw new LogicException('Unexpected native instance; no schema mutation performed.');
}
$pdo->exec('CREATE DATABASE IF NOT EXISTS `vaseyaudio_member_original_facts` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$metadata += ['php_version' => PHP_VERSION, 'owned_schema' => getenv('DB_DATABASE'),
 'client_socket' => getenv('DB_SOCKET'), 'published_port' => 33413,
 'configured_private_datadir_mount' => '/workspace/runtime/mysql-policy-data',
 'source_paths' => array_values($paths), 'baseline_existing_api' => getenv('MEMBER_SCHEMA_BASELINE') === '1'];
echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
'''
setup_result = subprocess.run(['php', '-r', setup], cwd=root, env=env, capture_output=True, text=True)
(out / 'setup.log').write_text(setup_result.stdout + setup_result.stderr)
(out / 'setup.exit').write_text(str(setup_result.returncode) + '\n')
if setup_result.returncode:
    raise SystemExit('Setup failed; initial output is retained and no tests ran.')
metadata = json.loads(setup_result.stdout)
(out / 'metadata.json').write_text(json.dumps(metadata, indent=2) + '\n')
cmd = ['php', '-r', 'require "vendor/autoload.php"; exit((new PHPUnit\\TextUI\\Application)->run(array_merge(["phpunit"], array_slice($argv, 1))));', '--', '--do-not-cache-result', '--colors=never',
       '--log-junit', str(out / 'phpunit.xml')]
if args.phase == 'old':
    cmd += ['--filter', RACE + '#0-1']
cmd += [str(TEST)]
(out / 'command.json').write_text(json.dumps({'cwd': str(root), 'argv': cmd,
 'runner_argv': sys.argv,
 'environment': {key: env[key] for key in ['APP_BASE_PATH', 'APP_ENV', 'APP_DEBUG', 'DB_CONNECTION', 'DB_HOST',
  'DB_PORT', 'DB_DATABASE', 'DB_SOCKET', 'DB_URL', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION',
  'MAIL_MAILER', 'BROADCAST_CONNECTION', 'XDEBUG_MODE', 'MEMBER_SCHEMA_BASELINE']}}, indent=2) + '\n')
with (out / 'phpunit.log').open('w') as stream:
    result = subprocess.run(cmd, cwd=root, env=env, stdout=stream, stderr=subprocess.STDOUT)
(out / 'phpunit.exit').write_text(str(result.returncode) + '\n')
try:
    after = snapshot()
    (out / 'source-after.json').write_text(json.dumps(after, indent=2) + '\n')
    if before != after:
        raise RuntimeError('Source or external runner changed during the selected execution.')
    suite = ET.parse(out / 'phpunit.xml').getroot().find('testsuite')
    if suite is None:
        raise RuntimeError('Missing root JUnit suite.')
    cases = suite.findall('.//testcase')
    expected_count = 2 if args.phase == 'old' else 4
    if int(suite.get('tests')) != expected_count or len(cases) != expected_count:
        raise RuntimeError('Unexpected selected case count; no result is accepted.')
    if int(suite.get('errors')) != 0 or int(suite.get('skipped')) != 0:
        raise RuntimeError('Setup/body errors or skipped conditions are not regression evidence.')
    failures = suite.findall('.//failure')
    if args.phase == 'old':
        if result.returncode != 1 or len(failures) != 2 or int(suite.get('failures')) != 2:
            raise RuntimeError('Old source did not produce exactly two desired assertion failures.')
        if not all(REFUSAL in (failure.text or '') for failure in failures):
            raise RuntimeError('An old-source failure was not actual incorrect admission.')
        if not all(RACE in case.get('name') for case in cases):
            raise RuntimeError('An unexpected old-source case ran.')
        if not all(int(case.get('assertions')) >= 5 for case in cases):
            raise RuntimeError('Old snapshot setup assertions did not all complete.')
    elif result.returncode != 0 or failures or int(suite.get('failures')) != 0:
        raise RuntimeError('Fixed source did not pass every selected native case.')
    receipt = {'phase': args.phase, 'source': args.sha, 'phpunit_exit': result.returncode,
               'tests': int(suite.get('tests')), 'assertions': int(suite.get('assertions')),
               'failures': int(suite.get('failures')), 'errors': 0, 'skips': 0,
               'xml_seconds': suite.get('time'), 'expected_behavior_observed': True,
               'source_files_unchanged': before['tracked_file_count'],
               'cases': [case.get('name') for case in cases], 'native': metadata}
    (out / 'verification.json').write_text(json.dumps(receipt, indent=2) + '\n')
    print(json.dumps(receipt))
except Exception as error:
    (out / 'verification-error.txt').write_text(str(error) + '\n')
    raise
finally:
    artifacts = {path.name: {'bytes': path.stat().st_size, 'sha256': digest(path)}
                 for path in sorted(out.iterdir()) if path.is_file() and path.name != 'artifacts.json'}
    (out / 'artifacts.json').write_text(json.dumps(artifacts, indent=2) + '\n')
# A validated old regression has phpunit_exit=1. Runner success means its expected red receipt was verified.
