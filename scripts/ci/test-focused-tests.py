#!/usr/bin/env python3
"""Adversarial checks for the focused selector, command boundary and evidence."""
from __future__ import annotations

from contextlib import redirect_stdout
from io import StringIO
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("focused_tests", ROOT / "scripts/ci/focused-tests.py")
focused = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = focused
spec.loader.exec_module(focused)
IDENTITY = {"checked_out_commit": "a" * 40, "checked_out_tree": "b" * 40, "tracked_worktree_clean": True}
CONFIG = '''<phpunit bootstrap="vendor/autoload.php" colors="true"><testsuites>
<testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
<testsuite name="Feature"><directory>tests/Feature</directory><exclude>tests/Feature/Old.php</exclude></testsuite>
</testsuites><source><include><directory>app</directory></include></source>
<php><env name="DB_CONNECTION" value="sqlite"/><env name="MAIL_MAILER" value="array"/></php>
<extensions><bootstrap class="ExampleExtension"/></extensions></phpunit>'''


def fixture(root, files):
    for name in files:
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("synthetic selected test definition\n")
    (root / "phpunit.xml").write_text(CONFIG)


def junit(root, content='<testsuites><testsuite><testcase name="one" assertions="3"/></testsuite></testsuites>'):
    (root / focused.RESULTS).write_text(content)


class SelectionTests(unittest.TestCase):
    def test_every_enum_maps_to_nonempty_bounded_unique_fixed_files(self):
        for suite in focused.SUITES:
            selected = focused.selection({"FOCUSED_SUITE": suite, "FOCUSED_ENGINE": "sqlite"})
            self.assertTrue(selected.files)
            self.assertLessEqual(len(selected.files), focused.MAX_FILES)
            self.assertEqual(len(selected.files), len(set(selected.files)))

    def test_unknown_empty_whitespace_and_shell_like_selections_fail_closed(self):
        for suite in ("", "all", "../tests", " unit", "unit; true", "unit$(id)", "media\nkind=php"):
            with self.subTest(suite=suite), self.assertRaises(focused.FocusedError):
                focused.selection({"FOCUSED_SUITE": suite, "FOCUSED_ENGINE": "sqlite"})
        for engine in ("", "none", "MYSQL", "sqlite --filter=all", "mysql\nkind=browser"):
            with self.subTest(engine=engine), self.assertRaises(focused.FocusedError):
                focused.selection({"FOCUSED_SUITE": "unit", "FOCUSED_ENGINE": engine})

    def test_preset_authoring_is_in_operator_and_browser_feedback(self):
        for target in ("tests/Feature/TrackMetadataPresetsTest.php", "tests/Feature/TrackMetadataPresetsConcurrencyTest.php", "tests/Feature/TrackMetadataPresetsMigrationTest.php"):
            self.assertIn(target, focused.PHP_TARGETS["operator"])
        self.assertIn("tests/browser/track-metadata-presets.spec.ts", focused.BROWSER_TARGETS)

    def test_bulk_metadata_is_in_operator_and_browser_feedback(self):
        for target in ("tests/Feature/BulkUpdateTrackMetadataTest.php", "tests/Feature/BulkUpdateTrackMetadataConcurrencyTest.php"):
            self.assertIn(target, focused.PHP_TARGETS["operator"])
        self.assertIn("tests/browser/bulk-track-metadata.spec.ts", focused.BROWSER_TARGETS)

    def test_private_track_review_is_in_operator_and_browser_feedback(self):
        for target in ("tests/Feature/PrivateTrackReviewTest.php", "tests/Feature/PrivateTrackReviewPrivacyTest.php"):
            self.assertIn(target, focused.PHP_TARGETS["operator"])
        self.assertIn("tests/browser/private-track-review.spec.ts", focused.BROWSER_TARGETS)

    def test_reviewed_track_publication_is_in_operator_and_browser_feedback(self):
        for target in ("tests/Feature/TrackPublicationGuardTest.php", "tests/Feature/TrackPublicationGuardMigrationTest.php", "tests/Feature/TrackPublicationGuardConcurrencyTest.php"):
            self.assertIn(target, focused.PHP_TARGETS["operator"])
        self.assertIn("tests/browser/track-publication-guard.spec.ts", focused.BROWSER_TARGETS)

    def test_read_only_publication_manifests_are_in_operator_feedback(self):
        for target in ("tests/Feature/TrackPublicationManifestTest.php", "tests/Feature/TrackPublicationManifestConcurrencyTest.php"):
            self.assertIn(target, focused.PHP_TARGETS["operator"])

    def test_publication_feedback_keeps_operator_bounded_and_covers_apply_and_editor(self):
        for target in ("tests/Feature/TrackPublicationApplyTest.php", "tests/Feature/TrackPublicationApplyConcurrencyTest.php",
                       "tests/Feature/TrackPublicationManifestEditorTest.php"):
            self.assertIn(target, focused.PHP_TARGETS["publication"])
        self.assertIn("tests/Feature/TrackPublicationManifestEditorTest.php", focused.PHP_TARGETS["operator"])
        self.assertIn("publication", (ROOT / ".github/workflows/focused.yml").read_text())

    def test_mysql_only_reaches_php_modes_and_browser_reports_isolated_sqlite(self):
        for suite in focused.PHP_TARGETS:
            self.assertEqual(focused.selection({"FOCUSED_SUITE": suite, "FOCUSED_ENGINE": "mysql"}).engine, "mysql")
        for suite in ("frontend", "browser"):
            with self.assertRaises(focused.FocusedError):
                focused.selection({"FOCUSED_SUITE": suite, "FOCUSED_ENGINE": "mysql"})
        self.assertEqual(focused.selection({"FOCUSED_SUITE": "frontend", "FOCUSED_ENGINE": "sqlite"}).engine, "none")

    def test_customer_and_product_feedback_preserve_the_fixed_cap_and_native_coverage(self):
        self.assertEqual(focused.MAX_FILES, 32)
        for target in ("CustomerAccountAccessTest", "CustomerAccountCommerceTest", "CustomerAccountConcurrencyTest",
                       "CustomerAccountMigrationTest", "CustomerSessionHttpTest", "OwnedTestOrderHistoryTest",
                       "TestOwnerDeliveryHttpTest", "TestOwnerDeliveryProjectionTest"):
            self.assertIn("tests/Feature/" + target + ".php", focused.PHP_TARGETS["customer"])
        for target in ("ProductDraftTest", "ProductDraftEditorTest", "ProductDraftMigrationTest", "ProductDraftConcurrencyTest"):
            self.assertIn("tests/Feature/" + target + ".php", focused.PHP_TARGETS["seller"])
        self.assertIn("tests/frontend/customer-account.test.tsx", focused.FRONTEND_TARGETS)
        self.assertIn("tests/browser/customer-account.spec.ts", focused.BROWSER_TARGETS)

    def test_workflow_dispatch_choices_exactly_match_reviewed_suite_enums(self):
        workflow = (ROOT / ".github/workflows/focused.yml").read_text()
        choices = workflow.split("options: [", 1)[1].split("]", 1)[0].split(", ")
        self.assertEqual(set(focused.SUITES), set(choices))
        self.assertEqual(len(focused.SUITES), len(choices))

    def test_unpaid_release_feedback_keeps_native_races_and_browser_journey_selected(self):
        for target in ("TestUnpaidReleaseTest", "TestUnpaidReleaseMigrationTest",
                       "TestUnpaidReleaseConcurrencyTest", "TestUnpaidOrderResourceTest"):
            self.assertIn("tests/Feature/" + target + ".php", focused.PHP_TARGETS["financial"])
        self.assertIn("tests/browser/test-unpaid-release.spec.ts", focused.BROWSER_TARGETS)
        policy = json.loads((ROOT / "scripts/ci/database-sqlite-skips.json").read_text())
        race_class = "Tests\\Feature\\TestUnpaidReleaseConcurrencyTest"
        self.assertEqual({row[1] for row in policy["methods"] if row[0] == race_class}, {
            "test_exact_order_fence_preserves_money_and_the_winning_resource_disposition",
            "test_release_serializes_new_capacity_users_without_reviving_old_bindings",
        })

    def test_new_sqlite_exceptions_are_exact_native_method_identities(self):
        policy = json.loads((ROOT / "scripts/ci/database-sqlite-skips.json").read_text())
        new_classes = {"Tests\\Feature\\CustomerAccountConcurrencyTest", "Tests\\Feature\\ProductDraftConcurrencyTest"}
        actual = {tuple(row) for row in policy["methods"] if row[0] in new_classes}
        self.assertEqual(actual, {
            ("Tests\\Feature\\CustomerAccountConcurrencyTest", "test_current_withdrawal_wins_exact_user_fence_before_every_customer_entrypoint"),
            ("Tests\\Feature\\ProductDraftConcurrencyTest", "test_native_product_wait_serializes_competing_draft_changes"),
            ("Tests\\Feature\\ProductDraftConcurrencyTest", "test_required_mfa_withdrawn_during_actor_wait_refuses_product_operation"),
            ("Tests\\Feature\\ProductDraftConcurrencyTest", "test_source_lock_captures_metadata_committed_while_creator_waits"),
        })

    def test_related_source_is_covered_without_putting_ready_fixtures_in_the_default_browser_set(self):
        for target in ("tests/Feature/PublicCatalogRelatedLinksTest.php", "tests/Feature/SiteRelatedTrackContentTest.php",
                       "tests/Feature/SiteRelatedTrackDamageTest.php", "tests/Feature/SiteRelatedTrackEditorTest.php",
                       "tests/Feature/SiteRelatedTrackHttpTest.php", "tests/Feature/SiteRelatedTrackImageMigrationTest.php",
                       "tests/Feature/SiteContentConcurrencyTest.php"):
            self.assertIn(target, focused.PHP_TARGETS["seller"])
        self.assertIn("tests/Unit/RelatedTrackBrowserFixtureGuardTest.php", focused.PHP_TARGETS["unit"])
        self.assertIn("tests/frontend/editorial-related-tracks.test.tsx", focused.FRONTEND_TARGETS)
        self.assertFalse(any("related" in target for target in focused.BROWSER_TARGETS))

    def test_missing_and_escaping_targets_cannot_silently_reduce_selection(self):
        with tempfile.TemporaryDirectory() as temp, tempfile.TemporaryDirectory() as external:
            root = Path(temp)
            selected = focused.Selection("unit", "sqlite", "php", ("tests/Unit/One.php",))
            with self.assertRaises(focused.FocusedError):
                focused.validate_files(root, selected)
            fixture(root, selected.files)
            focused.validate_files(root, selected)
            target = root / selected.files[0]
            target.unlink()
            outside = Path(external) / "outside.php"
            outside.write_text("not in the checkout")
            target.symlink_to(outside)
            with self.assertRaises(focused.FocusedError):
                focused.validate_files(root, selected)

    def test_empty_duplicate_oversized_and_traversal_lists_are_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            for files in ((), ("tests/Unit/One.php",) * 2, tuple(f"tests/Unit/T{i}.php" for i in range(33)),
                          ("tests/../outside.php",), ("/tests/Unit/One.php",), ("app/Test.php",)):
                with self.subTest(files=files), self.assertRaises(focused.FocusedError):
                    focused.validate_files(Path(temp), focused.Selection("unit", "sqlite", "php", files))

    def test_php_configuration_selects_exact_files_without_altering_other_contracts(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            selected = focused.Selection("media", "mysql", "php", ("tests/Unit/One.php", "tests/Feature/Two.php"))
            fixture(root, selected.files)
            original = (root / "phpunit.xml").read_bytes()
            output = focused.php_configuration(root, selected)
            self.assertEqual(output.parent, root)
            self.assertEqual((root / "phpunit.xml").read_bytes(), original)
            parsed = ET.parse(output).getroot()
            self.assertEqual(parsed.attrib, ET.fromstring(CONFIG).attrib)
            for name in ("php", "source", "extensions"):
                self.assertEqual(ET.tostring(parsed.find(name)), ET.tostring(ET.fromstring(CONFIG).find(name)))
            self.assertEqual([node.text for node in parsed.findall("testsuites/testsuite/file")], list(selected.files))
            self.assertEqual(parsed.findall("testsuites/testsuite/directory"), [])
            self.assertEqual(parsed.findall("testsuites/testsuite/exclude"), [])

    def test_php_config_without_testsuites_fails_instead_of_using_full_config(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "phpunit.xml").write_text('<phpunit bootstrap="vendor/autoload.php"/>')
            with self.assertRaises(focused.FocusedError):
                focused.php_configuration(root, focused.selection({"FOCUSED_SUITE": "unit", "FOCUSED_ENGINE": "sqlite"}))

    def test_commands_keep_warning_empty_suite_and_browser_wrapper_boundaries(self):
        php = focused.commands(focused.selection({"FOCUSED_SUITE": "commerce", "FOCUSED_ENGINE": "mysql"}))[0]
        self.assertIn("--fail-on-phpunit-warning", php)
        self.assertIn("--fail-on-empty-test-suite", php)
        self.assertNotIn("--filter", " ".join(php))
        browser = focused.commands(focused.selection({"FOCUSED_SUITE": "browser", "FOCUSED_ENGINE": "sqlite"}))[0]
        self.assertEqual(browser[:4], ["npm", "run", "test:browser", "--"])
        self.assertEqual(browser[4:-1], list(focused.BROWSER_TARGETS))
        frontend = focused.commands(focused.selection({"FOCUSED_SUITE": "frontend", "FOCUSED_ENGINE": "sqlite"}))
        self.assertEqual(frontend[0][3:-3], list(focused.FRONTEND_TARGETS))
        self.assertIn(["npm", "run", "build"], frontend)
        self.assertIn(["python3", "scripts/ci/scan-client-bundle.py"], frontend)

    def test_php_runner_overrides_production_database_mail_and_queue_environment(self):
        selected = focused.selection({"FOCUSED_SUITE": "media", "FOCUSED_ENGINE": "sqlite"})
        env = focused.runner_environment(selected, {"APP_ENV": "production", "DB_URL": "production-url", "DB_CONNECTION": "mysql",
                                                  "MAIL_MAILER": "smtp", "QUEUE_CONNECTION": "production", "SESSION_DRIVER": "database"})
        self.assertEqual((env["APP_ENV"], env["DB_CONNECTION"], env["DB_DATABASE"], env["DB_URL"]), ("testing", "sqlite", ":memory:", ""))
        self.assertEqual((env["MAIL_MAILER"], env["QUEUE_CONNECTION"], env["SESSION_DRIVER"]), ("array", "sync", "array"))

    def test_junit_counts_preserve_skip_failure_error_and_assertion_evidence(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            junit(root, '<testsuites><testsuite><testcase assertions="4"/><testsuite><testcase assertions="2"><skipped/></testcase><testcase assertions="1"><failure/></testcase><testcase><error/></testcase></testsuite></testsuite></testsuites>')
            self.assertEqual(focused.junit_counts(root / focused.RESULTS), {"reported_cases": 4, "executed_cases": 3, "skipped_cases": 1,
                                                                          "failures": 1, "errors": 1, "assertions": 7})

    def test_empty_unknown_or_invalid_junit_never_proves_success(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            for report in ("<testsuites/>", "<anything><testcase/></anything>", '<testsuite><testcase assertions="NaN"/></testsuite>', '<testsuite><testcase assertions="-1"/></testsuite>'):
                junit(root, report)
                with self.subTest(report=report), self.assertRaises(focused.FocusedError):
                    focused.junit_counts(root / focused.RESULTS)

    def test_event_sha_mismatch_or_unrecordable_checkout_fails(self):
        values = [subprocess.CompletedProcess([], 0, "a" * 40 + "\n", ""), subprocess.CompletedProcess([], 0, "b" * 40 + "\n", ""),
                  subprocess.CompletedProcess([], 0, "", "")]
        with patch.object(focused.subprocess, "run", side_effect=values):
            self.assertEqual(focused.source_identity(ROOT, {"GITHUB_SHA": "a" * 40}), IDENTITY)
        with patch.object(focused.subprocess, "run", side_effect=values), self.assertRaises(focused.FocusedError):
            focused.source_identity(ROOT, {"GITHUB_SHA": "c" * 40})
        with patch.object(focused.subprocess, "run", return_value=subprocess.CompletedProcess([], 1, "", "not a checkout")), self.assertRaises(focused.FocusedError):
            focused.source_identity(ROOT, {})

    def test_tracked_staged_or_worktree_changes_prevent_clean_source_claim(self):
        for status in (1, 128):
            values = [subprocess.CompletedProcess([], 0, "a" * 40 + "\n", ""), subprocess.CompletedProcess([], 0, "b" * 40 + "\n", ""),
                      subprocess.CompletedProcess([], status, "", "")]
            with patch.object(focused.subprocess, "run", side_effect=values), self.assertRaises(focused.FocusedError):
                focused.source_identity(ROOT, {})


class ExecutionTests(unittest.TestCase):
    def run_main(self, root, runner, args=()):
        with patch.object(focused, "__file__", str(root / "scripts/ci/focused-tests.py")), patch.object(focused, "source_identity", return_value=IDENTITY), \
             patch.dict(os.environ, {"FOCUSED_SUITE": "unit", "FOCUSED_ENGINE": "sqlite"}, clear=True), \
             patch.object(focused.subprocess, "run", side_effect=runner), redirect_stdout(StringIO()):
            return focused.main(list(args))

    def root_fixture(self, root):
        fixture(root, focused.PHP_TARGETS["unit"])

    def test_plan_never_executes_tests_and_has_no_success_claim(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self.root_fixture(root)
            def unexpected(*args, **kwargs):
                raise AssertionError("Plan must not run a test command")
            self.assertEqual(self.run_main(root, unexpected, ("--plan",)), 0)
            data = json.loads((root / focused.EVIDENCE).read_text())
            self.assertEqual(data["status"], "planned")
            self.assertEqual(data["purpose"], "focused-feedback-not-merge-acceptance")
            self.assertNotIn("test_counts", data)

    def test_success_requires_fresh_nonempty_passing_report_and_argv_execution(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self.root_fixture(root)
            junit(root, "<testsuites><testcase name='STALE'/></testsuites>")
            def runner(command, **kwargs):
                self.assertIsInstance(command, list)
                self.assertFalse(kwargs["shell"])
                self.assertFalse((root / focused.RESULTS).exists())
                junit(root)
                return subprocess.CompletedProcess(command, 0)
            self.assertEqual(self.run_main(root, runner), 0)
            data = json.loads((root / focused.EVIDENCE).read_text())
            self.assertEqual(data["status"], "passed")
            self.assertEqual(data["test_counts"]["executed_cases"], 1)
            self.assertEqual(data["command_results"][0]["exit_code"], 0)

    def test_successful_exit_without_fresh_report_or_with_only_skips_is_failure(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self.root_fixture(root)
            self.assertEqual(self.run_main(root, lambda command, **kwargs: subprocess.CompletedProcess(command, 0)), 1)
            self.assertEqual(json.loads((root / focused.EVIDENCE).read_text())["status"], "failed")
            def skipped(command, **kwargs):
                junit(root, '<testsuites><testcase><skipped/></testcase></testsuites>')
                return subprocess.CompletedProcess(command, 0)
            self.assertEqual(self.run_main(root, skipped), 1)

    def test_process_failure_keeps_exit_status_and_does_not_become_subset_pass(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self.root_fixture(root)
            def failed(command, **kwargs):
                junit(root, '<testsuites><testcase><failure/></testcase></testsuites>')
                return subprocess.CompletedProcess(command, 7)
            self.assertEqual(self.run_main(root, failed), 7)
            data = json.loads((root / focused.EVIDENCE).read_text())
            self.assertEqual(data["status"], "failed")
            self.assertEqual(data["test_counts"]["failures"], 1)

    def test_timeout_or_missing_executable_records_failure(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self.root_fixture(root)
            for error in (subprocess.TimeoutExpired(["php"], 900), FileNotFoundError("php unavailable")):
                def failed(command, **kwargs):
                    raise error
                self.assertEqual(self.run_main(root, failed), 1)
                self.assertEqual(json.loads((root / focused.EVIDENCE).read_text())["status"], "failed")


class WorkflowBoundaryTests(unittest.TestCase):
    def assert_read_only_workflow(self, text):
        for forbidden in ("secrets.", "pull_request_target", "continue-on-error", "always() &&", "pull_request:"):
            self.assertNotIn(forbidden, text)
        # The required xmlwriter extension is not a mutation permission.
        self.assertNotRegex(text, r"\bwrite(?:-all)?\b")
        self.assertIn("contents: read", text)
        self.assertEqual(text.count("persist-credentials: false"), 4)
        self.assertIn("branches-ignore: [main]", text)

    def test_workflow_has_no_secrets_privileged_event_or_mutation_permissions(self):
        self.assert_read_only_workflow((ROOT / ".github/workflows/focused.yml").read_text())

    def test_xmlwriter_is_allowed_but_write_and_write_all_permissions_are_rejected(self):
        text = (ROOT / ".github/workflows/focused.yml").read_text()
        self.assertIn("xmlwriter", text)
        self.assert_read_only_workflow(text)
        for mutation in (text.replace("contents: read", "contents: write"),
                         text.replace("permissions:\n  contents: read", "permissions: write-all")):
            self.assertNotEqual(text, mutation)
            with self.assertRaisesRegex(AssertionError, "Regex matched"):
                self.assert_read_only_workflow(mutation)

    def test_workflow_never_interpolates_expressions_in_shell_steps(self):
        lines = (ROOT / ".github/workflows/focused.yml").read_text().splitlines()
        in_run, indent = False, 0
        for line in lines:
            stripped = line.lstrip()
            current = len(line) - len(stripped)
            if stripped.startswith("run:") or stripped.startswith("- run:"):
                in_run, indent = True, current
            elif line.strip() and current <= indent:
                in_run = False
            if in_run:
                self.assertNotIn("${{", line)


if __name__ == "__main__":
    unittest.main(verbosity=2)
