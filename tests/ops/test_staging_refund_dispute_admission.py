"""The test refund and dispute lifecycle is an optional staging capability (Sean's decision, 2026-11-12).

Runs the real staging deploy gate (ops/staging/validate-runtime.php: the application's own configuration and the real
RefundDisputePolicy, no database and no provider request) against synthetic candidate files in a private temporary directory.
VASEY_TEST_REFUND_DISPUTE_ENABLED passes only as exactly false (or absent), or as exactly true together with Stripe test mode, an
`acct_` account and the four policy values each equal to its DD-16 choice (RefundDisputePolicy::PRODUCTION_CONFIRMED). The policy
class also accepts `operator_review` and `remain_sold`; the staging gate does not, so the rehearsal runs the decided policy. The
production refusal is in tests/ops/test_production_runtime_validator.py; the operator profile validator applies the same rule
(tests/Unit/TestCommerceProfileValidatorTest.php)."""
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "ops/staging/validate-runtime.php"
PHP = os.environ.get("PHP_BIN") or shutil.which("php8.4") or shutil.which("php")
SENTINEL = "SyntheticPrivateRuntimeMarker6624"
HOST = "staging.synthetic.invalid"
FLAG = "VASEY_TEST_REFUND_DISPUTE_ENABLED"
REFUSAL = "runtime.optional_vasey_test_refund_dispute_enabled"
DD16 = {
    "VASEY_TEST_REFUND_DISPUTE_REFUND_AUTHORITY": "authoritative_provider_operator_review",
    "VASEY_TEST_REFUND_DISPUTE_PARTIAL_REFUNDS": "exact_line_allocation",
    "VASEY_TEST_REFUND_DISPUTE_DISPUTE_ACCESS": "temporarily_block_while_reconciling",
    "VASEY_TEST_REFUND_DISPUTE_EXCLUSIVE_REOPENING": "qualified_operator_review",
}


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
    return candidate(**{FLAG: "true", **DD16, **overrides})


@unittest.skipUnless(PHP and (REPO / "vendor/autoload.php").exists(), "PHP and the Composer dependencies are required")
class RefundDisputeIsOptionalOnStaging(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-staging-refund-")
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
        self.assertNotIn("temporarily_block_while_reconciling", combined)
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
        # Policy values alone turn nothing on, whatever they say.
        self.passes(self.validate(candidate(**{FLAG: "false", **DD16})))
        self.passes(self.validate(candidate(**{FLAG: "false", "VASEY_TEST_REFUND_DISPUTE_DISPUTE_ACCESS": "operator_review"})))

    def test_on_with_the_dd16_values_in_stripe_test_mode_passes_on_staging_and_local(self):
        self.passes(self.validate(enabled()))
        self.passes(self.validate(enabled(**{FLAG: "(true)"})))
        self.passes(self.validate(enabled(environment="local"), expected="local"))

    def test_on_with_any_value_missing_or_blank_is_refused_by_its_own_identifier(self):
        self.refused(self.validate(candidate(**{FLAG: "true"})), REFUSAL)
        for key in DD16:
            with self.subTest(missing=key):
                self.refused(self.validate(enabled(**{key: None})), REFUSAL)
            with self.subTest(blank=key):
                self.refused(self.validate(enabled(**{key: ""})), REFUSAL)

    def test_on_with_a_malformed_or_other_value_is_refused(self):
        for key, value in DD16.items():
            for other in ("refund_everything", value.upper(), f'"{value} "', "null", "true"):
                with self.subTest(key=key, value=other):
                    self.refused(self.validate(enabled(**{key: other})), REFUSAL)

    def test_on_with_a_value_the_class_supports_but_dd16_did_not_choose_is_refused(self):
        # RefundDisputePolicy::SUPPORTED admits these on the test chain; the staging rehearsal runs DD-16 only.
        self.refused(self.validate(enabled(VASEY_TEST_REFUND_DISPUTE_DISPUTE_ACCESS="operator_review")), REFUSAL)
        self.refused(self.validate(enabled(VASEY_TEST_REFUND_DISPUTE_EXCLUSIVE_REOPENING="remain_sold")), REFUSAL)

    def test_on_outside_stripe_test_mode_or_without_a_test_account_is_refused(self):
        self.refused(self.validate(enabled(STRIPE_ACCOUNT_ID=None)), REFUSAL)
        self.refused(self.validate(enabled(STRIPE_ACCOUNT_ID="acct_bad-shape")), REFUSAL)
        self.refused(self.validate(enabled(STRIPE_MODE="live")), REFUSAL, "runtime.stripe_mode")

    def test_any_other_spelling_of_the_flag_is_refused(self):
        for value in ("1", "yes", "on", "", '"yes"', "TRUE_ISH", "0", "null"):
            with self.subTest(value=value):
                self.refused(self.validate(enabled(**{FLAG: value})), REFUSAL)

    def test_the_held_flags_are_still_refused_beside_it(self):
        # In particular refund resolution, a separate capability, stays held false.
        run = self.validate(enabled(VASEY_TEST_REFUND_RESOLUTION_ENABLED="true"))
        self.refused(run, "runtime.flag_off_vasey_test_refund_resolution_enabled")

    def test_it_and_unpaid_release_are_refused_independently(self):
        run = self.validate(enabled(VASEY_TEST_UNPAID_RELEASE_ENABLED="true"))
        self.refused(run, "runtime.optional_vasey_test_unpaid_release_enabled")
        run = self.validate(enabled(**{"VASEY_TEST_REFUND_DISPUTE_PARTIAL_REFUNDS": "", "VASEY_TEST_UNPAID_RELEASE_ENABLED": "true"}))
        self.refused(run, REFUSAL, "runtime.optional_vasey_test_unpaid_release_enabled")


if __name__ == "__main__":
    unittest.main()
