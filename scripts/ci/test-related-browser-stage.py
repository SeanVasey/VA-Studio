#!/usr/bin/env python3
"""Execute real isolated PHP bootstrap and startup guards, not browser/scanner acceptance."""

import base64
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import sqlite3
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class RelatedBrowserStageSafeguards(unittest.TestCase):
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


class BrowserBootstrapFixtures(unittest.TestCase):
    PROJECTS = {"chromium-desktop": "CHROMIUM", "webkit-mobile": "WEBKIT"}

    def bootstrap(self, stage=None, marker=None, *, accounts=True):
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
        result = subprocess.run(
            ["/usr/bin/timeout", "--signal=TERM", "--kill-after=15s", "600s", "php", "tests/browser/bootstrap.php"], cwd=ROOT, env=env,
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
        self.assertEqual(result.returncode, 0, "The native isolated PHP bootstrap failed.")
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
                self.assertTrue(result.stderr == "Isolated browser fixture setup failed; no browser run was started.\n",
                                "Refused bootstrap did not retain its fixed failure response.")
                self.assertEqual((directory / "database.sqlite").read_bytes(), b"")
                self.assertFalse((directory / "fixtures.json").exists())
                self.assertFalse((directory / "customer-fixtures.json").exists())


if __name__ == "__main__":
    unittest.main()
