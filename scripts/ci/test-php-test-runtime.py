#!/usr/bin/env python3
"""Exercise the actual read-only PHP preflight without loading the application."""

from pathlib import Path
import re
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
HELPER = ROOT / "scripts/ci/verify-php-test-runtime.php"


class PhpTestRuntimeTests(unittest.TestCase):
    def probe(self, memory="512M", disabled="", no_ini=False):
        executable = shutil.which("php")
        self.assertIsNotNone(executable, "PHP runtime verification requires PHP.")
        return subprocess.run([executable, *(["-n"] if no_ini else []), "-d", "memory_limit=" + memory,
                               "-d", "disable_functions=" + disabled, str(HELPER)],
                              capture_output=True, text=True, timeout=5)

    def test_real_configured_runtime_passes_without_output_or_application_boot(self):
        result = self.probe()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("", result.stdout + result.stderr)

    def test_disabled_process_control_is_rejected_before_tests(self):
        for disabled in ("pcntl_signal", "posix_setrlimit"):
            result = self.probe(disabled=disabled)
            with self.subTest(disabled=disabled):
                self.assertEqual(1, result.returncode)
                self.assertEqual("", result.stdout)
                self.assertIn("require POSIX resource limits and PCNTL signals", result.stderr)

    def test_small_unbounded_or_different_memory_limit_is_rejected(self):
        for memory in ("128M", "-1", "1G"):
            result = self.probe(memory=memory)
            with self.subTest(memory=memory):
                self.assertEqual(1, result.returncode)
                self.assertEqual("", result.stdout)
                self.assertIn("512M memory limit", result.stderr)

    def test_missing_extensions_are_rejected(self):
        result = self.probe(no_ini=True)
        self.assertEqual(1, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertIn("required PHP test extension is unavailable", result.stderr)

    def test_every_actions_php_setup_is_bounded_and_preflighted(self):
        for filename in ("final-verification.yml", "focused-feedback.yml", "preflight.yml"):
            workflow = (ROOT / ".github/workflows" / filename).read_text()
            setups = re.findall(r"(?ms)^      - uses: shivammathur/setup-php@[^\n]+\n(.*?)(?=^      - uses:|\Z)", workflow)
            self.assertTrue(setups)
            for setup in setups:
                with self.subTest(workflow=filename, setup=setup[:100]):
                    self.assertIn("ini-values: memory_limit=512M", setup)
                    extensions = re.search(r"(?m)^          extensions: (.+)$", setup)
                    self.assertIsNotNone(extensions)
                    self.assertTrue({"posix", "pcntl", "fileinfo", "pdo_mysql", "pdo_sqlite", "dom", "xml", "xmlwriter"}
                                    <= set(extensions.group(1).split(", ")))
                    # Before Composer or any test, the first following step is the actual preflight.
                    self.assertRegex(setup, r"tools: composer:v2\n      - name: Verify bounded PHP test capabilities\n        run: php scripts/ci/verify-php-test-runtime.php\n")


if __name__ == "__main__":
    unittest.main(verbosity=2)
