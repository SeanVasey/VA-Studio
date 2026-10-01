#!/usr/bin/env python3
"""Execute startup refusals; these safeguards do not prove scanner/native acceptance."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]


class RelatedBrowserStageSafeguards(unittest.TestCase):
    def test_discovery_keeps_exact_editorial_and_sharing_cases_in_both_projects(self):
        with tempfile.TemporaryDirectory(prefix="vasey-related-discovery-") as directory:
            env = {**os.environ, "VASEY_BROWSER_DIRECTORY": directory, "VASEY_BROWSER_RELATED_STAGE": "1", "VASEY_BROWSER_RELATED_MARKER": "0" * 64}
            result = subprocess.run(
                ["node", "node_modules/@playwright/test/cli.js", "test", "--config=playwright.related.config.ts", "--list", "--reporter=json"],
                cwd=ROOT, env=env, text=True, capture_output=True, timeout=10,
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        report = json.loads(result.stdout)
        self.assertEqual([], report.get("errors", []))
        test_root = Path(report["config"]["rootDir"]).resolve()
        self.assertEqual((ROOT / "tests/browser-related").resolve(), test_root)
        observed = []

        def collect(suite):
            for spec in suite.get("specs", []):
                for case in spec.get("tests", []):
                    file = (test_root / spec["file"]).resolve().relative_to(test_root).as_posix()
                    observed.append((file, case["projectName"]))
            for child in suite.get("suites", []):
                collect(child)

        for suite in report.get("suites", []):
            collect(suite)
        self.assertEqual(sorted(observed), sorted(
            (file, project)
            for file in ["editorial-related-tracks.spec.ts", "operator-sharing.spec.ts"]
            for project in ["chromium-desktop", "webkit-mobile"]
        ))

    def test_config_refuses_missing_stage_or_noncanonical_marker(self):
        for stage, marker in [("", "0" * 64), ("1", ""), ("1", "A" * 64), ("1", "0" * 64 + "\n")]:
            with self.subTest(stage=stage, marker=repr(marker)):
                env = {**os.environ, "VASEY_BROWSER_DIRECTORY": "/tmp/vasey-related-discovery-only", "VASEY_BROWSER_RELATED_STAGE": stage, "VASEY_BROWSER_RELATED_MARKER": marker}
                result = subprocess.run(
                    ["node", "node_modules/@playwright/test/cli.js", "test", "--config=playwright.related.config.ts", "--list"],
                    cwd=ROOT, env=env, text=True, capture_output=True, timeout=10,
                )
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("Use node tests/browser/run-related.mjs", result.stderr)

    def test_runner_refuses_redirected_selection_and_configuration(self):
        for argument in ["--config=playwright.config.ts", "operator.spec.ts", "--grep=operator", "--project=chromium-desktop"]:
            with self.subTest(argument=argument):
                result = subprocess.run(
                    ["node", "tests/browser/run-related.mjs", argument], cwd=ROOT,
                    text=True, capture_output=True, timeout=10,
                )
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("accepts no selection or configuration arguments", result.stderr)

    def test_scanner_installer_refuses_developer_self_hosted_and_other_platforms(self):
        for values in [
            {},
            {"GITHUB_ACTIONS": "false", "RUNNER_ENVIRONMENT": "github-hosted", "RUNNER_OS": "Linux"},
            {"GITHUB_ACTIONS": "true", "RUNNER_ENVIRONMENT": "self-hosted", "RUNNER_OS": "Linux"},
            {"GITHUB_ACTIONS": "true", "RUNNER_ENVIRONMENT": "github-hosted", "RUNNER_OS": "Windows"},
        ]:
            with self.subTest(values=values):
                env = {key: value for key, value in os.environ.items() if key not in {"GITHUB_ACTIONS", "RUNNER_ENVIRONMENT", "RUNNER_OS"}}
                result = subprocess.run(
                    ["bash", "tests/browser/install-related-scanner.sh"], cwd=ROOT,
                    env={**env, **values}, text=True, capture_output=True, timeout=10,
                )
                self.assertEqual(result.returncode, 1)
                self.assertIn("requires a disposable GitHub-hosted Linux runner", result.stderr)
                self.assertEqual(result.stdout, "")

    def test_installer_refuses_unexpected_arguments_before_any_install(self):
        env = {**os.environ, "GITHUB_ACTIONS": "true", "RUNNER_ENVIRONMENT": "github-hosted", "RUNNER_OS": "Linux"}
        result = subprocess.run(
            ["bash", "tests/browser/install-related-scanner.sh", "--force"], cwd=ROOT,
            env=env, text=True, capture_output=True, timeout=10,
        )
        self.assertEqual(result.returncode, 1)
        self.assertIn("requires a disposable GitHub-hosted Linux runner", result.stderr)
        self.assertEqual(result.stdout, "")


if __name__ == "__main__":
    unittest.main()
