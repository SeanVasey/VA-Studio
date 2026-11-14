"""Verify the archived receipts, derive per-invocation results, and bind source hashes.

Run with Python 3 in a checkout containing the referenced Git objects. No tests,
application, network, or database operation runs. No mixed-source count is an
integrated/final-source suite result. README/review/ledgers have separate owners.
"""
from pathlib import Path
from collections import Counter
import hashlib
import json
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
PACKET = HERE.parent
REPO = Path(subprocess.check_output(["git", "rev-parse", "--show-toplevel"], cwd=HERE, text=True).strip())


def save(name, value):
    (HERE / name).write_text(json.dumps(value, indent=2) + "\n")


def sha(data):
    return hashlib.sha256(data).hexdigest()


registry = json.loads((HERE / "runs.json").read_text())
runs = []
for run in registry["runs"]:
    document = ET.parse(HERE / run["xml"]).getroot()
    suite = document.find("testsuite") if document.tag == "testsuites" else document
    cases = []
    for case in suite.iter("testcase"):
        status = next((tag for tag in ("error", "failure", "skipped") if case.find(tag) is not None), "passed")
        cases.append({"identity": case.attrib.get("class", case.attrib.get("classname", "")) + "::" + case.attrib["name"],
                      "assertions": int(case.attrib.get("assertions", 0)), "status": status,
                      "detail": (case.find(status).text or "").strip() if status != "passed" else None})
    observed = {key: int(suite.attrib[key]) for key in ("tests", "assertions", "errors", "failures", "skipped")}
    calculated = {"tests": len(cases), "assertions": sum(case["assertions"] for case in cases),
                  "errors": sum(case["status"] == "error" for case in cases),
                  "failures": sum(case["status"] == "failure" for case in cases),
                  "skipped": sum(case["status"] == "skipped" for case in cases)}
    assert observed == calculated, (run["id"], observed, calculated)
    assert len({case["identity"] for case in cases}) == len(cases), run["id"]
    for receipt in run["source_receipts"] + run["command_receipts"]:
        assert (HERE / receipt).is_file(), (run["id"], receipt)
    status = int((HERE / run["exit"]).read_text().strip())
    assert status == run["expected_exit"], (run["id"], status)
    assert observed == run["expected_counts"], (run["id"], observed)
    runs.append({**run, "observed_exit": status, **observed, "junit_seconds": suite.attrib.get("time"), "cases": cases})
save("junit-summary.json", {"scope": "Completed retained invocations, including original failures/errors. Per-run counts overlap and are not a final integrated suite or a unique coverage total.",
                            "run_count": len(runs), "runs": runs})

by_id = {run["id"]: run for run in runs}
for group in registry.get("disjoint_component_groups", []):
    selected = [by_id[run_id] for run_id in group["runs"]]
    identities = [case["identity"] for run in selected for case in run["cases"]]
    assert len(set(identities)) == len(identities), group["id"]
    assert all(run["observed_exit"] == 0 for run in selected), group["id"]
    totals = {key: sum(run[key] for run in selected) for key in ("tests", "assertions", "errors", "failures", "skipped")}
    assert totals == group["expected_counts"], group["id"]
    save(group["output"], {**group, **totals, "disjoint_case_identities": True})

command_runs = []
for run in registry.get("command_runs", []):
    status = int((HERE / run["exit"]).read_text().strip())
    log = (HERE / run["log"]).read_text()
    assert status == run["expected_exit"], run["id"]
    for expected_text in run.get("log_contains", []):
        assert expected_text in log, (run["id"], expected_text)
    if run.get("pint_result"):
        assert json.loads(log)["result"] == run["pint_result"], run["id"]
    if run.get("unittest_count") is not None:
        count = re.search(r"^Ran (\d+) tests? in ", log, re.MULTILINE)
        assert count and int(count.group(1)) == run["unittest_count"], run["id"]
        assert re.search(r"^OK$", log, re.MULTILINE), run["id"]
    command_runs.append({**run, "observed_exit": status})
save("command-summary.json", {"scope": "Completed non-JUnit local commands. CI policy self-tests do not constitute hosted CI, runtime discovery, application test-body execution or final-source preflight.",
                              "runs": command_runs})

for spec in registry.get("discovery_receipts", []):
    prefix = Path(spec["directory"])
    manifest = json.loads((HERE / prefix / "phpunit-ci-mysql-manifest.json").read_text())
    namespace = "{https://xml.phpunit.de/testSuite}"

    def listing(name):
        document = ET.parse(HERE / prefix / name).getroot()
        cases, groups = {}, Counter()
        assert document.tag == namespace + "testSuite"
        for test_class in document.find(namespace + "tests"):
            assert test_class.tag == namespace + "testClass"
            path = test_class.attrib["file"]
            assert path.startswith(spec["source_root"] + "/")
            path = path.removeprefix(spec["source_root"] + "/")
            for method in test_class:
                assert method.tag == namespace + "testMethod"
                identity = method.attrib["id"]
                assert identity not in cases
                cases[identity] = path
        for group in document.find(namespace + "groups"):
            for case in group:
                assert case.attrib["id"] in cases
                groups[(group.attrib["name"], case.attrib["id"])] += 1
        return cases, groups

    complete, complete_groups = listing("phpunit-ci-mysql-source-tests.xml")
    assert len(complete) == manifest["source_test_cases"] == 8151
    assert len(set(complete.values())) == manifest["source_files"] == 599
    assert sha(json.dumps(sorted(complete.items())).encode()) == manifest["source_case_identity_sha256"]
    assert sha(json.dumps(sorted(complete_groups.items())).encode()) == manifest["source_group_identity_sha256"]
    selected, selected_groups, selected_files = {}, Counter(), set()
    assert len(manifest["shards"]) == 24
    for shard in manifest["shards"]:
        cases, groups = listing(f"phpunit-ci-mysql-{shard['index']}-tests.xml")
        assert cases and len(cases) == shard["test_cases"]
        files = set(cases.values())
        assert files == set(shard["files"])
        assert not selected_files.intersection(files) and not set(selected).intersection(cases)
        assert cases == {identity: path for identity, path in complete.items() if path in files}
        assert groups == Counter({pair: count for pair, count in complete_groups.items() if pair[1] in cases})
        selected.update(cases)
        selected_files.update(files)
        selected_groups.update(groups)
    assert len(selected) == manifest["selection"]["test_cases"] == 2068
    assert len(selected_files) == manifest["selection"]["files"] == 199
    assert sha(json.dumps(sorted(selected.items())).encode()) == manifest["selection"]["case_identity_sha256"]
    save(spec["output"], {**spec, "scope": "Offline recomputation of archived discovery listings and manifest only; no PHPUnit or test body is executed by packet assembly.",
                         "discovered_tests": len(complete), "discovered_files": len(set(complete.values())),
                         "selected_tests": len(selected), "selected_files": len(selected_files),
                         "nonempty_shards": len(manifest["shards"]), "complete_files_disjoint": True,
                         "case_and_group_identities_match": True, "test_bodies_executed": False,
                         "untimed_files": manifest["weighting"]["untimed_files"]})

cleanup_results = []
for spec in registry.get("discovery_cleanup_receipts", []):
    receipt = json.loads((HERE / spec["receipt"]).read_text())
    entries = receipt["deleted_files"]
    assert len(entries) == 50 and len({item["path"] for item in entries}) == 50
    archived, omitted_configs = [], []
    for item in entries:
        path = HERE / spec["archived_directory"] / item["path"]
        if path.is_file():
            assert sha(path.read_bytes()) == item["sha256"], item["path"]
            archived.append(item["path"])
        else:
            assert re.fullmatch(r"phpunit-ci-mysql-(?:[1-9]|1[0-9]|2[0-4])\.xml", item["path"])
            omitted_configs.append(item["path"])
    assert len(archived) == 26 and len(omitted_configs) == 24
    cleanup_results.append({**spec, "archived_public_listing_and_manifest_hashes_match": archived,
                            "generated_configuration_files_not_archived": omitted_configs})
save("discovery-cleanup-verification.json", {
    "scope": "Hash-only reconciliation of the owner's cleanup receipt against 25 archived public listings plus their manifest. The 24 generated environment/configuration files were deliberately not archived.",
    "receipts": cleanup_results})

worker_results = []
for spec in registry.get("native_worker_receipts", []):
    receipt = json.loads((HERE / spec["receipt"]).read_text())
    workers = receipt["workers"]
    assert len(workers) == 2
    results = [worker["result"] for worker in workers]
    assert len({result["pid"] for result in results}) == 2
    assert len({result["connection_id"] for result in results}) == 2
    assert {result["result"] for result in results} == {"saved", "denied"}
    for worker in workers:
        result = worker["result"]
        assert json.loads(worker["stdout"]) == result
        assert worker["exit"] == 0 and not worker["stopped_by_cleanup"] and not worker["stderr"]
        assert result["transaction_level"] == 0 and result["in_transaction"] is False
        assert set(result["snapshot_before"].values()) == {0}
    assert {wait["requester"] for wait in receipt["waits"]} == {result["connection_id"] for result in results}
    assert all(wait["blocker"] == receipt["parent_connection_id"] and wait["start_id"] == receipt["start_id"] for wait in receipt["waits"])
    loser = next(result for result in results if result["result"] == "denied")
    assert loser["sqlstate"] == "40001" and loser["driver_code"] == 1213 and loser["snapshot_after"] is None
    worker_results.append({**spec, "case": receipt["case"], "mysql_version": receipt["mysql_version"],
                           "positive_controls": receipt["positive_controls"], "observed_waits": receipt["waits"],
                           "workers": results, "observed_loser_branch": "40001 / 1213 deadlock refusal",
                           "alternative_45000_branch_observed": False,
                           "limit": "The losing transaction has no retained snapshot_after. The alternative 45000 post-refusal snapshot branch was not observed; separate schema old-snapshot tests retain their own coverage."})
save("native-worker-summary.json", {"scope": "Actual completed upgraded263 interval-worker outcomes, not every permitted branch of the test.", "receipts": worker_results})

coverage_path = HERE / "coverage-plan.json"
if coverage_path.exists():
    coverage = json.loads(coverage_path.read_text())

    def coverage_scope(run, identity):
        if identity in coverage["case_scopes"]:
            return coverage["case_scopes"][identity]
        class_name = identity.split("::", 1)[0]
        return coverage["class_scopes"].get(class_name, coverage["run_scopes"].get(run, "current263_runtime"))

    latest, replaced, skips = {}, [], []
    for run_id in coverage["successful_invocation_precedence"]:
        run = by_id[run_id]
        assert run["observed_exit"] == 0 and not run["errors"] and not run["failures"], run_id
        for case in run["cases"]:
            item = {**case, "run": run_id, "driver": run["driver"], "invocation_exit": 0,
                    "coverage_scope": coverage_scope(run_id, case["identity"])}
            if case["status"] == "skipped":
                skips.append(item)
                continue
            assert case["status"] == "passed"
            key = (run["driver"], case["identity"])
            if key in latest:
                replaced.append({"driver": key[0], "identity": key[1], "earlier_run": latest[key]["run"], "selected_run": run_id})
            latest[key] = item
    groups = []
    for driver, scope in sorted({(item["driver"], item["coverage_scope"]) for item in latest.values()}):
        selected_cases = [item for item in latest.values() if item["driver"] == driver and item["coverage_scope"] == scope]
        groups.append({"driver": driver, "coverage_scope": scope, "tests": len(selected_cases),
                       "assertions": sum(item["assertions"] for item in selected_cases)})
    supplemental = []
    for run_id in coverage["nonzero_invocations_with_passing_leaves"]:
        run = by_id[run_id]
        assert run["observed_exit"] != 0
        for case in run["cases"]:
            if case["status"] == "passed":
                supplemental.append({**case, "run": run_id, "driver": run["driver"], "invocation_exit": run["observed_exit"],
                                     "coverage_scope": coverage_scope(run_id, case["identity"]),
                                     "also_in_successful_invocation_union": (run["driver"], case["identity"]) in latest})
    save("successful-case-union.json", {
        "scope": "Explicit successful-invocation precedence, deduplicated by driver and full testcase identity. Mixed source/component evidence, not one integrated or final-source suite. Historical schema, synthetic timing and current263 runtime cases remain separate categories.",
        "behavior_reference": coverage["behavior_reference"], "final_source_reference": registry.get("final_source_reference"),
        "precedence": coverage["successful_invocation_precedence"], "groups": groups,
        "cases": sorted(latest.values(), key=lambda item: (item["driver"], item["identity"])),
        "overridden_cases": replaced, "skipped_leaves_excluded_from_pass_union": skips})
    save("passing-leaves-in-nonzero-invocations.json", {
        "scope": "Supplemental passing testcase leaves inside failed invocations. Excluded from the successful-invocation union; they never turn their parent invocation green and are not a unique additional coverage total.",
        "invocations": [{"run": run_id, "exit": by_id[run_id]["observed_exit"],
                         **{key: by_id[run_id][key] for key in ("tests", "assertions", "errors", "failures", "skipped")}}
                        for run_id in coverage["nonzero_invocations_with_passing_leaves"]], "cases": supplemental})
    pending_native = [identity for identity in coverage.get("required_root_native_cases", []) if ("mysql", identity) not in latest]
    save("uncovered-changed-behaviors.json", {
        "scope": "Required native identity reconciliation plus explicit untested conditions; no acceptance decision or whole-suite claim.",
        "behavior_reference": coverage["behavior_reference"], "pending_root_native_cases": pending_native,
        "pending_schema_native_selection": coverage.get("pending_schema_native_selection"),
        "out_of_scope_conditions": coverage.get("out_of_scope_conditions", [])})


def source_hashes(spec):
    path = HERE / spec["receipt"]
    if path.suffix == ".sha256":
        return {line.split(None, 1)[1].lstrip("*"): line.split(None, 1)[0]
                for line in path.read_text().splitlines() if line.strip()}
    data = json.loads(path.read_text())
    for key in spec.get("keys", []):
        data = data[key]
    assert isinstance(data, dict)
    assert all(isinstance(value, str) and len(value) == 64 for value in data.values()), path
    return data


stability = []
for spec in registry.get("source_stability", []):
    if "before" in spec:
        before, after = source_hashes(spec["before"]), source_hashes(spec["after"])
        assert before == after, spec["id"]
        stability.append({**spec, "method": "independent comparison of archived before/after source maps",
                          "captured_files": len(before), "unchanged": True})
    else:
        data = json.loads((HERE / spec["receipt"]).read_text())
        for key in spec["keys"]:
            data = data[key]
        assert data == spec["expected"], spec["id"]
        stability.append({**spec, "method": "retained runner stability result; raw after-map not separately retained",
                          "unchanged": True})
save("source-stability-verification.json", {
    "scope": "Before/after map comparisons and retained runner stability results are distinguished. A missing stability receipt is not inferred from a passing test.",
    "unavailable": registry.get("source_stability_unavailable", []), "runs": stability})

programs = []
for spec in registry.get("external_program_bindings", []):
    before = json.loads((HERE / spec["before"]).read_text())
    after = json.loads((HERE / spec["after"]).read_text())
    for path, keys in spec["programs"].items():
        original, final = before, after
        for key in keys:
            original, final = original[key], final[key]
        actual = sha((HERE / path).read_bytes())
        assert original == final == actual, (spec["id"], path)
        programs.append({"run": spec["id"], "path": path, "sha256": actual,
                         "before_after_and_archived_bytes_match": True})
save("external-program-verification.json", {
    "scope": "External runtime probe/runner source bytes independently checked against retained before and after source-receipt fields. Private autoload and key inputs are not copied or attested here.",
    "programs": programs})

runner_bindings = []
for spec in registry.get("runner_hash_bindings", []):
    receipt = json.loads((HERE / spec["receipt"]).read_text())
    expected = receipt
    for key in spec["keys"]:
        expected = expected[key]
    actual = sha((HERE / spec["program"]).read_bytes())
    assert actual == expected, spec["program"]
    runner_bindings.append({**spec, "sha256": actual, "matches_captured_runner_hash": True})
save("runner-source-verification.json", {
    "scope": "Archived public runner/setup script bytes compared with their captured receipt hashes; no script is executed by this check.",
    "bindings": runner_bindings})

carried = []
for spec in registry.get("policy_carry_receipts", []):
    receipt = json.loads((HERE / spec["receipt"]).read_text())
    earlier = json.loads((HERE / spec["before"]).read_text())
    later = json.loads((HERE / spec["after"]).read_text())
    paths = receipt["policy_and_script_paths"]
    assert len(paths) == receipt["unchanged_policy_and_script_count"] == 39
    assert all(earlier[path] == later[path] == value for path, value in paths.items())
    assert sha((HERE / spec["original_results"]).read_bytes()) == receipt["original_results_sha256"]
    changed = sorted(path for path in set(earlier) | set(later) if earlier.get(path) != later.get(path))
    assert changed == receipt["all_source_inventory_changes"]
    carried.append({**spec, "unchanged_paths": len(paths), "changed_inventory_paths": changed,
                    "original_results_match": True, "tests_rerun": False})
save("policy-carry-verification.json", {"scope": "Exact archived policy/script/workflow/dependency hashes across the style-only correction. This verifies carry, not rerunning checks on a later head.", "receipts": carried})

imported = []
archive_origins = {item["packet_path"].removeprefix("evidence/"): item
                   for item in json.loads((HERE / "archive-index.json").read_text())["files"]}
for spec in registry.get("imported_digest_manifests", []):
    manifest = json.loads((HERE / spec["receipt"]).read_text())
    for key in spec.get("keys", []):
        manifest = manifest[key]
    checked = []
    for name, value in manifest.items():
        target = str(Path(spec["directory"]) / spec.get("path_overrides", {}).get(name, name))
        path = HERE / target
        expected = value if isinstance(value, str) else value["sha256"]
        if spec.get("original_archive_hashes"):
            origin = archive_origins[target]
            assert origin["original_sha256"] == expected and sha(path.read_bytes()) == origin["sha256"], path
        else:
            assert sha(path.read_bytes()) == expected, path
        checked.append(name)
    imported.append({**spec, "verified_members": len(checked)})
save("imported-manifest-verification.json", {"scope": "All selected lane-manifest members reconciled to archived bytes. A manifest explicitly marked original_archive_hashes uses the original-copy hash for projected JSON and independently checks the projected archived digest; omitted metadata is not reconstructed.", "manifests": imported})


trees, digests = {}, {}
reader = subprocess.Popen(["git", "cat-file", "--batch"], cwd=REPO, stdin=subprocess.PIPE, stdout=subprocess.PIPE)


def tree(ref):
    if ref not in trees:
        raw = subprocess.check_output(["git", "ls-tree", "-r", "-z", ref], cwd=REPO)
        entries = {}
        for entry in raw.split(b"\0"):
            if entry:
                info, path = entry.split(b"\t", 1)
                mode, kind, oid = info.decode().split()
                if kind == "blob":
                    entries[path.decode()] = oid
        trees[ref] = entries
    return trees[ref]


def blob(oid):
    reader.stdin.write((oid + "\n").encode())
    reader.stdin.flush()
    header = reader.stdout.readline().decode().split()
    assert header[1] == "blob", header
    data = reader.stdout.read(int(header[2]))
    assert reader.stdout.read(1) == b"\n"
    return data


def digest(oid):
    if oid not in digests:
        digests[oid] = sha(blob(oid))
    return digests[oid]


bindings = []
for spec in registry["source_bindings"]:
    captured = source_hashes(spec)
    ref = spec["reference"]
    entries = tree(ref)
    overrides = {}
    for path, receipt in spec.get("retained_overrides", {}).items():
        overrides[path] = sha((HERE / receipt).read_bytes())
    if spec.get("overlay"):
        patch = HERE / spec["overlay"]
        changed_paths = [line[6:] for line in patch.read_text().splitlines() if line.startswith("+++ b/")]
        with tempfile.TemporaryDirectory(prefix="billing-c3-source-binding-") as tmp:
            for path in changed_paths:
                target = Path(tmp) / path
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(blob(entries[path]))
            subprocess.run(["git", "apply", "--", str(patch)], cwd=tmp, check=True, capture_output=True)
            assert not set(overrides).intersection(changed_paths), spec["id"]
            overrides.update({path: sha((Path(tmp) / path).read_bytes()) for path in changed_paths})
    mismatches, absent = [], []
    for path, expected in captured.items():
        if path not in entries and path not in overrides:
            absent.append(path)
        else:
            actual = overrides[path] if path in overrides else digest(entries[path])
            if actual != expected:
                mismatches.append({"path": path, "receipt_sha256": expected, "reference_sha256": actual})
    if spec.get("require_exact"):
        assert not mismatches and not absent, (spec["id"], mismatches, absent)
    bindings.append({**spec, "captured_files": len(captured), "matched_files": len(captured) - len(mismatches) - len(absent),
                     "mismatches": mismatches, "absent_at_reference": absent,
                     "all_captured_hashes_match": not mismatches and not absent})

final_reference = registry.get("final_source_reference")
if final_reference and coverage_path.exists():
    final_entries = tree(final_reference)
    specs = {spec["id"]: spec for spec in registry["source_bindings"]}
    final_comparisons = []
    for run_id in coverage["successful_invocation_precedence"]:
        run = by_id[run_id]
        spec = specs[run_id.split("/", 1)[1]]
        captured = source_hashes(spec)
        selected_tests = {case["identity"].split("::", 1)[0].replace("Tests\\", "tests/").replace("\\", "/") + ".php" for case in run["cases"]}
        differences = []
        for path, recorded in captured.items():
            current = digest(final_entries[path]) if path in final_entries else None
            if current != recorded:
                category = ("application" if path.startswith("app/") else "schema" if path.startswith("database/")
                            else "configuration" if path.startswith(("config/", "bootstrap/"))
                            else "selected_test" if path in selected_tests else "test_support" if path.startswith("tests/Support/")
                            else "other_test" if path.startswith("tests/") else "tooling" if path.startswith(("scripts/ci/", ".github/", ".gitlab"))
                            else "other_tracked_input")
                differences.append({"path": path, "category": category, "captured_sha256": recorded, "final_sha256": current})
        final_comparisons.append({"run": run_id, "component_reference": spec["reference"], "captured_files": len(captured),
                                  "all_captured_hashes_match_final": not differences, "differences": differences})
    save("final-source-verification.json", {
        "final_source_reference": final_reference,
        "scope": "Every captured hash for each explicitly selected successful invocation compared with the actual frozen root Git tree. Differences are retained by category. Comparison is limited to each captured inventory; it does not establish unlisted inputs or rerun component cases on the final source. External runtime probe programs have their own before/after verification.",
        "comparisons": final_comparisons})
reader.stdin.close()
reader.wait()
save("source-binding-verification.json", {
    "scope": "Actual Git blob SHA-256 comparisons against explicitly named component references and retained overlays. A partial historical match remains partial; it is not silently promoted to final-source evidence.",
    "final_source_reference": registry.get("final_source_reference"), "bindings": bindings})

index = json.loads((HERE / "archive-index.json").read_text())
for item in index["files"]:
    data = (PACKET / item["packet_path"]).read_bytes()
    assert len(data) == item["bytes"] and sha(data) == item["sha256"], item["packet_path"]

owned = [PACKET / name for name in ("commands.md", "coverage-map.md", "coverage-review.md", "source-map.md") if (PACKET / name).exists()]
owned += [path for path in HERE.rglob("*") if path.is_file() and "__pycache__" not in path.parts
          and path != HERE / "artifact-digests.json"]
save("artifact-digests.json", {
    "scope": "Packet-owned commands/maps/evidence only; excludes this top-level digest, separately owned README/review, and external ledgers.",
    "files": {str(path.relative_to(PACKET)): {"bytes": path.stat().st_size, "sha256": sha(path.read_bytes())} for path in sorted(owned)}})
print(json.dumps({"completed_junit_runs": len(runs), "source_bindings": len(bindings),
                  "completed_non_junit_commands": len(command_runs),
                  "partial_historical_bindings": sum(not item["all_captured_hashes_match"] for item in bindings),
                  "runtime_copies_verified": len(index["files"]), "digested_files": len(owned)}))
