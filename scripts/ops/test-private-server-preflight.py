#!/usr/bin/env python3
"""Exercise the real PHP preflight against isolated synthetic configuration files."""

import base64
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/ops/private-server-preflight.php"
PHP = os.environ.get("PRIVATE_PREFLIGHT_PHP", "php")
TEMPLATE = (ROOT / "ops/private-server/env.example").read_text()
SENTINEL = "SYNTHETIC_SECRET_DO_NOT_REPORT_1247"


def replace(source, key, value):
    lines = source.splitlines()
    found = False
    for index, line in enumerate(lines):
        if line.startswith(key + "="):
            lines[index] = key + "=" + value
            found = True
    if not found:
        lines.append(key + "=" + value)
    return "\n".join(lines) + "\n"


def configured():
    source = TEMPLATE
    for key, value in {
        "APP_URL": "https://synthetic.example.invalid",
        "APP_KEY": "base64:" + base64.b64encode(b"s" * 32).decode(),
        "DB_HOST": "synthetic.example.invalid",
        "DB_DATABASE": "synthetic_database",
        "DB_USERNAME": "synthetic_runtime",
        "DB_PASSWORD": SENTINEL,
        "MEDIA_FFMPEG": "/not-installed/ffmpeg",
        "MEDIA_FFPROBE": "/not-installed/ffprobe",
        "MEDIA_PRLIMIT": "/not-installed/prlimit",
        "MEDIA_CLAMSCAN": "/not-installed/clamscan",
        "MEDIA_TAG_PATH": "approved/tag.wav",
        "MEDIA_TAG_SHA256": "a" * 64,
    }.items():
        source = replace(source, key, value)
    return source


class PreflightTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="va-preflight-")
        self.directory = Path(self.temporary.name)
        self.path = self.directory / "private-config"

    def tearDown(self):
        self.temporary.cleanup()

    def run_check(self, source=None, mode=0o600, arguments=None, env=None):
        if source is not None:
            if self.path.exists():
                self.path.unlink()
            self.path.write_text(source)
            self.path.chmod(mode)
        command = [PHP, str(SCRIPT)] + (arguments or ["--env-file", str(self.path)])
        result = subprocess.run(command, capture_output=True, text=True, timeout=15, env=env)
        self.assertEqual(result.stderr, "")
        self.assertNotIn(SENTINEL, result.stdout)
        self.assertNotIn(str(self.path), result.stdout)
        report = json.loads(result.stdout)
        self.assertFalse(report["deployment_ready"])
        self.assertEqual(result.returncode, 1 if report["result"] == "BLOCKED" else 0)
        return report

    def blocked(self, report, check):
        self.assertEqual(report["result"], "BLOCKED")
        self.assertIn({"id": check, "status": "blocked"}, report["checks"])

    def test_template_valid_is_distinct_from_deployment(self):
        report = self.run_check(arguments=["--template"])
        self.assertEqual(report["result"], "TEMPLATE_VALID")
        self.assertEqual(report["scope"], "template_file")

    def test_blank_template_is_blocked_as_actual_configuration(self):
        self.blocked(self.run_check(TEMPLATE), "configured_app_key")

    def test_protected_configuration_is_only_file_level_pass(self):
        for mode in [0o400, 0o440, 0o600, 0o640]:
            with self.subTest(mode=oct(mode)):
                report = self.run_check(configured(), mode)
                self.assertEqual(report["result"], "FILE_CHECKS_PASSED")
                self.assertIn("mysql_version_connection_grants_and_schema", report["unverified"])
                self.assertIn("effective_cached_and_process_configuration", report["unverified"])

    def test_exposed_writable_or_executable_file_is_refused_before_parse(self):
        for mode in [0o644, 0o666, 0o620, 0o700]:
            with self.subTest(mode=oct(mode)):
                report = self.run_check('DB_PASSWORD="' + SENTINEL, mode)
                self.blocked(report, "input_permissions")
                self.assertNotIn("dotenv_syntax", [check["id"] for check in report["checks"]])

    def test_symlink_file_and_ancestor_are_refused(self):
        target = self.directory / "actual"
        target.mkdir()
        secret = target / "config"
        secret.write_text(configured())
        secret.chmod(0o600)
        self.path.symlink_to(secret)
        self.blocked(self.run_check(), "input_canonical_regular_file")
        alias = self.directory / "alias"
        alias.symlink_to(target, target_is_directory=True)
        self.blocked(self.run_check(arguments=["--env-file", str(alias / "config")]), "input_canonical_regular_file")

    def test_hard_link_is_refused(self):
        self.path.write_text(configured())
        self.path.chmod(0o600)
        os.link(self.path, self.directory / "second-link")
        self.blocked(self.run_check(), "input_bounded_single_link")

    def test_duplicate_and_malformed_secrets_never_escape(self):
        self.blocked(self.run_check(configured() + "DB_PASSWORD=" + SENTINEL + "\n"), "dotenv_unique_keys")
        self.blocked(self.run_check(configured() + 'OTHER="' + SENTINEL), "dotenv_syntax")

    def test_size_bound(self):
        self.blocked(self.run_check("#" + "x" * 131073), "input_bounded_single_link")

    def test_unsafe_activation_and_debug_controls_are_blocked(self):
        for key in ["APP_DEBUG", "STRIPE_WEBHOOK_ENABLED", "STRIPE_TEST_CHECKOUT_ENABLED", "CONTACT_INQUIRIES_ENABLED"]:
            with self.subTest(key=key):
                self.blocked(self.run_check(replace(configured(), key, "true")), "baseline_" + key.lower())
        self.blocked(self.run_check(replace(configured(), "STRIPE_MODE", "live")), "baseline_stripe_mode")

    def test_no_real_provider_secrets_or_test_policies_in_preparation(self):
        self.blocked(self.run_check(replace(configured(), "STRIPE_TEST_SECRET_KEY", SENTINEL)), "inactive_stripe_test_secret_key")

    def test_retry_must_exceed_media_lease(self):
        for value in ["90", "900", "960", "1200seconds"]:
            self.blocked(self.run_check(replace(configured(), "DB_QUEUE_RETRY_AFTER", value)), "queue_retry_exceeds_media_timeout_and_lease")

    def test_credential_and_endpoint_shapes(self):
        for key, value, check in [
            ("APP_URL", "http://synthetic.example.invalid", "app_https_origin"),
            ("APP_URL", "https://user:password@synthetic.example.invalid", "app_https_origin"),
            ("APP_KEY", SENTINEL, "application_key_shape_only"),
            ("DB_HOST", "", "database_endpoint_configured"),
            ("DB_PORT", "65536", "database_port_valid"),
            ("DB_USERNAME", "root", "database_nonroot_identity_named"),
            ("MEDIA_TAG_PATH", "../secret.wav", "media_tag_relative_path"),
            ("MEDIA_TAG_SHA256", "invalid", "media_tag_hash_shape_only"),
        ]:
            with self.subTest(key=key, check=check):
                self.blocked(self.run_check(replace(configured(), key, value)), check)

    def test_unreviewed_precedence_overrides_are_blocked(self):
        for key in ["DB_URL", "LARAVEL_STORAGE_PATH", "APP_CONFIG_CACHE", "DB_QUEUE_CONNECTION"]:
            self.blocked(self.run_check(replace(configured(), key, SENTINEL)), "no_unreviewed_" + key.lower())

    def test_no_shell_evaluation_or_parent_environment_resolution(self):
        marker = self.directory / "must-not-exist"
        source = replace(configured(), "APP_NAME", "'$(touch " + str(marker) + ")'")
        self.assertEqual(self.run_check(source)["result"], "FILE_CHECKS_PASSED")
        self.assertFalse(marker.exists())
        environment = os.environ | {"ONLY_IN_PARENT": SENTINEL}
        source = replace(configured(), "DB_PASSWORD", '"${ONLY_IN_PARENT}"')
        self.blocked(self.run_check(source, env=environment), "configured_db_password")

    def test_runtime_inspection_reports_missing_tools_without_executing_them(self):
        executable = self.directory / "tool"
        marker = self.directory / "tool-was-executed"
        executable.write_text("#!/bin/sh\ntouch '" + str(marker) + "'\n")
        executable.chmod(0o700)
        source = replace(configured(), "MEDIA_FFMPEG", str(executable))
        before = source.encode()
        report = self.run_check(source, arguments=["--env-file", str(self.path), "--runtime"])
        self.blocked(report, "runtime_executable_media_ffprobe")
        self.assertIn({"id": "runtime_executable_media_ffmpeg", "status": "pass"}, report["checks"])
        self.assertFalse(marker.exists())
        self.assertEqual(self.path.read_bytes(), before)
        self.assertTrue(report["runtime_inspection_requested"])

    def test_invalid_arguments_and_missing_file_are_safe_failures(self):
        self.blocked(self.run_check(arguments=["--runtime"]), "usage_template_or_absolute_env_file_with_optional_runtime")
        self.blocked(self.run_check(), "input_canonical_regular_file")


if __name__ == "__main__":
    if shutil.which(PHP) is None:
        raise SystemExit("PHP runtime unavailable; tests were not executed.")
    unittest.main()
