#!/usr/bin/env python3
"""Offline C4c receipt storage and inspection. Never invokes PHP or a database."""
import argparse
import base64
import difflib
import gzip
import hashlib
import json
import pathlib
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

HERE = pathlib.Path(__file__).resolve().parent
REPO = HERE.parents[3]
HEX = re.compile(r"[0-9a-f]{64}\Z")


def digest(data):
    return hashlib.sha256(data).hexdigest()


def load(path):
    return json.loads(path.read_text())


def save(path, value):
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n")


def relative(value):
    path = pathlib.PurePosixPath(value)
    assert value and not path.is_absolute() and ".." not in path.parts, value
    assert not any(p == ".env" or p.startswith(".env.") for p in path.parts), value
    assert path.suffix.lower() not in {".pdf", ".wav", ".mp3", ".sqlite", ".db", ".pem", ".key"}, value
    return path


def members(document):
    for key in ("files", "entries", "public_files", "members", "artifacts"):
        if key in document:
            data = document[key]
            if isinstance(data, dict):
                return [{"path": name, **spec} for name, spec in data.items()]
            return data
    raise ValueError("Unrecognized explicit release allowlist")


class Store:
    def __init__(self, registry):
        self.registry = registry
        self.cache = {}

    def read(self, sha):
        assert HEX.fullmatch(sha), sha
        if sha in self.cache:
            return self.cache[sha]
        spec = self.registry["objects"][sha]
        packed = (HERE / spec["archive"]).read_bytes()
        assert digest(packed) == spec["archive_sha256"] and len(packed) == spec["archive_bytes"], sha
        payload = gzip.decompress(packed)
        if spec["encoding"] == "line-delta-gzip-v1":
            patch = json.loads(payload)
            original = self.read(patch["base_sha256"]).splitlines(keepends=True)
            data = b"".join(
                b"".join(original[item[1]:item[2]]) if item[0] == "copy" else base64.b64decode(item[1], validate=True)
                for item in patch["parts"]
            )
        else:
            assert spec["encoding"] == "gzip"
            data = payload
        assert len(data) == spec["bytes"] and digest(data) == sha, sha
        self.cache[sha] = data
        return data

    def put(self, data, name):
        sha = digest(data)
        if sha in self.registry["objects"]:
            assert self.read(sha) == data
            return sha
        packed = gzip.compress(data, mtime=0)
        encoding = "gzip"
        # Source inventories differ mostly in a few lines. Preserve exact original
        # bytes through a one-level, lossless delta against the first full bundle.
        if len(data) > 65536 and name.endswith(".json"):
            base = self.registry.get("large_json_base")
            if base:
                before = self.read(base).splitlines(keepends=True)
                after = data.splitlines(keepends=True)
                parts = []
                for tag, i, j, x, y in difflib.SequenceMatcher(None, before, after, autojunk=False).get_opcodes():
                    if tag == "equal":
                        parts.append(["copy", i, j])
                    elif x != y:
                        parts.append(["literal", base64.b64encode(b"".join(after[x:y])).decode()])
                delta = gzip.compress(json.dumps({"base_sha256": base, "parts": parts}, separators=(",", ":")).encode(), mtime=0)
                if len(delta) < len(packed):
                    packed, encoding = delta, "line-delta-gzip-v1"
            else:
                self.registry["large_json_base"] = sha
        archive = f"objects/{sha}.gz"
        (HERE / "objects").mkdir(exist_ok=True)
        (HERE / archive).write_bytes(packed)
        self.registry["objects"][sha] = {
            "bytes": len(data), "archive": archive, "encoding": encoding,
            "archive_bytes": len(packed), "archive_sha256": digest(packed),
        }
        assert self.read(sha) == data
        return sha


def ingest(plan_path):
    registry_path = HERE / "releases.json"
    registry = load(registry_path) if registry_path.exists() else {"schema": "c4c-public-receipts-v1", "releases": {}, "objects": {}}
    store = Store(registry)
    for release in load(plan_path):
        rid = release["id"]
        if rid in registry["releases"]:
            old = registry["releases"][rid]
            assert all(old[k] == release[k] for k in ("runtime_root", "manifest", "manifest_sha256")), rid
            old.update({k: v for k, v in release.items() if k in {"scope", "limits"}})
            continue  # Immutable old runtime locations are not read again.
        root = pathlib.Path(release["runtime_root"])
        manifest_name = release["manifest"]
        relative(manifest_name)
        raw = (root / manifest_name).read_bytes()
        assert digest(raw) == release["manifest_sha256"], rid
        entries = members(json.loads(raw))
        names = set()
        public = {}
        # Validate the whole explicit allowlist before writing any member.
        for entry in entries:
            name = entry["path"]
            relative(name)
            assert name not in names, (rid, name)
            names.add(name)
            path = root / name
            assert path.resolve().is_relative_to(root.resolve()) and not path.is_symlink(), path
            data = path.read_bytes()
            assert len(data) == entry["bytes"] and digest(data) == entry["sha256"], (rid, name)
            data.decode("utf-8")  # This packet admits released text receipts only.
            public[name] = data
        paths = {manifest_name: store.put(raw, manifest_name)}
        for name, data in public.items():
            assert name not in paths
            paths[name] = store.put(data, name)
        registry["releases"][rid] = {**release, "paths": paths}
    save(registry_path, registry)
    print(f"Stored {len(registry['releases'])} releases / {len(registry['objects'])} unique objects")


class Git:
    def __init__(self):
        self.proc = subprocess.Popen(["git", "cat-file", "--batch"], cwd=REPO, stdin=subprocess.PIPE, stdout=subprocess.PIPE)
        self.cache = {}
        self.expressions = {}

    def hash(self, ref, path):
        expression = ref + ":" + path
        assert "\n" not in expression
        if expression in self.expressions:
            return self.expressions[expression]
        self.proc.stdin.write(expression.encode() + b"\n")
        self.proc.stdin.flush()
        header = self.proc.stdout.readline().decode().rstrip()
        if header.endswith(" missing"):
            self.expressions[expression] = None
            return None
        blob, kind, size = header.split()
        assert kind == "blob", expression
        data = self.proc.stdout.read(int(size))
        assert self.proc.stdout.read(1) == b"\n"
        if blob not in self.cache:
            self.cache[blob] = digest(data)
        self.expressions[expression] = self.cache[blob]
        return self.cache[blob]

    def close(self):
        self.proc.stdin.close()
        assert self.proc.wait() == 0


def xml_counts(data):
    if not data.strip():
        return None, "empty XML; no execution count inferred"
    try:
        root = ET.fromstring(data)
    except ET.ParseError as error:
        return None, "malformed XML: " + str(error)
    cases = root.findall(".//testcase")
    if not cases:
        return None, "no testcases; no execution count inferred"
    counts = {
        "tests": len(cases), "assertions": sum(int(c.get("assertions", "0")) for c in cases),
        "errors": sum(c.find("error") is not None for c in cases),
        "failures": sum(c.find("failure") is not None for c in cases),
        "skipped": sum(c.find("skipped") is not None for c in cases),
    }
    top = root if root.tag == "testsuite" else root.find("testsuite")
    assert top is not None
    for key, value in counts.items():
        assert int(top.get(key, "0")) == value, (key, value, top.attrib)
    return counts, None


def verify(output, with_git):
    registry = load(HERE / "releases.json")
    store = Store(registry)
    for sha in registry["objects"]:
        store.read(sha)
    expected_archives = {spec["archive"] for spec in registry["objects"].values()}
    assert {p.relative_to(HERE).as_posix() for p in (HERE / "objects").iterdir()} == expected_archives
    git = Git() if with_git else None
    source_map = load(HERE / "source-map.json") if (HERE / "source-map.json").exists() else None
    if git and source_map:
        refs = {m["default_ref"] for m in source_map["sources"].values()}
        refs.update(ref for m in source_map["sources"].values() for ref in m["overrides"].values())
        for ref in refs:
            subprocess.run(["git", "merge-base", "--is-ancestor", ref, source_map["anchor"]], cwd=REPO, check=True)
        subprocess.run(["git", "merge-base", "--is-ancestor", source_map["base"], source_map["anchor"]], cwd=REPO, check=True)
    def source_ref(source_id, head, path):
        if source_map and source_id in source_map["sources"]:
            mapping = source_map["sources"][source_id]
            assert mapping["author_head"] == head
            return mapping["overrides"].get(path, mapping["default_ref"])
        return head
    def mapping_status(source_id):
        if not source_map or source_id not in source_map["sources"]:
            return "pending integrated candidate mapping"
        return "reachable root versions; exact named path bytes" if with_git else "recorded mapping; Git comparison not performed by this invocation"
    def validate_mapping(source_id, files, extras=()):
        if with_git:
            assert source_map and source_id in source_map["sources"], (source_id, "Complete source mapping required for Git verification")
        if not source_map or source_id not in source_map["sources"]:
            return
        mapping = source_map["sources"][source_id]
        assert mapping["input_count"] == len(files), source_id
        assert mapping.get("non_git_inputs", {}) == {p: files[p] for p in extras}, source_id
        assert set(mapping["overrides"]) <= set(files) - set(extras), source_id
    report = {"schema": "c4c-receipt-index-v1", "application_tests_executed_by_verifier": False, "git_source_check": with_git, "releases": {}, "sources": {}, "runs": {}, "static": {}, "exit_markers": {}}
    try:
        for rid, release in registry["releases"].items():
            paths = release["paths"]
            get = lambda name: store.read(paths[name])
            doc = lambda name: json.loads(get(name))
            manifest = doc(release["manifest"])
            entries = members(manifest)
            assert paths[release["manifest"]] == release["manifest_sha256"]
            names = [entry["path"] for entry in entries]
            assert len(names) == len(set(names))
            assert set(paths) == set(names) | {release["manifest"]}
            assert release["manifest"] not in names
            for entry in entries:
                assert paths[entry["path"]] == entry["sha256"]
                assert len(get(entry["path"])) == entry["bytes"]
            report["releases"][rid] = {"manifest_sha256": release["manifest_sha256"], "members": len(entries), "scope": release["scope"], "limits": release.get("limits", [])}
            for name in paths:
                if name.endswith(".exit") or name.endswith("/exit"):
                    report["exit_markers"][rid + "/" + name] = int(get(name))
            bundles = {}
            for name in paths:
                if not name.endswith(".json") or name == release["manifest"]:
                    continue
                value = doc(name)
                if not isinstance(value, dict):
                    continue
                if value.get("scope", "").startswith("Local synthetic CI policy self-tests only."):
                    assert value["verified"] and value["source_before"] == value["source_after"]
                    assert value["started_source"] == value["ended_source"] and value["started_source"]["status"] == ""
                    assert value["runner_sha256"] == value["runner_after_sha256"] == paths["run.py"]
                    assert not value["untested_scripts"] and len(value["runs"]) == 5
                    validate_mapping(rid + "/" + name, value["source_before"])
                    for run in value["runs"]:
                        assert run["exit"] == 0 and run["tests_reported"] > 0
                        log_name = pathlib.PurePosixPath(run["argv"][1]).name + ".log"
                        assert paths[log_name] == run["log_sha256"]
                        count = re.search(rb"Ran ([0-9]+) tests? in ", get(log_name))
                        assert count and int(count.group(1)) == run["tests_reported"]
                    if git:
                        assert all(git.hash(value["started_source"]["head"], p) == h for p, h in value["source_before"].items())
                    report["static"][rid + "/" + name] = {
                        "head": value["started_source"]["head"], "source_count": len(value["source_before"]),
                        "git_verified": with_git, "behavioral_execution": False,
                        "local_policy_unittest_counts": [r["tests_reported"] for r in value["runs"]],
                        "scope": value["scope"],
                    }
                    continue
                files = value.get("files")
                if isinstance(files, dict) and files and all(isinstance(v, str) and HEX.fullmatch(v) for v in files.values()) and value.get("head"):
                    bundles[name] = value
                    source_id = rid + "/" + name
                    validate_mapping(source_id, files)
                    mismatches = []
                    if git:
                        mismatches = [p for p, sha in files.items() if git.hash(source_ref(source_id, value["head"], p), p) != sha]
                        assert not mismatches, (source_id, mismatches)
                    assert "composer.lock" in files and "composer.json" in files and "phpunit.xml" in files, source_id
                    runner = value.get("runner_sha256")
                    assert runner in paths.values(), (source_id, "released runner missing")
                    report["sources"][source_id] = {"head": value["head"], "input_count": len(files), "bundle_sha256": paths[name], "runner_sha256": runner, "git_matches": len(files) if git else None, "runtime": value.get("runtime", value.get("versions")), "autoload_count": len(value.get("autoload", value.get("autoload_metadata", {}))), "portable_mapping": mapping_status(source_id)}
                    if rid in {"history-core", "reader"}:
                        report["sources"][source_id]["limits"] = ["Executed Laravel base tests/TestCase.php was not captured; complete harness source binding is partial."]
                    if value.get("overlays"):
                        overlay = value["overlays"]
                        local = lambda p: pathlib.Path(p).relative_to(release["runtime_root"]).as_posix()
                        map_name = local(overlay["map_path"])
                        assert paths[map_name] == overlay["map_sha256"]
                        assert doc(map_name)["entries"] == overlay["entries"]
                        overlay_files = {item["source_path"]: item["sha256"] for item in overlay["entries"]}
                        heads = {item["source_commit"] for item in overlay["entries"]}
                        assert len(heads) == 1
                        overlay_head = heads.pop()
                        overlay_id = rid + "/" + map_name
                        validate_mapping(overlay_id, overlay_files)
                        for item in overlay["entries"]:
                            assert paths[local(item["path"])] == item["sha256"]
                            assert len(get(local(item["path"]))) == item["bytes"]
                            if git:
                                assert git.hash(source_ref(overlay_id, overlay_head, item["source_path"]), item["source_path"]) == item["sha256"]
                        report["sources"][overlay_id] = {"head": overlay_head, "input_count": len(overlay_files), "bundle_sha256": paths[map_name], "git_matches": len(overlay_files) if git else None, "source_kind": "Preloaded historical class capsules", "portable_mapping": mapping_status(overlay_id)}
                        report["sources"][source_id]["overlay_source"] = overlay_id
                        report["sources"][source_id]["limits"] = ["Captured working-tree class files are overridden by the explicitly preloaded historical capsules; this is not final-source execution."]
                elif "source_bindings" in value:
                    checks = value["source_bindings"]
                    validate_mapping(rid + "/" + name, {p: spec["sha256"] for p, spec in checks.items()})
                    if git:
                        assert all(git.hash(source_ref(rid + "/" + name, value["head"], p), p) == spec["sha256"] for p, spec in checks.items())
                    report["static"][rid + "/" + name] = {"head": value["head"], "source_count": len(checks), "git_verified": with_git, "behavioral_execution": value.get("behavioral_execution")}
                elif ("runs" in value or ("commands" in value and "owned_overlay" not in value)) and "before" in value and "after" in value:
                    assert value["before"] == value["after"]
                    assert value["runner_sha256"] in paths.values()
                    report["static"][rid + "/" + name] = {"observed_head": value.get("head"), "source_count": len(value["before"]), "before_after_equal": True, "exits": [r["exit"] for r in value.get("runs", value.get("commands"))], "behavioral_execution": False}
                elif "commands" in value and "owned_overlay" in value:
                    binding = doc("source-commit-binding.json")
                    files = value["before"]
                    assert files == value["after"] and value["source_unchanged"]
                    assert value["status_before"] == value["status_after"]
                    assert len(files) == binding["declared_sources"]
                    assert set(value["owned_overlay"]) == set(binding["exact_owned_paths"])
                    assert value["runner_sha256"] == paths["run-composed-static.py"]
                    assert value["smoke_sha256"] == paths["read-entry-refusal.php"]
                    for path, sha in doc("static-initial.json")["before"].items():
                        assert paths["static-initial-source/" + path] == sha
                    for path, sha in doc("static-final.json")["before"].items():
                        assert files[path] == sha
                    source_id = rid + "/" + name
                    validate_mapping(source_id, files)
                    if git:
                        mismatch = [p for p, h in files.items() if git.hash(source_ref(source_id, binding["release_commit"], p), p) != h]
                        assert not mismatch, (source_id, mismatch)
                    report["sources"][source_id] = {
                        "head": binding["release_commit"], "observed_head": value["head"],
                        "input_count": len(files), "bundle_sha256": paths[name],
                        "runner_sha256": value["runner_sha256"], "git_matches": len(files) if git else None,
                        "runtime": value["versions"], "autoload_count": len(value["autoload"]),
                        "portable_mapping": mapping_status(source_id),
                        "limits": ["Five untracked working source files were later bound to the release commit; this is not execution at that commit.", "Autoload was regenerated inside the command block; before/after metadata differ and do not prove per-test runtime stability."],
                    }
                    prefix = name.removesuffix("result.json")
                    counts, problem = xml_counts(get(prefix + "ownership.xml"))
                    assert not problem and counts
                    expected = {"skipped" if k == "skips" else k: v for k, v in value["junit"].items()}
                    assert counts == expected
                    for command in value["commands"]:
                        assert int(get(prefix + command["id"] + ".exit")) == command["exit"]
                    ownership = next(c for c in value["commands"] if c["id"] == "ownership")
                    assert ownership["exit"] != 0 or counts["failures"] == counts["errors"] == 0
                    report["runs"][rid + "/" + prefix + "ownership"] = {
                        "command_and_result": name, "source": source_id, "head": value["head"],
                        "later_source_binding": binding["release_commit"], "owned_overlay": value["owned_overlay"],
                        "source_unchanged": True, "process_exit": ownership["exit"], "counts": counts,
                        "xml_problem": None, "kind": "PHPUnit", "declared_driver": "pure",
                        "status": "pass" if ownership["exit"] == 0 else "failed",
                        "duration_seconds": ownership["elapsed_seconds"],
                        "selection": ["tests/Unit/MemberActivationOwnershipTest.php"],
                    }
                    report["static"][rid + "/" + name] = {"observed_head": value["head"], "command_exits": {c["id"]: c["exit"] for c in value["commands"]}, "smoke": "Three entry refusals; not PHPUnit tests or successful authority", "database_execution": False}
                elif "commands" in value and isinstance(value.get("source"), dict) and value.get("git_verified"):
                    files = value["source"]
                    assert value["source_unchanged"] and value["head"] == value["head_after"]
                    assert value["runner_sha256"] in paths.values()
                    source_id = rid + "/" + name
                    validate_mapping(source_id, files)
                    if git:
                        assert all(git.hash(source_ref(source_id, value["head"], p), p) == h for p, h in files.items())
                    report["static"][source_id] = {"head": value["head"], "source_count": len(files), "git_verified": with_git, "behavioral_execution": False, "exits": [c["exit"] for c in value["commands"]]}
            for name in paths:
                if not name.endswith("/command.json"):
                    continue
                prefix = name.removesuffix("command.json")
                result_name = prefix + "result.json"
                if result_name not in paths and prefix + "recovery-observation.json" in paths:
                    command = doc(name)
                    recovery = doc(prefix + "recovery-observation.json")
                    directory = command["bundle"]
                    manifest_name = directory + "/manifest.json"
                    identity = doc(directory + "/identity.json")
                    files = doc(manifest_name)
                    runtime = doc(directory + "/runtime.json")
                    public_env = doc(directory + "/public-environment.json")
                    canonical = lambda value: json.dumps(value, sort_keys=True, separators=(",", ":")).encode()
                    assert identity["manifest_sha256"] == digest(canonical(files)) == command["before_digest"] == recovery["fresh_source_digest"]
                    assert identity["runtime_sha256"] == digest(canonical(runtime))
                    assert identity["public_environment_sha256"] == digest(canonical(public_env))
                    assert public_env == command["public_environment"]
                    assert command["source_before"] == recovery["source_before"] == recovery["fresh_source_state"]
                    assert command["source_before"]["head"] == identity["head"]
                    assert len(files) == command["source_input_count"] == recovery["source_input_count"]
                    expected_gates = {"fresh_source_equals_before", "fresh_source_equals_recorded_git", "fresh_head_status_equal_before",
                                      "fresh_autoload_equals_before", "runner_equals_captured"}
                    assert command["before_git_equal"] and set(recovery["fresh_metadata_gates"]) == expected_gates
                    assert all(value is True for value in recovery["fresh_metadata_gates"].values())
                    assert recovery["process_exit"] is None and recovery["original_runner_verifier_exit"] is None
                    assert recovery["fresh_metadata_verifier_exit"] == 0
                    assert prefix + "process.exit" not in paths
                    assert paths[directory + "/runner.py"] == identity["runner_sha256"]
                    assert paths[prefix + "log.txt"] == recovery["log_sha256"]
                    assert runtime["composer_lock_sha256"] == files["composer.lock"]
                    assert not command.get("overlays")
                    source_id = rid + "/" + manifest_name
                    validate_mapping(source_id, files)
                    if git:
                        assert all(git.hash(source_ref(source_id, identity["head"], p), p) == h for p, h in files.items())
                    counts, problem = xml_counts(get(prefix + "junit.xml"))
                    assert not problem and counts == recovery["actual_junit"]
                    correction_name = prefix + "recovery-wording-correction.md"
                    correction = correction_name if correction_name in paths else None
                    recovery_limits = list(recovery["limits"])
                    if correction:
                        recovery_limits.append("Read " + correction + " alongside the unchanged original recovery wording.")
                    report["sources"][source_id] = {
                        "head": identity["head"], "input_count": len(files), "bundle_sha256": paths[manifest_name],
                        "runner_sha256": identity["runner_sha256"], "git_matches": len(files) if git else None,
                        "runtime": {k: runtime[k] for k in ("php", "phpunit", "database")},
                        "autoload_count": len(runtime["autoload"]), "portable_mapping": mapping_status(source_id),
                        "limits": [command["input_scope"], *recovery_limits],
                    }
                    report["runs"][rid + "/" + prefix.rstrip("/")] = {
                        "command": name, "recovery_observation": prefix + "recovery-observation.json", "source": source_id,
                        "recovery_wording_correction": correction,
                        "head": identity["head"], "source_unchanged": None, "fresh_source_equals_before": True,
                        "process_exit": None, "verifier_exit": None, "fresh_metadata_verifier_exit": 0,
                        "counts": counts, "xml_problem": None, "kind": "PHPUnit",
                        "status": "partial; original process and runner exits unobserved",
                        "declared_driver": public_env["DB_CONNECTION"], "selection": command["selected_files"],
                        "filter": command["filter"], "duration_seconds": None,
                        "workload_overlap": command["workload_scope"], "limits": recovery_limits,
                    }
                    continue
                assert result_name in paths, (rid, prefix)
                command, result = doc(name), doc(result_name)
                if "source_before" in command:
                    directory = command["bundle"]
                    manifest_name = directory + "/manifest.json"
                    identity = doc(directory + "/identity.json")
                    files = doc(manifest_name)
                    runtime = doc(directory + "/runtime.json")
                    public_env = doc(directory + "/public-environment.json")
                    canonical = lambda value: json.dumps(value, sort_keys=True, separators=(",", ":")).encode()
                    assert identity["manifest_sha256"] == digest(canonical(files)) == command["before_digest"] == result["after_digest"]
                    assert identity["runtime_sha256"] == digest(canonical(runtime))
                    assert identity["public_environment_sha256"] == digest(canonical(public_env))
                    assert public_env == command["public_environment"]
                    assert command["source_before"] == result["source_after"]
                    assert command["source_before"]["head"] == identity["head"]
                    assert len(files) == command["source_input_count"] == result["source_input_count"]
                    assert command["before_git_equal"]
                    for key in ("source_unchanged", "source_head_unchanged", "source_status_unchanged", "runner_unchanged", "autoload_unchanged"):
                        assert result[key]
                    assert paths[directory + "/runner.py"] == identity["runner_sha256"] == result["runner_after_sha256"]
                    assert paths[prefix + "log.txt"] == result["log_sha256"]
                    assert int(get(prefix + "process.exit")) == result["process_exit"]
                    assert command["bundle"] == result["bundle"]
                    assert runtime["composer_lock_sha256"] == files["composer.lock"]
                    source_id = rid + "/" + manifest_name
                    validate_mapping(source_id, files)
                    if git:
                        mismatch = [p for p, h in files.items() if git.hash(source_ref(source_id, identity["head"], p), p) != h]
                        assert not mismatch, (source_id, mismatch)
                    counts, problem = xml_counts(get(prefix + "junit.xml")) if prefix + "junit.xml" in paths else (None, "missing XML; no execution count inferred")
                    assert not problem or result["process_exit"] != 0
                    assert result["process_exit"] != 0 or (counts and counts["errors"] == counts["failures"] == 0)
                    if counts:
                        assert counts == result["junit"]
                    assert result["verifier_exit"] == (0 if result["process_exit"] == 0 and not problem else 1)
                    diagnostic = command.get("diagnostic_only", False)
                    assert diagnostic == result.get("diagnostic_only", False)
                    if diagnostic:
                        assert diagnostic is True and result["overlays_unchanged"]
                        assert command["overlays"] == doc("overlays.json")
                        expected_overlays = {}
                        for overlay in command["overlays"]:
                            local_name = pathlib.Path(overlay["path"]).relative_to(release["runtime_root"]).as_posix()
                            assert paths[local_name] == overlay["sha256"]
                            expected_overlays[overlay["path"]] = overlay["sha256"]
                            if overlay["class"] is not None:
                                assert files[overlay["source_path"]] == overlay["source_sha256"]
                        assert expected_overlays == result["overlay_after"]
                        assert len(expected_overlays) == 9
                    else:
                        assert not command.get("overlays")
                    report["sources"][source_id] = {"head": identity["head"], "input_count": len(files), "bundle_sha256": paths[manifest_name], "runner_sha256": identity["runner_sha256"], "git_matches": len(files) if git else None, "runtime": {k: runtime[k] for k in ("php", "phpunit", "database")}, "autoload_count": len(runtime["autoload"]), "portable_mapping": mapping_status(source_id), "limits": [command["input_scope"]]}
                    report["runs"][rid + "/" + prefix.rstrip("/")] = {
                        "command": name, "result": result_name, "source": source_id,
                        "head": identity["head"], "source_unchanged": True,
                        "process_exit": result["process_exit"], "verifier_exit": result["verifier_exit"],
                        "counts": counts, "xml_problem": problem, "kind": "PHPUnit",
                        "status": "aborted before test counts" if problem else ("pass" if result["process_exit"] == 0 else "failed"),
                        "declared_driver": public_env["DB_CONNECTION"], "selection": command["selected_files"],
                        "filter": command["filter"], "duration_seconds": result["wall_seconds"],
                        "workload_overlap": command["workload_scope"],
                        "diagnostic_only": diagnostic,
                    }
                    if diagnostic:
                        report["sources"][source_id]["limits"].append(command["diagnostic_limits"])
                        report["sources"][source_id]["diagnostic_only"] = True
                        report["runs"][rid + "/" + prefix.rstrip("/")]["overlay_count"] = len(expected_overlays)
                    continue
                bundle_name = command.get("bundle")
                assert bundle_name in bundles, (rid, bundle_name)
                bundle = bundles[bundle_name]
                assert paths[bundle_name] == command["bundle_sha256"]
                before_head = command.get("before_head", command.get("head"))
                after_head = result.get("after_head", result.get("head_after"))
                assert before_head == after_head == bundle["head"]
                assert command["before_manifest_sha256"] == result["after_manifest_sha256"]
                assert command["before_manifest_sha256"] == digest(json.dumps(bundle["files"], sort_keys=True).encode())
                for key in ("source_changes", "source_deltas"):
                    assert not result.get(key)
                for key in ("inputs_unchanged", "metadata_unchanged", "autoload_unchanged", "runner_unchanged"):
                    assert result.get(key, True) is True
                if bundle.get("overlays"):
                    assert result["overlay_unchanged"]
                    code = command["argv"][command["argv"].index("-r") + 1]
                    expected_code = '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php";require $GLOBALS["_composer_autoload_path"];'
                    expected_code += "".join("require " + json.dumps(entry["path"]) + ";" for entry in bundle["overlays"]["entries"])
                    expected_code += 'require "vendor/phpunit/phpunit/phpunit";'
                    assert code == expected_code, (rid, prefix, "Historical preload order/template changed")
                exit_code = result.get("process_exit", result.get("exit"))
                assert isinstance(exit_code, int)
                if prefix + "exit" in paths:
                    assert int(get(prefix + "exit")) == exit_code
                counts, problem = (None, None)
                xml_name = prefix + "junit.xml"
                is_phpunit = any("phpunit" in arg.lower() for arg in command["argv"])
                is_pint = any("pint" in arg.lower() for arg in command["argv"])
                assert is_phpunit != is_pint, (rid, prefix, "Unclassified command")
                if is_phpunit and xml_name not in paths:
                    assert exit_code != 0, (rid, prefix, "Successful PHPUnit without XML")
                    problem = "missing XML; no execution count inferred"
                if xml_name in paths:
                    counts, problem = xml_counts(get(xml_name))
                    assert not problem or exit_code != 0
                    assert exit_code != 0 or (counts and counts["errors"] == counts["failures"] == 0)
                recorded = result.get("leaf_counts", result.get("counts"))
                if counts is not None and recorded is not None:
                    assert counts == recorded, (rid, prefix, counts, recorded)
                report["runs"][rid + "/" + prefix.rstrip("/")] = {
                    "command": name, "result": result_name, "source": rid + "/" + bundle_name,
                    "head": before_head, "source_unchanged": True,
                    "process_exit": exit_code, "counts": counts, "xml_problem": problem,
                    "status": "aborted before test counts" if problem else ("pass" if exit_code == 0 else "failed"),
                    "kind": "PHPUnit" if is_phpunit else "formatting",
                    "declared_driver": command.get("declared_driver"),
                    "selection": command.get("selection"),
                    "duration_seconds": result.get("duration_seconds"),
                    "workload_overlap": command.get("workload_overlap"),
                    "overlay_source": report["sources"][rid + "/" + bundle_name].get("overlay_source"),
                }
            # The policy lane retained command and result in one receipt, with a
            # separate exact source manifest/runtime/runner bundle.
            for name in paths:
                if not name.endswith("/result.json"):
                    continue
                result = doc(name)
                if "source_sha256" in result and "all_source_blobs_match_commit" in result:
                    prefix = name.removesuffix("result.json")
                    source_name = prefix + "source.json"
                    files = doc(source_name)
                    assert paths[source_name] == result["source_sha256"]
                    assert len(files) == result["source_count"]
                    assert result["all_source_blobs_match_commit"] and result["sources_unchanged"] and not result["source_delta"]
                    assert result["head"] == result["head_after"]
                    assert result["autoload_before"] == result["autoload_after"] and result["autoload_unchanged"]
                    assert result["runner_sha256"] in paths.values()
                    assert int(get(prefix + "exit.txt")) == result["exit"]
                    source_id = rid + "/" + source_name
                    validate_mapping(source_id, files)
                    if git:
                        assert all(git.hash(source_ref(source_id, result["head"], p), p) == h for p, h in files.items())
                    selection = [arg for arg in result["argv"] if arg.startswith("tests/") and arg.endswith(".php")]
                    assert selection == ["tests/Unit/MemberActivationReadEnvironmentTest.php"]
                    assert all(path in files for path in selection)
                    assert "PHPUnit\\TextUI\\Application" in result["argv"][result["argv"].index("-r") + 1]
                    xml_name = prefix + "junit.xml"
                    counts, problem = xml_counts(get(xml_name)) if xml_name in paths else (None, "missing XML; no execution count inferred")
                    assert not problem or result["exit"] != 0
                    if counts:
                        assert counts == {"skipped" if k == "skips" else k: v for k, v in result["junit"].items()}
                    assert result["exit"] != 0 or (counts and counts["failures"] == counts["errors"] == 0)
                    report["sources"][source_id] = {"head": result["head"], "input_count": len(files), "bundle_sha256": paths[source_name], "runner_sha256": result["runner_sha256"], "git_matches": len(files) if git else None, "runtime": {"php": result["php"]}, "autoload_count": len(result["autoload_before"]), "portable_mapping": mapping_status(source_id), "limits": ["Source and autoload before/after are recorded; external runner hash is captured before execution only."]}
                    report["runs"][rid + "/" + prefix.rstrip("/")] = {"command_and_result": name, "source": source_id, "head": result["head"], "source_unchanged": True, "process_exit": result["exit"], "counts": counts, "xml_problem": problem, "kind": "PHPUnit", "status": "aborted before test counts" if problem else ("pass" if result["exit"] == 0 else "failed"), "declared_driver": "pure", "duration_seconds": result["seconds"], "selection": selection}
                    continue
                if "source" not in result or "runtime_identity" not in result:
                    continue
                prefix = name.removesuffix("result.json")
                bundle = result["bundle"]
                manifest_name = bundle + "/manifest.json"
                files = doc(manifest_name)
                assert all(HEX.fullmatch(h) for h in files.values())
                assert len(files) == result["manifest_count"]
                assert digest(json.dumps(files, sort_keys=True, separators=(",", ":")).encode()) == result["manifest_digest"]
                assert result["before_git_equal"] and result["after_equal"] and not result.get("drift")
                assert result["source"] == result["after_source"]
                head = result["source"]["head"]
                extras = result.get("observed_extra_paths", [])
                assert len(extras) == len(set(extras)) and set(extras) <= set(files)
                tracked_count = len(files) - len(extras)
                assert result.get("tracked_manifest_count", tracked_count) == tracked_count
                validate_mapping(rid + "/" + manifest_name, files, extras)
                if git:
                    mismatch = [p for p, h in files.items() if p not in extras and git.hash(source_ref(rid + "/" + manifest_name, head, p), p) != h]
                    assert not mismatch, (rid, prefix, mismatch)
                assert paths[bundle + "/runner.py"] == result["runner_sha256"]
                runtime = doc(bundle + "/runtime.json")
                assert runtime == result["runtime_identity"]
                if "after_runtime_identity" in result:
                    assert result["after_runtime_identity"] == runtime and result["runtime_after_equal"]
                    assert result["after_runner_sha256"] == result["runner_sha256"] and result["runner_after_equal"]
                assert runtime["composer_lock_sha256"] == files["composer.lock"]
                assert paths[prefix + "log.txt"] == result["log_sha256"]
                counts, problem = xml_counts(get(prefix + "junit.xml"))
                assert not problem or result["process_exit"] != 0
                assert result["process_exit"] != 0 or (counts and counts["errors"] == counts["failures"] == 0)
                if counts:
                    assert counts == {k: int(result["junit"][k]) for k in counts}
                source_id = rid + "/" + manifest_name
                report["sources"][source_id] = {
                    "head": head, "input_count": len(files), "bundle_sha256": paths[manifest_name],
                    "runner_sha256": result["runner_sha256"], "git_matches": tracked_count if git else None,
                    "non_git_inputs": {p: files[p] for p in extras},
                    "runtime": {k: runtime[k] for k in ("php", "phpunit")},
                    "autoload_count": len(runtime["autoload_sha256"]),
                    "portable_mapping": mapping_status(source_id),
                    "limits": (["Generated bootstrap/cache metadata hashes are observed runtime inputs, not Git-bound source."] if "after_runtime_identity" in result else ["Tracked source scope only; ignored/untracked additions are not inventoried.", "Runtime/autoload/runner hashes recorded before execution only; no after metadata receipt."]),
                }
                report["runs"][rid + "/" + prefix.rstrip("/")] = {
                    "command_and_result": name, "source": source_id, "head": head,
                    "source_unchanged": True, "process_exit": result["process_exit"],
                    "counts": counts, "xml_problem": problem,
                    "status": "aborted before test counts" if problem else ("pass" if result["process_exit"] == 0 else "failed"),
                    "kind": "PHPUnit",
                    "declared_driver": "pure", "selection": result["selected"],
                    "duration_seconds": result["wall_seconds"], "workload_overlap": result["workload_scope"],
                }
    finally:
        if git:
            git.close()
    report["storage"] = {"release_members": sum(r["members"] for r in report["releases"].values()), "unique_objects_including_manifests": len(registry["objects"]), "original_unique_bytes": sum(s["bytes"] for s in registry["objects"].values()), "archive_bytes": sum(s["archive_bytes"] for s in registry["objects"].values()), "delta_objects": sum(s["encoding"] == "line-delta-gzip-v1" for s in registry["objects"].values())}
    save(output, report)
    print(json.dumps({"sources": len(report["sources"]), "runs": len(report["runs"]), **report["storage"]}))


def make_map(commits_path, anchor, base):
    registry = load(HERE / "releases.json")
    index = load(HERE / "index.json")
    store, git = Store(registry), Git()
    commit_document = load(commits_path)
    commit_map = commit_document.get("author_to_integration", commit_document)
    resolve = lambda ref: subprocess.check_output(["git", "rev-parse", ref + "^{commit}"], cwd=REPO, text=True).strip()
    anchor, base = resolve(anchor), resolve(base)
    commit_map = {a if re.fullmatch(r"[0-9a-f]{40}", a) else resolve(a): resolve(b) for a, b in commit_map.items()}
    refs = subprocess.check_output(["git", "rev-list", anchor, "^" + base], cwd=REPO, text=True).splitlines() + [base]
    result = {"schema": "c4c-portable-source-map-v1", "anchor": anchor, "base": base,
              "meaning": "Exact named path-byte equivalence to reachable root versions; not execution of those versions or complete uncaptured harness proof.",
              "author_to_integration": commit_map, "sources": {}}
    source_items = dict(index["sources"])
    source_items.update({k: v for k, v in index["static"].items() if v.get("head")})
    try:
        for source_id, source in source_items.items():
            rid, path = source_id.split("/", 1)
            value = json.loads(store.read(registry["releases"][rid]["paths"][path]))
            files = value.get("files", value)
            if value.get("scope", "").startswith("Local synthetic CI policy self-tests only."):
                files = value["source_before"]
            elif "commands" in value and "owned_overlay" in value:
                files = value["before"]
            elif "commands" in value and "source" in value:
                files = value["source"]
            elif "entries" in value:
                files = {entry["source_path"]: entry["sha256"] for entry in value["entries"]}
            if "source_bindings" in value:
                files = {p: spec["sha256"] for p, spec in value["source_bindings"].items()}
            default = commit_map[source["head"]]
            assert default in refs
            overrides = {}
            for path, sha in files.items():
                if path in source.get("non_git_inputs", {}):
                    continue
                if git.hash(default, path) == sha:
                    continue
                equivalent = next((ref for ref in refs if git.hash(ref, path) == sha), None)
                assert equivalent, (source_id, path, "No reachable root path equivalence")
                overrides[path] = equivalent
            result["sources"][source_id] = {"author_head": source["head"], "default_ref": default, "input_count": len(files), "overrides": overrides, "non_git_inputs": source.get("non_git_inputs", {})}
    finally:
        git.close()
    save(HERE / "source-map.json", result)
    print(json.dumps({"sources": len(result["sources"]), "overrides": sum(len(s["overrides"]) for s in result["sources"].values()), "anchor": anchor}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="action", required=True)
    p = sub.add_parser("ingest"); p.add_argument("plan", type=pathlib.Path)
    p = sub.add_parser("verify"); p.add_argument("--git", action="store_true"); p.add_argument("--output", type=pathlib.Path, default=HERE / "index.json")
    p = sub.add_parser("cat"); p.add_argument("release"); p.add_argument("path")
    p = sub.add_parser("extract"); p.add_argument("release"); p.add_argument("destination", type=pathlib.Path)
    p = sub.add_parser("map"); p.add_argument("commits", type=pathlib.Path); p.add_argument("--anchor", required=True); p.add_argument("--base", required=True)
    args = parser.parse_args()
    if args.action == "ingest":
        ingest(args.plan)
    elif args.action == "verify":
        verify(args.output, args.git)
    elif args.action == "map":
        make_map(args.commits, args.anchor, args.base)
    else:
        registry = load(HERE / "releases.json")
        store = Store(registry)
        paths = registry["releases"][args.release]["paths"]
        if args.action == "cat":
            sys.stdout.buffer.write(store.read(paths[args.path]))
        else:
            assert not args.destination.exists(), "Extract into a new directory; never overwrite receipts"
            for name, sha in paths.items():
                path = args.destination / relative(name)
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(store.read(sha))


if __name__ == "__main__":
    main()
