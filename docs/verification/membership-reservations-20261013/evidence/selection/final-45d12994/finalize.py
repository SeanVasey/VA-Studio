from pathlib import Path
import hashlib, importlib.util, json, shutil, subprocess, sys
sys.dont_write_bytecode = True
root = Path('/workspace/VA-Studio-membership-ledger')
base = Path('/workspace/runtime/membership-reservation-selection-evidence')
out = base / 'final-45d12994'
sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
assert sha == '45d1299406821627f4ba02af186e324a70c6ade0'
assert (out/'discovery.exit').read_text().strip() == '0'
assert json.loads((out/'discovery-source-check.json').read_text()) == {'files': 2091, 'changed': []}
spec = importlib.util.spec_from_file_location('reservation_shards_final', root/'scripts/ci/phpunit-shards.py')
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)
selection = module.read_selection(root)
full = module.read_inventory(root/'phpunit-ci-mysql-source-tests.xml', root)
native = module.select_native(full, selection)
paths = sorted(p.relative_to(root).as_posix() for p in (root/'tests').rglob('*.php'))
branch_errors = module.branching_coverage_errors(root, paths, selection)
original = json.loads((base/'policy.sources.json').read_text())
current = {p: hashlib.sha256((root/p).read_bytes()).hexdigest() for p in original}
changed = [p for p in original if original[p] != current[p]]
carry = {
    'source': sha,
    'prior_policy_test_source': '565f19d1d19c9a7a513670c169a6c2e181083648',
    'policy_files_compared': len(original),
    'changed_policy_files': changed,
    'current_driver_branch_census_errors': branch_errors,
    'policy_suites_rerun': False,
    'selection_only_commit': 'a01bdc3673a4b0fd25f25958075201b1290ce888',
    'integrated_selection_commit': '397a339f',
    'source_hashes': current,
}
(out/'policy-carry-verification.json').write_text(json.dumps(carry, indent=2)+'\n')
assert changed == [] and branch_errors == []
prior = json.loads((base/'final-defe9580/discovery.sources.json').read_text())
now = json.loads((out/'discovery.sources.json').read_text())
source_delta = {'prior_source': 'defe95802e7d09e7df12c61a98c1ebf1caee99c0', 'source': sha,
    'changed_files': [p for p in sorted(set(prior)|set(now)) if prior.get(p) != now.get(p)]}
(out/'source-delta.json').write_text(json.dumps(source_delta, indent=2)+'\n')
assert source_delta['changed_files'] == [
    'tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php',
    'tests/Feature/ProductionMembershipBilling/MembershipNativeReservationRaceTest.php',
]
manifest = json.loads((root/'phpunit-ci-mysql-manifest.json').read_text())
(out/'discovery-manifest.json').write_text(json.dumps(manifest, indent=2)+'\n')
census = {'source': sha, 'source_files': len(full.files), 'source_cases': len(full.cases),
    'native_files': len(native.files), 'native_cases': len(native.cases),
    'sqlite_skip_methods': len(selection.skip_pairs), 'mysql_skip_methods': len(selection.mysql_skip_pairs), 'files': []}
for entry in json.loads((base/'discovery-census.json').read_text())['files']:
    file = entry['file']
    cases = [c for c, f in full.cases.items() if f == file]
    census['files'].append({'file': file, 'discovered_cases': len(cases), 'mysql_selected': file in native.files,
        'sqlite_skip_cases': sum(full.methods.get(c) in selection.skip_pairs for c in cases),
        'mysql_skip_cases': sum(full.methods.get(c) in selection.mysql_skip_pairs for c in cases)})
(out/'discovery-census.json').write_text(json.dumps(census, indent=2)+'\n')
assert (len(native.cases), len(native.files), len(full.cases), len(full.files), len(manifest['shards'])) == (1973,187,8047,587,24)
assert (len(selection.skip_pairs), len(selection.mysql_skip_pairs), len(manifest['weighting']['untimed_files'])) == (231,16,24)
artifacts = sorted(root.glob('phpunit-ci-mysql*'))
assert len(artifacts) == 50
hashes = {p.name: {'sha256': hashlib.sha256(p.read_bytes()).hexdigest(), 'bytes': p.stat().st_size} for p in artifacts}
(out/'discovery-generated-artifacts.json').write_text(json.dumps(hashes, indent=2)+'\n')
generated = out/'generated'
generated.mkdir(exist_ok=False)
for p in artifacts:
    assert hashes[p.name]['sha256'] == hashlib.sha256(p.read_bytes()).hexdigest()
    shutil.move(p, generated/p.name)
    assert hashes[p.name]['sha256'] == hashlib.sha256((generated/p.name).read_bytes()).hexdigest()
print(json.dumps({'source': sha, 'native_cases': len(native.cases), 'native_files': len(native.files),
    'source_cases': len(full.cases), 'source_files': len(full.files), 'shards':len(manifest['shards']),
    'source_files_checked':len(now), 'policy_files_unchanged':len(original), 'branch_errors':branch_errors,
    'source_delta':source_delta['changed_files'], 'generated_files_retained':len(artifacts)}, indent=2))
