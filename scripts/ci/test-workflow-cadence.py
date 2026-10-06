#!/usr/bin/env python3
"""Guard cost controls without allowing deferred or failed work to pass acceptance."""
import importlib.util
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
CI = (ROOT / '.github/workflows/final-verification.yml').read_text()
FOCUSED = (ROOT / '.github/workflows/focused-feedback.yml').read_text()
spec = importlib.util.spec_from_file_location('scope', ROOT / 'scripts/ci/ci-scope.py')
scope = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scope)


def block(source, name):
    match = re.search(rf'(?ms)^  {re.escape(name)}:\n(.*?)(?=^  [\w-]+:\n|^\S|\Z)', source)
    if not match:
        raise AssertionError(f'Missing block: {name}')
    return match.group(1)


class CadenceTests(unittest.TestCase):
    def test_retired_workflow_paths_cannot_reenter_the_tracked_tree(self):
        tracked = subprocess.run(["git", "ls-files", "-z", "--", ".github/workflows"],
                                 cwd=ROOT, capture_output=True, check=True, timeout=10).stdout.split(b"\0")
        for path in (b".github/workflows/ci.yml", b".github/workflows/focused.yml"):
            self.assertNotIn(path, tracked)
        for path in (b".github/workflows/final-verification.yml", b".github/workflows/focused-feedback.yml",
                     b".github/workflows/preflight.yml"):
            self.assertIn(path, tracked)

    def test_full_suite_is_only_explicit_dispatch_with_pinned_candidate(self):
        events = CI.split('on:\n', 1)[1].split('\npermissions:', 1)[0]
        self.assertEqual(['workflow_dispatch'], re.findall(r'^  ([a-z_]+):', events, re.M))
        self.assertIn('expected_sha:', events)
        self.assertIn('required: true', events)
        job = block(CI, 'scope')
        self.assertLess(job.index('verify-final-candidate.py'), job.index('ci-scope.py classify'))

    def test_persistent_native_checks_have_fresh_locked_asset_prerequisites(self):
        for source in (CI, (ROOT / '.github/workflows/preflight.yml').read_text()):
            job = block(source, 'backend-quality')
            self.assertLess(job.index('composer install'), job.index('npm ci'))
            self.assertLess(job.index('npm ci'), job.index('npm run build'))
            self.assertLess(job.index('npm run build'), job.index('node --test scripts/dev/persistent-content.test.mjs'))
            self.assertIn("PERSISTENT_CONTENT_REQUIRE_PHP: '1'", job)
        gitlab = (ROOT / '.gitlab-ci.yml').read_text()
        job = gitlab.split('backend-quality:\n', 1)[1].split('\n.database:', 1)[0]
        self.assertIn('bash scripts/ci/setup-gitlab-node.sh', job)
        self.assertLess(job.index('npm ci'), job.index('npm run build'))
        self.assertLess(job.index('npm run build'), job.index('PERSISTENT_CONTENT_REQUIRE_PHP=1 node --test'))

    def test_preflight_has_no_database_or_browser_jobs_and_keeps_security_checks(self):
        preflight = (ROOT / '.github/workflows/preflight.yml').read_text()
        jobs = preflight.split('jobs:\n', 1)[1]
        self.assertEqual(['scope', 'documentation', 'frontend', 'backend-quality', 'preflight'],
                         re.findall(r'^  ([a-z-]+):', jobs, re.M))
        for job in ('frontend', 'backend-quality', 'documentation'):
            self.assertEqual(block(CI, job), block(preflight, job))
        events = preflight.split('on:\n', 1)[1].split('\npermissions:', 1)[0]
        self.assertEqual(['pull_request'], re.findall(r'^  ([a-z_]+):', events, re.M))
        self.assertIn('ready_for_review', events)
        self.assertIn('cancel-in-progress: true', preflight)
        self.assertNotIn('pull_request_target', preflight)

    def test_optional_feedback_has_no_automatic_push_or_pr_trigger(self):
        events = FOCUSED.split('on:\n', 1)[1].split('\npermissions:', 1)[0]
        self.assertEqual(['workflow_dispatch'], re.findall(r'^  ([a-z_]+):', events, re.M))
        self.assertIn('FOCUSED_SUITE: ${{ inputs.suite }}', FOCUSED)
        self.assertIn('FOCUSED_ENGINE: ${{ inputs.engine }}', FOCUSED)

    def test_gitlab_full_pipeline_is_manual_and_exact_sha_guarded_before_provenance(self):
        source = (ROOT / '.gitlab-ci.yml').read_text()
        workflow = source.split('workflow:\n', 1)[1].split('\nstages:', 1)[0]
        rules = re.findall(r"^    - if: '(.+)'$", workflow, re.M)
        self.assertEqual(['($CI_PIPELINE_SOURCE == "web" || $CI_PIPELINE_SOURCE == "api") && $EXPECTED_SHA =~ /^[0-9a-f]{40}$/ && $EXPECTED_SHA == $CI_COMMIT_SHA'], rules)
        self.assertIn('    - when: never', workflow)
        self.assertIn("EXPECTED_SHA: ''", source)
        job = source.split('provenance-access:\n', 1)[1].split('\nfrontend:', 1)[0]
        self.assertLess(job.index('verify-final-candidate.py --gitlab'), job.index('gitlab-database-receipts.py probe'))
        self.assertNotIn('allow_failure:', job)

    def test_every_expensive_job_requires_both_preflight_checks(self):
        for name in ('backend-mysql', 'backend-sqlite', 'operator-browser', 'related-browser'):
            with self.subTest(job=name):
                job = block(CI, name)
                self.assertIn('    needs: [scope, backend-quality, frontend]\n', job)
                self.assertIn("    if: needs.scope.outputs.mode != 'docs'\n", job)
                # A job-level status override would bypass GitHub's default success().
                condition = re.search(r'^    if: (.+)$', job, re.M).group(1)
                self.assertNotRegex(condition, r'always\(|!cancelled\(')
                self.assertNotIn('continue-on-error:', job)

    def test_preflight_checks_are_required_and_can_start_without_expensive_jobs(self):
        for name in ('frontend', 'backend-quality'):
            job = block(CI, name)
            self.assertIn('    needs: scope\n', job)
            self.assertNotIn('continue-on-error:', job)
        self.assertIn('python3 scripts/ci/test-workflow-cadence.py', block(CI, 'backend-quality'))

    def test_failed_or_missing_preflight_cannot_pass_full_aggregate(self):
        baseline = {'scope': {'result': 'success', 'outputs': {'mode': 'full'}},
                    'documentation': {'result': 'skipped'},
                    **{job: {'result': 'success'} for job in scope.RUNTIME_JOBS}}
        for blocker in ('scope', 'frontend', 'backend-quality'):
            for result in ('failure', 'cancelled', 'skipped'):
                with self.subTest(blocker=blocker, result=result):
                    needs = {key: dict(value) for key, value in baseline.items()}
                    needs[blocker]['result'] = result
                    for job in ('backend-mysql', 'backend-sqlite', 'operator-browser', 'related-browser'):
                        needs[job]['result'] = 'skipped'
                    with self.assertRaises(scope.EvidenceError):
                        scope.accept(needs)
        self.assertEqual('full', scope.accept(baseline))
        self.assertIn('    if: always()\n', block(CI, 'backend'))
        self.assertIn('run: python3 scripts/ci/ci-scope.py gate', block(CI, 'backend'))


class GateTests(unittest.TestCase):
    @staticmethod
    def load(name):
        spec = importlib.util.spec_from_file_location(name, ROOT / f'scripts/ci/{name}.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_final_dispatch_requires_exact_event_and_checkout(self):
        gate = self.load('verify-final-candidate')
        sha = 'a' * 40
        self.assertTrue(gate.verify('workflow_dispatch', sha, sha, sha))
        for event in ('push', 'pull_request', '', 'schedule'):
            self.assertFalse(gate.verify(event, sha, sha, sha))
        for wrong in ('', 'main', 'a' * 7, 'a' * 39, 'g' * 40, 'b' * 40, sha + ' '):
            self.assertFalse(gate.verify('workflow_dispatch', wrong, sha, sha))
            self.assertFalse(gate.verify('workflow_dispatch', sha, wrong, sha))
            self.assertFalse(gate.verify('workflow_dispatch', sha, sha, wrong))

    def test_gitlab_dispatch_rejects_automatic_or_moved_or_missing_sha(self):
        gate = self.load('verify-final-candidate')
        sha = 'a' * 40
        for event in ('web', 'api'):
            self.assertTrue(gate.verify_gitlab('true', event, sha, sha, sha))
            self.assertFalse(gate.verify_gitlab('false', event, sha, sha, sha))
            for wrong in ('', 'main', 'a' * 7, 'a' * 39, 'A' * 40, 'g' * 40, 'b' * 40, sha + ' '):
                self.assertFalse(gate.verify_gitlab('true', event, wrong, sha, sha))
                self.assertFalse(gate.verify_gitlab('true', event, sha, wrong, sha))
                self.assertFalse(gate.verify_gitlab('true', event, sha, sha, wrong))
        for event in ('push', 'merge_request_event', 'schedule', 'pipeline', 'parent_pipeline', '', 'workflow_dispatch'):
            self.assertFalse(gate.verify_gitlab('true', event, sha, sha, sha))

    def test_preflight_gate_requires_every_result_in_both_modes(self):
        gate = self.load('preflight-gate')
        for mode in ('docs', 'full'):
            good = {'scope': {'result': 'success', 'outputs': {'mode': mode}},
                    'documentation': {'result': 'success' if mode == 'docs' else 'skipped'},
                    **{j: {'result': 'skipped' if mode == 'docs' else 'success'}
                       for j in ('frontend', 'backend-quality')}}
            self.assertEqual(mode, gate.accept(good))
            for job in good:
                for result in ('failure', 'cancelled', 'skipped', 'neutral', '', 'success'):
                    if result == good[job]['result']:
                        continue
                    bad = {k: dict(v) for k, v in good.items()}
                    bad[job]['result'] = result
                    with self.assertRaises(ValueError):
                        gate.accept(bad)
                bad = dict(good)
                del bad[job]
                with self.assertRaises(ValueError):
                    gate.accept(bad)
            bad = dict(good, unexpected={'result': 'success'})
            with self.assertRaises(ValueError):
                gate.accept(bad)
            for unknown in ('', 'preflight', None):
                bad = dict(good, scope={'result': 'success', 'outputs': {'mode': unknown}})
                with self.assertRaises(ValueError):
                    gate.accept(bad)


if __name__ == '__main__':
    unittest.main()
