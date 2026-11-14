"""Verify retained, completed receipts and rebuild this packet's derived manifests.

Run from any directory with Python 3; this script performs no application or
network operation. README/review/ledgers are separately owned and not digested.
"""
from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

EVIDENCE = Path(__file__).resolve().parent
PACKET = EVIDENCE.parent


def save(path, data):
    path.write_text(json.dumps(data, indent=2) + "\n")


def totals(element):
    return {key: int(element.attrib[key]) for key in
            ("tests", "assertions", "errors", "failures", "skipped")}


registry = json.loads((EVIDENCE / "runs.json").read_text())
runs = []
for run in registry["runs"]:
    xml = EVIDENCE / run["xml"]
    exit_path = EVIDENCE / run["exit"]
    assert xml.is_file() and exit_path.is_file(), run["id"]
    document = ET.parse(xml).getroot()
    suite = document.find("testsuite") if document.tag == "testsuites" else document
    cases = []
    for case in suite.iter("testcase"):
        status = next((name for name in ("error", "failure", "skipped")
                       if case.find(name) is not None), "passed")
        cases.append({"identity": case.attrib.get("class", case.attrib["classname"])
                      + "::" + case.attrib["name"],
                      "assertions": int(case.attrib.get("assertions", "0")),
                      "status": status})
    observed = totals(suite)
    computed = {"tests": len(cases),
                "assertions": sum(c["assertions"] for c in cases),
                "errors": sum(c["status"] == "error" for c in cases),
                "failures": sum(c["status"] == "failure" for c in cases),
                "skipped": sum(c["status"] == "skipped" for c in cases)}
    assert computed == observed, (run["id"], observed, computed)
    assert len({c["identity"] for c in cases}) == len(cases), run["id"]
    for name in run["source_receipts"] + run["command_receipts"]:
        assert (EVIDENCE / name).exists(), (run["id"], name)
    runs.append({**run, "observed_exit": int(exit_path.read_text().strip()),
                 **observed, "junit_seconds": suite.attrib["time"], "cases": cases})

save(EVIDENCE / "junit-summary.json", {
    "scope": "Every completed retained JUnit record, including failures. Counts are per run; overlapping runs must not be summed as unique coverage.",
    "run_count": len(runs), "runs": runs,
})

# Explicit historical precedence, oldest component to latest relevant result.
# These results span documented sources; this is not one integrated suite run.
sqlite_precedence = [
    "policy/sqlite-policy-final",
    "integration-and-adapters/license-binding-unit",
    "ledger/ledger-focused",
    "integration-and-adapters/entry-sqlite-v2",
    "integration-and-adapters/eligibility-review-final",
    "integration-and-adapters/award-replay-integration",
    "integration-and-adapters/sqlite-integrated-lifecycle",
]
by_id = {run["id"]: run for run in runs}
latest = {}
overridden = []
for run_id in sqlite_precedence:
    run = by_id[run_id]
    assert run["driver"] == "sqlite" and run["observed_exit"] == 0
    assert run["errors"] == 0 and run["failures"] == 0
    for case in run["cases"]:
        if case["identity"] in latest:
            overridden.append({"identity": case["identity"],
                               "old_run": latest[case["identity"]]["run"],
                               "new_run": run_id})
        latest[case["identity"]] = {**case, "run": run_id}
groups = []
for run_id in sqlite_precedence:
    selected = [case for case in latest.values() if case["run"] == run_id]
    groups.append({"run": run_id, "retained_unique_tests": len(selected),
                   "assertions": sum(case["assertions"] for case in selected),
                   "passed": sum(case["status"] == "passed" for case in selected),
                   "skipped": sum(case["status"] == "skipped" for case in selected)})
save(EVIDENCE / "sqlite-unique-case-summary.json", {
    "scope": "Deduplicated latest successful relevant component results at documented mixed sources; not a single full or final-source suite execution. Native-only race file was not executed on SQLite and contributes no cases or skips here.",
    "precedence": sqlite_precedence,
    "tests": len(latest),
    "assertions": sum(case["assertions"] for case in latest.values()),
    "passed": sum(case["status"] == "passed" for case in latest.values()),
    "skipped": sum(case["status"] == "skipped" for case in latest.values()),
    "errors": 0, "failures": 0,
    "contributing_runs": groups, "overridden_cases": overridden,
    "cases": sorted(latest.values(), key=lambda case: case["identity"]),
})

# Report completed native leaves without relabeling an errored/failed invocation.
# In-progress output never enters runs.json or this reconciliation.
native_runs = [run for run in runs if run["driver"] == "mysql"]
native_history = {}
for run in native_runs:
    for case in run["cases"]:
        native_history.setdefault(case["identity"], []).append({
            **case, "run": run["id"], "invocation_exit": run["observed_exit"],
            "source_description": run["source_description"],
        })
native_latest = [history[-1] for history in native_history.values()]
save(EVIDENCE / "native-case-reconciliation.json", {
    "scope": "Latest completed native case results with full invocation status and prior history. Passing leaves in failed/errored invocations are identified individually; those invocations are never relabeled green. Pending selections are excluded, and this reconciliation alone is not final acceptance.",
    "pending_selections": registry.get("pending_native_selections", []),
    "latest_completed_cases": len(native_latest),
    "latest_status_counts": {status: sum(c["status"] == status for c in native_latest)
                             for status in ("passed", "failure", "error", "skipped")},
    "assertions_in_latest_passed_cases": sum(c["assertions"] for c in native_latest
                                             if c["status"] == "passed"),
    "nonzero_invocations": [{"run": run["id"], "exit": run["observed_exit"],
                              **{key: run[key] for key in
                                 ("tests", "assertions", "errors", "failures", "skipped")}}
                             for run in native_runs if run["observed_exit"] != 0],
    "case_histories": [{"identity": identity, "results": history}
                       for identity, history in sorted(native_history.items())],
})

worker_summaries = []
for run in native_runs:
    for receipt in run.get("worker_receipts", []):
        data = json.loads((EVIDENCE / receipt).read_text())
        workers = []
        for worker in data["workers"]:
            result = worker.get("result")
            if result is not None:
                assert json.loads(worker["stdout"]) == result, receipt
                assert result["pid"] == worker["ready"]["pid"], receipt
                assert result["connection_id"] == worker["ready"]["connection_id"], receipt
            workers.append({
                "pid": worker.get("spawned_pid"), "exit": worker.get("exit"),
                "stopped_by_teardown": worker.get("stopped_by_teardown"),
                "reason": result.get("reason") if result else None,
                "status": result["value"].get("status") if result and result.get("value") else None,
                "elapsed_ns": result["finished_ns"] - result["entered_ns"] if result else None,
                "transaction_level": result.get("transaction_level") if result else None,
                "pdo_transaction": result.get("pdo_transaction") if result else None,
            })
        before = data.get("before_fresh_retry")
        after = data.get("after_fresh_retry")
        worker_summaries.append({
            "run": run["id"], "receipt": receipt, "case": data["case"],
            "workers": workers, "observed_barriers": data["barriers"],
            "concurrent_retry": data.get("concurrent_retry"),
            "fresh_retry": data.get("fresh_retry"),
            "before_fresh_retry": before, "after_fresh_retry": after,
            "audited_summaries_equal": before == after if before and after else None,
        })
save(EVIDENCE / "native-worker-reconciliation.json", {
    "scope": "Completed archived worker receipts, mapped explicitly to their invocation. Null optional fields mean no structured fresh-retry summary was retained; they do not replace the testcase's own audit assertions or imply success. Monotonic intervals overlap and cannot be summed as independent work.",
    "receipts": worker_summaries,
})

index = json.loads((EVIDENCE / "archive-index.json").read_text())
for item in index["files"]:
    data = (PACKET / item["packet_path"]).read_bytes()
    assert len(data) == item["bytes"], item["packet_path"]
    assert hashlib.sha256(data).hexdigest() == item["sha256"], item["packet_path"]

owned = [PACKET / "commands.md", PACKET / "source-map.md"]
owned += sorted(p for p in EVIDENCE.rglob("*") if p.is_file()
                and p.name != "artifact-digests.json"
                and "__pycache__" not in p.parts)
# Imported lane artifact manifests are preserved and included; only this
# generated top-level manifest excludes itself.
owned += sorted(p for p in EVIDENCE.rglob("artifact-digests.json")
                if p.parent != EVIDENCE)
files = {}
for path in sorted(set(owned)):
    data = path.read_bytes()
    files[str(path.relative_to(PACKET))] = {
        "sha256": hashlib.sha256(data).hexdigest(), "bytes": len(data)}
save(EVIDENCE / "artifact-digests.json", {
    "scope": "Owned commands/source map and evidence files; excludes this manifest, README, review receipt, and external ledgers.",
    "files": files,
})
print(json.dumps({"completed_junit_runs": len(runs),
                  "runtime_copies_verified": len(index["files"]),
                  "digested_files": len(files)}))
