#!/usr/bin/env python3
"""The retired bootstrap entry point must never invoke an external command."""
import importlib.util
import io
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr
from unittest.mock import patch

SCRIPT = Path(__file__).with_name("publish-github.py")


class RetiredPublisherTest(unittest.TestCase):
    def test_import_and_main_cannot_run_subprocesses(self):
        spec = importlib.util.spec_from_file_location("retired_publisher", SCRIPT)
        publisher = importlib.util.module_from_spec(spec)
        with patch("subprocess.run", side_effect=AssertionError("Unexpected process")) as run, \
                patch("subprocess.Popen", side_effect=AssertionError("Unexpected process")) as popen, \
                redirect_stderr(io.StringIO()) as output:
            spec.loader.exec_module(publisher)
            self.assertEqual(publisher.main(), 2)
        run.assert_not_called()
        popen.assert_not_called()
        self.assertIn("https://github.com/SeanVasey/VA-Studio", output.getvalue())
        self.assertIn("pull-request workflow", output.getvalue())

    def test_actual_entrypoint_refuses_every_legacy_mode_without_git_or_gh(self):
        with tempfile.TemporaryDirectory(prefix="retired-publisher-test-") as temporary:
            root = Path(temporary)
            marker = root / "external-command-ran"
            binaries = root / "bin"
            binaries.mkdir()
            for command in ("git", "gh"):
                fake = binaries / command
                fake.write_text("#!/bin/sh\nprintf '%s\\n' invoked >> \"$PUBLISHER_TEST_MARKER\"\nexit 99\n")
                fake.chmod(0o700)
            config = root / ".git" / "config"
            config.parent.mkdir()
            before = '[remote "origin"]\n    url = https://github.com/SeanVasey/VA-Studio.git\n'
            config.write_text(before)
            environment = os.environ | {"PATH": str(binaries), "PUBLISHER_TEST_MARKER": str(marker),
                                        "GH_REPO": "VASEYDEV/VASEYAUDIO"}
            for arguments in ([], ["--issues"], ["--help"], ["--unknown"], ["--issues", "--help"]):
                with self.subTest(arguments=arguments):
                    result = subprocess.run([sys.executable, str(SCRIPT), *arguments], cwd=root, env=environment,
                                            capture_output=True, text=True, timeout=5)
                    self.assertEqual(result.returncode, 2)
                    self.assertEqual(result.stdout, "")
                    self.assertIn("retired; no changes were made", result.stderr)
                    self.assertIn("https://github.com/SeanVasey/VA-Studio", result.stderr)
                    self.assertNotIn("Expected authenticated GitHub account", result.stderr)
                    self.assertFalse(marker.exists(), "A retired publisher invoked git or gh")
                    self.assertEqual(config.read_text(), before)


if __name__ == "__main__":
    unittest.main()
