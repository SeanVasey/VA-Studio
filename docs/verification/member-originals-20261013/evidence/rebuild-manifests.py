"""Offline receipt, count, source and digest reconciliation; runs no application."""
from pathlib import Path
import argparse
import gzip
import hashlib
import json
import re
import subprocess
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--git-repo')
args = parser.parse_args()
if args.git_repo is None:
    args.git_repo = subprocess.check_output(['git', 'rev-parse', '--show-toplevel'], cwd=HERE, text=True).strip()


def sha(data):
    return hashlib.sha256(data).hexdigest()


def raw(name):
    data = (HERE / name).read_bytes()
    return gzip.decompress(data) if name.endswith('.gz') else data


def load(name):
    return json.loads(raw(name))


def save(name, value):
    (HERE / name).write_text(json.dumps(value, indent=2) + '\n')


def field(value, key):
    for part in key or []:
        value = value[part]
    return value


index = load('archive-index.json')
for item in index['files']:
    data = (HERE / item['path']).read_bytes()
    assert sha(data) == item['archived_sha256'] and len(data) == item['archived_bytes'], item['path']
    original = raw(item['path'])
    assert sha(original) == item.get('public_sha256', item['original_sha256']) and len(original) == item.get('public_bytes', item['original_bytes']), item['path']
    if not item['projection']:
        assert sha(original) == item['original_sha256'], item['path']
registry = load('runs.json')
releases = []
for spec in registry.get('release_manifests', []):
    manifest = field(load(spec['receipt']), spec['key'])
    members = manifest if isinstance(manifest, list) else [dict(item, path=name) for name, item in manifest.items()]
    verified = []
    for member in members:
        expected_path = spec['prefix'] + member['path']
        matches = [x for x in index['files'] if x['path'] in (expected_path, expected_path + '.gz')]
        assert len(matches) == 1, expected_path
        archived = matches[0]
        assert archived['original_sha256'] == member['sha256'] and archived['original_bytes'] == member['bytes'], expected_path
        verified.append(archived['path'])
    releases.append(spec | {'verified_members': len(verified), 'archived_paths': verified})
save('release-verification.json', {'scope': 'Every explicitly released manifest member reconciled with the archived original bytes, including lossless compressed maps.', 'releases': releases})
runs = []
for run in registry['runs']:
    cases = []
    if run.get('aborted_before_tests'):
        try:
            ET.fromstring(raw(run['xml']))
        except ET.ParseError:
            pass
        else:
            raise AssertionError((run['id'], 'Expected retained malformed XML'))
        assert run['abort_log_marker'] in raw(run['log']).decode(), run['id']
        counts = dict(tests=0, assertions=0, errors=0, failures=0, skipped=0)
    elif run.get('xml'):
        doc = ET.fromstring(raw(run['xml']))
        for case in doc.iter('testcase'):
            status = next((kind for kind in ('error', 'failure', 'skipped') if case.find(kind) is not None), 'passed')
            cases.append({'identity': case.get('class', case.get('classname', '')) + '::' + case.get('name', ''),
                          'assertions': int(case.get('assertions', '0')), 'status': status})
        counts = {'tests': len(cases), 'assertions': sum(x['assertions'] for x in cases),
                  'errors': sum(x['status'] == 'error' for x in cases),
                  'failures': sum(x['status'] == 'failure' for x in cases),
                  'skipped': sum(x['status'] == 'skipped' for x in cases)}
        suite = doc.find('testsuite') if doc.tag == 'testsuites' else doc
        for key in counts:
            assert counts[key] == int(suite.get(key, '0')), (run['id'], key)
    else:
        text = raw(run['log']).decode()
        passed = re.search(r'OK \((\d+) tests?, (\d+) assertions?\)', text)
        if text.lstrip().startswith('{'):
            reported = json.loads(text)
            assert reported['tool'] == 'phpunit', run['id']
            counts = {'tests': reported['tests'], 'assertions': reported['assertions'],
                      'errors': reported.get('errored', 0), 'failures': reported.get('failed', 0),
                      'skipped': reported.get('skipped', 0)}
            assert counts['tests'] == reported['passed'] + counts['errors'] + counts['failures'] + counts['skipped'], run['id']
        elif passed:
            counts = dict(zip(('tests', 'assertions'), map(int, passed.groups())))
            counts.update(errors=0, failures=0, skipped=0)
        else:
            tail = next(line for line in reversed(text.splitlines()) if line.startswith('Tests:'))
            values = {key.lower(): int(value) for key, value in re.findall(r'(Tests|Assertions|Errors|Failures|Skipped): (\d+)', tail)}
            counts = {key: values.get(key, 0) for key in ('tests', 'assertions', 'errors', 'failures', 'skipped')}
    if run.get('exit_file'):
        counts['exit'] = int(raw(run['exit_file']).strip())
    else:
        counts['exit'] = run['reported_exit']
        assert run['exit_provenance'], run['id']
    assert counts == run['expected'], (run['id'], counts, run['expected'])
    runs.append(run | counts | {'cases': cases})
components = []
for spec in registry.get('component_slices', []):
    run = next(x for x in runs if x['id'] == spec['run'])
    cases = [x for x in run['cases'] if x['identity'].startswith(spec['class'] + '::')]
    counts = {'tests': len(cases), 'assertions': sum(x['assertions'] for x in cases),
              'errors': sum(x['status'] == 'error' for x in cases),
              'failures': sum(x['status'] == 'failure' for x in cases),
              'skipped': sum(x['status'] == 'skipped' for x in cases)}
    assert counts == spec['expected'], (spec['id'], counts)
    components.append(spec | counts | {'invocation_exit': run['exit'], 'cases': cases})
save('test-summary.json', {
    'scope': 'Independent JUnit leaf/suite reconciliation or explicit log totals. Counts overlap; unknown command exits remain null. Component runs are not an integrated suite.',
    'runs': runs, 'component_slices': components,
})

stability = []
for spec in registry.get('execution_receipts', []):
    source = load(spec['source'])
    assert sha(raw(spec['runner'])) == source['runner_sha256'], spec['run']
    result = None
    if spec.get('result'):
        result = load(spec['result'])
        assert result['changed_sources'] == [], spec['run']
        run = next(x for x in runs if x['id'] == spec['run'])
        assert result['exit'] == run['exit'], spec['run']
        for key in ('tests', 'assertions', 'errors', 'failures', 'skipped'):
            assert int(result['junit'][key]) == run[key], (spec['run'], key)
    stability.append(spec | {'runner_bytes_verified': True, 'owner_unchanged_source_sidecar': result is not None,
                             'independent_before_after_maps': False})
for spec in registry.get('before_after_receipts', []):
    before, after = load(spec['before']), load(spec['after'])
    assert before == after, spec['run']
    for name, expected in before['programs'].items():
        assert sha(raw(spec['program_directory'] + '/' + name)) == expected, name
    stability.append(spec | {'independent_before_after_maps': True, 'captured_files': len(before['files']),
                             'program_bytes_verified': list(before['programs'])})
for spec in registry.get('capsule_checks', []):
    captured = field(load(spec['source']), spec['key'])
    assert sha(raw(spec['capsule'])) == captured[spec['path']], spec['capsule']
for spec in registry.get('digest_checks', []):
    expected = field(load(spec['receipt']), spec['key'])
    assert sha(raw(spec['artifact'])) == expected, spec['artifact']
save('stability-and-programs.json', {
    'scope': 'Independent full before/after equality where retained; other unchanged-source claims are identified as owner result sidecars. Runner and exact historical capsule bytes are checked independently.',
    'observations': stability, 'capsules': registry.get('capsule_checks', []),
    'digest_checks': registry.get('digest_checks', []),
})

trees = {}
blob_hashes = {}
portable = load('portable-git-map.json') if (HERE / 'portable-git-map.json').exists() else {'references': []}
portable_refs = {item['original_reference']: item for item in portable['references']}
if portable_refs:
    reachable = set(subprocess.check_output(['git', '-C', args.git_repo, 'rev-list', portable['derived_from_root_head']], text=True).splitlines())
    for spec in portable_refs.values():
        assert spec['default_reachable_reference'] in reachable, spec['original_reference']
        assert all(item['reference'] in reachable for item in spec['path_overrides'].values()), spec['original_reference']
resolved_pairs = {ref: {} for ref in portable_refs}
process = subprocess.Popen(['git', '-C', args.git_repo, 'cat-file', '--batch'], stdin=subprocess.PIPE, stdout=subprocess.PIPE)


def git_oid(ref, path):
    if ref not in trees:
        tree = subprocess.check_output(['git', '-C', args.git_repo, 'ls-tree', '-r', '-z', ref])
        trees[ref] = {}
        for line in tree.split(b'\0'):
            if not line:
                continue
            left, name = line.split(b'\t', 1)
            _, kind, oid = left.decode().split()
            if kind == 'blob':
                trees[ref][name.decode()] = oid
    return trees[ref].get(path)


def resolved_oid(ref, path):
    if ref not in portable_refs:
        return git_oid(ref, path)
    spec = portable_refs[ref]
    assert path not in spec['unavailable_paths'], (ref, path)
    if path in spec['absent_paths']:
        oid = None
    else:
        override = spec['path_overrides'].get(path)
        oid = git_oid(override['reference'], override['path']) if override else git_oid(spec['default_reachable_reference'], path)
        if override:
            assert oid == override['original_git_blob'], (ref, path)
    resolved_pairs[ref][path] = oid
    return oid


def git_hash(ref, path):
    oid = resolved_oid(ref, path)
    if oid is None:
        return None
    if oid not in blob_hashes:
        process.stdin.write((oid + '\n').encode())
        process.stdin.flush()
        header = process.stdout.readline().decode().split()
        data = process.stdout.read(int(header[2]))
        assert process.stdout.read(1) == b'\n'
        blob_hashes[oid] = sha(data)
    return blob_hashes[oid]


bindings = []
try:
    for binding in registry['source_bindings']:
        if binding.get('format') == 'sha256sum':
            captured = {line.split(None, 1)[1]: line.split(None, 1)[0] for line in raw(binding['source']).decode().splitlines() if line.strip()}
        else:
            captured = field(load(binding['source']), binding.get('key'))
        if binding.get('path_key'):
            entries = captured if isinstance(captured, list) else [captured]
            captured = {item[binding['path_key']]: item for item in entries}
        assert isinstance(captured, dict), binding['id']
        differences = []
        for path, expected in captured.items():
            expected = expected[binding['hash_key']] if binding.get('hash_key') else expected
            assert re.fullmatch('[a-f0-9]{64}', expected), (binding['id'], path)
            override = binding.get('overrides', {}).get(path)
            actual = sha(raw(override)) if override else git_hash(binding['reference'], path)
            if actual != expected:
                differences.append({'path': path, 'captured_sha256': expected, 'reference_sha256': actual})
        if binding.get('require_exact'):
            assert not differences, (binding['id'], differences)
        bindings.append(binding | {'reference_resolution': 'portable reachable-root path mapping' if binding['reference'] in portable_refs else 'direct Git reference',
                                   'captured_paths': len(captured), 'matched_paths': len(captured) - len(differences),
                                   'all_captured_paths_match': not differences, 'differences': differences})
    frozen = []
    for spec in registry.get('frozen_profiles', []):
        inventory = load(spec['receipt'])
        assert sum(x['kind'] == 'manifest' for x in inventory['entries']) == inventory['manifests']
        assert sum(x['kind'] == 'member' for x in inventory['entries']) == inventory['member_entries']
        for item in inventory['entries']:
            assert git_hash(inventory['baseline'], item['path']) == item['sha256'], item['path']
            assert git_hash(spec['reference'], item['path']) == item['sha256'], item['path']
            if item['kind'] == 'member':
                assert item['declared_sha256'] == item['sha256'], item['path']
        frozen.append(spec | {'manifests': inventory['manifests'], 'member_entries': inventory['member_entries'],
                             'entries_verified_against_baseline_and_reference': len(inventory['entries'])})
    profiles = []
    for spec in registry.get('profile_manifests', []):
        assert git_hash(spec['reference'], spec['path']) == spec['sha256'], spec['path']
        manifest = json.loads(subprocess.check_output(['git', '-C', args.git_repo, 'cat-file', 'blob', resolved_oid(spec['reference'], spec['path'])]))
        for name, expected in manifest['files'].items():
            assert git_hash(spec['reference'], name) == expected, name
        profiles.append(spec | {'members': manifest['files'], 'member_count': len(manifest['files'])})
    portable_checks = []
    for ref, pairs in resolved_pairs.items():
        spec = portable_refs[ref]
        assert len(pairs) == spec['requested_paths_count'], ref
        canonical = lambda value: json.dumps(value, separators=(',', ':'), ensure_ascii=False).encode()
        assert sha(canonical(sorted(pairs))) == spec['requested_sorted_paths_sha256'], ref
        assert sha(canonical(sorted(pairs.items()))) == spec['original_path_blob_pairs_sha256'], ref
        for path, override in spec['path_overrides'].items():
            assert git_hash(ref, path) == override['original_sha256'], (ref, path)
        portable_checks.append({'original_reference': ref, 'reachable_default': spec['default_reachable_reference'],
                                'verified_path_pairs': len(pairs), 'overrides': len(spec['path_overrides']),
                                'preserved_absences': len(spec['absent_paths']),
                                'original_path_blob_pairs_sha256': spec['original_path_blob_pairs_sha256']})
finally:
    process.stdin.close()
    process.wait()
    assert process.returncode == 0
save('source-verification.json', {
    'scope': 'Captured paths compared to actual Git blob bytes or explicitly retained source overrides. A partial comparison remains partial. This does not attest unlisted source or execute a suite.',
    'bindings': bindings, 'frozen_profiles': frozen, 'new_member_profiles': profiles,
    'portable_git_checks': portable_checks,
})

coverage = {}
for identity in registry['coverage_precedence']:
    run = next(x for x in runs if x['id'] == identity)
    assert run['exit'] == 0, identity
    for case in run['cases']:
        if case['status'] == 'passed':
            coverage[(run['driver'], case['identity'])] = case | {'run': identity, 'driver': run['driver']}
save('coverage-summary.json', {
    'scope': 'Successful driver/case identities in explicit component precedence. Superseded invocations remain history. No summed whole-suite or native-concurrency claim.',
    'precedence': registry['coverage_precedence'], 'passed_driver_case_identities': len(coverage),
    'assertions': sum(x['assertions'] for x in coverage.values()), 'cases': list(coverage.values()),
    'pending': registry['pending'],
})

owned = [HERE.parent / name for name in ('commands.md', 'source-map.md', 'coverage-map.md', 'coverage-review.md')]
files = {}
for path in sorted([p for p in owned if p.exists()] + list(HERE.rglob('*'))):
    if path.is_file() and path != HERE / 'artifact-digests.json' and '__pycache__' not in path.parts:
        data = path.read_bytes()
        files[str(path.relative_to(HERE.parent))] = {'bytes': len(data), 'sha256': sha(data)}
save('artifact-digests.json', {
    'scope': 'Evidence-lane commands, source/coverage maps and evidence only. Excludes this self-digest, root README, contracts, design, independent DECISION and ledgers.',
    'files': files,
})
print(json.dumps({'archived_artifacts': len(index['files']), 'test_invocations': len(runs),
                  'source_comparisons': len(bindings), 'partial_comparisons': sum(not x['all_captured_paths_match'] for x in bindings),
                  'passed_driver_case_identities': len(coverage)}))
