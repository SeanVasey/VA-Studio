#!/usr/bin/env python3
"""Exercise scope routing with real Git histories and acceptance failure states."""

import importlib.util
import json
from pathlib import Path
import re
import subprocess
import tempfile
import unittest


spec = importlib.util.spec_from_file_location("ci_scope", Path(__file__).with_name("ci-scope.py"))
scope = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scope)


class HistoryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.run_git("init", "-q")
        self.run_git("config", "user.email", "ci@example.invalid")
        self.run_git("config", "user.name", "CI fixture")
        self.write("README.md", "# Initial\n")
        self.write("app/code.php", "<?php // fixture\n")
        self.base = self.commit()

    def run_git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.root, stderr=subprocess.PIPE).decode().strip()

    def write(self, name, value):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(value)

    def commit(self):
        self.run_git("add", "--all")
        self.run_git("commit", "-qm", "fixture", "--allow-empty")
        return self.run_git("rev-parse", "HEAD")

    def decision(self, head, base=None):
        return scope.classify(self.root, "pull_request", {"pull_request": {"base": {"sha": base or self.base}}}, head)

    def test_prose_update_has_verified_identity_and_passes_documents(self):
        self.write("README.md", "# Updated\n")
        head = self.commit()
        decision = self.decision(head)
        self.assertEqual("docs", decision["mode"])
        self.assertEqual(["README.md"], decision["paths"])
        self.assertEqual(head, decision["head"])
        self.assertEqual(1, scope.validate_documents(self.root, decision))

    def test_two_parent_pr_merge_compares_to_the_current_base(self):
        self.run_git("checkout", "-qb", "fixture-feature")
        self.write("README.md", "feature docs\n")
        feature = self.commit()
        self.run_git("checkout", "--detach", self.base)
        self.write("app/code.php", "<?php // independently accepted base\n")
        advanced_base = self.commit()
        self.run_git("merge", "--no-ff", "-m", "synthetic PR merge", feature)
        merged = self.run_git("rev-parse", "HEAD")
        self.assertEqual("docs", self.decision(merged, advanced_base)["mode"])
        self.assertEqual("full", self.decision(merged, self.base)["mode"])
        self.assertEqual("full", self.decision(feature, advanced_base)["mode"])

    def test_new_unknown_document_requires_full(self):
        self.write("docs/not-in-allowlist.md", "# New\n")
        self.assertEqual("full", self.decision(self.commit())["mode"])

    def test_mixed_application_and_docs_requires_full(self):
        self.write("README.md", "# Updated\n")
        self.write("app/code.php", "<?php // changed\n")
        self.assertEqual("full", self.decision(self.commit())["mode"])

    def test_workflow_classifier_and_lock_changes_require_full(self):
        for name in (".github/workflows/ci.yml", "scripts/ci/ci-scope.py", "composer.lock", "AGENTS.md", "docs/brand/asset-manifest.json"):
            with self.subTest(name=name):
                self.run_git("reset", "--hard", self.base)
                self.write(name, "changed\n")
                self.assertEqual("full", self.decision(self.commit())["mode"])

    def test_rename_from_runtime_into_allowed_document_is_full(self):
        self.run_git("mv", "app/code.php", "CHANGELOG.md")
        self.assertEqual("full", self.decision(self.commit())["mode"])

    def test_allowed_document_deletion_is_validated(self):
        (self.root / "README.md").unlink()
        head = self.commit()
        self.assertEqual("docs", self.decision(head)["mode"])
        self.assertEqual(0, scope.validate_documents(self.root, self.decision(head)))

    def test_deletion_cannot_leave_retained_dangling_link(self):
        self.write("CHANGELOG.md", "[Readme](README.md)\n")
        self.base = self.commit()
        (self.root / "README.md").unlink()
        head = self.commit()
        with self.assertRaises(scope.EvidenceError):
            scope.validate_documents(self.root, self.decision(head))

    def test_link_outside_checkout_is_rejected(self):
        self.write("README.md", "[Outside](../)\n")
        head = self.commit()
        with self.assertRaises(scope.EvidenceError):
            scope.validate_documents(self.root, self.decision(head))

    def test_symlink_and_executable_document_require_full(self):
        for mode in ("symlink", "executable"):
            with self.subTest(mode=mode):
                self.run_git("reset", "--hard", self.base)
                path = self.root / "README.md"
                if mode == "symlink":
                    path.unlink()
                    path.symlink_to("app/code.php")
                else:
                    path.chmod(0o755)
                    self.write("README.md", "changed\n")
                self.assertEqual("full", self.decision(self.commit())["mode"])

    def test_empty_diff_missing_base_and_stale_checkout_are_full(self):
        self.assertEqual("full", self.decision(self.base)["mode"])
        self.write("README.md", "changed\n")
        head = self.commit()
        self.assertEqual("full", self.decision(head, "0" * 40)["mode"])
        self.assertEqual("full", self.decision(self.base)["mode"])
        self.assertEqual("full", self.decision(head, "--output=bad")["mode"])

    def test_unrelated_base_is_full(self):
        self.write("README.md", "branch one\n")
        other = self.commit()
        self.run_git("reset", "--hard", self.base)
        self.write("README.md", "branch two\n")
        self.assertEqual("full", self.decision(self.commit(), other)["mode"])

    def test_manual_and_unknown_event_always_full(self):
        self.write("README.md", "changed\n")
        head = self.commit()
        for event in ("workflow_dispatch", "schedule", "merge_group", "unknown"):
            with self.subTest(event=event):
                self.assertEqual("full", scope.classify(self.root, event, {}, head)["mode"])

    def test_main_push_uses_entire_before_after_diff(self):
        self.write("README.md", "changed\n")
        head = self.commit()
        event = {"ref": "refs/heads/main", "before": self.base, "after": head}
        self.assertEqual("docs", scope.classify(self.root, "push", event, head)["mode"])
        event["ref"] = "refs/heads/feature"
        self.assertEqual("full", scope.classify(self.root, "push", event, head)["mode"])

    def test_docs_validator_rejects_changed_evidence_and_checkout(self):
        self.write("README.md", "changed\n")
        head = self.commit()
        decision = self.decision(head)
        decision["paths"] = []
        with self.assertRaises(scope.EvidenceError):
            scope.validate_documents(self.root, decision)
        decision = self.decision(head)
        self.write("README.md", "newer\n")
        self.commit()
        with self.assertRaises(scope.EvidenceError):
            scope.validate_documents(self.root, decision)

    def test_csv_width_and_text_nul_are_rejected(self):
        for name, content in (("docs/remaining-development-tasks.csv", "id,value\na\n"), ("README.md", "bad\0text\n")):
            with self.subTest(name=name):
                self.run_git("reset", "--hard", self.base)
                self.write(name, content)
                head = self.commit()
                with self.assertRaises(scope.EvidenceError):
                    scope.validate_documents(self.root, self.decision(head))

    def test_git_evidence_bound_falls_back_to_full(self):
        self.write("README.md", "changed\n")
        head = self.commit()
        previous = scope.MAX_DIFF_BYTES
        try:
            scope.MAX_DIFF_BYTES = 1
            self.assertEqual("full", self.decision(head)["mode"])
        finally:
            scope.MAX_DIFF_BYTES = previous


class AcceptanceTests(unittest.TestCase):
    def needs(self, mode):
        return {"scope": {"result": "success", "outputs": {"mode": mode}}, "documentation": {"result": "success" if mode == "docs" else "skipped"}, **{job: {"result": "skipped" if mode == "docs" else "success"} for job in scope.RUNTIME_JOBS}}

    def test_both_valid_modes(self):
        for mode in ("docs", "full"):
            self.assertEqual(mode, scope.accept(self.needs(mode)))

    def test_runtime_gate_rejects_every_unsuccessful_mandatory_job(self):
        for job in ("scope", *scope.RUNTIME_JOBS):
            for result in ("failure", "cancelled", "skipped", "", "neutral", "unknown"):
                with self.subTest(job=job, result=result):
                    needs = self.needs("full")
                    needs[job]["result"] = result
                    with self.assertRaises(scope.EvidenceError):
                        scope.accept(needs)

    def test_docs_gate_requires_success_and_exact_mode(self):
        for job in ("scope", "documentation"):
            for result in ("failure", "cancelled", "skipped", "", "unknown"):
                with self.subTest(job=job, result=result):
                    needs = self.needs("docs")
                    needs[job]["result"] = result
                    with self.assertRaises(scope.EvidenceError):
                        scope.accept(needs)
        for mode in (None, "", "doc", "FULL"):
            needs = self.needs("docs")
            needs["scope"]["outputs"]["mode"] = mode
            with self.assertRaises(scope.EvidenceError):
                scope.accept(needs)

    def test_absent_or_extra_job_cannot_satisfy_gate(self):
        for job in self.needs("full"):
            needs = self.needs("full")
            del needs[job]
            with self.assertRaises(scope.EvidenceError):
                scope.accept(needs)
        needs = self.needs("docs")
        needs["unexpected"] = {"result": "success"}
        with self.assertRaises(scope.EvidenceError):
            scope.accept(needs)

    def test_inconsistent_docs_runtime_results_are_rejected(self):
        for job in scope.RUNTIME_JOBS:
            needs = self.needs("docs")
            needs[job]["result"] = "failure"
            with self.assertRaises(scope.EvidenceError):
                scope.accept(needs)


class BrowserWorkflowTests(unittest.TestCase):
    root = Path(__file__).resolve().parents[2]

    def operator_job(self):
        workflow = (self.root / ".github/workflows/ci.yml").read_text()
        match = re.search(r"(?ms)^  operator-browser:\n(.*?)(?=^  [\w-]+:\n|\Z)", workflow)
        self.assertIsNotNone(match)
        return match.group(1)

    def test_matrix_covers_every_configured_engine_through_the_complete_isolated_wrapper(self):
        job = self.operator_job()
        matrix = re.search(r"(?m)^        project: \[([^\]]+)\]$", job)
        self.assertIsNotNone(matrix)
        projects = [project.strip() for project in matrix.group(1).split(",")]
        config = (self.root / "playwright.config.ts").read_text()
        configured = re.findall(r"\{ name: '([^']+)', use:", config)
        self.assertEqual(["chromium-desktop", "webkit-mobile"], projects)
        self.assertEqual(configured, projects)
        self.assertIn("      fail-fast: false\n", job)
        self.assertIn("    runs-on: ubuntu-latest\n", job)
        for forbidden in ("include:", "exclude:", "fromJSON", "continue-on-error"):
            self.assertNotIn(forbidden, job)
        self.assertIn("      BROWSER_PROJECT: ${{ matrix.project }}\n", job)
        browser_commands = [command for command in re.findall(r"(?m)^      - run: (.+)$", job) if "test:browser" in command]
        # A project is the sole selector: no file subset, alternate config, grep or additional runner.
        self.assertEqual(['npm run test:browser -- --project="$BROWSER_PROJECT"'], browser_commands)
        package = json.loads((self.root / "package.json").read_text())
        self.assertEqual("node tests/browser/run.mjs", package["scripts"]["test:browser"])
        self.assertIn("  testDir: './tests/browser',\n", config)
        self.assertIn("  testMatch: '**/*.spec.ts',\n", config)

    def test_every_ordinary_browser_job_installs_genuine_customer_delivery_prerequisites(self):
        for workflow, job_name, command in (
            ("ci.yml", "operator-browser", 'npm run test:browser -- --project="$BROWSER_PROJECT"'),
            ("focused.yml", "focused-browser", "python3 scripts/ci/focused-tests.py"),
        ):
            with self.subTest(workflow=workflow):
                source = (self.root / ".github/workflows" / workflow).read_text()
                match = re.search(rf"(?ms)^  {job_name}:\n(.*?)(?=^  [\w-]+:\n|\Z)", source)
                self.assertIsNotNone(match)
                job = match.group(1)
                # A required step with no conditional, fallback or ignored failure.
                install = re.search(r"(?m)^      - name: [^\n]+\n        run: bash tests/browser/install-related-scanner.sh$", job)
                self.assertIsNotNone(install)
                self.assertLess(install.start(), job.index(command))
                self.assertEqual(1, job.count("bash tests/browser/install-related-scanner.sh"))
        source = (self.root / ".gitlab-ci.yml").read_text()
        match = re.search(r"(?ms)^operator-browser:\n(.*?)(?=^[\w-]+:\n|\Z)", source)
        self.assertIsNotNone(match)
        job = match.group(1)
        self.assertIn("    - bash scripts/ci/setup-gitlab-related-scanner.sh\n", job)
        self.assertLess(job.index("bash scripts/ci/setup-gitlab-related-scanner.sh"), job.index("  script:\n"))
        wrapper = (self.root / "tests/browser/run.mjs").read_text()
        self.assertIn("APP_ENV: 'local'", wrapper)
        self.assertIn("MEDIA_CLAMSCAN: join(directory, 'no-clamscan')", wrapper)

    def test_each_engine_retains_failure_evidence_without_run_or_attempt_collisions(self):
        job = self.operator_job()
        upload = re.search(r"(?ms)^      - name: [^\n]+\n        if: always\(\)\n        uses: actions/upload-artifact@[^\n]+\n        with:\n(.*?)(?=^      - |\Z)", job)
        self.assertIsNotNone(upload)
        artifact = re.search(r"(?m)^          name: (.+)$", upload.group(1))
        self.assertIsNotNone(artifact)
        template = artifact.group(1)
        for identity in ("${{ matrix.project }}", "${{ github.run_id }}", "${{ github.run_attempt }}"):
            self.assertIn(identity, template)
        names = {template.replace("${{ matrix.project }}", project).replace("${{ github.run_id }}", run).replace("${{ github.run_attempt }}", attempt)
                 for project in ("chromium-desktop", "webkit-mobile") for run in ("1", "2") for attempt in ("1", "2")}
        self.assertEqual(8, len(names))
        self.assertIn("          path: |\n            playwright-report/\n            test-results/\n", upload.group(1))
        self.assertIn("          retention-days: 7\n", upload.group(1))

    def test_browser_budgets_preserve_teardown_and_installation_headroom(self):
        config = (self.root / "playwright.config.ts").read_text()
        wrapper = (self.root / "tests/browser/run.mjs").read_text()
        suite = re.search(r"(?m)^  globalTimeout: (\d+) \* 60_000,$", config)
        process = re.search(r"cwd: root, env, stdio: 'inherit', timeout: (\d+),", wrapper)
        self.assertIsNotNone(suite)
        self.assertIsNotNone(process)
        suite_ms, wrapper_ms = int(suite.group(1)) * 60_000, int(process.group(1))
        self.assertEqual(26 * 60_000, suite_ms)
        self.assertEqual(60_000, wrapper_ms - suite_ms)
        for unchanged in ("  workers: 1,\n", "  retries: 0,\n", "  timeout: 60_000,\n", "  expect: { timeout: 10_000 },\n"):
            self.assertIn(unchanged, config)
        gitlab = (self.root / ".gitlab-ci.yml").read_text()
        gitlab_job = re.search(r"(?ms)^operator-browser:\n(.*?)(?=^[\w-]+:\n|\Z)", gitlab)
        self.assertIsNotNone(gitlab_job)
        self.assertIn('    - npm run test:browser -- --project="$BROWSER_PROJECT"\n', gitlab_job.group(1))
        github_limit = re.search(r"(?m)^    timeout-minutes: (\d+)$", self.operator_job())
        gitlab_limit = re.search(r"(?m)^  timeout: (\d+)m$", gitlab_job.group(1))
        for limit in (github_limit, gitlab_limit):
            self.assertIsNotNone(limit)
            self.assertEqual(50, int(limit.group(1)))
            # The 14-minute observed install delay plus one-minute fixture ceiling must
            # fit outside the wrapper, with time remaining for builds and artifacts.
            self.assertGreater(int(limit.group(1)) * 60_000 - wrapper_ms, 15 * 60_000)


if __name__ == "__main__":
    unittest.main()
