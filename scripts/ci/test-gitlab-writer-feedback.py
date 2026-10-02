#!/usr/bin/env python3
"""Adversarial checks for native GitLab identity and complete focused evidence."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("gitlab_writer_feedback", ROOT / "scripts/ci/gitlab-writer-feedback.py")
feedback = importlib.util.module_from_spec(spec)
spec.loader.exec_module(feedback)
H = "a" * 40


def environment():
    return {"GITLAB_CI": "true", "CI_PROJECT_ID": "87181037", "CI_PROJECT_PATH": "vaseydev/va-studio",
            "CI_PIPELINE_ID": "12", "CI_JOB_ID": "34", "CI_PIPELINE_SOURCE": "merge_request_event", "CI_COMMIT_SHA": H}


def git(root, args, label):
    if label in {"commit", "tree"}:
        return (H + "\n").encode()
    return b""


def reports(root):
    ns = "{https://xml.phpunit.de/testSuite}"
    discovery = ET.Element(ns + "testSuite")
    tests = ET.SubElement(discovery, ns + "tests")
    ET.SubElement(discovery, ns + "groups")
    junit = ET.Element("testsuites")
    for name in feedback.FILES:
        classname = "Tests\\Feature\\" + name
        filename = str(root / "tests/Feature" / (name + ".php"))
        suite = ET.SubElement(tests, ns + "testClass", name=classname, file=filename)
        ET.SubElement(suite, ns + "testMethod", id=classname + "::test_writer", name="test_writer")
        suite = ET.SubElement(junit, "testsuite", name=classname, file=filename, tests="1", assertions="1", errors="0", failures="0", skipped="0")
        ET.SubElement(suite, "testcase", name="test_writer", attrib={"class": classname, "assertions": "1", "time": "0.1"})
    return discovery, junit


class IdentityTests(unittest.TestCase):
    def test_native_project_pipeline_and_job_are_bound_to_actual_checkout(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / ".gitlab-ci.yml").write_text("synthetic config\n")
            with patch.object(feedback.proof, "run", side_effect=git):
                result = feedback.source(root, environment())
            self.assertEqual(result["provider"], "gitlab")
            self.assertEqual(result["job_id"], 34)
            self.assertEqual(result["pipeline_id"], 12)
            self.assertEqual(result["commit"], H)

    def test_wrong_project_event_sha_configuration_or_numeric_identity_is_refused(self):
        cases = [("GITLAB_CI", "false"), ("CI_PROJECT_ID", "1"), ("CI_PROJECT_PATH", "other/project"),
                 ("CI_COMMIT_SHA", "b" * 40), ("CI_CONFIG_PATH", "other.yml"), ("CI_PIPELINE_SOURCE", "schedule"),
                 ("CI_PIPELINE_ID", "0"), ("CI_JOB_ID", "-1"), ("CI_JOB_ID", " 34"), ("CI_JOB_ID", "34;true")]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / ".gitlab-ci.yml").write_text("synthetic config\n")
            for key, value in cases:
                with self.subTest(key=key, value=value), patch.object(feedback.proof, "run", side_effect=git), self.assertRaises(feedback.proof.ReceiptError):
                    feedback.source(root, environment() | {key: value})

    def test_github_variables_do_not_impersonate_gitlab(self):
        with self.assertRaises(feedback.proof.ReceiptError):
            feedback.source(ROOT, {"GITHUB_SHA": H, "GITHUB_REPOSITORY": "VASEYDEV/VASEYAUDIO"})

    def test_untracked_source_prevents_clean_source_claim(self):
        def untracked(root, args, label):
            return b"unreviewed.php\0" if label == "untracked source" else git(root, args, label)
        with patch.object(feedback.proof, "run", side_effect=untracked), self.assertRaises(feedback.proof.ReceiptError):
            feedback.source(ROOT, environment())


class CensusTests(unittest.TestCase):
    def test_every_selected_class_and_case_is_executed(self):
        discovery, junit = reports(ROOT)
        with patch.object(feedback.proof, "sqlite_skip_pairs", return_value=set()):
            result = feedback.result(ROOT, "mysql", ET.tostring(discovery), ET.tostring(junit))
        self.assertEqual(result["executed_cases"], len(feedback.FILES))
        self.assertEqual(result["skipped_cases"], 0)

    def test_missing_class_unknown_duplicate_failure_or_skipped_case_is_refused(self):
        for mutation in ("missing-class", "missing-case", "unknown", "duplicate", "failure", "skipped", "counter"):
            discovery, junit = reports(ROOT)
            case = junit[0][0]
            if mutation == "missing-class": discovery[0].remove(discovery[0][0])
            elif mutation == "missing-case": junit[0].remove(case)
            elif mutation == "unknown": case.set("name", "test_unknown")
            elif mutation == "duplicate": junit[0].append(case)
            elif mutation == "failure": ET.SubElement(case, "failure")
            elif mutation == "skipped":
                case.set("assertions", "0")
                ET.SubElement(case, "skipped")
            else: junit[0].set("assertions", "99")
            with self.subTest(mutation=mutation), patch.object(feedback.proof, "sqlite_skip_pairs", return_value=set()), self.assertRaises(feedback.proof.ReceiptError):
                feedback.result(ROOT, "mysql", ET.tostring(discovery), ET.tostring(junit))

    def test_unreviewed_sqlite_skip_is_refused(self):
        discovery, junit = reports(ROOT)
        junit[0][0].set("assertions", "0")
        ET.SubElement(junit[0][0], "skipped")
        junit[0].set("assertions", "0")
        junit[0].set("skipped", "1")
        with patch.object(feedback.proof, "sqlite_skip_pairs", return_value=set()), self.assertRaises(feedback.proof.ReceiptError):
            feedback.result(ROOT, "sqlite", ET.tostring(discovery), ET.tostring(junit))


if __name__ == "__main__":
    unittest.main()
