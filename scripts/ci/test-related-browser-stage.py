#!/usr/bin/env python3
"""Execute startup refusals; these safeguards do not prove scanner/native acceptance."""

import os
from pathlib import Path
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class RelatedBrowserStageSafeguards(unittest.TestCase):
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
