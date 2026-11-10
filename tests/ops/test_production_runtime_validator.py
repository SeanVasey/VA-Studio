"""ops/production/validate-runtime.php: the reviewed preflight baseline plus real configuration admission and production holds.

Runs the validator for real (PHP, the application's own configuration, no database and no provider request) against
synthetic environment files in a private temporary directory. It is the production counterpart of
tests/Unit/StagingRuntimeValidatorTest.php; it lives here, in the ops tests' style, so the PHPUnit census is not touched."""
import base64
import json
import os
import re
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
# Pre-launch hold (Sean, 2026-10-18): environment key => config key. The validator names the keys; the boundary supplies the config keys.
PRE_LAUNCH_HOLDS = {
    "VASEY_OPERATIVE_FREE_GRANTS_ENABLED": "free-grants.operative_enabled",
    "CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED": "inquiries.operator_notifications_enabled",
    "DISCOVERY_SITEMAP_ENABLED": "discovery-sitemap.enabled",
    "VASEY_LISTENING_V2_PROMOTION_ENABLED": "customer-listening.v2_promotion_enabled",
}
# Off through launch (decision register, 2026-11-10). DD-44: the marketing segment flag, its config key from the boundary.
MARKETING_SEGMENT = "REPORTING_MARKETING_SEGMENT_ENABLED"
MARKETING_SEGMENT_KEY = "reporting.customer_segments.marketing_segment_enabled"
# BL-71: the staging-only BeatStars catalog import flag is held off in production by the same rule.
BEATSTARS_IMPORT = "VASEY_STAGING_BEATSTARS_IMPORT_ENABLED"
BEATSTARS_IMPORT_KEY = "staging-beatstars-import.enabled"
# DD-50: code literals, refusal identifier => config key.
MEMBERSHIP_LITERALS = {
    "holds.production_memberships_enabled": "production-memberships.enabled",
    "holds.production_memberships_http_mounted": "production-memberships.http_mounted",
    "holds.member_grants_enabled": "member-grants.enabled",
}


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

    def test_mail_and_stripe_mode_are_inherited_from_the_preflight_without_the_admission_flags(self):
        # The defaults are unchanged: without an exact `true` flag the preflight still requires `log` and `test`.
        for flags in ({}, {"VASEY_PRODUCTION_REAL_MAIL_ADMITTED": "false", "VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED": "false"}):
            with self.subTest(flags=flags):
                self.assertEqual(self.validate(profile(**flags)).returncode, 0)
                self.failed(self.validate(profile(MAIL_MAILER="smtp", **flags)), "preflight.baseline_mail_mailer")
                self.failed(self.validate(profile(STRIPE_MODE="live", **flags)), "preflight.baseline_stripe_mode")

    # ---- admissions (lane PCC) ----

    REAL_MAIL = {"VASEY_PRODUCTION_REAL_MAIL_ADMITTED": "true", "MAIL_MAILER": "smtp", "MAIL_SCHEME": "smtps",
                 "MAIL_HOST": "smtp.synthetic-mail-provider.com", "MAIL_PORT": "465", "MAIL_USERNAME": "synthetic-user",
                 "MAIL_PASSWORD": SENTINEL, "MAIL_FROM_ADDRESS": "support@vasey.audio"}
    LIVE = {"VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED": "true", "STRIPE_MODE": "live"}
    LIVE_KEY = "sk_" + "live_SYNTHETICONLY1234"

    def test_the_real_mail_flag_admits_an_implicit_tls_transport_and_nothing_else(self):
        run = self.validate(profile(**self.REAL_MAIL))
        self.assertEqual(run.returncode, 0, run.stderr)
        self.failed(self.validate(profile(**{**self.REAL_MAIL, "MAIL_SCHEME": "smtp"})), "preflight.production_mail_implicit_tls")
        self.failed(self.validate(profile(**{**self.REAL_MAIL, "STRIPE_MODE": "live"})), "preflight.baseline_stripe_mode")
        self.failed(self.validate(profile(**self.REAL_MAIL) + f"PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY={self.LIVE_KEY}\n"),
                    "holds.no_live_provider_credential")

    def test_the_live_flag_admits_a_live_secret_key_in_its_one_place_only(self):
        self.assertEqual(self.validate(profile(**self.LIVE)).returncode, 0)
        run = self.validate(profile(**self.LIVE) + f"PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY={self.LIVE_KEY}\nPRODUCTION_CHECKOUT_FUNDS_MODE=live\n")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertNotIn(self.LIVE_KEY, run.stdout + run.stderr)
        for extra, identifier in (
            (f"MEMBERSHIP_BILLING_STRIPE_SECRET_KEY={self.LIVE_KEY}\n", "holds.no_live_provider_credential"),
            ("PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY=rk_" + "live_SYNTHETICONLY1234\n", "holds.no_live_provider_credential"),
            ("PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY=pk_" + "live_SYNTHETICONLY1234\n", "holds.no_live_provider_credential"),
            (f"PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY={self.LIVE_KEY}!\n", "holds.no_live_provider_credential"),
            ("PRODUCTION_CHECKOUT_FUNDS_MODE=test\n", "holds.production_credentials"),
            ("PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY=sk_" + "test_SYNTHETICONLY1234\n", "holds.production_credentials"),
        ):
            with self.subTest(identifier=identifier, extra=extra.split("=")[0]):
                self.failed(self.validate(profile(**self.LIVE) + extra), identifier)
        self.failed(self.validate(profile(**{**self.LIVE, "MAIL_PASSWORD": self.LIVE_KEY})), "holds.no_live_provider_credential")

    def test_live_admission_never_lifts_a_production_checkout_switch_or_a_pre_launch_hold(self):
        base = profile(**self.LIVE) + f"PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY={self.LIVE_KEY}\nPRODUCTION_CHECKOUT_FUNDS_MODE=live\n"
        for switch in ("PRODUCTION_CHECKOUT_ENABLED", "PRODUCTION_CHECKOUT_HTTP_ENABLED", "PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED",
                       "PRODUCTION_CHECKOUT_RECONCILIATION_ENABLED"):
            with self.subTest(switch=switch):
                self.failed(self.validate(base + f"{switch}=true\n"), "holds." + switch.lower())
        for key in PRE_LAUNCH_HOLDS:
            with self.subTest(key=key):
                self.failed(self.validate(base + f"{key}=true\n"), "holds." + key.lower())

    def test_identity_smtp_values_need_the_real_mail_flag_and_settings_need_it_too(self):
        self.failed(self.validate(profile(PRODUCTION_IDENTITY_SMTP_PASSWORD=SENTINEL)), "preflight.inactive_production_identity_smtp_password")
        self.assertEqual(self.validate(profile(**self.REAL_MAIL, PRODUCTION_IDENTITY_SMTP_PASSWORD=SENTINEL)).returncode, 0)
        # Composed settings exist only once the code literal is flipped; the hold then still needs the real mail flag.
        self.assertIn("$check('holds.production_identity_smtp', $realMail || config('production-identity-smtp.settings') === null);",
                      VALIDATOR.read_text())

    def test_a_malformed_flag_admits_nothing(self):
        for value in ("TRUE", "1", "yes", "", "(true)"):
            with self.subTest(value=value):
                run = self.validate(profile(**{**self.LIVE, "VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED": value})
                                    + f"PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY={self.LIVE_KEY}\n")
                self.failed(run, "holds.no_live_provider_credential")
                run = self.validate(profile(**{**self.LIVE, "VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED": value}))
                self.failed(run, "preflight.production_admission_boolean_vasey_production_live_stripe_admitted", "preflight.baseline_stripe_mode")

    def test_the_effective_admissions_must_match_the_file(self):
        # `${PATH}` resolves to the file's own PATH in the file checks (PATH must come first) but to the process PATH in the real
        # bootstrap, so only the configuration checks see the difference.
        run = self.validate(profile(**{"PATH": "true", **self.LIVE, "VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED": "${PATH}"}))
        self.assertEqual(run.stderr.split(), ["FAIL", "runtime.production_admissions"], run.stderr)

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

    def test_proxy_trust_is_an_explicit_bounded_list_or_none(self):
        for value in ("", "10.0.0.5", "173.245.48.0/20,2400:cb00::/32"):
            with self.subTest(value=value):
                self.assertEqual(self.validate(profile(TRUSTED_PROXIES=value)).returncode, 0)
        for value in ("*", "**", "REMOTE_ADDR", "0.0.0.0/0", "10.0.0.5,*", "proxy.synthetic.invalid"):
            with self.subTest(value=value):
                run = self.validate(profile(TRUSTED_PROXIES=f"'{value}'"))
                self.assertEqual(run.stderr.split(), ["FAIL", "runtime.trusted_proxies"], run.stderr)

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

    def test_the_operator_reconciliation_switch_and_the_composition_literal_are_held(self):
        # Lane PCC: the operator command's switch is held like every other checkout switch, and the code-literal mount is held in
        # the effective configuration, so lifting either is a reviewed change to this file.
        self.failed(self.validate(profile() + "PRODUCTION_CHECKOUT_OPERATOR_RECONCILIATION_ENABLED=true\n"),
                    "holds.production_checkout_operator_reconciliation_enabled")
        self.assertEqual(self.validate(profile() + "PRODUCTION_CHECKOUT_OPERATOR_RECONCILIATION_ENABLED=false\n").returncode, 0)
        text = VALIDATOR.read_text()
        self.assertIn("'committed_read_receipts_enabled', 'operator_reconciliation_enabled'] as $flag", text)
        self.assertIn("$check('holds.production_checkout_mounted', config('production_checkout.mounted') === false);", text)

    def test_both_contract_identity_literals_are_held(self):
        # D-30: each checkout's contract identity capture is a code literal; lifting either is a reviewed change to this file.
        text = VALIDATOR.read_text()
        self.assertIn("$check('holds.production_checkout_contract_identity_v2', config('production_checkout.contract_identity_v2') === false);", text)
        self.assertIn("$check('holds.production_tax_checkout_contract_identity_v2', "
                      "config('production-tax-checkout.contract_identity_v2') === false);", text)
        self.assertEqual(self.validate(profile()).returncode, 0)

    def test_the_v2_license_issuance_literal_is_held(self):
        # Contract increment 5: selecting the v2 license renderer for new paid originals is a code literal, held false here.
        text = VALIDATOR.read_text()
        self.assertIn("$check('holds.vasey_license_v2_issuance', config('contracts.vasey_license_v2_issuance') === false);", text)
        self.assertEqual(self.validate(profile()).returncode, 0)

    def test_the_v2_license_issuance_literal_flipped_to_true_fails_the_validator(self):
        # A shadow project (symlinks to this checkout, a copied validator, bootstrap and config) whose only change is the
        # literal: the validator run from it must refuse, so lifting the gate is a reviewed change to this hold.
        shadow = self.dir / "shadow"
        shadow.mkdir()
        for name in ("app", "vendor", "scripts", "routes", "resources", "database", "public", "storage", "composer.json", "artisan"):
            (shadow / name).symlink_to(REPO / name)
        shutil.copytree(REPO / "bootstrap", shadow / "bootstrap")
        shutil.copytree(REPO / "config", shadow / "config")
        (shadow / "ops/production").mkdir(parents=True)
        shutil.copy2(VALIDATOR, shadow / "ops/production/validate-runtime.php")
        contracts = shadow / "config/contracts.php"
        source = contracts.read_text()
        self.assertEqual(source.count("'vasey_license_v2_issuance' => false,"), 1)

        def run():
            self.file.write_text(profile())
            self.file.chmod(0o600)
            return subprocess.run([PHP, str(shadow / "ops/production/validate-runtime.php"), str(self.file), "production", HOST, "vasey_production"],
                                  cwd=shadow, env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")}, capture_output=True, text=True, timeout=120)

        self.assertEqual(run().returncode, 0)
        contracts.write_text(source.replace("'vasey_license_v2_issuance' => false,", "'vasey_license_v2_issuance' => true,"))
        self.failed(run(), "holds.vasey_license_v2_issuance")

    def test_the_operator_alert_mail_production_literal_is_held(self):
        # FP-013: the alert mail transport refuses production in code unless this literal is true; it sits beside the flag hold.
        text = VALIDATOR.read_text()
        self.assertIn("$check('holds.operator_alert_mail_production_admitted', "
                      "config('inquiries.operator_alert_mail_production_admitted') === false);", text)
        self.assertRegex(text, r"foreach \(\$holdKeys as \$name => \$key\) \{\s*\$check\('holds\.'\.strtolower\(\$name\), config\(\$key\) === false\);\s*\}"
                               r"\s*(?://[^\n]*\n\s*)+\$check\('holds\.operator_alert_mail_production_admitted'")
        self.assertEqual(self.validate(profile()).returncode, 0)

    def test_the_operator_alert_mail_production_literal_flipped_fails_the_validator(self):
        # A shadow project whose only change is the literal (true, or a truthy non-boolean): the validator run from it refuses.
        shadow = self.dir / "shadow"
        shadow.mkdir()
        for name in ("app", "vendor", "scripts", "routes", "resources", "database", "public", "storage", "composer.json", "artisan"):
            (shadow / name).symlink_to(REPO / name)
        shutil.copytree(REPO / "bootstrap", shadow / "bootstrap")
        shutil.copytree(REPO / "config", shadow / "config")
        (shadow / "ops/production").mkdir(parents=True)
        shutil.copy2(VALIDATOR, shadow / "ops/production/validate-runtime.php")
        inquiries = shadow / "config/inquiries.php"
        source = inquiries.read_text()
        literal = "'operator_alert_mail_production_admitted' => false,"
        self.assertEqual(source.count(literal), 1)

        def run():
            self.file.write_text(profile())
            self.file.chmod(0o600)
            return subprocess.run([PHP, str(shadow / "ops/production/validate-runtime.php"), str(self.file), "production", HOST, "vasey_production"],
                                  cwd=shadow, env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")}, capture_output=True, text=True, timeout=120)

        self.assertEqual(run().returncode, 0)
        for value in ("true", "1", "'false'", "null"):
            with self.subTest(value=value):
                inquiries.write_text(source.replace(literal, f"'operator_alert_mail_production_admitted' => {value},"))
                result = run()
                self.failed(result, "holds.operator_alert_mail_production_admitted")
                self.assertEqual({line for line in result.stderr.splitlines() if line.startswith("FAIL ")},
                                 {"FAIL holds.operator_alert_mail_production_admitted"}, result.stderr)

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

    # ---- pre-launch capability hold ----

    def test_the_four_pre_launch_flags_pass_as_false_or_unset(self):
        # The template-like profile() sets none of them: unset is allowed, the configuration default is false.
        self.assertEqual(self.validate(profile()).returncode, 0)
        every = "".join(f"{key}=false\n" for key in PRE_LAUNCH_HOLDS)
        run = self.validate(profile() + every)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(run.stderr, "")

    def test_the_documented_off_values_are_accepted_for_the_pre_launch_flags(self):
        for value in ("false", "(false)", "FALSE", "False", '"false"', "'false'"):
            for key in PRE_LAUNCH_HOLDS:
                with self.subTest(key=key, value=value):
                    run = self.validate(profile() + f"{key}={value}\n")
                    self.assertEqual(run.returncode, 0, run.stderr)

    def test_each_pre_launch_flag_is_refused_unless_false(self):
        for key in PRE_LAUNCH_HOLDS:
            # A blank assignment is not an unset key (the application's default does not apply to it); `${BAD}` interpolates to true.
            for value in ("true", "1", "yes", "", "(true)", "TRUE", '"true"', "on", "0", "null", "${BAD}"):
                with self.subTest(key=key, value=value):
                    text = profile() + ("BAD=true\n" if "BAD" in value else "") + f"{key}={value}\n"
                    run = self.validate(text)
                    self.failed(run, "holds." + key.lower())
                    # Only fixed identifiers come back: never the flag's value, and no other flag is blamed.
                    self.assertEqual({line for line in run.stderr.splitlines() if line.startswith("FAIL ")},
                                     {"FAIL holds." + key.lower()}, run.stderr)

    def test_a_value_only_flag_with_no_equals_sign_is_refused(self):
        self.failed(self.validate(profile() + "DISCOVERY_SITEMAP_ENABLED\n"), "holds.discovery_sitemap_enabled")

    def test_the_hold_is_checked_before_the_preflight_child_runs(self):
        # With a file the preflight would also refuse, the file-level hold is still the identifier reported, and the
        # preflight's own identifiers are absent because the child never ran.
        run = self.validate(profile(APP_DEBUG="true", VASEY_LISTENING_V2_PROMOTION_ENABLED="true"))
        self.failed(run, "holds.vasey_listening_v2_promotion_enabled")
        self.assertNotIn("preflight.", run.stderr)

    def test_the_hold_keys_are_the_shared_capability_boundary_entries(self):
        # The validator names only the four environment keys; each config key comes from scripts/ops/capability-boundary.php,
        # the definition the staging deploy gate and the profile validator also read, so there is no second copy to drift.
        text = VALIDATOR.read_text()
        block = re.search(r"const PRE_LAUNCH_HOLDS = \[(.*?)\n\];", text, re.S)
        self.assertIsNotNone(block, "PRE_LAUNCH_HOLDS constant block not found")
        self.assertNotIn("=>", block.group(1), "the hold list must name environment keys only, not restate config keys")
        names = re.findall(r"'([A-Z0-9_]+)',", block.group(1))
        self.assertEqual(names, list(PRE_LAUNCH_HOLDS))
        # The boundary is read by running PHP: it must hold every named key, with the config key the hold is expected to read.
        script = ("require $argv[1]; echo json_encode(array_intersect_key(Vasey\\Ops\\CapabilityBoundary::stagedFlags(), "
                  "array_flip(array_slice($argv, 2))));")
        run = subprocess.run([PHP, "-r", script, str(REPO / "scripts/ops/capability-boundary.php"), *PRE_LAUNCH_HOLDS],
                             capture_output=True, text=True, timeout=60)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(json.loads(run.stdout), PRE_LAUNCH_HOLDS)

    def test_the_consumer_reads_the_boundary_and_fails_closed_without_it(self):
        # Pin the consumer side: the boundary file is required (a missing or linked file is runtime.unavailable, never a skip),
        # the map is derived from CapabilityBoundary::stagedFlags(), a key the boundary lost throws, and no config key is restated.
        text = VALIDATOR.read_text()
        self.assertRegex(text, r"\$boundary = \$root\.'/scripts/ops/capability-boundary\.php';\s*if \(! is_file\(\$boundary\) \|\| is_link\(\$boundary\)\) \{\s*throw new RuntimeException;\s*\}\s*require_once \$boundary;")
        self.assertRegex(text, r"\$holdKeys = array_intersect_key\(CapabilityBoundary::stagedFlags\(\), array_flip\(PRE_LAUNCH_HOLDS\)\);\s*if \(count\(\$holdKeys\) !== count\(PRE_LAUNCH_HOLDS\)\) \{\s*throw new RuntimeException;")
        for config_key in PRE_LAUNCH_HOLDS.values():
            self.assertNotIn(f"'{config_key}'", text, f"{config_key} must come from the boundary, not be restated")

    def test_the_hold_config_keys_exist_with_a_false_default(self):
        # A renamed or removed config key would read null and fail closed in the validator; this proves they exist today.
        for config_key in PRE_LAUNCH_HOLDS.values():
            name, key = config_key.split(".")
            source = (REPO / "config" / f"{name}.php").read_text()
            self.assertRegex(source, r"'" + re.escape(key) + r"'\s*=>\s*env\('[A-Z0-9_]+',\s*false\)", config_key)

    def test_the_effective_configuration_must_be_strictly_false(self):
        # Pin its shape: it iterates the same constant and compares with ===, so null (a missing key) fails closed.
        text = VALIDATOR.read_text()
        self.assertRegex(text, r"foreach \(\$holdKeys as \$name => \$key\) \{\s*\$check\('holds\.'\.strtolower\(\$name\), config\(\$key\) === false\);")

    def test_the_effective_configuration_refuses_what_the_file_check_admits(self):
        # Interpolation reaches the configuration layer alone: in the file, `${PATH}` resolves to the file's own `PATH=false`, so
        # the file check sees `false`; the real bootstrap keeps the process PATH (an existing variable wins over the file), so the
        # flag's effective value is not false and only the configuration check refuses it.
        for flag in PRE_LAUNCH_HOLDS:
            with self.subTest(flag=flag):
                run = self.validate(profile(**{"PATH": "false", flag: "${PATH}"}))
                self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
                self.assertEqual(run.stderr.split(), ["FAIL", f"holds.{flag.lower()}"], run.stderr)

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

    # ---- off through launch: DD-44 (marketing segment) and DD-50 (memberships), decision register 2026-11-10 ----

    def shadow_project(self, boundary=None):
        # A shadow project (symlinks to this checkout, a copied validator, bootstrap and config, and optionally a copied
        # capability boundary) so one literal or boundary entry can be changed without touching the checkout.
        shadow = self.dir / "shadow"
        shadow.mkdir()
        linked = ["app", "vendor", "routes", "resources", "database", "public", "storage", "composer.json", "artisan"]
        if boundary is None:
            linked.append("scripts")
        else:
            (shadow / "scripts/ops").mkdir(parents=True)
            (shadow / "scripts/ops/capability-boundary.php").write_text(boundary)
        for name in linked:
            (shadow / name).symlink_to(REPO / name)
        shutil.copytree(REPO / "bootstrap", shadow / "bootstrap")
        shutil.copytree(REPO / "config", shadow / "config")
        (shadow / "ops/production").mkdir(parents=True)
        shutil.copy2(VALIDATOR, shadow / "ops/production/validate-runtime.php")

        def run(text=None):
            self.file.write_text(profile() if text is None else text)
            self.file.chmod(0o600)
            return subprocess.run([PHP, str(shadow / "ops/production/validate-runtime.php"), str(self.file), "production", HOST, "vasey_production"],
                                  cwd=shadow, env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")}, capture_output=True, text=True, timeout=120)

        return shadow, run

    def test_the_marketing_segment_flag_passes_only_as_false_or_unset(self):
        self.assertNotIn(MARKETING_SEGMENT, profile())
        for value in ("false", "(false)", "FALSE", '"false"', "'false'"):
            with self.subTest(value=value):
                run = self.validate(profile() + f"{MARKETING_SEGMENT}={value}\n")
                self.assertEqual(run.returncode, 0, run.stderr)
        for value in ("true", "1", "yes", "", "(true)", "TRUE", '"true"', "on", "0", "null", "${BAD}"):
            with self.subTest(value=value):
                run = self.validate(profile() + ("BAD=true\n" if "BAD" in value else "") + f"{MARKETING_SEGMENT}={value}\n")
                self.failed(run, "holds.reporting_marketing_segment_enabled")
                self.assertEqual({line for line in run.stderr.splitlines() if line.startswith("FAIL ")},
                                 {"FAIL holds.reporting_marketing_segment_enabled"}, run.stderr)
        self.failed(self.validate(profile() + f"{MARKETING_SEGMENT}\n"), "holds.reporting_marketing_segment_enabled")

    def test_the_marketing_segment_hold_is_checked_before_the_preflight_child_runs(self):
        run = self.validate(profile(APP_DEBUG="true", **{MARKETING_SEGMENT: "true"}))
        self.failed(run, "holds.reporting_marketing_segment_enabled")
        self.assertNotIn("preflight.", run.stderr)

    def test_the_marketing_segment_effective_configuration_is_strictly_false(self):
        # `${PATH}` reads `false` in the file but the process PATH in the real bootstrap: only the configuration check refuses it.
        run = self.validate(profile(**{"PATH": "false", MARKETING_SEGMENT: "${PATH}"}))
        self.assertEqual(run.returncode, 1, run.stdout + run.stderr)
        self.assertEqual(run.stderr.split(), ["FAIL", "holds.reporting_marketing_segment_enabled"], run.stderr)
        text = VALIDATOR.read_text()
        self.assertRegex(text, r"foreach \(\$launchOffKeys as \$name => \$key\) \{\s*\$check\('holds\.'\.strtolower\(\$name\), config\(\$key\) === false\);")
        self.assertRegex(text, r"foreach \(\[\.\.\.PRE_LAUNCH_HOLDS, \.\.\.LAUNCH_OFF_HOLDS\] as \$name\)")

    def test_the_marketing_segment_key_comes_from_the_capability_boundary(self):
        text = VALIDATOR.read_text()
        block = re.search(r"const LAUNCH_OFF_HOLDS = \[(.*?)\n\];", text, re.S)
        self.assertIsNotNone(block, "LAUNCH_OFF_HOLDS constant block not found")
        self.assertNotIn("=>", block.group(1))
        self.assertEqual(re.findall(r"'([A-Z0-9_]+)',", block.group(1)), [MARKETING_SEGMENT, BEATSTARS_IMPORT])
        self.assertNotIn(f"'{MARKETING_SEGMENT_KEY}'", text, "the config key must come from the boundary, not be restated")
        self.assertRegex(text, r"\$launchOffKeys = array_intersect_key\(CapabilityBoundary::PRODUCTION_LAUNCH_OFF_FLAGS, array_flip\(LAUNCH_OFF_HOLDS\)\);"
                               r"\s*if \(count\(\$launchOffKeys\) !== count\(LAUNCH_OFF_HOLDS\)\) \{\s*throw new RuntimeException;")
        script = "require $argv[1]; echo json_encode(Vasey\\Ops\\CapabilityBoundary::PRODUCTION_LAUNCH_OFF_FLAGS);"
        run = subprocess.run([PHP, "-r", script, str(REPO / "scripts/ops/capability-boundary.php")], capture_output=True, text=True, timeout=60)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(json.loads(run.stdout), {MARKETING_SEGMENT: MARKETING_SEGMENT_KEY, BEATSTARS_IMPORT: BEATSTARS_IMPORT_KEY})
        # Not a staged flag: the staging gates keep their own list, and this hold is production's.
        script = "require $argv[1]; echo json_encode(array_key_exists($argv[2], Vasey\\Ops\\CapabilityBoundary::stagedFlags()));"
        run = subprocess.run([PHP, "-r", script, str(REPO / "scripts/ops/capability-boundary.php"), MARKETING_SEGMENT],
                             capture_output=True, text=True, timeout=60)
        self.assertEqual(run.stdout, "false", run.stderr)
        self.assertRegex((REPO / "config/reporting.php").read_text(),
                         r"'marketing_segment_enabled'\s*=>\s*env\('" + MARKETING_SEGMENT + r"',\s*false\)")
        self.assertRegex((REPO / "ops/production/env.production.example").read_text(), rf"(?m)^{MARKETING_SEGMENT}=false$")

    def test_the_beatstars_import_flag_is_held_off_in_production(self):
        self.assertNotIn(BEATSTARS_IMPORT, profile())
        for value in ("false", "(false)", "FALSE"):
            with self.subTest(value=value):
                self.assertEqual(self.validate(profile() + f"{BEATSTARS_IMPORT}={value}\n").returncode, 0)
        for value in ("true", "1", "yes", "", "(true)", "TRUE", '"true"', "on", "0", "null"):
            with self.subTest(value=value):
                run = self.validate(profile() + f"{BEATSTARS_IMPORT}={value}\n")
                self.failed(run, "holds.vasey_staging_beatstars_import_enabled")
                self.assertEqual({line for line in run.stderr.splitlines() if line.startswith("FAIL ")},
                                 {"FAIL holds.vasey_staging_beatstars_import_enabled"}, run.stderr)
        self.failed(self.validate(profile() + f"{BEATSTARS_IMPORT}\n"), "holds.vasey_staging_beatstars_import_enabled")
        # Not a staged flag: the staging deploy gate must let the import run set it true.
        script = "require $argv[1]; echo json_encode(array_key_exists($argv[2], Vasey\\Ops\\CapabilityBoundary::stagedFlags()));"
        run = subprocess.run([PHP, "-r", script, str(REPO / "scripts/ops/capability-boundary.php"), BEATSTARS_IMPORT],
                             capture_output=True, text=True, timeout=60)
        self.assertEqual(run.stdout, "false", run.stderr)
        self.assertRegex((REPO / "config/staging-beatstars-import.php").read_text(),
                         r"'enabled'\s*=>\s*env\('" + BEATSTARS_IMPORT + r"',\s*false\)")
        self.assertRegex((REPO / "ops/production/env.production.example").read_text(), rf"(?m)^{BEATSTARS_IMPORT}=false$")

    def test_a_boundary_without_the_beatstars_import_entry_fails_closed(self):
        source = (REPO / "scripts/ops/capability-boundary.php").read_text()
        entry = f"        '{BEATSTARS_IMPORT}' => '{BEATSTARS_IMPORT_KEY}',\n"
        self.assertEqual(source.count(entry), 1)
        _, run = self.shadow_project(boundary=source.replace(entry, ""))
        result = run()
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertEqual(result.stderr.split(), ["FAIL", "runtime.unavailable"], result.stderr)

    def test_a_boundary_without_the_marketing_segment_entry_fails_closed(self):
        source = (REPO / "scripts/ops/capability-boundary.php").read_text()
        entry = f"        '{MARKETING_SEGMENT}' => '{MARKETING_SEGMENT_KEY}',\n"
        self.assertEqual(source.count(entry), 1)
        _, run = self.shadow_project(boundary=source.replace(entry, ""))
        result = run()
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertEqual(result.stderr.split(), ["FAIL", "runtime.unavailable"], result.stderr)

    def test_the_membership_literals_are_held(self):
        text = VALIDATOR.read_text()
        for identifier, key in MEMBERSHIP_LITERALS.items():
            with self.subTest(key=key):
                self.assertIn(f"$check('{identifier}', config('{key}') === false);", text)
                name, item = key.split(".")
                self.assertRegex((REPO / "config" / f"{name}.php").read_text(), rf"(?m)^    '{item}' => false,$")
        self.assertEqual(self.validate(profile()).returncode, 0)

    def test_each_membership_literal_flipped_fails_the_validator(self):
        # MembershipHttpMount and both lanes ship literal false; the validator run from a shadow whose only change is one literal
        # (true, or a truthy or non-boolean value) refuses with that literal's identifier alone.
        shadow, run = self.shadow_project()
        self.assertEqual(run().returncode, 0)
        for identifier, key in MEMBERSHIP_LITERALS.items():
            name, item = key.split(".")
            path = shadow / "config" / f"{name}.php"
            source = path.read_text()
            literal = f"    '{item}' => false,\n"
            self.assertEqual(source.count(literal), 1, key)
            for value in ("true", "1", "'false'", "null"):
                with self.subTest(key=key, value=value):
                    path.write_text(source.replace(literal, f"    '{item}' => {value},\n"))
                    result = run()
                    self.failed(result, identifier)
                    self.assertEqual({line for line in result.stderr.splitlines() if line.startswith("FAIL ")},
                                     {"FAIL " + identifier}, result.stderr)
            path.write_text(source)
        self.assertEqual(run().returncode, 0)

    # ---- the synthetic staging seller tag is a rehearsal placeholder, never a production tag ----

    def test_a_staging_seller_tag_path_is_refused_before_the_preflight_child_runs(self):
        for path in ("branding/STAGING-synthetic-seller-tag.wav", "branding/staging-tag.wav", "branding/Synthetic-tag.wav"):
            with self.subTest(path=path):
                run = self.validate(profile(MEDIA_TAG_PATH=path))
                self.failed(run, "holds.staging_seller_tag")
                self.assertEqual(run.stderr.split(), ["FAIL", "holds.staging_seller_tag"], run.stderr)

    def test_a_staging_seller_tag_renamed_to_look_approved_is_refused_by_its_content(self):
        # The validator reads the tag where the application would: the private local disk of this checkout.
        private = REPO / "storage/app/private"
        directory = Path(tempfile.mkdtemp(prefix="ops-validator-tag-", dir=private))
        self.addCleanup(shutil.rmtree, directory, True)
        relative = f"{directory.name}/approved-seller-tag.wav"
        tag = directory / "approved-seller-tag.wav"
        tag.write_bytes(b"RIFF" + b"x" * 70000 + b"VASEY-STAGING-SYNTHETIC-SELLER-TAG" + b"data")
        self.failed(self.validate(profile(MEDIA_TAG_PATH=relative)), "holds.staging_seller_tag")
        # The same path with unmarked content, or with no file at all, is not this hold's to refuse.
        tag.write_bytes(b"RIFF" + b"x" * 70000 + b"data")
        self.assertEqual(self.validate(profile(MEDIA_TAG_PATH=relative)).returncode, 0)
        tag.unlink()
        self.assertEqual(self.validate(profile(MEDIA_TAG_PATH=relative)).returncode, 0)

    def test_the_staging_marker_is_the_applications_own(self):
        marker = re.search(r"const MARKER = '([A-Z-]+)';", (REPO / "app/Domain/Media/StagingSellerTag.php").read_text())
        self.assertEqual(marker and marker.group(1), "VASEY-STAGING-SYNTHETIC-SELLER-TAG")

    def test_php_syntax(self):
        lint = subprocess.run([PHP, "-l", str(VALIDATOR)], capture_output=True, text=True)
        self.assertEqual(lint.returncode, 0, lint.stderr)


if __name__ == "__main__":
    unittest.main()
