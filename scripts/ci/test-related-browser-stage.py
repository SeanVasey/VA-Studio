#!/usr/bin/env python3
"""Execute real isolated PHP bootstrap and startup guards, not browser/scanner acceptance."""

import base64
import calendar
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shlex
import shutil
import sqlite3
import subprocess
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[2]

# Phases the isolated bootstrap may report (tests/browser/bootstrap.php). Its refusal prints only one of these and a bounded class
# name, so that fixed line, and nothing else it prints, may appear in a failure message here.
BOOTSTRAP_PHASES = {
    "isolation", "stage_identity", "scanner_prerequisite", "application_boot", "effective_configuration", "migrations",
    "operator_provisioning", "customer_provisioning", "catalog_fixtures", "catalog_manifest", "installation_diagnostics",
    "customer_purchase", "customer_manifest", "delivery_evidence", "delivery_stream", "customer_manifest_write",
}


def unmet_prerequisites(root=ROOT, clamscan="/usr/bin/clamscan"):
    """What the CI stage provides before it runs this file, and this checkout may lack.

    The hosted stage runs `npm ci`, `npm run build` and the genuine-scanner installer first. Without them the fixtures fail
    for environmental reasons that the bare "bootstrap failed" assertion cannot tell apart from a real regression: Playwright
    is missing, `vasey:doctor` reports `frontend_build` failed (the bootstrap runs it), or the scanner's signatures are older
    than `media.max_signature_age_seconds`, which the ordinary fixtures refuse by design. This only reports; it never skips.
    """
    gaps = []
    if not (root / "node_modules/playwright-core/lib/coreBundle.js").is_file():
        gaps.append("node_modules is missing or incomplete (run npm ci)")
    if not (root / "public/build/manifest.json").is_file():
        gaps.append("public/build/manifest.json is missing (run npm run build; vasey:doctor's frontend_build check and "
                    "tests/browser/run.mjs need it, while the PHP unit suite needs it absent, so remove it afterwards)")
    limit = 172800
    config = root / "config/media.php"
    if config.is_file():
        match = re.search(r"'max_signature_age_seconds'\s*=>\s*(\d+)", config.read_text())
        limit = int(match.group(1)) if match else limit
    try:
        version = subprocess.run([clamscan, "--version"], capture_output=True, text=True, timeout=30, env={"PATH": "/usr/bin:/bin", "TZ": "UTC"}).stdout.strip()
    except (OSError, subprocess.SubprocessError):
        version = ""
    parts = version.split("/", 2)
    if not version.startswith("ClamAV ") or len(parts) != 3 or not parts[1].isdigit():
        gaps.append("the ClamAV scanner prints no signature date (install it and load signatures with freshclam)")
    else:
        try:
            age = time.time() - calendar.timegm(time.strptime(parts[2].strip(), "%a %b %d %H:%M:%S %Y"))
        except ValueError:
            age = None
        if age is None or age > limit or age < -3600:
            gaps.append("the ClamAV signatures are stale or undated (freshclam; the ordinary fixtures refuse a database older than %d s)" % limit)
    return gaps


def safe_diagnosis(stderr):
    """The bootstrap's fixed `{phase, exception_class}` line, when stderr ends with exactly that; otherwise nothing."""
    lines = stderr.splitlines()
    if not lines:
        return ""
    try:
        record = json.loads(lines[-1])
    except ValueError:
        return ""
    if (not isinstance(record, dict) or set(record) != {"phase", "exception_class"} or record["phase"] not in BOOTSTRAP_PHASES
            or not isinstance(record["exception_class"], str) or len(record["exception_class"]) > 160
            or not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)*", record["exception_class"])):
        return ""
    return " Bootstrap stopped in phase %s (%s)." % (record["phase"], record["exception_class"])


def prerequisite_note():
    gaps = unmet_prerequisites()
    return " Unmet prerequisites of this stage: " + "; ".join(gaps) + "." if gaps else ""


def failure_note(stderr):
    """Appended to a bootstrap failure message: the safe phase line and every unmet prerequisite of this checkout."""
    return safe_diagnosis(stderr) + prerequisite_note()


class RelatedBrowserStageSafeguards(unittest.TestCase):
    def test_pinned_webkit_backport_rejects_drift_without_touching_shared_sdk(self):
        result = subprocess.run(
            ["node", "--test", "scripts/ci/apply-playwright-webkit-offline-backport.test.mjs"],
            cwd=ROOT, text=True, capture_output=True, timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_source_renderer_uses_only_signed_official_ubuntu_origins(self):
        result = subprocess.run(
            ["bash", "-c", 'source "$1"; render_related_ubuntu_sources "$2"', "related-source-test",
             "tests/browser/install-related-scanner.sh", "noble"],
            cwd=ROOT, text=True, capture_output=True, timeout=10,
        )
        self.assertEqual(result.returncode, 0)
        self.assertEqual(result.stderr, "")
        stanzas = [dict(line.split(": ", 1) for line in stanza.splitlines())
                   for stanza in result.stdout.strip().split("\n\n")]
        self.assertEqual(stanzas, [
            {"Types": "deb", "URIs": "https://archive.ubuntu.com/ubuntu",
             "Suites": "noble noble-updates noble-backports",
             "Components": "main restricted universe multiverse",
             "Signed-By": "/usr/share/keyrings/ubuntu-archive-keyring.gpg"},
            {"Types": "deb", "URIs": "https://security.ubuntu.com/ubuntu",
             "Suites": "noble-security", "Components": "main restricted universe multiverse",
             "Signed-By": "/usr/share/keyrings/ubuntu-archive-keyring.gpg"},
        ])

    def test_source_renderer_refuses_missing_duplicate_or_injected_codenames(self):
        for arguments in [[], [""], ["noble", "jammy"], ["NOBLE"], ["noble\nTrusted: yes"],
                          ["noble; true"], ["../../noble"], ["$(true)"]]:
            with self.subTest(arguments=arguments):
                result = subprocess.run(
                    ["bash", "-c", 'source "$1"; shift; render_related_ubuntu_sources "$@"',
                     "related-source-test", "tests/browser/install-related-scanner.sh", *arguments],
                    cwd=ROOT, text=True, capture_output=True, timeout=10,
                )
                self.assertEqual(result.returncode, 1)
                self.assertEqual(result.stdout, "")
                self.assertEqual(result.stderr, "")

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


class FailureDiagnosisHelpers(unittest.TestCase):
    """The helpers only explain a failure; these prove they report what is missing and never echo private output."""

    def test_each_missing_prerequisite_is_reported_and_a_complete_checkout_reports_none_of_the_files(self):
        with tempfile.TemporaryDirectory(prefix="vasey-prerequisites-") as temporary:
            root = Path(temporary)
            (root / "config").mkdir()
            (root / "config/media.php").write_text("<?php return ['max_signature_age_seconds' => 172800];\n")
            fresh = time.strftime("%a %b %d %H:%M:%S %Y", time.gmtime())
            scanner = root / "clamscan"
            scanner.write_text("#!/bin/sh\necho 'ClamAV 1.5.4/28144/%s'\n" % fresh)
            scanner.chmod(0o700)
            gaps = unmet_prerequisites(root, str(scanner))
            self.assertEqual([gap.split(" ")[0] for gap in gaps], ["node_modules", "public/build/manifest.json"])
            (root / "node_modules/playwright-core/lib").mkdir(parents=True)
            (root / "node_modules/playwright-core/lib/coreBundle.js").write_text("")
            (root / "public/build").mkdir(parents=True)
            (root / "public/build/manifest.json").write_text("{}")
            self.assertEqual(unmet_prerequisites(root, str(scanner)), [])
            # A database older than the configured limit is refused by the fixtures, so it is reported.
            scanner.write_text("#!/bin/sh\necho 'ClamAV 1.5.4/28100/Sat Oct  3 08:00:00 2026'\n")
            self.assertEqual(len(unmet_prerequisites(root, str(scanner))), 1)
            self.assertIn("ClamAV signatures are stale", unmet_prerequisites(root, str(scanner))[0])
            # An undated or absent scanner is reported too.
            scanner.write_text("#!/bin/sh\necho 'ClamAV 1.5.4'\n")
            self.assertIn("prints no signature date", unmet_prerequisites(root, str(scanner))[0])
            self.assertIn("prints no signature date", unmet_prerequisites(root, str(root / "absent"))[0])

    def test_only_the_fixed_phase_line_is_ever_echoed(self):
        self.assertEqual(safe_diagnosis('{"phase":"installation_diagnostics","exception_class":"RuntimeException"}\n'),
                         " Bootstrap stopped in phase installation_diagnostics (RuntimeException).")
        self.assertEqual(safe_diagnosis('Isolated browser fixture setup failed; no browser run was started.\n'
                                        '{"phase":"customer_purchase","exception_class":"App\\\\Domain\\\\Media\\\\MediaFailure"}\n'),
                         " Bootstrap stopped in phase customer_purchase (App\\Domain\\Media\\MediaFailure).")
        for stderr in ["", "private-token\n", '{"phase":"unknown","exception_class":"RuntimeException"}',
                       '{"phase":"migrations","exception_class":"RuntimeException","message":"private"}',
                       '{"phase":"migrations","exception_class":"has space"}', '{"phase":"migrations","exception_class":"' + "A" * 161 + '"}',
                       '{"phase":"migrations","exception_class":["x"]}', '["migrations"]', '{"phase":"migrations"}']:
            with self.subTest(stderr=stderr[:40]):
                self.assertEqual(safe_diagnosis(stderr), "")


class BrowserBootstrapFixtures(unittest.TestCase):
    PROJECTS = {"chromium-desktop": "CHROMIUM", "webkit-mobile": "WEBKIT"}

    def bootstrap(self, stage=None, marker=None, *, accounts=True, failure=None):
        # Match the real runner's guard: direct /tmp child, alphanumeric suffix,
        # new empty SQLite file and private storage. Never reuse a developer DB.
        directory = Path("/tmp") / ("vasey-browser-" + secrets.token_hex(12))
        directory.mkdir(mode=0o700)
        self.addCleanup(shutil.rmtree, directory)
        for child in ["framework/views", "framework/sessions", "framework/cache/data", "logs", "app/private"]:
            (directory / child).mkdir(parents=True, mode=0o700)
        database = directory / "database.sqlite"
        database.touch(mode=0o600, exist_ok=False)
        env = {**os.environ,
               "APP_ENV": "local", "APP_DEBUG": "false", "APP_URL": "http://127.0.0.1:8173", "ASSET_URL": "",
               "APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
               "APP_CONFIG_CACHE": str(directory / "config.php"), "APP_ROUTES_CACHE": str(directory / "routes.php"),
               "APP_EVENTS_CACHE": str(directory / "events.php"), "APP_LOCALE": "en", "LARAVEL_STORAGE_PATH": str(directory),
               "DB_CONNECTION": "sqlite", "DB_DATABASE": str(database), "DB_URL": "", "DB_FOREIGN_KEYS": "true",
               "SESSION_DRIVER": "file", "SESSION_ENCRYPT": "true", "SESSION_SECURE_COOKIE": "false", "SESSION_DOMAIN": "null",
               "CACHE_STORE": "file", "QUEUE_CONNECTION": "sync", "MAIL_MAILER": "array", "LOG_CHANNEL": "single", "LOG_LEVEL": "error",
               "FILESYSTEM_DISK": "local", "STRIPE_WEBHOOK_ENABLED": "false", "STRIPE_ACCOUNT_ID": "acct_SYNTHETICONLY",
               "STRIPE_MODE": "test", "STRIPE_TEST_SECRET_KEY": "", "STRIPE_WEBHOOK_SECRET": "",
               "STRIPE_TEST_CHECKOUT_ENABLED": "false", "STRIPE_TEST_PAYMENT_PROCESSING_ENABLED": "false",
               "STRIPE_TEST_FINALIZATION_ENABLED": "false", "MEDIA_CLAMSCAN": str(directory / "no-clamscan"),
               "VASEY_BROWSER_DIRECTORY": str(directory), "VASEY_BROWSER_PASSWORD": "Browser-" + secrets.token_hex(24),
               "VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED": "true" if accounts else "false",
               "CONTACT_INQUIRIES_ENABLED": "true", "CONTACT_INQUIRIES_OPERATOR_ID": "1",
               "CONTACT_INQUIRIES_PRIVACY_NOTICE": "Synthetic browser privacy notice. Inquiries are saved privately for verification.",
               "CONTACT_INQUIRIES_RETENTION_REFERENCE": "SYNTHETIC-BROWSER-ONLY"}
        for name, value in [("VASEY_BROWSER_RELATED_STAGE", stage), ("VASEY_BROWSER_RELATED_MARKER", marker)]:
            env.pop(name, None)
            if value is not None:
                env[name] = value
        php_arguments = []
        if failure is not None:
            # Exercise the installed bootstrap's actual catch through PHP's existing
            # prepend option; production code has no fault-injection switch.
            declarations, expression = {
                "standard": ("", "new \\RuntimeException($private)"),
                "namespaced": ("namespace FixtureDiagnostic; class ControlledFailure extends \\RuntimeException {}",
                               "new ControlledFailure($private)"),
                "anonymous": ("", "new class($private) extends \\RuntimeException {}"),
                "overlong": ("class " + "PrivateFailure" * 15 + " extends \\RuntimeException {}",
                             "new " + "PrivateFailure" * 15 + "($private)"),
            }[failure]
            prepend = directory / "private-fixture-diagnostic-canary.php"
            prepend.write_text("<?php\n" + declarations + "\nrequire " + json.dumps(str(ROOT / "vendor/autoload.php")) + ";\n"
                               "spl_autoload_register(static function (string $class): void {\n"
                               "  if ($class === 'App\\\\Providers\\\\AppServiceProvider') {\n"
                               "    $private = 'private-request-body private-token ' . getenv('VASEY_BROWSER_PASSWORD')"
                               " . ' ' . getenv('APP_KEY') . ' ' . getenv('DB_DATABASE');\n"
                               "    throw " + expression + ";\n  }\n}, true, true);\n")
            prepend.chmod(0o600)
            php_arguments = ["-d", "auto_prepend_file=" + str(prepend)]
        result = subprocess.run(
            ["/usr/bin/timeout", "--signal=TERM", "--kill-after=15s", "600s", "php", *php_arguments, "tests/browser/bootstrap.php"], cwd=ROOT, env=env,
            text=True, capture_output=True, timeout=620,
        )
        return directory, result

    def database(self, directory):
        connection = sqlite3.connect(f"file:{directory / 'database.sqlite'}?mode=ro", uri=True)
        connection.row_factory = sqlite3.Row
        self.addCleanup(connection.close)
        return connection

    def assert_bootstrapped(self, directory, result):
        # Do not print arbitrary PHP output/environment on failure.
        self.assertEqual(result.returncode, 0, "The native isolated PHP bootstrap failed." + failure_note(result.stderr))
        self.assertTrue(result.stderr == "", "The native bootstrap emitted unexpected diagnostic output.")
        self.assertTrue(result.stdout == "Fresh SQLite migrations, interactive operator command and installation diagnostics passed.\n",
                        "The native bootstrap did not emit its fixed success receipt.")
        expected = {project: {
            "editable": {"title": "Synthetic editable " + project, "slug": "editable-" + project},
            "retained": {"title": "Synthetic retained URL " + project, "slug": "retained-" + project},
        } for project in self.PROJECTS}
        path = directory / "fixtures.json"
        self.assertEqual(json.loads(path.read_text()), expected)
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        db = self.database(directory)
        for project in self.PROJECTS:
            for kind in ["editable", "retained"]:
                fixture = expected[project][kind]
                rows = db.execute("SELECT title, slug, status, published_slug FROM tracks WHERE slug = ?", (fixture["slug"],)).fetchall()
                self.assertEqual([tuple(row) for row in rows], [(fixture["title"], fixture["slug"], "draft",
                                                               fixture["slug"] if kind == "retained" else None)])
        return db

    def test_related_bootstrap_preserves_empty_commerce_without_customer_policy(self):
        directory, result = self.bootstrap("1", secrets.token_hex(32), accounts=False)
        db = self.assert_bootstrapped(directory, result)
        self.assertEqual(db.execute("SELECT COUNT(*) FROM tracks").fetchone()[0], 4)
        self.assertEqual([tuple(row) for row in db.execute("SELECT email, is_admin FROM users ORDER BY id")],
                         [("browser-operator@example.test", 1), ("browser-customer@example.test", 0)])
        for table in ["customer_accounts", "orders", "license_grants", "media_assets", "license_versions", "rights_declarations"]:
            with self.subTest(table=table):
                self.assertEqual(db.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0], 0)
        self.assertFalse((directory / "customer-fixtures.json").exists())
        self.assertEqual(list((directory / "app/private").rglob("*")), [])

    def test_ordinary_bootstrap_keeps_project_bound_paid_customer_deliveries(self):
        for stage, marker in [(None, None), ("", "")]:
            with self.subTest(markers="absent" if stage is None else "empty"):
                directory, result = self.bootstrap(stage, marker, accounts=True)
                db = self.assert_bootstrapped(directory, result)
                accounts = db.execute("SELECT u.email, a.active, a.access_version FROM customer_accounts a JOIN users u ON u.id = a.user_id").fetchall()
                self.assertEqual([tuple(row) for row in accounts], [("browser-customer@example.test", 1, 1)])
                for table in ["orders", "verified_payments", "order_finalizations", "license_grants", "grant_contracts",
                              "test_fulfillment_activations", "test_delivery_controls", "pending_entitlements"]:
                    self.assertEqual(db.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0], 2, table)
                path = directory / "customer-fixtures.json"
                manifest = json.loads(path.read_text())
                self.assertEqual(set(manifest), {"projects"})
                self.assertEqual(set(manifest["projects"]), set(self.PROJECTS))
                self.assertEqual(path.stat().st_mode & 0o777, 0o600)
                self.assertEqual(len({fixture["orderId"] for fixture in manifest["projects"].values()}), 2)
                for project, suffix in self.PROJECTS.items():
                    fixture = manifest["projects"][project]
                    self.assertEqual(set(fixture), {"orderId", "contract", "asset"})
                    rows = db.execute("""
                        SELECT g.public_id AS grant_id, p.provider_payment_intent_id,
                               c.disk AS contract_disk, c.storage_path AS contract_path,
                               c.pdf_hash AS contract_hash, c.size_bytes AS contract_size,
                               m.disk AS asset_disk, m.storage_path AS asset_path,
                               e.asset_hash, e.size_bytes AS asset_size, e.role,
                               s.sha256 AS source_hash, r.input_sha256, r.profile AS media_profile, r.evidence AS media_evidence
                        FROM orders o
                        JOIN customer_accounts a ON a.owner_key = o.owner_key
                        JOIN users u ON u.id = a.user_id
                        JOIN order_finalizations f ON f.order_id = o.id AND f.outcome = 'paid' AND f.mode = 'test'
                        JOIN verified_payments p ON p.id = f.verified_payment_id AND p.order_id = o.id AND p.mode = 'test'
                        JOIN test_fulfillment_activations v ON v.order_id = o.id AND v.order_finalization_id = f.id
                        JOIN test_delivery_controls d ON d.order_id = o.id AND d.test_fulfillment_activation_id = v.id AND d.blocked = 0
                        JOIN license_grants g ON g.order_finalization_id = f.id
                        JOIN grant_contracts c ON c.license_grant_id = g.id
                        JOIN pending_entitlements e ON e.license_grant_id = g.id AND e.state = 'pending'
                        JOIN media_assets m ON m.id = e.media_asset_id AND m.sha256 = e.asset_hash AND m.size_bytes = e.size_bytes AND m.status = 'ready'
                        JOIN media_assets s ON s.id = m.parent_asset_id
                        JOIN media_processing_runs r ON r.id = m.processing_run_id AND r.source_asset_id = s.id AND r.status = 'completed'
                        WHERE o.public_id = ? AND u.email = 'browser-customer@example.test'
                        """, (fixture["orderId"],)).fetchall()
                    self.assertEqual(len(rows), 1)
                    row = rows[0]
                    self.assertEqual(row["provider_payment_intent_id"], "pi_CUSTOMER" + suffix)
                    self.assertEqual(row["role"], "master_wav")
                    self.assertEqual(row["asset_hash"], row["source_hash"])
                    self.assertEqual(row["asset_hash"], row["input_sha256"])
                    evidence = json.loads(row["media_evidence"])
                    profile = json.loads(row["media_profile"])
                    for name, sha256 in [("source_scan", row["asset_hash"]), ("tag_scan", profile["tag_sha256"])]:
                        scan = evidence[name]
                        self.assertEqual(scan["engine"], "clamav")
                        self.assertEqual(scan["status"], "clean")
                        self.assertEqual(scan["sha256"], sha256)
                        self.assertRegex(scan["version"], r"^ClamAV [^/\r\n]+/[0-9]+/[^\r\n]+$")
                    for kind, extension in [("contract", "pdf"), ("asset", "wav")]:
                        role = "contract" if kind == "contract" else row["role"]
                        self.assertEqual(fixture[kind], {
                            "kind": role, "filename": row["grant_id"] + "-" + role + "." + extension,
                            "sha256": row[kind + "_hash"], "sizeBytes": row[kind + "_size"],
                        })
                        self.assertEqual(row[kind + "_disk"], "local")
                        private = directory / "app/private"
                        retained = private / row[kind + "_path"]
                        self.assertTrue(retained.resolve().is_relative_to(private))
                        self.assertFalse(retained.is_symlink())
                        content = retained.read_bytes()
                        self.assertGreater(len(content), 0)
                        self.assertEqual(len(content), fixture[kind]["sizeBytes"])
                        self.assertEqual(hashlib.sha256(content).hexdigest(), fixture[kind]["sha256"])

    def test_partial_or_malformed_stage_identity_is_refused_before_migration(self):
        marker = "a" * 64
        invalid = [(None, marker), ("", marker), ("1", None), ("1", ""), ("0", ""), ("false", ""),
                   ("true", marker), ("01", marker), ("1\n", marker), ("1", "A" * 64), ("1", "g" * 64),
                   ("1", marker[:-1]), ("1", marker + "0"), ("1", marker + "\n")]
        for stage, value in invalid:
            with self.subTest(stage=stage, marker=value):
                directory, result = self.bootstrap(stage, value)
                self.assertEqual(result.returncode, 1)
                self.assertTrue(result.stdout == "", "Refused bootstrap emitted unexpected output.")
                self.assertTrue(result.stderr == "Isolated browser fixture setup failed; no browser run was started.\n"
                                '{"phase":"stage_identity","exception_class":"RuntimeException"}\n',
                                "Refused bootstrap did not retain its fixed bounded failure response.")
                self.assertEqual((directory / "database.sqlite").read_bytes(), b"")
                self.assertFalse((directory / "fixtures.json").exists())
                self.assertFalse((directory / "customer-fixtures.json").exists())

    def test_bootstrap_failure_diagnostic_excludes_private_values_and_unsafe_class_names(self):
        for failure, expected_class in [("standard", "RuntimeException"),
                                        ("namespaced", "FixtureDiagnostic\\ControlledFailure"),
                                        ("anonymous", "Throwable"), ("overlong", "Throwable")]:
            with self.subTest(failure=failure):
                directory, result = self.bootstrap("1", secrets.token_hex(32), accounts=False, failure=failure)
                self.assertEqual(result.returncode, 1)
                self.assertTrue(result.stdout == "", "Refused bootstrap emitted unexpected output.")
                expected = "Isolated browser fixture setup failed; no browser run was started.\n" + json.dumps(
                    {"phase": "application_boot", "exception_class": expected_class}, separators=(",", ":")) + "\n"
                # Exact equality also excludes the private message, body/token canaries,
                # generated credentials, temporary paths, stack and anonymous-class path.
                self.assertTrue(result.stderr == expected, "Failure diagnosis exposed unexpected fields or values.")
                self.assertLessEqual(len(result.stderr.encode()), 300)
                self.assertEqual((directory / "database.sqlite").read_bytes(), b"")
                self.assertFalse((directory / "fixtures.json").exists())
                self.assertFalse((directory / "customer-fixtures.json").exists())

    def test_ordinary_runner_keeps_safe_refusal_and_cleans_up_its_actual_directory(self):
        with tempfile.TemporaryDirectory(prefix="vasey-runner-diagnostic-") as temporary:
            directory = Path(temporary)
            observed = directory / "private-directory.txt"
            prepend = directory / "private-guard-canary.php"
            prepend.write_text("<?php\nfile_put_contents(" + json.dumps(str(observed))
                               + ", getenv('VASEY_BROWSER_DIRECTORY'));\n"
                               "putenv('VASEY_BROWSER_DIRECTORY=private-isolation-canary');\n")
            prepend.chmod(0o600)
            installed_php = shutil.which("php")
            self.assertIsNotNone(installed_php)
            delegate = directory / "php"
            # Delegate every argument to the actual installed PHP. An explicit -d
            # also works when a local runtime wrapper pins its INI search directory.
            delegate.write_text("#!/bin/sh\nexec " + shlex.quote(installed_php)
                                + " -d " + shlex.quote("auto_prepend_file=" + str(prepend)) + ' "$@"\n')
            delegate.chmod(0o700)
            result = subprocess.run(
                ["node", "tests/browser/run.mjs", "--project=chromium-desktop"], cwd=ROOT,
                env={**os.environ, "PATH": str(directory) + os.pathsep + os.environ["PATH"]},
                text=True, capture_output=True, timeout=10,
            )
            self.assertEqual(result.returncode, 1)
            self.assertTrue(result.stdout == "", "Refused runner emitted unexpected output.")
            self.assertTrue(result.stderr == "Isolated browser fixture setup failed; no browser run was started.\n"
                            '{"phase":"isolation","exception_class":"RuntimeException"}\n'
                            "Isolated browser fixture setup failed.\n",
                            "Runner refusal exposed unexpected fields or values." + prerequisite_note())
            self.assertTrue(observed.is_file(), "The actual runner did not reach its PHP setup process.")
            private_directory = Path(observed.read_text())
            self.assertEqual(private_directory.parent, Path("/tmp"))
            self.assertRegex(private_directory.name, r"^vasey-browser-[A-Za-z0-9]+$")
            self.assertFalse(private_directory.exists(), "The actual runner did not clean up its private installation.")


if __name__ == "__main__":
    unittest.main()
