"""ops/production/validate-runtime.php: the reviewed preflight baseline plus real configuration admission and production holds.

Runs the validator for real (PHP, the application's own configuration, no database and no provider request) against
synthetic environment files in a private temporary directory. It is the production counterpart of
tests/Unit/StagingRuntimeValidatorTest.php; it lives here, in the ops tests' style, so the PHPUnit census is not touched."""
import base64
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
VALIDATOR = REPO / "ops/production/validate-runtime.php"
PHP = os.environ.get("PHP_BIN") or shutil.which("php8.4") or shutil.which("php")
SENTINEL = "SyntheticPrivateRuntimeMarker1247"
HOST = "shop.synthetic.invalid"


def profile(**overrides):
    values = {
        "APP_NAME": '"VASEY.AUDIO"', "APP_ENV": "production", "APP_DEBUG": "false", "APP_URL": f"https://{HOST}",
        "APP_KEY": "base64:" + base64.b64encode(b"k" * 32).decode(), "APP_MAINTENANCE_DRIVER": "file",
        "DB_CONNECTION": "mysql", "DB_HOST": "127.0.0.1", "DB_PORT": "3306", "DB_DATABASE": "vasey_production",
        "DB_USERNAME": "vasey_app", "DB_PASSWORD": SENTINEL,
        "SESSION_DRIVER": "database", "SESSION_LIFETIME": "120", "SESSION_ENCRYPT": "true", "SESSION_DOMAIN": "null",
        "SESSION_SECURE_COOKIE": "true", "SESSION_HTTP_ONLY": "true", "SESSION_SAME_SITE": "lax",
        "CACHE_STORE": "database", "QUEUE_CONNECTION": "database", "DB_QUEUE_RETRY_AFTER": "1200",
        "FILESYSTEM_DISK": "local", "MEDIA_FFMPEG": "/usr/bin/ffmpeg", "MEDIA_FFPROBE": "/usr/bin/ffprobe",
        "MEDIA_PRLIMIT": "/usr/bin/prlimit", "MEDIA_CLAMSCAN": "/usr/bin/clamscan", "MEDIA_TAG_PATH": "branding/tag.wav",
        "MEDIA_TAG_SHA256": "a" * 64, "VASEY_PHP_CLI_BINARY": PHP and shutil.which(PHP) or "/usr/bin/php8.4",
        "LOG_CHANNEL": "stderr", "LOG_LEVEL": "info", "MAIL_MAILER": "log", "STRIPE_MODE": "test",
        "CONTACT_INQUIRIES_ENABLED": "false",
    }
    for switch in ("STRIPE_WEBHOOK_ENABLED", "STRIPE_TEST_CHECKOUT_ENABLED", "STRIPE_TEST_PAYMENT_PROCESSING_ENABLED",
                   "STRIPE_TEST_FINALIZATION_ENABLED", "VASEY_TEST_CONTRACT_ISSUANCE_ENABLED",
                   "VASEY_TEST_FULFILLMENT_ACTIVATION_ENABLED", "VASEY_TEST_DELIVERY_ACCESS_ENABLED"):
        values[switch] = "false"
    values.update(overrides)
    return "\n".join(f"{key}={value}" for key, value in values.items() if value is not None) + "\n"


@unittest.skipUnless(PHP and (REPO / "vendor/autoload.php").exists(), "PHP and the Composer dependencies are required")
class ProductionRuntimeValidator(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-prod-validate-")
        self.addCleanup(self.tmp.cleanup)
        self.dir = Path(self.tmp.name)
        self.file = self.dir / "candidate.env"

    def validate(self, text, previous=None, expected="production", host=HOST, database="vasey_production", mode=0o600, extra=()):
        if self.file.exists():
            self.file.unlink()
        self.file.write_text(text)
        self.file.chmod(mode)
        arguments = [PHP, str(VALIDATOR), str(self.file), expected, host, database]
        if previous is not None:
            old = self.dir / "previous.env"
            old.write_text(previous)
            arguments.append(str(old))
        arguments.extend(extra)
        run = subprocess.run(arguments, cwd=REPO, env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")},
                             capture_output=True, text=True, timeout=120)
        combined = run.stdout + run.stderr
        self.assertNotIn(SENTINEL, combined)
        self.assertNotIn(str(self.file), combined)
        self.assertNotIn(str(self.dir), combined)
        return run

    def failed(self, run, *ids):
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        reported = set(run.stderr.split())
        for identifier in ids:
            self.assertIn(identifier, reported, run.stderr)

    # ---- acceptance ----

    def test_a_valid_production_profile_passes_with_key_custody(self):
        good = profile()
        run = self.validate(good, previous=good)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("PASS runtime.profile (production", run.stdout)
        self.assertEqual(run.stderr, "")

    def test_it_makes_no_changes_and_leaves_no_scratch_directory(self):
        before = set(Path(tempfile.gettempdir()).glob("vasey-runtime-*"))
        text = profile()
        self.validate(text, previous=text)
        self.assertEqual(self.file.read_text(), text)
        self.assertEqual(oct(self.file.stat().st_mode & 0o777), "0o600")
        self.assertEqual(set(Path(tempfile.gettempdir()).glob("vasey-runtime-*")), before)

    # ---- the reviewed preflight baseline is reused, not restated ----

    def test_a_staging_profile_is_refused_with_the_preflights_own_identifiers(self):
        staging = profile(APP_ENV="staging", STRIPE_WEBHOOK_ENABLED="true", STRIPE_TEST_CHECKOUT_ENABLED="true",
                          STRIPE_ACCOUNT_ID="acct_SYNTHETICONLY", STRIPE_TEST_SECRET_KEY="sk_test_SYNTHETICONLY1234")
        run = self.validate(staging)
        self.failed(run, "preflight.result", "preflight.baseline_app_env", "preflight.baseline_stripe_webhook_enabled",
                    "preflight.baseline_stripe_test_checkout_enabled", "preflight.inactive_stripe_test_secret_key")

    def test_the_expected_environment_must_be_production(self):
        for expected in ("staging", "local", "testing", ""):
            with self.subTest(expected=expected):
                run = self.validate(profile(), expected=expected)
                self.assertNotEqual(run.returncode, 0)
                self.assertIn("runtime.arguments_shape", run.stderr)

    def test_baseline_violations_come_back_as_preflight_identifiers(self):
        for override, identifier in (
            ({"APP_DEBUG": "true"}, "preflight.baseline_app_debug"),
            ({"SESSION_SECURE_COOKIE": "false"}, "preflight.baseline_session_secure_cookie"),
            ({"LOG_CHANNEL": "stack"}, "preflight.baseline_log_channel"),
            ({"CACHE_STORE": "file"}, "preflight.baseline_cache_store"),
            ({"DB_QUEUE_RETRY_AFTER": "900"}, "preflight.queue_retry_exceeds_media_timeout_and_lease"),
            ({"DB_USERNAME": "root"}, "preflight.database_nonroot_identity_named"),
            ({"DB_URL": "mysql://u:p@h/db"}, "preflight.no_unreviewed_db_url"),
            ({"APP_KEY": "short"}, "preflight.application_key_shape_only"),
            ({"MEDIA_CLAMSCAN": "clamscan"}, "preflight.absolute_media_clamscan"),
            ({"MEDIA_TAG_SHA256": "nope"}, "preflight.media_tag_hash_shape_only"),
            ({"CONTACT_INQUIRIES_ENABLED": "true"}, "preflight.baseline_contact_inquiries_enabled"),
            ({"VASEY_TEST_PRICING_POLICY": "'{}'"}, "preflight.inactive_vasey_test_pricing_policy"),
        ):
            with self.subTest(identifier=identifier):
                self.failed(self.validate(profile(**override)), "preflight.result", identifier)

    def test_mail_and_stripe_mode_are_inherited_from_the_preflight_today(self):
        # The kit itself forces neither; the reviewed production profile still does. Relaxing them is a preflight change.
        self.failed(self.validate(profile(MAIL_MAILER="smtp")), "preflight.baseline_mail_mailer")
        self.failed(self.validate(profile(STRIPE_MODE="live")), "preflight.baseline_stripe_mode")

    def test_file_custody_is_the_preflights(self):
        self.failed(self.validate(profile(), mode=0o644), "preflight.input_permissions")
        self.failed(self.validate(profile(), mode=0o666), "preflight.input_permissions")

    # ---- real configuration admission ----

    def test_effective_configuration_must_match_the_host_and_database(self):
        self.failed(self.validate(profile(APP_URL="https://other.synthetic.invalid")), "runtime.origin")
        self.failed(self.validate(profile(), host="elsewhere.synthetic.invalid"), "runtime.origin")
        self.failed(self.validate(profile(), database="vasey_staging"), "runtime.database")
        self.failed(self.validate(profile(DB_HOST="db.synthetic.invalid")), "runtime.database")
        self.failed(self.validate(profile(DB_USERNAME="somebody_else")), "runtime.database")
        self.failed(self.validate(profile(DB_SOCKET="/var/run/mysqld/mysqld.sock")), "runtime.database")

    def test_the_php_cli_binary_is_probed(self):
        run = self.validate(profile(VASEY_PHP_CLI_BINARY="/nonexistent/php"))
        self.failed(run, "runtime.php_cli_binary")

    def test_key_custody_requires_the_served_key(self):
        other = profile(APP_KEY="base64:" + base64.b64encode(b"z" * 32).decode())
        self.failed(self.validate(profile(), previous=other), "runtime.key_custody")
        self.failed(self.validate(profile(), previous=profile(APP_KEY=None)), "runtime.key_custody")

    def test_duplicate_keys_are_refused(self):
        self.failed(self.validate(profile() + "APP_DEBUG=true\n"), "runtime.unique_keys")

    # ---- production holds ----

    def test_production_checkout_switches_stay_off(self):
        for assignment in ('PRODUCTION_CHECKOUT_ENABLED=true', 'PRODUCTION_CHECKOUT_ENABLED="true"', "PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED='true'",
                           'PRODUCTION_CHECKOUT_HTTP_ENABLED=(true)', 'PRODUCTION_CHECKOUT_RECONCILIATION_ENABLED=1',
                           'PRODUCTION_CHECKOUT_COMMITTED_READ_RECEIPTS_ENABLED=yes', "BAD=true\nPRODUCTION_CHECKOUT_EXEMPTION_AUTHORING_ENABLED=\"${BAD}\""):
            with self.subTest(assignment=assignment):
                run = self.validate(profile() + assignment + "\n")
                self.assertEqual(run.returncode, 1, run.stderr)
                self.assertTrue(any(line.startswith("FAIL holds.production_checkout") or line.startswith("FAIL holds.") for line in run.stderr.splitlines()), run.stderr)

    def test_the_documented_off_values_are_accepted_and_a_blank_assignment_is_not(self):
        for value in ("false", "(false)"):
            with self.subTest(value=value):
                run = self.validate(profile() + f"PRODUCTION_CHECKOUT_ENABLED={value}\nPRODUCTION_CHECKOUT_HTTP_ENABLED=false\n")
                self.assertEqual(run.returncode, 0, run.stderr)
        # `KEY=` is not the same as an unset key: the application's default does not apply to it.
        self.failed(self.validate(profile() + "PRODUCTION_CHECKOUT_ENABLED=\n"), "holds.production_checkout_enabled")

    def test_no_live_stripe_credential_anywhere_and_no_production_funds(self):
        live = "sk_" + "live_SYNTHETICONLY1234"
        restricted = "rk_" + "live_SYNTHETICONLY1234"
        self.failed(self.validate(profile() + f"PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY={live}\n"), "holds.no_live_provider_credential")
        self.failed(self.validate(profile() + f"MEMBERSHIP_BILLING_STRIPE_SECRET_KEY={restricted}\n"), "holds.no_live_provider_credential")
        self.failed(self.validate(profile(MAIL_PASSWORD=live)), "holds.no_live_provider_credential")
        for funds in ("test", "live"):
            run = self.validate(profile() + f"PRODUCTION_CHECKOUT_FUNDS_MODE={funds}\n")
            self.failed(run, "holds.production_credentials")

    # ---- host checks and argument handling ----

    def test_runtime_flag_is_passed_to_the_preflight(self):
        # In a checkout the private root is not an attached 0700 directory, so the host checks must refuse here.
        run = self.validate(profile(), extra=("--runtime",))
        self.assertEqual(run.returncode, 1, run.stdout)
        self.assertTrue(any(line.startswith("FAIL preflight.runtime_") for line in run.stderr.splitlines()), run.stderr)
        self.assertNotIn("PASS", run.stdout)

    def test_bad_arguments_are_refused(self):
        for arguments in ([], [str(self.file)], [str(self.file), "production", HOST]):
            run = subprocess.run([PHP, str(VALIDATOR), *arguments], cwd=REPO, env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")},
                                 capture_output=True, text=True)
            self.assertEqual(run.returncode, 2)
        self.file.write_text(profile())
        self.file.chmod(0o600)
        relative = subprocess.run([PHP, str(VALIDATOR), "candidate.env", "production", HOST, "vasey_production"], cwd=self.dir,
                                  env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")}, capture_output=True, text=True)
        self.assertEqual(relative.returncode, 1)

    def test_symlinked_or_missing_files_are_refused(self):
        self.file.write_text(profile())
        self.file.chmod(0o600)
        link = self.dir / "link.env"
        link.symlink_to(self.file)
        for path in (link, self.dir / "missing.env"):
            run = subprocess.run([PHP, str(VALIDATOR), str(path), "production", HOST, "vasey_production"], cwd=REPO,
                                 env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")}, capture_output=True, text=True)
            self.assertEqual(run.returncode, 1)
            self.assertIn("runtime.unavailable", run.stderr)

    def test_php_syntax(self):
        lint = subprocess.run([PHP, "-l", str(VALIDATOR)], capture_output=True, text=True)
        self.assertEqual(lint.returncode, 0, lint.stderr)


if __name__ == "__main__":
    unittest.main()
