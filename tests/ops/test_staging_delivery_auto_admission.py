"""Automatic test delivery enabling is an optional staging capability (D-19 amendment, 2026-11-12, under Sean's delegation).

Runs the real staging deploy gate (ops/staging/validate-runtime.php: the application's own configuration and the real
TestDeliveryAutoEnablePolicy, no database and no provider request) against synthetic candidate files in a private temporary directory.
The flag passes only as exactly false (or absent), or as exactly true together with Stripe test mode, an `acct_` account and the
exact policy JSON. The production refusal is in tests/ops/test_production_runtime_validator.py."""
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
SENTINEL = "SyntheticPrivateRuntimeMarker5310"
HOST = "staging.synthetic.invalid"
FLAG = "VASEY_TEST_DELIVERY_AUTO_ENABLED"
POLICY = "VASEY_TEST_DELIVERY_AUTO_POLICY"
REFUSAL = "runtime.optional_vasey_test_delivery_auto_enabled"
CONTRACT = {"schema_version": 1, "purpose": "test_delivery_auto_enable", "version": "test-delivery-auto-enable-v1",
            "provider": "stripe", "mode": "test", "scope": "ready_orders_never_controlled", "lanes": "track_and_sound_kit",
            "holds": "skip_while_refund_dispute_held", "staff_decisions": "final", "actor": "system"}


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


def enabled(**overrides):
    return candidate(**{FLAG: "true", POLICY: policy(), **overrides})


@unittest.skipUnless(PHP and (REPO / "vendor/autoload.php").exists(), "PHP and the Composer dependencies are required")
class AutomaticDeliveryEnablingIsOptionalOnStaging(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-staging-auto-delivery-")
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
        self.assertNotIn("acct_StagingSyntheticOnly", combined)
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

    def test_off_absent_or_false_passes_as_before(self):
        self.passes(self.validate(candidate()))
        for value in ("false", "(false)", "FALSE"):
            with self.subTest(value=value):
                self.passes(self.validate(candidate(**{FLAG: value})))
        # A policy line alone turns nothing on.
        self.passes(self.validate(candidate(**{FLAG: "false", POLICY: policy()})))

    def test_on_with_the_exact_policy_in_stripe_test_mode_passes_on_staging_and_local(self):
        self.passes(self.validate(enabled()))
        self.passes(self.validate(enabled(**{FLAG: "(true)"})))
        self.passes(self.validate(enabled(environment="local"), expected="local"))
        # Key order inside the JSON does not matter: the policy compares canonical JSON.
        self.passes(self.validate(enabled(VASEY_TEST_DELIVERY_AUTO_POLICY=policy(dict(reversed(list(CONTRACT.items())))))))

    def test_on_without_the_policy_is_refused_by_its_own_identifier(self):
        self.refused(self.validate(enabled(VASEY_TEST_DELIVERY_AUTO_POLICY=None)), REFUSAL)
        self.refused(self.validate(enabled(VASEY_TEST_DELIVERY_AUTO_POLICY="")), REFUSAL)
        self.refused(self.validate(enabled(VASEY_TEST_DELIVERY_AUTO_POLICY="'not json'")), REFUSAL)

    def test_on_with_any_changed_policy_is_refused(self):
        changes = {
            "staff decisions overridable": {**CONTRACT, "staff_decisions": "overridable"},
            "holds ignored": {**CONTRACT, "holds": "ignore"},
            "live mode": {**CONTRACT, "mode": "live"},
            "tracks only": {**CONTRACT, "lanes": "track"},
            "other version": {**CONTRACT, "version": "test-delivery-auto-enable-v2"},
            "schema as string": {**CONTRACT, "schema_version": "1"},
            "extra key": {**CONTRACT, "reenable": "blocked"},
            "missing key": {key: value for key, value in CONTRACT.items() if key != "actor"},
        }
        for name, document in changes.items():
            with self.subTest(name=name):
                self.refused(self.validate(enabled(VASEY_TEST_DELIVERY_AUTO_POLICY=policy(document))), REFUSAL)

    def test_on_outside_stripe_test_mode_or_without_a_test_account_is_refused(self):
        self.refused(self.validate(enabled(STRIPE_ACCOUNT_ID=None)), REFUSAL)
        self.refused(self.validate(enabled(STRIPE_ACCOUNT_ID="acct_bad-shape")), REFUSAL)
        self.refused(self.validate(enabled(STRIPE_MODE="live")), REFUSAL, "runtime.stripe_mode")

    def test_any_other_spelling_is_refused(self):
        for value in ("1", "yes", "on", "", '"yes"', "TRUE_ISH", "0", "null"):
            with self.subTest(value=value):
                self.refused(self.validate(enabled(**{FLAG: value})), REFUSAL)

    def test_the_eleven_held_flags_are_still_refused_beside_it(self):
        run = self.validate(enabled(VASEY_TEST_REFUND_RESOLUTION_ENABLED="true"))
        self.refused(run, "runtime.flag_off_vasey_test_refund_resolution_enabled")


if __name__ == "__main__":
    unittest.main()
