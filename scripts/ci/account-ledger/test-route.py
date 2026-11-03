#!/usr/bin/env python3
"""HOST source/gate contracts; never invoke discovery, PHP, Docker or CI."""
import ast
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import unittest

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class RouteControls(unittest.TestCase):
    def test_both_mirrors_require_real_runner_and_receipts(self):
        github = (ROOT / '.github/workflows/final-verification.yml').read_text()
        job = re.search(r'(?ms)^  backend-ledger:\n(.*?)(?=^  [\w-]+:\n)', github)[1]
        aggregate = re.search(r'(?ms)^  backend:\n(.*?)(?=^  [\w-]+:\n)', github)[1]
        self.assertIn('needs: [scope, backend-quality, frontend]', job)
        self.assertLess(job.index('verify-final-candidate.py'), job.index('account-ledger/bootstrap.py'))
        self.assertIn('backend-ledger', aggregate)
        self.assertIn('account-ledger/receipts.py collect', aggregate)
        gitlab = (ROOT / '.gitlab-ci.yml').read_text()
        job = re.search(r'(?ms)^backend-ledger:\n(.*?)(?=^[\w-]+:\n)', gitlab)[1]
        aggregate = re.search(r'(?ms)^backend:\n(.*)', gitlab)[1]
        self.assertIn('needs: [provenance-access, backend-quality, frontend]', job)
        self.assertLess(job.index('verify-final-candidate.py --gitlab'), job.index('account-ledger/bootstrap.py'))
        self.assertIn('job: backend-ledger', aggregate)
        for source in (job, github):
            self.assertNotIn('continue-on-error:', source)
            self.assertNotIn('allow_failure: true', source)
        collector = (ROOT / 'scripts/ci/gitlab-database-receipts.py').read_text()
        self.assertIn('ledger.collect_gitlab', collector)
        self.assertIn('"backend-ledger"', collector)

    def test_missing_failed_skipped_or_cancelled_job_cannot_pass_aggregate(self):
        scope = load('ledger_scope_controls', ROOT / 'scripts/ci/ci-scope.py')
        baseline = {'scope': {'result': 'success', 'outputs': {'mode': 'full'}},
                    'documentation': {'result': 'skipped'},
                    **{name: {'result': 'success'} for name in scope.RUNTIME_JOBS}}
        self.assertEqual(scope.accept(baseline), 'full')
        for result in ('failure', 'cancelled', 'skipped'):
            with self.subTest(result=result), self.assertRaises(scope.EvidenceError):
                scope.accept({**baseline, 'backend-ledger': {'result': result}})
        del baseline['backend-ledger']
        with self.assertRaises(scope.EvidenceError):
            scope.accept(baseline)

    def test_explicit_closed_inventory_preserves_original_assertions_and_unit_classifier(self):
        registry = json.loads((HERE / 'selection.json').read_bytes())
        self.assertEqual(registry['providers'], ['up-overlap', 'down-overlap', 'up-end', 'down-end'])
        self.assertEqual(registry['required_job'], 'backend-ledger')
        source = (ROOT / registry['file']).read_text().replace('namespace Tests\\Supervised\\ProductionIdentity;',
            'namespace Tests\\Feature\\ProductionIdentity;').replace(
            "$GLOBALS['accountMigratorQualifiedRuntime']['php_binary_sha256'] ?? null, hash_file('sha256', PHP_BINARY)",
            "$pins['php_binary_sha256'], hash_file('sha256', PHP_BINARY)")
        self.assertEqual(hashlib.sha256(source.encode()).hexdigest(), registry['normalized_test_sha256'])
        self.assertFalse((ROOT / 'tests/Feature/ProductionIdentity/AccountAdmissionMigratorTest.php').exists())
        self.assertTrue((ROOT / 'tests/Unit/ProductionIdentity/AccountMigratorForegroundTest.php').is_file())
        pins = json.loads((ROOT / 'docs/verification/account-migrator-evidence-20261102/pins.json').read_bytes())
        self.assertEqual(hashlib.sha256((ROOT / registry['file']).read_bytes()).hexdigest(), pins['sha256'][registry['file']])

    def test_supervisor_packet_reaper_and_deadline_algorithms_are_accepted_source(self):
        previous = ROOT / 'docs/verification/account-migrator-evidence-20261102'
        source = (HERE / 'supervision.py').read_text().replace('Tests.Supervised.ProductionIdentity.', 'Tests.Feature.ProductionIdentity.')
        self.assertEqual(source, (previous / 'supervision.py').read_text())
        old = ast.parse((previous / 'run-one.py').read_text())
        new = ast.parse((HERE / 'run-one.py').read_text().replace("root = '/candidate'",
            'root = str(Path(__file__).resolve().parents[3])'))
        for name in ('packet', 'receive', 'report', 'main'):
            before = next(node for node in old.body if isinstance(node, ast.FunctionDef) and node.name == name)
            after = next(node for node in new.body if isinstance(node, ast.FunctionDef) and node.name == name)
            self.assertEqual(ast.dump(before), ast.dump(after), name)

    def test_moved_ref_guard_precedes_requested_commit_snapshot(self):
        source = (HERE / 'bootstrap.py').read_text()
        self.assertLess(source.index("'moved-checkout-refused'"), source.index('snapshot.snapshot('))
        self.assertIn('event_sha == expected', source)
        self.assertIn("'rev-parse', 'HEAD'", source)
        self.assertIn("'outer_buildkit_capability'", source)


if __name__ == '__main__':
    unittest.main()
