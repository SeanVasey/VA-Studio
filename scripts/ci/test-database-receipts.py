#!/usr/bin/env python3
"""Adversarial tests for current-run database receipts and permanently full shadow mode."""

from copy import deepcopy
from datetime import datetime, timedelta, timezone
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET
import zipfile


spec = importlib.util.spec_from_file_location("database_receipts", Path(__file__).with_name("database-receipts.py"))
receipt = importlib.util.module_from_spec(spec)
spec.loader.exec_module(receipt)
ROOT = "/home/runner/work/VASEYAUDIO/VASEYAUDIO"
H = "a" * 64
SKIP = ("Tests\\Feature\\SyntheticMysqlTest", "test_mysql_only")


def listing(rows):
    root = ET.Element(receipt.NS + "testSuite")
    tests, groups = ET.SubElement(root, receipt.NS + "tests"), ET.SubElement(root, receipt.NS + "groups")
    group = ET.SubElement(groups, receipt.NS + "group", name="default")
    for cls, file, method, label in rows:
        suite = ET.SubElement(tests, receipt.NS + "testClass", name=cls, file=ROOT + "/" + file)
        identifier = cls + "::" + method + ("#" + label if label is not None else "")
        ET.SubElement(suite, receipt.NS + "testMethod", id=identifier, name=method)
        ET.SubElement(group, receipt.NS + "test", id=identifier)
    return ET.tostring(root)


ROWS = [("Tests\\Feature\\Synthetic" + str(index) + "Test", f"tests/Feature/Synthetic{index}Test.php", "test_retained", None) for index in range(4)]
ROWS.append((SKIP[0], "tests/Feature/SyntheticMysqlTest.php", SKIP[1], "lock & \"quoted\"\nlabel"))


def results(rows, engine="mysql", declaring_file=None):
    inventory = receipt.inventory(listing(rows), ROOT)
    root = ET.Element("testsuites")
    for (cls, name), identifier in inventory["names"].items():
        skipped = engine == "sqlite" and inventory["methods"][identifier] == SKIP
        suite = ET.SubElement(root, "testsuite", name=cls, file=ROOT + "/" + inventory["cases"][identifier],
                              tests="1", assertions="0" if skipped else "2", errors="0", failures="0", skipped="1" if skipped else "0")
        case = ET.SubElement(suite, "testcase", name=name, **{"class": cls, "file": declaring_file or suite.get("file"),
                                  "assertions": "0" if skipped else "2", "time": "0"})
        if skipped:
            ET.SubElement(case, "skipped")
    return ET.tostring(root)


def source():
    return {"checkout_commit": "a" * 40, "checkout_tree": "b" * 40, "checkout_parents": ["c" * 40, "d" * 40],
            "checkout_root": ROOT, "tracked_worktree_clean": True, "nonignored_untracked_absent": True,
            "event": {"name": "pull_request", "repository": receipt.REPOSITORY, "repository_id": receipt.REPOSITORY_ID,
                      "pr": 123, "base": "c" * 40, "head": "d" * 40, "head_repository_id": receipt.REPOSITORY_ID,
                      "before": None, "after": None, "ref": "refs/pull/123/merge", "forced": None},
            "run_id": 123, "run_attempt": 1, "workflow_sha": "a" * 40,
            "workflow_ref": receipt.REPOSITORY + "/" + receipt.WORKFLOW_PATH + "@refs/pull/123/merge",
            "policy_sha256": {name: H for name in receipt.POLICY_FILES}}


def runtime(engine):
    database = {"version": "3.50.1"} if engine == "sqlite" else {
        "version": "8.4.11", "version_comment": "MySQL", "sql_mode": "STRICT_TRANS_TABLES",
        "character_set_server": "utf8mb4", "collation_server": "utf8mb4_0900_ai_ci", "transaction_isolation": "REPEATABLE-READ",
        "default_storage_engine": "InnoDB", "lower_case_table_names": "0", "innodb_strict_mode": "1", "container_image_id": "sha256:" + H,
    }
    return {"engine": engine, "runner": {key: "synthetic" for key in ("RUNNER_OS", "RUNNER_ARCH", "ImageOS", "ImageVersion")},
            "os_release_sha256": H, "php": {"version": "8.4.26", "integer_size": 8,
                "extensions": {key: "8.4.26" for key in ("mbstring", "intl", "PDO", "pdo_" + engine, "bcmath", "gd", "zip", "curl", "posix")}},
            "tools": {key: {"binary_sha256": H, "version_output_sha256": H} for key in ("php", "composer", "ffmpeg", "qpdf", "pdftocairo", "flock")},
            "database": database, "dependencies": {"package_count": 1, "installed_identity_sha256": H, "composer_lock_sha256": H}}


def evidence(engine, shard, alternate=False):
    prefix = "phpunit-ci-" + engine
    count = receipt.COUNTS[engine]
    assignments = [ROWS[index::count] for index in range(count)]
    if alternate:
        assignments = assignments[1:] + assignments[:1]
    files = {prefix + "-source-tests.xml": listing(ROWS)}
    full = receipt.census(receipt.inventory(files[prefix + "-source-tests.xml"], ROOT))
    shards = []
    for index, rows in enumerate(assignments, 1):
        files[f"{prefix}-{index}.xml"] = b"<phpunit/>"
        files[f"{prefix}-{index}-tests.xml"] = listing(rows)
        shards.append({"index": index, "configuration": f"{prefix}-{index}.xml", "files": sorted({row[1] for row in rows}), "test_cases": len(rows), "weight": 1})
    files[prefix + "-manifest.json"] = receipt.canonical({
        "schema_version": 1, "source_configuration_sha256": H, "source_files": full["files"], "source_test_cases": full["cases"],
        "source_case_identity_sha256": full["case_identity_sha256"], "source_group_identity_sha256": full["group_identity_sha256"], "shards": shards,
        "proof": "every expanded source case, file and group appears exactly once",
        "weighting": {"basis": "measured-milliseconds", "timings_file": f"scripts/ci/phpunit-timings-{engine}.json", "timings_sha256": H,
                      "timings_driver": engine, "timings_source": "synthetic", "untimed_files": []},
    })
    files[f"{prefix}-{shard}-results.xml"] = results(assignments[shard - 1], engine)
    now = datetime.now(timezone.utc)
    initial = {"schema_version": 1, "purpose": "database-runtime-start-not-acceptance", "engine": engine, "shard": shard,
               "source": source(), "runtime": runtime(engine), "started_at": (now - timedelta(seconds=10)).isoformat()}
    files[f"{prefix}-{shard}-start.json"] = receipt.canonical(initial)
    value = {"schema_version": 1, "purpose": "database-job-receipt-not-acceptance", "engine": engine, "shard": shard,
             "source": source(), "runtime": runtime(engine), "runtime_sha256": receipt.digest(receipt.canonical(runtime(engine))),
             "test_step_outcome": "success", "started_at": initial["started_at"], "finished_at": (now - timedelta(seconds=5)).isoformat(),
             **receipt.database_evidence(files, source(), engine, shard, {SKIP})}
    files[f"{prefix}-{shard}-receipt.json"] = receipt.canonical(value)
    return files


def zipped(files):
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as archive:
        for name, raw in files.items():
            archive.writestr(name, raw)
    return output.getvalue()


class FakeGithub:
    def __init__(self):
        self.run = {"id": 123, "run_attempt": 1, "workflow_id": receipt.WORKFLOW_ID, "path": receipt.WORKFLOW_PATH,
                    "event": "pull_request", "head_sha": "d" * 40, "repository": {"id": receipt.REPOSITORY_ID}, "head_repository": {"id": receipt.REPOSITORY_ID}}
        self.jobs, self.artifacts, self.archives = [], [], {}
        now = datetime.now(timezone.utc)
        for engine, count in receipt.COUNTS.items():
            for shard in range(1, count + 1):
                id_ = len(self.jobs) + 1
                self.jobs.append({"id": id_, "name": f"backend-{engine} ({shard}/{count})", "run_id": 123, "run_attempt": 1,
                                  "head_sha": "d" * 40, "status": "completed", "conclusion": "success",
                                  "started_at": (now - timedelta(seconds=20)).isoformat(), "completed_at": now.isoformat()})
                label = "MySQL" if engine == "mysql" else "SQLite"
                self.jobs[-1]["steps"] = [{"name": name, "status": "completed", "conclusion": "success"} for name in
                    (f"Record source and {label} runtime before tests", f"Prove the complete {label} test partition", f"Run all tests assigned to this {label} shard",
                     f"Verify complete {label} shard receipt", "Retain partition and test evidence")]
                self.archives[id_] = zipped(evidence(engine, shard))
                self.artifacts.append({"id": id_, "name": f"backend-{engine}-{shard}-123-1", "expired": False,
                                       "expires_at": (now + timedelta(days=7)).isoformat(),
                                       "digest": "sha256:" + receipt.digest(self.archives[id_]),
                                       "workflow_run": {"id": 123, "repository_id": receipt.REPOSITORY_ID, "head_repository_id": receipt.REPOSITORY_ID, "head_sha": "d" * 40}})

    def get(self, path):
        return deepcopy(self.run)

    def pages(self, path, key):
        return deepcopy(self.jobs if key == "jobs" else self.artifacts)

    def artifact(self, id_):
        return self.archives[id_]

    def replace(self, id_, files):
        self.archives[id_] = zipped(files)
        self.artifacts[id_ - 1]["digest"] = "sha256:" + receipt.digest(self.archives[id_])


class ParsingTests(unittest.TestCase):
    def test_exact_named_numeric_negative_noncanonical_and_overflow_dataset_mapping(self):
        for label, ending in [("0", "#0"), ("-2", "#-2"), ("00", '"00"'), ("9223372036854775808", '"9223372036854775808"'),
                              ('a & "quote"\nline', '"a & "quote"\nline"')]:
            rows = [(ROWS[0][0], ROWS[0][1], "test_dataset", label)]
            expected = receipt.inventory(listing(rows), ROOT)
            self.assertEqual(["test_dataset with data set " + ending], [key[1] for key in expected["names"]])
            self.assertEqual(1, receipt.junit(results(rows), expected, ROOT, "mysql", set())["executed_cases"])

    def test_inherited_method_file_does_not_replace_the_owning_class_file(self):
        expected = receipt.inventory(listing(ROWS[:1]), ROOT)
        self.assertEqual(1, receipt.junit(results(ROWS[:1], declaring_file=ROOT + "/tests/Support/SharedTrait.php"), expected, ROOT, "mysql", set())["executed_cases"])

    def test_missing_duplicate_wrong_owner_failure_bad_assertions_and_nonfinite_time_reject(self):
        expected = receipt.inventory(listing(ROWS[:1]), ROOT)
        for mutation in ("missing", "duplicate", "owner", "failure", "assertions", "time", "counters", "unknown", "nested"):
            root = ET.fromstring(results(ROWS[:1])); case = root[0][0]
            if mutation == "missing": root[0].remove(case)
            elif mutation == "duplicate": root[0].append(deepcopy(case))
            elif mutation == "owner": root[0].set("file", ROOT + "/tests/Feature/Wrong.php")
            elif mutation == "failure": ET.SubElement(case, "failure")
            elif mutation == "assertions": case.set("assertions", "-1")
            elif mutation == "time": case.set("time", "NaN")
            elif mutation == "counters": root[0].set("tests", "0")
            elif mutation == "unknown": ET.SubElement(case, "incomplete")
            elif mutation == "nested": ET.SubElement(ET.SubElement(case, "system-out"), "testcase")
            with self.subTest(mutation=mutation), self.assertRaises(receipt.ReceiptError):
                receipt.junit(ET.tostring(root), expected, ROOT, "mysql", set())

    def test_exact_reviewed_sqlite_skips_and_no_mysql_skip(self):
        expected = receipt.inventory(listing(ROWS), ROOT)
        self.assertEqual(1, receipt.junit(results(ROWS, "sqlite"), expected, ROOT, "sqlite", {SKIP})["skipped_cases"])
        for engine, policy in [("mysql", {SKIP}), ("sqlite", set())]:
            with self.assertRaises(receipt.ReceiptError):
                receipt.junit(results(ROWS, "sqlite"), expected, ROOT, engine, policy)
        with self.assertRaises(receipt.ReceiptError):
            receipt.junit(results(ROWS), expected, ROOT, "sqlite", {SKIP})
        with self.assertRaises(receipt.ReceiptError):
            receipt.database_evidence(evidence("mysql", 1), source(), "mysql", 1, {SKIP, ("RemovedTest", "test_removed")})

    def test_dtd_entities_duplicate_json_and_nonfinite_json_reject(self):
        for raw in (b'<!DOCTYPE a [<!ENTITY x "secret">]><a>&x;</a>', b'<!ENTITY x "secret"><a/>', '<!DOCTYPE a [<!ENTITY x "secret">]><a>&x;</a>'.encode('utf-16')):
            with self.assertRaises(receipt.ReceiptError): receipt.xml(raw)
        for raw in (b'{"id":1,"id":2}', b'{"id":NaN}'):
            with self.assertRaises(receipt.ReceiptError): receipt.json_data(raw)
        expected = receipt.inventory(listing(ROWS[:1]), ROOT)
        nested = results(ROWS[:1])
        for _ in range(receipt.MAX_XML_DEPTH):
            nested = b'<testsuite tests="1" assertions="2" errors="0" failures="0" skipped="0">' + nested + b'</testsuite>'
        with self.assertRaises(receipt.ReceiptError): receipt.junit(nested, expected, ROOT, "mysql", set())
        with patch.object(receipt, "MAX_XML_NODES", 1), self.assertRaises(receipt.ReceiptError):
            receipt.xml(results(ROWS[:1]))

    def test_archive_refuses_extra_duplicate_traversal_symlink_and_size(self):
        expected = {"evidence.json"}
        self.assertEqual({"evidence.json": b"{}"}, receipt.archive(zipped({"evidence.json": b"{}"}), expected))
        for name in ("../evidence.json", "folder/evidence.json", "evidence.json\\child"):
            with self.assertRaises(receipt.ReceiptError): receipt.archive(zipped({name: b"{}"}), {name})
        with self.assertRaises(receipt.ReceiptError): receipt.archive(zipped({"evidence.json": b"{}", "extra": b"x"}), expected)
        output = io.BytesIO()
        with zipfile.ZipFile(output, "w") as archive:
            info = zipfile.ZipInfo("evidence.json"); info.external_attr = 0o120777 << 16
            archive.writestr(info, "target")
        with self.assertRaises(receipt.ReceiptError): receipt.archive(output.getvalue(), expected)
        with patch.object(receipt, "MAX_FILE", 1), self.assertRaises(receipt.ReceiptError):
            receipt.archive(zipped({"evidence.json": b"{}"}), expected)

    def test_download_origin_rejects_http_credentials_localhost_and_suffix_confusion(self):
        self.assertTrue(receipt.download_host("https://productionresultssa1.blob.core.windows.net/path?sig=synthetic"))
        for url in ("http://productionresultssa1.blob.core.windows.net/path", "https://user:token@productionresultssa1.blob.core.windows.net/path",
                    "https://localhost/path", "https://127.0.0.1/path", "https://productionresultssa1.blob.core.windows.net.evil.test/path",
                    "https://productionresultssa1.blob.core.windows.net:444/path"):
            self.assertFalse(receipt.download_host(url))


class CollectorTests(unittest.TestCase):
    def collect(self, api):
        with patch.object(receipt, "source_identity", return_value=source()), patch.object(receipt, "sqlite_skip_pairs", return_value={SKIP}), \
                patch.object(receipt, "validate_discovered_files"), patch.object(receipt, "locked_dependencies", return_value=({}, runtime("mysql")["dependencies"])):
            return receipt.collect(Path(ROOT), {}, api)

    def test_six_complete_current_attempt_receipts_remain_full_and_outer_pending(self):
        value = self.collect(FakeGithub())
        self.assertEqual(6, len(value["database_receipts"]))
        self.assertEqual("full", value["shadow"]["execution_mode"])
        self.assertFalse(value["shadow"]["reuse_enabled"])
        self.assertIn("pending", value["outer_acceptance"])
        self.assertEqual("unknown", value["shadow"]["prior_full_acceptance"])

    def test_missing_duplicate_failed_pending_skipped_and_mixed_attempt_jobs_reject(self):
        for mutation in ("missing", "duplicate", "failure", "pending", "skipped", "attempt", "head"):
            api = FakeGithub()
            if mutation == "missing": api.jobs.pop()
            elif mutation == "duplicate": api.jobs.append(deepcopy(api.jobs[0]))
            elif mutation in {"failure", "skipped"}: api.jobs[0]["conclusion"] = mutation
            elif mutation == "pending": api.jobs[0]["status"] = "in_progress"
            elif mutation == "attempt": api.jobs[0]["run_attempt"] = 2
            elif mutation == "head": api.jobs[0]["head_sha"] = "e" * 40
            with self.subTest(mutation=mutation), self.assertRaises(receipt.ReceiptError): self.collect(api)

    def test_wrong_workflow_event_repository_attempt_or_path_rejects(self):
        for key, value in (("workflow_id", 1), ("event", "workflow_dispatch"), ("repository", {"id": 1}), ("run_attempt", 2), ("path", ".github/workflows/focused.yml")):
            api = FakeGithub(); api.run[key] = value
            with self.subTest(key=key), self.assertRaises(receipt.ReceiptError): self.collect(api)

    def test_missing_duplicate_expired_wrong_digest_and_wrong_run_artifacts_reject(self):
        for mutation in ("missing", "duplicate", "expired", "timestamp", "digest", "run"):
            api = FakeGithub()
            if mutation == "missing": api.artifacts.pop()
            elif mutation == "duplicate": api.artifacts.append(deepcopy(api.artifacts[0]))
            elif mutation == "expired": api.artifacts[0]["expired"] = True
            elif mutation == "timestamp": api.artifacts[0]["expires_at"] = "2000-01-01T00:00:00Z"
            elif mutation == "digest": api.artifacts[0]["digest"] = "sha256:" + "b" * 64
            elif mutation == "run": api.artifacts[0]["workflow_run"]["id"] = 124
            with self.subTest(mutation=mutation), self.assertRaises(receipt.ReceiptError): self.collect(api)

    def test_changed_source_incomplete_runtime_missing_receipt_and_future_time_reject(self):
        for mutation in ("tree", "runtime", "missing", "future", "schema", "dependencies"):
            api = FakeGithub(); files = evidence("mysql", 1); name = "phpunit-ci-mysql-1-receipt.json"
            value = json.loads(files[name])
            if mutation == "tree": value["source"]["checkout_tree"] = "e" * 40
            elif mutation == "runtime": value["runtime"] = {}; value["runtime_sha256"] = receipt.digest(receipt.canonical({}))
            elif mutation == "future": value["finished_at"] = (datetime.now(timezone.utc) + timedelta(days=1)).isoformat()
            elif mutation == "schema": value["extra"] = "not allowed"
            elif mutation == "dependencies":
                value["runtime"]["dependencies"]["installed_identity_sha256"] = "e" * 64
                value["runtime_sha256"] = receipt.digest(receipt.canonical(value["runtime"]))
            if mutation == "missing": files.pop(name)
            else: files[name] = receipt.canonical(value)
            api.replace(1, files)
            with self.subTest(mutation=mutation), self.assertRaises(receipt.ReceiptError): self.collect(api)

    def test_individually_valid_different_partitions_cannot_duplicate_actual_executions(self):
        api = FakeGithub(); api.replace(2, evidence("mysql", 2, alternate=True))
        with self.assertRaises(receipt.ReceiptError): self.collect(api)

    def test_consistent_shard_junit_manifest_owner_remapping_rejects(self):
        api = FakeGithub()
        swapped_files = {ROWS[0][1]: ROWS[1][1], ROWS[1][1]: ROWS[0][1]}
        id_ = 0
        for engine, count in receipt.COUNTS.items():
            prefix = "phpunit-ci-" + engine
            for shard in range(1, count + 1):
                id_ += 1
                files = evidence(engine, shard)
                manifest = json.loads(files[prefix + "-manifest.json"])
                # Keep the authoritative source census unchanged while coherently
                # swapping case owners in every retained shard and result file.
                for index in range(1, count + 1):
                    name = f"{prefix}-{index}-tests.xml"
                    root = ET.fromstring(files[name])
                    for suite in root[0]:
                        owner = suite.get("file").removeprefix(ROOT + "/")
                        suite.set("file", ROOT + "/" + swapped_files.get(owner, owner))
                    files[name] = ET.tostring(root)
                    manifest["shards"][index - 1]["files"] = sorted(set(receipt.inventory(files[name], ROOT)["cases"].values()))
                files[prefix + "-manifest.json"] = receipt.canonical(manifest)
                result_name = f"{prefix}-{shard}-results.xml"
                root = ET.fromstring(files[result_name])
                for suite in root:
                    owner = suite.get("file").removeprefix(ROOT + "/")
                    suite.set("file", ROOT + "/" + swapped_files.get(owner, owner))
                    for case in suite:
                        case.set("file", suite.get("file"))
                files[result_name] = ET.tostring(root)
                receipt_name = f"{prefix}-{shard}-receipt.json"
                value = json.loads(files.pop(receipt_name))
                value["shard_census"] = receipt.census(receipt.inventory(files[f"{prefix}-{shard}-tests.xml"], ROOT))
                value["result_sha256"] = receipt.digest(files[result_name])
                value["file_sha256"] = {name: receipt.digest(raw) for name, raw in files.items()}
                files[receipt_name] = receipt.canonical(value)
                # Matching archive hashes cannot make remapped source owners valid.
                api.replace(id_, files)
        with self.assertRaisesRegex(receipt.ReceiptError, "Shard case ownership differs"):
            self.collect(api)

    def test_pagination_refuses_duplicate_incomplete_capped_and_changing_totals(self):
        for values in ([{"total_count": 2, "jobs": [{"id": 1}]}], [{"total_count": 1000, "jobs": []}],
                       [{"total_count": 2, "jobs": [{"id": 1}, {"id": 1}]}],
                       [{"total_count": 101, "jobs": [{"id": i} for i in range(100)]}, {"total_count": 102, "jobs": [{"id": 100}]}]):
            api = receipt.Github("synthetic-read-token")
            with patch.object(api, "get", side_effect=values), self.assertRaises(receipt.ReceiptError): api.pages("/actions/runs/123/attempts/1/jobs", "jobs")

    def test_signed_host_request_has_no_authorization_header(self):
        api = receipt.Github("synthetic-read-token")
        class Response(io.BytesIO):
            status = 200
            headers = {}
        with patch.object(api.opener, "open", return_value=Response(b"data")) as opened:
            api.request("https://productionresultssa1.blob.core.windows.net/file?sig=synthetic", False, 20)
        self.assertIsNone(opened.call_args.args[0].get_header("Authorization"))


class WorkflowTests(unittest.TestCase):
    def test_workflow_preserves_runtime_conditions_matrices_events_and_no_reuse_output(self):
        workflow = (Path(__file__).parents[2] / ".github/workflows/ci.yml").read_text()
        self.assertEqual(6, workflow.count("if: needs.scope.outputs.mode != 'docs'"))
        for retained in ("branches: [main]", "pull_request:", "workflow_dispatch:", "shard: [1, 2, 3, 4]", "shard: [1, 2]",
                         "--fail-on-phpunit-warning --display-warnings", "npm audit --audit-level=high", "npm run test:browser",
                         "needs: [scope, documentation, backend-quality, backend-mysql, backend-sqlite, frontend, operator-browser, related-browser]",
                         "  related-browser:", "run: bash tests/browser/install-related-scanner.sh", "run: node tests/browser/run-related.mjs"):
            self.assertIn(retained, workflow)
        for forbidden in ("pull_request_target", "workflow_run:", "continue-on-error", "reuse_enabled", "outputs.reuse", "contents: write", "actions: write", "secrets."):
            self.assertNotIn(forbidden, workflow)
        self.assertEqual(1, workflow.count("RECEIPT_GITHUB_TOKEN:"))
        self.assertEqual("full", receipt.shadow_decision()["execution_mode"])


class GitAndDependencyTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name) / "checkout"
        self.root.mkdir()
        self.git("init", "-q")
        self.git("config", "user.email", "ci@example.invalid")
        self.git("config", "user.name", "Synthetic CI")
        (self.root / ".gitignore").write_text("/vendor/\n/phpunit-ci-*\n")
        for name in receipt.POLICY_FILES:
            path = self.root / name; path.parent.mkdir(parents=True, exist_ok=True); path.write_text("synthetic policy\n")
        (self.root / "app").mkdir()
        (self.root / "app/source.php").write_text("<?php // baseline\n")
        self.base = self.commit()
        self.git("checkout", "-qb", "feature")
        (self.root / "app/source.php").write_text("<?php // candidate\n")
        self.head = self.commit()
        self.git("checkout", "--detach", self.base)
        self.git("merge", "--no-ff", "-m", "Synthetic PR checkout", self.head)
        self.merged = self.git("rev-parse", "HEAD")
        self.event = {"repository": {"id": receipt.REPOSITORY_ID, "full_name": receipt.REPOSITORY},
                      "pull_request": {"number": 123, "base": {"sha": self.base, "repo": {"id": receipt.REPOSITORY_ID}},
                                       "head": {"sha": self.head, "repo": {"id": receipt.REPOSITORY_ID}}}}
        self.event_path = Path(self.directory.name) / "event.json"
        self.event_path.write_text(json.dumps(self.event))
        self.env = {"GITHUB_SHA": self.merged, "GITHUB_REPOSITORY": receipt.REPOSITORY, "GITHUB_REPOSITORY_ID": str(receipt.REPOSITORY_ID),
                    "GITHUB_EVENT_PATH": str(self.event_path), "GITHUB_EVENT_NAME": "pull_request", "GITHUB_REF": "refs/pull/123/merge",
                    "GITHUB_WORKFLOW_SHA": self.merged, "GITHUB_WORKFLOW_REF": receipt.REPOSITORY + "/" + receipt.WORKFLOW_PATH + "@refs/pull/123/merge",
                    "GITHUB_RUN_ID": "123", "GITHUB_RUN_ATTEMPT": "1"}

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.root, stderr=subprocess.PIPE).decode().strip()

    def commit(self):
        self.git("add", "--all"); self.git("commit", "-qm", "Synthetic fixture")
        return self.git("rev-parse", "HEAD")

    def test_actual_clean_merge_identity_and_raw_parents_survive_shallow_boundary(self):
        value = receipt.source_identity(self.root, self.env)
        self.assertEqual([self.base, self.head], value["checkout_parents"])
        (self.root / ".git/shallow").write_text(self.merged + "\n")
        self.assertEqual([self.base, self.head], receipt.source_identity(self.root, self.env)["checkout_parents"])

    def test_dirty_checkout_wrong_event_sha_repository_or_parent_order_rejects(self):
        for mutation in ("dirty", "sha", "repository", "parents"):
            env, event = deepcopy(self.env), deepcopy(self.event)
            if mutation == "dirty": (self.root / "app/source.php").write_text("<?php // dirty\n")
            elif mutation == "sha": env["GITHUB_SHA"] = self.head
            elif mutation == "repository": event["repository"]["id"] = 1
            elif mutation == "parents": event["pull_request"]["base"]["sha"], event["pull_request"]["head"]["sha"] = self.head, self.base
            self.event_path.write_text(json.dumps(event))
            with self.subTest(mutation=mutation), self.assertRaises(receipt.ReceiptError): receipt.source_identity(self.root, env)
            self.git("checkout", "--", "app/source.php")

    def test_untracked_policy_cannot_claim_a_git_tree_identity(self):
        name = receipt.POLICY_FILES[-1]
        self.git("rm", "--cached", name)
        self.git("commit", "-qm", "Remove tracked policy")
        after = self.git("rev-parse", "HEAD")
        event = {"repository": self.event["repository"], "after": after, "before": self.merged, "ref": "refs/heads/main", "forced": False}
        self.event_path.write_text(json.dumps(event))
        env = {**self.env, "GITHUB_SHA": after, "GITHUB_EVENT_NAME": "push", "GITHUB_REF": "refs/heads/main",
               "GITHUB_WORKFLOW_SHA": after, "GITHUB_WORKFLOW_REF": receipt.REPOSITORY + "/" + receipt.WORKFLOW_PATH + "@refs/heads/main"}
        with self.assertRaises(receipt.ReceiptError): receipt.source_identity(self.root, env)

    def test_unrelated_executed_workflow_or_event_ref_rejects(self):
        for key, value in (("GITHUB_WORKFLOW_SHA", self.head), ("GITHUB_WORKFLOW_REF", receipt.REPOSITORY + "/" + receipt.WORKFLOW_PATH + "@refs/heads/main"),
                           ("GITHUB_REF", "refs/pull/124/merge")):
            with self.subTest(key=key), self.assertRaises(receipt.ReceiptError):
                receipt.source_identity(self.root, {**self.env, key: value})

    def test_unknown_pr_head_repository_identity_rejects(self):
        for value in (None, False, "1357536326", 0):
            event = deepcopy(self.event); event["pull_request"]["head"]["repo"]["id"] = value
            self.event_path.write_text(json.dumps(event))
            with self.subTest(value=value), self.assertRaises(receipt.ReceiptError): receipt.source_identity(self.root, self.env)

    def test_untracked_source_rejects_but_ignored_generated_outputs_remain_outside_git_proof(self):
        (self.root / "app/runtime.php").write_text("<?php // untracked runtime source\n")
        with self.assertRaises(receipt.ReceiptError): receipt.source_identity(self.root, self.env)
        (self.root / "app/runtime.php").unlink()
        (self.root / "vendor").mkdir()
        (self.root / "vendor/generated.php").write_text("<?php // dependency output\n")
        (self.root / "phpunit-ci-start.json").write_text("{}")
        self.assertTrue(receipt.source_identity(self.root, self.env)["nonignored_untracked_absent"])

    def test_exact_installed_composer_references_and_no_missing_or_extra_packages(self):
        package = {"name": "synthetic/package", "version": "v1.0.0", "source": {"reference": "a" * 40}, "dist": {"reference": "a" * 40}}
        (self.root / "composer.lock").write_text(json.dumps({"packages": [package], "packages-dev": []}))
        path = self.root / "vendor/composer/installed.json"; path.parent.mkdir(parents=True)
        path.write_text(json.dumps({"dev": True, "packages": [package]}))
        self.assertEqual(1, receipt.installed_dependencies(self.root)["package_count"])
        for mutation in ("missing", "reference", "extra", "dev"):
            value = {"dev": True, "packages": [deepcopy(package)]}
            if mutation == "missing": value["packages"] = []
            elif mutation == "reference": value["packages"][0]["source"]["reference"] = "b" * 40
            elif mutation == "extra": value["packages"].append({**package, "name": "synthetic/extra"})
            elif mutation == "dev": value["dev"] = False
            path.write_text(json.dumps(value))
            with self.subTest(mutation=mutation), self.assertRaises(receipt.ReceiptError): receipt.installed_dependencies(self.root)


if __name__ == "__main__":
    unittest.main()
