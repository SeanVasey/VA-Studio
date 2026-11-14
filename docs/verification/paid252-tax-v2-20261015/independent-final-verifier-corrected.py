#!/usr/bin/env python3
"""Read-only independent verification of the frozen Paid252 Tax V2 packet."""
from pathlib import Path, PurePosixPath
from collections import Counter
import hashlib
import json
import re
import subprocess
import tarfile
import xml.etree.ElementTree as ET

repo = Path('/workspace/VA-Studio-tax-v2-committed-read')
packet = repo / 'docs/verification/paid252-tax-v2-20261015'
head = '562147dac0b8dea1f9902a5e6b0be8b8bdbdd7ba'
source = '26c0c472ac6b19060e42064e3e9315aae80e6eb5'
base = '7567c52a3246ab2625196952a448c8cd7a890dcb'
sha = lambda raw: hashlib.sha256(raw).hexdigest()
canonical = lambda value: json.dumps(value, sort_keys=True, separators=(',', ':')).encode()
def git(*args):
    return subprocess.check_output(['git', *args], cwd=repo)
assert git('rev-parse', 'HEAD').decode().strip() == head
assert git('status', '--porcelain') == b''
index_raw = (packet / 'recorded-evidence-index.json').read_bytes()
index = json.loads(index_raw)
archive = packet / 'recorded-evidence.tar.gz'
assert sha(archive.read_bytes()) == index['archive_sha256'] == '8ef68cc4b8ed180541141fa2fb24565474ed8e8a8484455de2dff18c2e5e7b76'
assert archive.stat().st_size == index['archive_bytes'] == 11732248
with tarfile.open(archive) as tar:
    members = tar.getmembers()
    assert len(members) == index['archive_member_count'] == 2994
    assert len({m.name for m in members}) == len(members)
    data = {}
    for m in members:
        assert m.isfile() and not PurePosixPath(m.name).is_absolute() and '..' not in PurePosixPath(m.name).parts
        raw = tar.extractfile(m).read()
        assert len(raw) == m.size and sha(raw) == index['member_sha256'][m.name]
        data[m.name] = raw
assert set(data) == set(index['member_sha256'])
load = lambda name: json.loads(data[name])
blobs = {n.removeprefix('source-blobs/'): raw for n, raw in data.items() if n.startswith('source-blobs/')}
assert len(blobs) == index['source_blob_count'] == 2397
assert all(sha(raw) == digest for digest, raw in blobs.items())
trees = {}
def tree(commit):
    if commit not in trees:
        trees[commit] = {}
        for entry in git('ls-tree', '-r', '-z', commit).split(b'\0'):
            if not entry:
                continue
            meta, path = entry.split(b'\t', 1)
            mode, kind, oid = meta.split()
            if kind == b'blob':
                trees[commit][path.decode()] = oid.decode()
    return trees[commit]
inventory = {}
binding_rows = []
reachable = {}
for name in index['source_inventories']:
    identity, manifest, runtime, env = [load(name + '/' + n + '.json') for n in ['identity', 'manifest', 'runtime', 'public-environment']]
    assert sha(canonical(manifest)) == identity['manifest_sha256']
    assert sha(canonical(runtime)) == identity['runtime_sha256']
    assert sha(canonical(env)) == identity['public_environment_sha256']
    assert sha(data[name + '/runner.py']) == identity['runner_sha256']
    assert sha(canonical(identity))[:16] == name.rsplit('-', 1)[1]
    for path, digest in manifest.items():
        raw = blobs[digest]
        oid = hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()
        assert tree(identity['head'])[path] == oid
        reachable[oid] = raw
        binding_rows.append((identity['head'], path, digest, oid))
    inventory[name] = {'identity': identity, 'manifest': manifest, 'runtime': runtime, 'env': env}
assert len(inventory) == index['source_inventory_count'] == 32
assert set(blobs) == {row[2] for row in binding_rows}
# Read each unique reachable object once, independently of the packaged object bytes.
proc = subprocess.Popen(['git', 'cat-file', '--batch'], cwd=repo, stdin=subprocess.PIPE, stdout=subprocess.PIPE)
try:
    for oid, raw in reachable.items():
        proc.stdin.write((oid + '\n').encode()); proc.stdin.flush()
        header = proc.stdout.readline().split()
        assert header[0].decode() == oid and header[1] == b'blob'
        actual = proc.stdout.read(int(header[2]))
        assert proc.stdout.read(1) == b'\n' and actual == raw
finally:
    proc.stdin.close()
    assert proc.wait() == 0

invocations = []
for entry in index['invocations']:
    name = entry['name']
    command, result = load(name + '/command.json'), load(name + '/result.json')
    bound = inventory[result['bundle']]
    assert command['source_before'] == result['source_after'] == {'head': entry['head'], 'status': ''}
    assert bound['identity']['head'] == entry['head']
    assert command['before_digest'] == result['after_digest'] == bound['identity']['manifest_sha256']
    assert command['source_input_count'] == result['source_input_count'] == len(bound['manifest'])
    assert command['before_git_equal'] is True and command['overlays'] == []
    assert all(result[k] for k in ['source_unchanged', 'source_head_unchanged', 'source_status_unchanged', 'runner_unchanged', 'autoload_unchanged'])
    assert result['log_sha256'] == sha(data[name + '/log.txt'])
    assert int(data[name + '/process.exit']) == result['process_exit'] == entry['process_exit']
    assert result['verifier_exit'] == entry['verifier_exit']
    assert command['public_environment'] == bound['env']
    assert result['runner_after_sha256'] == bound['identity']['runner_sha256']
    assert data[result['bundle'] + '/runner.py'] == data['run-focused.py']
    doc = ET.fromstring(data[name + '/junit.xml'])
    cases = list(doc.iter('testcase'))
    actual = {'tests': len(cases), 'assertions': sum(int(c.get('assertions', 0)) for c in cases),
              'failures': sum(c.find('failure') is not None for c in cases), 'errors': sum(c.find('error') is not None for c in cases),
              'skipped': sum(c.find('skipped') is not None for c in cases)}
    top = doc if doc.tag == 'testsuite' else doc.find('testsuite')
    assert actual == {k: int(top.get(k)) for k in actual} == result['junit'] == entry['counts']
    policy_path = 'scripts/ci/database-' + command['public_environment']['DB_CONNECTION'] + '-skips.json'
    allowed = {tuple(pair) for pair in json.loads(blobs[bound['manifest'][policy_path]])['methods']}
    skips = [(c.get('class'), c.get('name').split(' with data set ', 1)[0]) for c in cases if c.find('skipped') is not None]
    assert all(pair in allowed for pair in skips) and [list(x) for x in skips] == entry['skipped_methods']
    os_exit = None
    if 'launch-' + name + '.json' in data:
        launch = load('launch-' + name + '.json')
        witness = load('observed-os-exit-' + name + '.json')
        assert launch['source'] == command['source_before']
        assert launch['runner_sha256'] == bound['identity']['runner_sha256']
        assert launch['launcher_sha256'] == sha(data['launch-durable.py'])
        assert launch['pid'] == witness['pid'] and launch['process_start_ticks'] == witness['start_ticks']
        assert witness['state'] == 'Z' and set(witness['process_uids']) == {launch['uid']}
        os_exit = witness['exit']
        assert os_exit == result['verifier_exit'] == entry['observed_recorder_os_exit']
    else:
        assert name in ['sqlite-fresh-environment-old-source', 'sqlite-fresh-environment-fixed', 'sqlite-fresh-policy-compatibility']
        assert entry['observed_recorder_os_exit'] is None
    args = json.loads(command['argv'][-1])
    assert '--stop-on-defect' in args and '--do-not-cache-result' in args
    assert all(path in args and path in bound['manifest'] for path in command['selected_files'])
    if command['filter'] is None:
        assert '--filter' not in args
    else:
        assert args[args.index('--filter') + 1] == command['filter']
    invocations.append({'name': name, 'head': entry['head'], 'counts': actual, 'php': result['process_exit'],
                        'verifier': result['verifier_exit'], 'os': os_exit, 'skips': [list(x) for x in skips],
                        'selected_files': command['selected_files'], 'filter': command['filter'], 'wall_seconds': result['wall_seconds']})
assert len(invocations) == index['actual_php_process_launches'] == index['executed_testcase_invocations'] == 38
assert index['pre_php_preparation_failures'] == 0 and index['preparation_failures'] == []
named = {x['name']: x for x in invocations}
for name, expected in [('mysql-bounded-origin-race-and-private-original', (2, 100, 0)), ('sqlite-whole-paid-tax-family', (91, 2660, 2)), ('sqlite-affected-v1-paid-journeys', (21, 398, 0))]:
    actual = named[name]
    assert actual['counts'] == {'tests': expected[0], 'assertions': expected[1], 'failures': 0, 'errors': 0, 'skipped': expected[2]}
    assert actual['php'] == actual['verifier'] == actual['os'] == 0 and actual['head'] == source
assert len(named['sqlite-whole-paid-tax-family']['selected_files']) == 9
assert len(named['sqlite-affected-v1-paid-journeys']['selected_files']) == 3
assert named['sqlite-whole-paid-tax-family']['filter'] is named['sqlite-affected-v1-paid-journeys']['filter'] is None
current = inventory[index['invocations'][next(i for i,x in enumerate(index['invocations']) if x['name'] == 'sqlite-whole-paid-tax-family')]['bundle']]
manifest = current['manifest']
assert len(manifest) == index['current_input_count'] == 2395
routes = [p for p in manifest if p.startswith('routes/')]
assert len(routes) == index['route_input_count'] == 16
for path, digest in manifest.items():
    assert sha((repo / path).read_bytes()) == digest
    assert tree(head)[path] == tree(source)[path]
loaders = current['runtime']['autoload']
assert len(loaders) == index['loader_hash_count'] == 13
assert all(sha((repo / p).read_bytes()) == digest for p, digest in loaders.items())
assert all(bound['runtime']['autoload'] == loaders for bound in inventory.values())
source_delta = git('diff', '--name-only', source, head).decode().splitlines()
assert all(p.startswith('docs/') for p in source_delta)

schema_carry = load('native-schema-component-carry-26c0c472.json')
doc = ET.fromstring(data['mysql-buyer-serialization-and-native-recovery/junit.xml'])
component = doc.find(".//testsuite[@name='Tests\\Feature\\PaidTaxSchemaRecoveryTest']")
assert {k: int(component.get(k)) for k in ['tests', 'assertions', 'failures', 'errors', 'skipped']} == schema_carry['selected_counts'] == {'tests': 11, 'assertions': 1305, 'failures': 0, 'errors': 0, 'skipped': 1}
assert sha(data['mysql-buyer-serialization-and-native-recovery/junit.xml']) == schema_carry['original_junit_sha256']
assert [c.get('name') for c in component.iter('testcase')] == schema_carry['case_names']
assert git('diff', '--name-only', schema_carry['original_head'], source).decode().splitlines() == ['tests/Feature/PaidTaxNativeBoundaryTest.php']
assert named['mysql-buyer-serialization-and-native-recovery']['counts']['failures'] == 1

formatting = load('pint-26c0c472.json')
assert formatting['head'] == source and formatting['exit'] == 0 and len(formatting['source_sha256']) == 45
assert formatting['source_unchanged'] and formatting['head_and_status_unchanged'] and json.loads(formatting['stdout']) == {'tool': 'pint', 'result': 'passed'}
assert formatting['argv'][3:] == list(formatting['source_sha256'])
assert all(sha((repo / p).read_bytes()) == digest for p, digest in formatting['source_sha256'].items())
policy_checks = {}
for label, count in [('partition', 52), ('workflow', 14), ('scope', 27)]:
    receipt = load(label + '-policy-26c0c472.json')
    assert receipt['head'] == source and receipt['exit'] == 0 and receipt['head_and_status_unchanged']
    assert re.search(r'Ran ' + str(count) + r' tests', receipt['stderr']) and receipt['stderr'].strip().endswith('OK')
    policy_checks[label] = count
census = load('census-26c0c472-phpunit-ci-mysql-manifest.json')
census_run = load('native-census-final-26c0c472.json')
assert census_run['process_exit'] == 0 and census_run['before'] == census_run['after'] == {'head': source, 'status': ''}
assert census['source_files'] == 659 and census['source_test_cases'] == 8854 and census['selection']['files'] == 226 and census['selection']['test_cases'] == 2307
assert len(census['weighting']['untimed_files']) == 63
for output in census_run['generated_outputs']:
    raw = data['census-26c0c472-' + output['path']]
    assert sha(raw) == output['sha256'] and len(raw) == output['bytes']
    assert not (repo / output['path']).exists()
def discovery(raw):
    doc = ET.fromstring(raw)
    ns = {'p': 'https://xml.phpunit.de/testSuite'}
    result = {}
    for cls in doc.findall('.//p:testClass', ns):
        path = cls.get('file').removeprefix(str(repo) + '/')
        for case in cls.findall('p:testMethod', ns):
            assert case.get('id') not in result
            result[case.get('id')] = path
    return result
discovered = discovery(data['census-26c0c472-phpunit-ci-mysql-source-tests.xml'])
assert len(discovered) == 8854 and len(set(discovered.values())) == 659
selected = {}
for shard in census['shards']:
    part = discovery(data['census-26c0c472-phpunit-ci-mysql-' + str(shard['index']) + '-tests.xml'])
    assert part and len(part) == shard['test_cases'] and set(part.values()) == set(shard['files'])
    assert not (set(selected) & set(part)) and all(discovered[k] == v for k,v in part.items())
    selected.update(part)
assert len(selected) == 2307 and len(set(selected.values())) == 226 and len(census['shards']) == 8
for key, path in [('policy_sha256', 'scripts/ci/database-mysql-selection.json'), ('sqlite_skip_policy_sha256', 'scripts/ci/database-sqlite-skips.json'), ('mysql_skip_policy_sha256', 'scripts/ci/database-mysql-skips.json')]:
    assert census['selection'][key] == sha((repo / path).read_bytes())
assert census['weighting']['timings_sha256'] == sha((repo / census['weighting']['timings_file']).read_bytes())

profile = json.loads((repo / 'resources/contracts/paid-tax-v2/profile-assets.json').read_text())
assert len(profile['files']) == 6
for path, digest in profile['files'].items():
    assert sha((repo / path).read_bytes()) == digest
    assert git('show', '42774b742b9536413c753bc40297646db0886207:' + path) == (repo / path).read_bytes()
assert git('show', '42774b742b9536413c753bc40297646db0886207:resources/contracts/paid-tax-v2/profile-assets.json') == (repo / 'resources/contracts/paid-tax-v2/profile-assets.json').read_bytes()
old_manifests = sorted(p for p in tree(base) if p.startswith('resources/contracts/') and p.endswith('/profile-assets.json'))
old_paths = set(old_manifests)
for p in old_manifests:
    old_profile = json.loads(git('show', base + ':' + p))
    old_paths.update(old_profile.get('files', {}))
assert len(old_manifests) == 6 and len(old_paths) == 28
assert all(tree(base)[p] == tree(head)[p] for p in old_paths)
assert all(tree(base)[p] == tree(head)[p] for p in tree(base) if p.startswith(('resources/contracts/', 'database/migrations/', 'app/Domain/Contracts/')))
assert git('diff', '--name-only', base, head, '--', 'app/Domain/Grants/Paid/', 'database/migrations/2026_10_01_252000_paid_grant_origins.php') == b''
for p in ['.github/workflows/final-verification.yml', '.gitlab-ci.yml']:
    strip_comments = lambda raw: b'\n'.join(line for line in raw.splitlines() if not line.lstrip().startswith(b'#'))
    assert strip_comments(git('show', base + ':' + p)) == strip_comments((repo / p).read_bytes())

original_notes = [n for n in data if n.startswith('independent-originals/')]
original_hashes = {}
for n in original_notes:
    local = Path('/workspace/runtime') / PurePosixPath(n).name
    assert local.exists() and local.read_bytes() == data[n]
    original_hashes[n] = sha(data[n])
packet_note_hashes = {}
for p in packet.glob('independent-*'):
    raw = p.read_bytes()
    assert any(raw == data[n] for n in original_notes), p
    packet_note_hashes[p.name] = sha(raw)
stop, launch = load('mysql-server-stop.json'), load('mysql-server-launch.json')
assert stop['process_exit'] == stop['after']['exit'] == 0 and stop['after']['running'] is False
assert stop['before']['id'] == stop['after']['id'] == launch['stdout'].strip()
assert any('source=' + stop['retained_volume'] + ',' in arg for arg in launch['argv'])

report = {
    'reviewed_commit': head, 'actual_tested_source': source, 'baseline': base,
    'archive': {'sha256': index['archive_sha256'], 'bytes': archive.stat().st_size, 'members': len(data), 'index_sha256': sha(index_raw), 'all_member_hashes_and_safe_regular_paths_match': True},
    'source_binding': {'inventories': len(inventory), 'unique_git_blob_objects': len(blobs), 'path_bindings': len(binding_rows), 'binding_sha256': sha(canonical(sorted(binding_rows))), 'all_reachable_git_bytes_match': True, 'current_inputs': len(manifest), 'routes': len(routes), 'loaders': len(loaders), 'all_actual_and_git_current_bytes_match': True, 'loaders_equal_all_recorded_runtimes': True, 'publication_delta_docs_only': source_delta},
    'invocations': invocations, 'invocation_totals': {'actual_phpunit_test_invocations': len(invocations), 'passing': sum(x['php'] == 0 for x in invocations), 'nonzero_originals': sum(x['php'] != 0 for x in invocations), 'missing_portable_os_witness': [x['name'] for x in invocations if x['os'] is None], 'pre_php_failures': 0},
    'selected_current': [named[x] for x in ['mysql-bounded-origin-race-and-private-original', 'sqlite-whole-paid-tax-family', 'sqlite-affected-v1-paid-journeys']],
    'schema_component_carry': schema_carry, 'quality': {'pint_paths': 45, 'pint_exit': 0, 'policy_counts': policy_checks, 'policy_exits': [0,0,0]},
    'census': {'total_cases': 8854, 'total_files': 659, 'selected_cases': 2307, 'selected_files': 226, 'nonempty_shards': 8, 'unmeasured_fallback_files': 63, 'outputs_hashed_and_cleaned': len(census_run['generated_outputs']), 'scope': 'Discovery only, not executed test evidence.'},
    'profiles': {'new_manifest_sha256': sha((repo / 'resources/contracts/paid-tax-v2/profile-assets.json').read_bytes()), 'six_new_pins_match_and_unchanged_from_427': True, 'old_profiles': 6, 'old_unique_manifest_member_paths': 28, 'old_paths_and_v1_source_unchanged': True, 'workflow_noncomment_bytes_unchanged': True},
    'packet_original_review_hashes': packet_note_hashes, 'all_archive_original_review_hashes': original_hashes, 'owned_server_stop': stop,
    'verifier_actions': 'Read-only archive/JUnit/receipt parsing, exact Git/source/current-loader hashing and original reviewer runtime comparisons only. No application/test/DB/network/CI execution or tracked edits.'
}
out = Path('/workspace/runtime/paid252-tax-v2-independent-final-working-report-562147da-20261015.json')
with out.open('x') as f:
    json.dump(report, f, indent=2, sort_keys=True); f.write('\n')
print(json.dumps({'report': str(out), 'report_sha256': sha(out.read_bytes()), 'archive_members': len(data), 'inventories': len(inventory), 'git_objects': len(blobs), 'path_bindings': len(binding_rows), 'invocation_totals': report['invocation_totals'], 'current': report['selected_current'], 'source_inputs': len(manifest), 'routes': len(routes), 'loaders': len(loaders)}, indent=2))
