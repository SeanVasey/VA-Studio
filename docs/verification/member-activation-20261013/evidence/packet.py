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
            assert registry["releases"][rid]["manifest_sha256"] == release["manifest_sha256"], rid
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
                files = value.get("files")
                if isinstance(files, dict) and files and all(isinstance(v, str) and HEX.fullmatch(v) for v in files.values()) and value.get("head"):
                    bundles[name] = value
                    source_id = rid + "/" + name
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
                elif "source_bindings" in value:
                    checks = value["source_bindings"]
                    if git:
                        assert all(git.hash(source_ref(rid + "/" + name, value["head"], p), p) == spec["sha256"] for p, spec in checks.items())
                    report["static"][rid + "/" + name] = {"head": value["head"], "source_count": len(checks), "git_verified": with_git, "behavioral_execution": value.get("behavioral_execution")}
                elif "runs" in value and "before" in value and "after" in value:
                    assert value["before"] == value["after"]
                    assert value["runner_sha256"] in paths.values()
                    report["static"][rid + "/" + name] = {"observed_head": value.get("head"), "source_count": len(value["before"]), "before_after_equal": True, "exits": [r["exit"] for r in value["runs"]], "behavioral_execution": False}
            for name in paths:
                if not name.endswith("/command.json"):
                    continue
                prefix = name.removesuffix("command.json")
                result_name = prefix + "result.json"
                assert result_name in paths, (rid, prefix)
                command, result = doc(name), doc(result_name)
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
                exit_code = result.get("process_exit", result.get("exit"))
                assert isinstance(exit_code, int)
                if prefix + "exit" in paths:
                    assert int(get(prefix + "exit")) == exit_code
                counts, problem = (None, None)
                xml_name = prefix + "junit.xml"
                is_phpunit = any("phpunit" in arg.lower() for arg in command["argv"])
                is_pint = any("pint" in arg.lower() for arg in command["argv"])
                assert is_phpunit != is_pint, (rid, prefix, "Unclassified command")
                assert not is_phpunit or xml_name in paths, (rid, prefix, "Missing PHPUnit XML receipt")
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
                }
            # The policy lane retained command and result in one receipt, with a
            # separate exact source manifest/runtime/runner bundle.
            for name in paths:
                if not name.endswith("/result.json"):
                    continue
                result = doc(name)
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
                if git:
                    mismatch = [p for p, h in files.items() if git.hash(source_ref(rid + "/" + manifest_name, head, p), p) != h]
                    assert not mismatch, (rid, prefix, mismatch)
                assert paths[bundle + "/runner.py"] == result["runner_sha256"]
                runtime = doc(bundle + "/runtime.json")
                assert runtime == result["runtime_identity"]
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
                    "runner_sha256": result["runner_sha256"], "git_matches": len(files) if git else None,
                    "runtime": {k: runtime[k] for k in ("php", "phpunit")},
                    "autoload_count": len(runtime["autoload_sha256"]),
                    "portable_mapping": mapping_status(source_id),
                    "limits": ["Tracked source scope only; ignored/untracked additions are not inventoried.", "Runtime/autoload/runner hashes recorded before execution only; no after metadata receipt."],
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
    commit_map = load(commits_path)
    resolve = lambda ref: subprocess.check_output(["git", "rev-parse", ref + "^{commit}"], cwd=REPO, text=True).strip()
    anchor, base = resolve(anchor), resolve(base)
    commit_map = {resolve(a): resolve(b) for a, b in commit_map.items()}
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
            if "source_bindings" in value:
                files = {p: spec["sha256"] for p, spec in value["source_bindings"].items()}
            default = commit_map[source["head"]]
            assert default in refs
            overrides = {}
            for path, sha in files.items():
                if git.hash(default, path) == sha:
                    continue
                equivalent = next((ref for ref in refs if git.hash(ref, path) == sha), None)
                assert equivalent, (source_id, path, "No reachable root path equivalence")
                overrides[path] = equivalent
            result["sources"][source_id] = {"author_head": source["head"], "default_ref": default, "input_count": len(files), "overrides": overrides}
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
