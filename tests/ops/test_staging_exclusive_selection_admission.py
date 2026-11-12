"""Test the Exclusive selection policy is an optional staging policy, admitted by the deploy gate exactly as the profile validator does.

Runs the real staging deploy gate (ops/staging/validate-runtime.php: the application's own configuration and the real
ExclusiveSelectionPolicy, no database and no provider request) against synthetic candidate files in a private temporary directory.
VASEY_TEST_EXCLUSIVE_SELECTION_POLICY passes when absent or empty (off), or when set to the one exact shape the policy class accepts;
any other value is refused under runtime.optional_vasey_test_exclusive_selection_policy. The operator profile validator applies the
same rule (tests/Unit/TestCommerceProfileValidatorTest.php). Production keeps it blank (scripts/ops/private-server-preflight.php)."""
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "ops/staging/validate-runtime.php"
PHP = os.environ.get("PHP_BIN") or shutil.which("php8.4") or shutil.which("php")
SENTINEL = "SyntheticPrivateRuntimeMarker7741"
HOST = "staging.synthetic.invalid"
KEY = "VASEY_TEST_EXCLUSIVE_SELECTION_POLICY"
REFUSAL = "runtime.optional_vasey_test_exclusive_selection_policy"
CONTRACT = {"schema_version": 1, "purpose": "test_exclusive_selection", "non_exclusive_cutoff": "block_while_reserved",
            "existing_pending": "retain_until_verified_resolution", "discounts": "explicit_revision_only"}


def policy(document=None):
    return "'" + json.dumps(CONTRACT if document is None else document, separators=(",", ":")) + "'"


def candidate(environment="staging", **overrides):
    values = {
        "APP_ENV": environment, "APP_DEBUG": "false", "APP_URL": f"https://{HOST}", "APP_KEY": "base64:" + "A" * 43 + "=",
        "APP_MAINTENANCE_DRIVER": "file", "SESSION_DRIVER": "database", "SESSION_SECURE_COOKIE": "true",
        "SESSION_HTTP_ONLY": "true", "CACHE_STORE": "database", "QUEUE_CONNECTION": "database", "DB_QUEUE_RETRY_AFTER": "1200",
        "DB_CONNECTION": "mysql", "DB_HOST": "127.0.0.1", "DB_DATABASE": "vasey_staging", "DB_USERNAME": "vasey_app",
        "DB_PASSWORD": SENTINEL, "FILESYSTEM_DISK": "local", "MAIL_MAILER": "log", "STRIPE_MODE": "test",
        "STRIPE_ACCOUNT_ID": "acct_StagingSyntheticOnly", "VASEY_PHP_CLI_BINARY": PHP and shutil.which(PHP) or "/usr/bin/php8.4",
    }
    values.update(overrides)
    return "\n".join(f"{key}={value}" for key, value in values.items() if value is not None) + "\n"


@unittest.skipUnless(PHP and (REPO / "vendor/autoload.php").exists(), "PHP and the Composer dependencies are required")
class ExclusiveSelectionIsOptionalOnStaging(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-staging-exclusive-")
        self.addCleanup(self.tmp.cleanup)
        self.file = Path(self.tmp.name) / "candidate.env"

    def validate(self, text, expected="staging"):
        self.file.write_text(text)
        self.file.chmod(0o600)
        run = subprocess.run([PHP, str(GATE), str(self.file), expected, HOST, "vasey_staging"], cwd=REPO,
                             env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")}, capture_output=True, text=True, timeout=120)
        combined = run.stdout + run.stderr
        self.assertNotIn(SENTINEL, combined)
        self.assertNotIn(self.tmp.name, combined)
        self.assertNotIn("block_while_reserved", combined)
        return run

    def passes(self, run):
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("PASS runtime.profile", run.stdout)
        self.assertEqual(run.stderr, "")

    def refused(self, run, *identifiers):
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertNotIn("PASS", run.stdout)
        self.assertEqual({line for line in run.stderr.splitlines() if line.startswith("FAIL ")},
                         {f"FAIL {identifier}" for identifier in identifiers}, run.stderr)

    def test_absent_or_empty_is_off_and_passes(self):
        self.passes(self.validate(candidate()))
        self.passes(self.validate(candidate(**{KEY: ""})))

    def test_the_exact_policy_passes_on_staging_and_local(self):
        self.passes(self.validate(candidate(**{KEY: policy()})))
        self.passes(self.validate(candidate(environment="local", **{KEY: policy()}), expected="local"))
        # Key order inside the JSON does not matter: the class decodes and compares each field.
        self.passes(self.validate(candidate(**{KEY: policy(dict(reversed(list(CONTRACT.items()))))})))

    def test_any_changed_policy_is_refused_by_its_own_identifier(self):
        changes = {
            "non-exclusive sales allowed while reserved": {**CONTRACT, "non_exclusive_cutoff": "allow"},
            "pending released early": {**CONTRACT, "existing_pending": "release_on_expiry"},
            "implicit discounts": {**CONTRACT, "discounts": "any"},
            "other purpose": {**CONTRACT, "purpose": "production_exclusive_selection"},
            "schema as string": {**CONTRACT, "schema_version": "1"},
            "extra key": {**CONTRACT, "reopen_after_refund": True},
            "missing key": {key: value for key, value in CONTRACT.items() if key != "discounts"},
        }
        for name, document in changes.items():
            with self.subTest(name=name):
                self.refused(self.validate(candidate(**{KEY: policy(document)})), REFUSAL)
        for raw in ("'not json'", "true", "'[]'", "0"):
            with self.subTest(raw=raw):
                self.refused(self.validate(candidate(**{KEY: raw})), REFUSAL)

    def test_the_held_flags_are_still_refused_beside_it(self):
        run = self.validate(candidate(**{KEY: policy(), "VASEY_TEST_REFUND_DISPUTE_ENABLED": "true"}))
        self.refused(run, "runtime.flag_off_vasey_test_refund_dispute_enabled")


if __name__ == "__main__":
    unittest.main()
