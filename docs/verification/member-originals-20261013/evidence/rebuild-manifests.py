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


def source_map(binding):
    if binding.get('format') == 'sha256sum':
        captured = {line.split(None, 1)[1]: line.split(None, 1)[0] for line in raw(binding['source']).decode().splitlines() if line.strip()}
    else:
        captured = field(load(binding['source']), binding.get('key'))
    if binding.get('path_key'):
        entries = captured if isinstance(captured, list) else [captured]
        captured = {item[binding['path_key']]: item for item in entries}
    assert isinstance(captured, dict), binding['id']
    if binding.get('hash_key'):
        captured = {path: value[binding['hash_key']] for path, value in captured.items()}
    if binding.get('path_aliases'):
        captured = {binding['path_aliases'].get(path, path): value for path, value in captured.items()}
    return captured


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
    members = manifest if isinstance(manifest, list) else [dict(sha256=item, path=name) if spec.get('hash_values') else dict(item, path=name) for name, item in manifest.items()]
    verified = []
    for member in members:
        expected_path = spec['prefix'] + (Path(member['path']).name if spec.get('absolute_paths') else member['path'])
        matches = [x for x in index['files'] if x['path'] in (expected_path, expected_path + '.gz')]
        assert len(matches) == 1, expected_path
        archived = matches[0]
        assert archived['original_sha256'] == member['sha256'], expected_path
        if 'bytes' in member:
            assert archived['original_bytes'] == member['bytes'], expected_path
        verified.append(archived['path'])
    releases.append(spec | {'verified_members': len(verified), 'archived_paths': verified})
for spec in registry.get('allowlist_receipts', []):
    members = field(load(spec['receipt']), spec['key'])
    assert len(members) == len(set(members)) == spec['expected_members'], spec['receipt']
    paths = {x['path'].removesuffix('.gz') for x in index['files'] if x['path'].startswith(spec['prefix'])}
    assert paths == {spec['prefix'] + name for name in members}, spec['receipt']
    releases.append(spec | {'verified_members': len(paths), 'verification': 'Exact explicit allowlist equality; individual original hashes are in archive-index.'})
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

quality = registry.get('quality_receipt')
if quality:
    prefix = quality['prefix']
    before = load(prefix + 'source-before.json.gz')
    after = load(prefix + 'source-after.json.gz')
    assert before['sources'] == after['sources'] and before['head'] == after['head'] == quality['head']
    assert not before['git_status'] and not after['git_status'] and after['unchanged']
    checks = []
    for name in list(quality['policy_tests']) + ['pint-operative', 'native-discovery']:
        command = load(prefix + name + '.command.json')
        result = load(prefix + name + '.result.json')
        assert command['head'] == result['head_after'] == quality['head'], name
        assert result['source_unchanged'] and result['source_count'] == len(before['sources']), name
        assert command['source_before_sha256'] == sha(raw(prefix + 'source-before.json.gz')), name
        assert command['runner_sha256'] == sha(raw(prefix + 'run-check.py')), name
        assert result['exit'] == int(raw(prefix + name + '.exit')) == 0, name
        if name in quality['policy_tests']:
            log = raw(prefix + name + '.log').decode()
            count = int(re.search(r'^Ran (\d+) tests? in ', log, re.M).group(1))
            assert log.rstrip().endswith('OK') and count == result['selftest_count'] == quality['policy_tests'][name], name
        checks.append({'name': name, 'exit': result['exit'], 'duration_seconds': result['duration_seconds'],
                       'policy_test_count': quality['policy_tests'].get(name), 'command_receipt': prefix + name + '.command.json'})
    selection = load(prefix + 'pint-selection.json')
    assert len(selection['operative_php']) == quality['style_files']
    style = load(prefix + 'pint-operative.result.json')
    assert style['selected_sources_unchanged'] and style['selected_php_files'] == quality['style_files']
    manifest = load(prefix + 'generated/phpunit-ci-mysql-manifest.json')
    selected, selected_files = [], set()
    for shard in manifest['shards']:
        xml = ET.fromstring(raw(prefix + 'generated/phpunit-ci-mysql-' + str(shard['index']) + '-tests.xml'))
        cases = [x.get('id') for x in xml.iter() if x.tag.endswith('}testMethod')]
        assert len(cases) == shard['test_cases'] and cases, shard['index']
        selected.extend(cases)
        selected_files.update(shard['files'])
    assert len(selected) == len(set(selected)) == manifest['selection']['test_cases'] == quality['discovery_cases']
    assert len(selected_files) == manifest['selection']['files'] == quality['discovery_files']
    assert len(manifest['shards']) == quality['discovery_shards']
    source_xml = ET.fromstring(raw(prefix + 'generated/phpunit-ci-mysql-source-tests.xml.gz'))
    source_cases = {x.get('id') for x in source_xml.iter() if x.tag.endswith('}testMethod')}
    assert len(source_cases) == manifest['source_test_cases'] and set(selected) <= source_cases
    assert 'tests/Feature/ProductionMemberOriginals/MemberGrantCurrentSchemaTest.php' not in selected_files
    assert 'tests/Feature/ProductionMemberOriginals/MemberGrantNativeCurrentSchemaTest.php' in selected_files
    generated = load(prefix + 'generated-artifacts.json')
    cleanup = load(prefix + 'generated-cleanup.json')
    assert generated['expected_count'] == generated['created_count'] == len(generated['files']) == 50
    for name, expected in generated['files'].items():
        path = prefix + 'generated/' + name
        assert sha(raw(path if (HERE / path).exists() else path + '.gz')) == expected, name
    assert set(generated['files']) == set(cleanup['deleted_exact_names'])
    assert cleanup['all_deleted_names_absent'] and cleanup['archived_bytes_verified'] and not cleanup['unrelated_files_deleted']
    save('quality-verification.json', {
        'scope': 'Offline source, runner, log/exit, exact generated artifact and listing reconciliation. Discovery is selection, not execution of application bodies.',
        'head': quality['head'], 'source_paths_unchanged': len(before['sources']), 'checks': checks,
        'policy_tests': sum(quality['policy_tests'].values()), 'style_files': quality['style_files'],
        'source_cases': len(source_cases), 'selected_cases': len(selected), 'selected_files': len(selected_files),
        'nonempty_shards': len(manifest['shards']), 'untimed_files_warning': len(manifest['weighting']['untimed_files']),
        'generated_files_verified': len(generated['files']), 'owner_cleanup_sidecar_verified': True,
    })

if (HERE / 'operation/reservation-wrapper-carry.json').exists():
    carry = load('operation/reservation-wrapper-carry.json')
    old = load('operation/reservation-wrappers-initial.source.json.gz')['source']
    new = load('operation/composition-fifteen-final.source.json.gz')['source']
    delta = [{'path': path, 'tested_sha256': digest, 'current_sha256': new.get(path)} for path, digest in old.items() if new.get(path) != digest]
    assert delta == carry['changed_inputs']
    for path, digest in carry['selected_tests_and_phase_split_files_unchanged'].items():
        assert old[path] == new[path] == digest, path
    comparison = load('operation/retained-deadline-red-green-comparison.json')
    red = load('operation/retained-deadline-old-bridge-red.source.json.gz')
    assert sha(raw('operation/retained-deadline-old-inspection.php.txt')) == red['overlay']['sha256'] == comparison['red_source']['sha256']
    for path, digest in comparison['identical_test_inputs'].items():
        assert red['source'][path] == new[path] == digest, path
    assert new['app/Domain/Grants/Member/MemberOriginalInspection.php'] == comparison['fixed_inspection_sha256']
    for run_id, outcome_key in [('operation/retained-deadline-old-bridge-red', 'old'), ('operation/composition-fifteen-final', 'fixed')]:
        run = next(x for x in runs if x['id'] == run_id)
        actual = {x['identity'].replace('\\', '.'): x for x in run['cases']}
        for case, expected in comparison['cases'].items():
            assert actual[case]['assertions'] == expected[outcome_key]['assertions']
            assert actual[case]['status'] == expected[outcome_key]['outcome']
    save('operation-relations.json', {
        'scope': 'Independent receipt-to-receipt comparisons. Original wrapper invocation remains at its original source; old Inspection is one class overlay.',
        'wrapper_original_inputs': len(old), 'wrapper_changed_inputs': delta,
        'wrapper_selected_unchanged_files': carry['selected_tests_and_phase_split_files_unchanged'],
        'retained_deadline_same_test_inputs': comparison['identical_test_inputs'], 'retained_deadline_cases': comparison['cases'],
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
        captured = source_map(binding)
        differences = []
        for path, expected in captured.items():
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
    final_comparisons = []
    final_source = registry.get('integrated_tested_source')
    prefixes = ('app/', 'bootstrap/', 'config/', 'database/', 'public/', 'resources/', 'routes/', 'scripts/', 'tests/', '.github/workflows/')
    root_inputs = {'artisan', 'composer.json', 'composer.lock', 'phpunit.xml', 'phpunit.xml.dist', 'package.json', 'package-lock.json', 'vite.config.ts', 'tsconfig.json', '.gitlab-ci.yml'}
    for spec in registry.get('final_comparisons', []):
        assert final_source, spec['run']
        binding = next(x for x in registry['source_bindings'] if x['id'] == spec['binding'])
        captured = source_map(binding)
        selected = {path: digest for path, digest in captured.items() if path.startswith(prefixes) or path in root_inputs or path.startswith('phpunit') and path.endswith('.xml')}
        differences = []
        for path, expected in selected.items():
            actual = git_hash(final_source, path)
            if actual != expected:
                category = 'application' if path.startswith('app/') else 'test/fixture' if path.startswith('tests/') else 'config/resource/tooling'
                differences.append({'path': path, 'category': category, 'captured_sha256': expected, 'final_sha256': actual})
        final_comparisons.append(spec | {'original_reference': binding['reference'], 'captured_paths': len(captured),
                                         'selected_input_paths': len(selected), 'all_selected_inputs_match_final': not differences,
                                         'differences': differences})
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
save('final-source-verification.json', {
    'scope': 'Each named captured executable/resource-input selection compared with the explicit integrated target. Differences remain visible; a byte comparison is not a rerun. External probes remain separately identified. Documentation-only inventory entries are excluded from this final carry comparison.',
    'integrated_tested_source': registry.get('integrated_tested_source'), 'comparisons': final_comparisons,
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
