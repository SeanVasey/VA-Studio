"""Reconcile archived quality followup receipts offline; execute no released program."""
from pathlib import Path
import argparse
import gzip
import hashlib
import json
import re
import subprocess
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
PREFIX = 'quality/native-budget-followup/'
HEAD = '32f083d28db0609a52cade793849a8767a855ae3'
PRIOR_HEAD = '78ef16be7626c81de49d103d581551b707201eda'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def raw(name):
    path = HERE / name
    if not path.is_file():
        path = HERE / (name + '.gz')
    data = path.read_bytes()
    return gzip.decompress(data) if path.suffix == '.gz' else data


def load(name):
    return json.loads(raw(name))


def cases(name):
    return [node.attrib['id'] for node in ET.fromstring(raw(name)).iter()
            if node.tag.rsplit('}', 1)[-1] == 'testMethod']


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-repo')
    args = parser.parse_args()
    repo = args.git_repo or subprocess.check_output(
        ['git', 'rev-parse', '--show-toplevel'], cwd=HERE, text=True).strip()

    def git(*arguments):
        return subprocess.check_output(['git', '--literal-pathspecs', '-C', repo, *arguments])

    assert HEAD in git('rev-list', '--all').decode().splitlines(), 'Exact root source must be reachable'
    archive = {item['path'].removesuffix('.gz'): item for item in load('archive-index.json')['files']}
    verified_artifacts = set()
    projected_artifacts = set()

    def verify_artifact(name, expected=None):
        item = archive[name]
        stored = (HERE / item['path']).read_bytes()
        public = raw(name)
        assert sha(stored) == item['archived_sha256'] and len(stored) == item['archived_bytes'], name
        assert sha(public) == item['public_sha256'] and len(public) == item['public_bytes'], name
        if item['projection']:
            projected_artifacts.add(name)
            assert item['omitted_json_paths'], name
        else:
            assert sha(public) == item['original_sha256'] and len(public) == item['original_bytes'], name
        if expected is not None:
            assert item['original_sha256'] == expected, name
        verified_artifacts.add(name)

    released = load(PREFIX + 'public-artifacts.json')['files']
    assert len(released) == len(set(released)) == 77
    assert {name for name in archive if name.startswith(PREFIX)} == {PREFIX + name for name in released}
    digests = load(PREFIX + 'artifact-digests.json')
    assert digests['algorithm'] == 'sha256'
    assert set(digests['files']) == set(released) - {'artifact-digests.json'}
    for name in released:
        verify_artifact(PREFIX + name, digests['files'].get(name))

    before, after = load(PREFIX + 'source-before.json'), load(PREFIX + 'source-after.json')
    summary = load(PREFIX + 'summary.json')
    assert before['head'] == after['head'] == summary['head'] == HEAD
    assert before['git_status'] == '' and after['unchanged']
    assert before['sources'] == after['sources'] and len(before['sources']) == 2360
    assert before['local_runner_metadata'] == after['local_runner_metadata']
    metadata_paths = {'vendor/bin/phpunit', 'vendor/bin/pint', 'vendor/autoload.php',
                      'vendor/composer/autoload_classmap.php', 'vendor/composer/autoload_psr4.php',
                      'vendor/composer/autoload_static.php', 'vendor/composer/autoload_real.php'}
    assert set(before['local_runner_metadata']) == metadata_paths
    assert all(re.fullmatch('[a-f0-9]{64}', value) for value in before['local_runner_metadata'].values())
    selected_php = before['selected_php']
    assert len(selected_php) == len(set(selected_php)) == 4
    assert before['selected_php_sha256'] == {name: before['sources'][name] for name in selected_php}

    tree = {}
    for row in git('ls-tree', '-r', '-z', HEAD).split(b'\0'):
        if row:
            meta, name = row.split(b'\t', 1)
            _, kind, oid = meta.decode().split()
            if kind == 'blob':
                tree[name.decode()] = oid
    process = subprocess.Popen(['git', '-C', repo, 'cat-file', '--batch'],
                               stdin=subprocess.PIPE, stdout=subprocess.PIPE)
    try:
        for name, expected in before['sources'].items():
            assert re.fullmatch('[a-f0-9]{64}', expected) and name in tree, name
            process.stdin.write((tree[name] + '\n').encode())
            process.stdin.flush()
            header = process.stdout.readline().decode().split()
            data = process.stdout.read(int(header[2]))
            assert process.stdout.read(1) == b'\n' and sha(data) == expected, name
    finally:
        process.stdin.close()
        process.wait()
        assert process.returncode == 0

    expected_argv = {
        'pint-four': ['vendor/bin/pint', '--test', *selected_php],
        'native-discovery': ['python3', 'scripts/ci/phpunit-shards.py', '--shards', '24',
                             '--prefix', 'phpunit-ci-mysql', '--timings',
                             'scripts/ci/phpunit-timings-mysql.json', '--mysql-native-selection'],
    }
    commands = []
    for name, argv in expected_argv.items():
        command, result = load(PREFIX + name + '.command.json'), load(PREFIX + name + '.result.json')
        assert command['head'] == result['head_after'] == HEAD and command['argv'] == argv, name
        assert command['runner_sha256'] == sha(raw(PREFIX + 'run-quality.py')), name
        assert command['source_receipt_sha256'] == sha(raw(PREFIX + 'source-before.json')), name
        assert result['exit'] == int(raw(PREFIX + name + '.exit')) == 0, name
        assert result['source_count'] == 2360 and result['sources_and_metadata_unchanged'], name
        for key, expected in {'APP_ENV': 'testing', 'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1',
                              'DB_PORT': '1', 'DB_SOCKET': '', 'DB_DATABASE': 'vaseyaudio_discovery_only'}.items():
            assert command['environment'][key] == expected, (name, key)
        commands.append({'name': name, 'exit': 0, 'elapsed_seconds': result['elapsed_seconds']})
    assert load(PREFIX + 'pint-four.log') == {'tool': 'pint', 'result': 'passed'}

    generated = load(PREFIX + 'generated-artifacts.json')
    generated_before, cleanup = load(PREFIX + 'generated-before.json'), load(PREFIX + 'generated-cleanup.json')
    expected_names = {f'phpunit-ci-mysql-{i}{suffix}.xml' for i in range(1, 25) for suffix in ('', '-tests')}
    expected_names |= {'phpunit-ci-mysql-source-tests.xml', 'phpunit-ci-mysql-manifest.json'}
    assert set(generated['files']) == set(generated_before['expected_names']) == set(cleanup['deleted_exact_names']) == expected_names
    assert len(generated_before['expected_names']) == len(cleanup['deleted_exact_names']) == 50
    assert generated['expected_count'] == generated['actual_count'] == 50 and generated['discovery_exit'] == 0
    assert generated_before['all50_absent'] and generated_before['all50_untracked'] and not generated_before['other_prefix_paths']
    assert cleanup['all_deleted_names_absent'] and cleanup['archived_hashes_verified']
    assert not cleanup['unrelated_files_deleted'] and cleanup['git_status_after'] == ''
    for name, expected in generated['files'].items():
        assert sha(raw(PREFIX + 'generated/' + name)) == expected, name

    manifest = load(PREFIX + 'generated/phpunit-ci-mysql-manifest.json')
    source = cases(PREFIX + 'generated/phpunit-ci-mysql-source-tests.xml')
    selected, files = [], set()
    assert len(manifest['shards']) == 24
    assert {shard['index'] for shard in manifest['shards']} == set(range(1, 25))
    for shard in manifest['shards']:
        listed = cases(PREFIX + f'generated/phpunit-ci-mysql-{shard["index"]}-tests.xml')
        assert len(listed) == shard['test_cases'] and listed
        selected.extend(listed)
        files.update(shard['files'])
    assert len(source) == len(set(source)) == manifest['source_test_cases'] == 8478
    assert manifest['source_files'] == 625
    assert len(selected) == len(set(selected)) == manifest['selection']['test_cases'] == 2145
    assert len(files) == manifest['selection']['files'] == 210 and set(selected) <= set(source)
    assert len(manifest['weighting']['untimed_files']) == 47
    log = raw(PREFIX + 'native-discovery.log').decode()
    assert 'warning: 47 test file(s)' in log
    assert 'Proved 2145 selected MySQL-native tests in 210 files (of 8478 discovered tests in 625 files) across 24 nonempty shards.' in log

    prior_before, prior_after = load(PREFIX + 'prior-release-before.json'), load(PREFIX + 'prior-release-after.json')
    assert prior_before['count'] == prior_after['count'] == len(prior_before['files']) == 88
    assert prior_before['files'] == prior_after['files'] and prior_after['unchanged']
    assert set(prior_before['files']) == set(load('quality/public-artifacts.json')['files'])
    for name, expected in prior_before['files'].items():
        verify_artifact('quality/' + name, expected)
    prior_source_cases = set(cases('quality/generated/phpunit-ci-mysql-source-tests.xml'))
    prior_selected = {case for i in range(1, 25) for case in cases(f'quality/generated/phpunit-ci-mysql-{i}-tests.xml')}
    added, selected_added = sorted(set(source) - prior_source_cases), sorted(set(selected) - prior_selected)
    unselected_added = sorted(set(added) - set(selected_added))
    assert len(added) == 3 and len(selected_added) == 2 and len(unselected_added) == 1
    assert all('MemberOriginalCompositionTest::' in case for case in selected_added)
    assert unselected_added == ['Tests\\Feature\\ProductionMemberOriginals\\MemberOriginalIssuerOwnershipTest::test_a_caller_cannot_select_pending_member_initialization_for_any_context_operation']
    assert prior_source_cases <= set(source) and prior_selected <= set(selected)
    census = load(PREFIX + 'census-verification.json')
    assert census['source_added_since_prior'] == added and census['native_selected_added_since_prior'] == selected_added
    assert census['native_unselected_additions'] == unselected_added and not census['removed_source_or_selected_cases']
    assert census['source_case_identities'] == 8478 and census['selected_case_identities'] == 2145
    assert census['duplicate_source_ids'] == census['duplicate_selected_ids'] == 0 and census['selected_are_subset']

    prior_source = load('quality/source-before.json')
    carry = load(PREFIX + 'prior-check-carry.json')
    assert prior_source['head'] == carry['prior_head'] == PRIOR_HEAD and carry['current_head'] == HEAD
    assert carry['prior_source_count'] == len(prior_source['sources']) == 2254
    assert carry['current_source_count'] == len(before['sources']) == 2360
    changed = [name for name, expected in prior_source['sources'].items() if before['sources'].get(name) != expected]
    comments = ['.github/workflows/final-verification.yml', '.gitlab-ci.yml']
    assert changed == carry['prior_source_changes'] and sorted(changed) == sorted(selected_php + comments)
    assert carry['historical_comment_only_paths'] == comments and carry['comment_stripped_workflow_bytes_unchanged']
    for name in comments:
        strip = lambda data: [line for line in data.decode().splitlines() if not line.lstrip().startswith('#')]
        assert strip(git('show', PRIOR_HEAD + ':' + name)) == strip(git('show', HEAD + ':' + name)), name
    assert raw(PREFIX + 'historical-census-comment-delta.patch') == git('diff', '--no-ext-diff', '--no-textconv', PRIOR_HEAD, HEAD, '--', *comments)
    prior_pint = load('quality/pint-selection.json')['operative_php']
    assert len(prior_pint) == len(set(prior_pint)) == carry['prior_pint_selected'] == 78
    unchanged_pint = [name for name in prior_pint if before['sources'][name] == prior_source['sources'][name]]
    assert len(unchanged_pint) == carry['prior_pint_unchanged_files'] == 74
    assert set(prior_pint) - set(unchanged_pint) == set(selected_php) and carry['current_pint_selected_files'] == 4
    policy = {name: digest for name, digest in prior_source['sources'].items() if name.startswith('scripts/ci/')}
    assert len(policy) == carry['ci_script_and_policy_files_unchanged'] == 31
    assert all(before['sources'][name] == expected for name, expected in policy.items())
    policy_counts = {'policy-phpunit-shards': 52, 'policy-database-receipts': 61,
                     'policy-gitlab-database-receipts': 32, 'policy-workflow-cadence': 14}
    for name, count in policy_counts.items():
        old_log = raw('quality/' + name + '.log').decode()
        assert int(re.search(r'^Ran (\d+) tests? in ', old_log, re.M).group(1)) == count
        assert old_log.rstrip().endswith('OK') and int(raw('quality/' + name + '.exit')) == 0
    assert carry['prior159_selftests_rerun'] is False and summary['policy_selftests_rerun'] is False

    failure = load(PREFIX + 'sealing-initial-error.json')
    assert failure['exit'] == 1 and 'AssertionError' in failure['observed_output']
    assert 'assert sorted(changed)==sorted(before[\'selected_php\'])' in failure['observed_output']
    assert failure['runner_sha256'] == sha(raw(PREFIX + 'seal-receipts-initial.py'))
    assert sha(raw(PREFIX + 'seal-receipts-initial.py')) != sha(raw(PREFIX + 'seal-receipts.py'))
    assert summary['pint_exit'] == summary['discovery_exit'] == 0
    assert summary['pint_php_files'] == 4 and summary['generated_archived_and_deleted'] == 50
    assert summary['source_count'] == 2360 and summary['sources_and_metadata_unchanged'] and summary['old88_release_unchanged']
    assert summary['application_test_bodies_executed'] is False
    expected_census = {'source_cases': 8478, 'source_files': 625, 'selected_cases': 2145,
                       'selected_files': 210, 'shards': 24, 'nonempty_shards': 24, 'untimed_files': 47}
    assert summary['census'] == expected_census

    result = {
        'scope': 'Offline archive, Git-source and receipt reconciliation only. Discovery lists tests; no application or database body is executed by this verifier.',
        'head': HEAD, 'public_followup_artifacts': 77, 'source_paths_verified_against_git': 2360,
        'local_runner_metadata_hashes_unchanged': 7,
        'metadata_scope': 'Seven captured before/after proxy/autoload hash values agree; local vendor files are not read or independently reconstructed.',
        'checks': commands, 'pint_selected_files': selected_php, 'generated_artifacts_verified': 50,
        'owner_exact_cleanup_receipt_verified': True, 'census': expected_census,
        'new_source_methods': added, 'new_native_selected_methods': selected_added,
        'new_native_unselected_methods': unselected_added,
        'prior_release_artifacts_unchanged': 88, 'prior_policy_tests': sum(policy_counts.values()),
        'prior_policy_tests_rerun': False, 'unchanged_policy_script_hashes': policy,
        'unchanged_prior_pint_paths': unchanged_pint, 'prior_source_changes': changed,
        'comment_only_paths_verified': comments, 'literal_comment_patch_sha256': sha(raw(PREFIX + 'historical-census-comment-delta.patch')),
        'initial_sealing_failure': {'exit': 1, 'classification': 'Historical receipt-verifier assertion failure; neither Pint nor discovery failed or was rerun.',
                                    'output_provenance': failure['output_provenance'], 'runner_sha256': failure['runner_sha256']},
        'archive_artifacts_verified': len(verified_artifacts), 'projected_original_hash_receipts': sorted(projected_artifacts),
        'projection_scope': 'Public/archive bytes are independently hashed. For projected receipts, preserved original hashes are reconciled to author manifests; omitted original environment values are not reconstructed.',
    }
    destination = HERE / 'quality-followup-verification.json'
    destination.write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps({'output': destination.name, 'head': HEAD, 'source_paths': 2360,
                      'metadata_hashes': 7, 'selected_cases': 2145, 'policy_tests_rerun': False}))


if __name__ == '__main__':
    main()
