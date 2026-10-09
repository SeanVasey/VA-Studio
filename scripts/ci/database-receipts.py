#!/usr/bin/env python3
"""Collect bounded current-run database receipts; never authorize CI reuse.

This stage has no prior-run consumer or mode output. The full Foundation gate
still runs. A collection artifact precedes its own job/run's final conclusion;
it is deliberately not a certificate of completed outer acceptance.
"""

from __future__ import annotations

import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import io
import json
import math
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zipfile


# Bind source and artifact provenance to the verified GitHub destination.
REPOSITORY = "SeanVasey/VA-Studio"
REPOSITORY_ID = 1402461806
WORKFLOW_PATH = ".github/workflows/final-verification.yml"
NS = "{https://xml.phpunit.de/testSuite}"
SHA = re.compile(r"[0-9a-f]{40}\Z")
HASH = re.compile(r"[0-9a-f]{64}\Z")
MAX_FILE = 8 * 1024 * 1024
MAX_ZIP = 8 * 1024 * 1024
MAX_UNPACKED = 16 * 1024 * 1024
MAX_JSON = 1024 * 1024
MAX_XML_DEPTH = 32
MAX_XML_NODES = 50000
POLICY_FILES = (
    WORKFLOW_PATH, "scripts/ci/database-receipts.py", "scripts/ci/test-database-receipts.py",
    "scripts/ci/ci-scope.py", "scripts/ci/phpunit-shards.py", "phpunit.xml",
    "scripts/ci/verify-php-test-runtime.php", "scripts/ci/test-php-test-runtime.py",
    "scripts/ci/database-sqlite-skips.json", "scripts/ci/database-mysql-selection.json", "scripts/ci/database-mysql-skips.json",
    "composer.json", "composer.lock", "package.json", "package-lock.json",
    "scripts/ci/phpunit-timings-mysql.json", "scripts/ci/phpunit-timings-sqlite.json",
)
COUNTS = {"mysql": 8, "sqlite": 2}
SQLITE_SKIP_POLICY = "scripts/ci/database-sqlite-skips.json"
SELECTION_POLICY = "scripts/ci/database-mysql-selection.json"
MYSQL_SKIP_POLICY = "scripts/ci/database-mysql-skips.json"
# The step that derives each engine's partition; MySQL partitions only its reviewed native selection.
PARTITION_STEPS = {"mysql": "Prove the native-selection MySQL test partition", "sqlite": "Prove the complete SQLite test partition"}


class ReceiptError(Exception):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ReceiptError(message)


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical(data: object) -> bytes:
    return json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode()


def unique_object(pairs: list[tuple[str, object]]) -> dict:
    result = {}
    for key, value in pairs:
        require(key not in result, "Duplicate JSON field")
        result[key] = value
    return result


def json_data(raw: bytes, limit: int = MAX_JSON) -> object:
    require(len(raw) <= limit, "JSON exceeds its bounded size")
    try:
        return json.loads(raw, object_pairs_hook=unique_object,
                          parse_constant=lambda _: (_ for _ in ()).throw(ReceiptError("Nonfinite JSON value")))
    except (ValueError, UnicodeError, RecursionError) as error:
        raise ReceiptError("Invalid bounded JSON") from error


def read_file(path: Path) -> bytes:
    require(path.is_file() and not path.is_symlink() and path.stat().st_size <= MAX_FILE,
            "Missing, linked or oversized evidence file")
    return path.read_bytes()


def run(root: Path, arguments: list[str], label: str, limit: int = MAX_FILE) -> bytes:
    result = subprocess.run(arguments, cwd=root, capture_output=True, check=False, timeout=30)
    require(result.returncode == 0 and len(result.stdout) <= limit, "Probe failed: " + label)
    return result.stdout


def positive(value: object) -> bool:
    return type(value) is int and value > 0


def identifier(value: object) -> bool:
    return isinstance(value, str) and SHA.fullmatch(value) is not None


def github_number(env: dict, name: str) -> int:
    value = env.get(name, "")
    require(isinstance(value, str) and re.fullmatch(r"[1-9][0-9]{0,19}", value) is not None,
            "Missing GitHub numeric identity: " + name)
    return int(value)


def source_identity(root: Path, env: dict) -> dict:
    commit = run(root, ["git", "rev-parse", "HEAD"], "checkout commit").decode().strip()
    tree = run(root, ["git", "rev-parse", "HEAD^{tree}"], "checkout tree").decode().strip()
    require(identifier(commit) and identifier(tree) and commit == env.get("GITHUB_SHA"),
            "Actual checkout differs from the event commit")
    run(root, ["git", "diff", "--quiet", "HEAD", "--"], "clean tracked checkout")
    require(not run(root, ["git", "ls-files", "--others", "--exclude-standard", "-z"], "untracked checkout paths"),
            "Checkout contains nonignored untracked source")
    # Read raw commit headers: shallow-checkout revision traversal hides parents.
    headers = run(root, ["git", "cat-file", "-p", commit], "commit parents").split(b"\n\n", 1)[0]
    parents = [line[7:].decode() for line in headers.splitlines() if line.startswith(b"parent ")]
    require(all(identifier(parent) for parent in parents), "Invalid checkout parent")
    event = json_data(read_file(Path(env["GITHUB_EVENT_PATH"])), MAX_FILE)
    require(isinstance(event, dict) and env.get("GITHUB_REPOSITORY") == REPOSITORY
            and github_number(env, "GITHUB_REPOSITORY_ID") == REPOSITORY_ID
            and event.get("repository", {}).get("id") == REPOSITORY_ID
            and event.get("repository", {}).get("full_name") == REPOSITORY,
            "Unexpected repository provenance")
    event_name = env.get("GITHUB_EVENT_NAME")
    require(event_name in {"pull_request", "push", "workflow_dispatch"}, "Unsupported Foundation event")
    provenance = {"name": event_name, "repository": REPOSITORY, "repository_id": REPOSITORY_ID,
                  "pr": None, "base": None, "head": commit, "head_repository_id": REPOSITORY_ID,
                  "before": None, "after": None, "ref": env.get("GITHUB_REF"), "forced": None}
    if event_name == "pull_request":
        pr = event.get("pull_request", {})
        base, head = pr.get("base", {}), pr.get("head", {})
        require(positive(pr.get("number")) and identifier(base.get("sha")) and identifier(head.get("sha"))
                and base.get("repo", {}).get("id") == REPOSITORY_ID and positive(head.get("repo", {}).get("id"))
                and env.get("GITHUB_REF") == f"refs/pull/{pr['number']}/merge"
                and parents == [base["sha"], head["sha"]], "PR checkout does not match its ordered base/head pair")
        provenance.update(pr=pr["number"], base=base["sha"], head=head["sha"],
                          head_repository_id=head.get("repo", {}).get("id"))
    elif event_name == "push":
        require(event.get("after") == commit and event.get("ref") == env.get("GITHUB_REF") == "refs/heads/main"
                and identifier(event.get("before")) and type(event.get("forced")) is bool,
                "Unexpected main push provenance")
        provenance.update(before=event["before"], after=commit, forced=event["forced"])
    else:
        require(isinstance(env.get("GITHUB_REF"), str) and env["GITHUB_REF"].startswith(("refs/heads/", "refs/tags/")),
                "Unexpected manual workflow ref")
        run(root, ["git", "check-ref-format", env["GITHUB_REF"]], "manual workflow ref")
    workflow_sha = env.get("GITHUB_WORKFLOW_SHA", "")
    workflow_ref = env.get("GITHUB_WORKFLOW_REF", "")
    require(workflow_sha == commit and workflow_ref == REPOSITORY + "/" + WORKFLOW_PATH + "@" + env["GITHUB_REF"],
            "Executed workflow differs from the exact checkout and event ref")
    entries = run(root, ["git", "ls-tree", "-z", commit, "--", *POLICY_FILES], "tracked policy files").split(b"\0")[:-1]
    require(len(entries) == len(POLICY_FILES) and all(entry.startswith(b"100644 blob ") for entry in entries),
            "Receipt policy must consist of tracked ordinary files")
    return {"checkout_commit": commit, "checkout_tree": tree, "checkout_parents": parents,
            "checkout_root": str(root.resolve()), "tracked_worktree_clean": True, "nonignored_untracked_absent": True, "event": provenance,
            "run_id": github_number(env, "GITHUB_RUN_ID"), "run_attempt": github_number(env, "GITHUB_RUN_ATTEMPT"),
            "workflow_sha": workflow_sha, "workflow_ref": workflow_ref,
            "policy_sha256": {name: digest(read_file(root / name)) for name in POLICY_FILES}}


def package_references(rows: object) -> dict:
    require(isinstance(rows, list) and rows, "Empty dependency evidence")
    result = {}
    for package in rows:
        require(isinstance(package, dict) and isinstance(package.get("name"), str)
                and package["name"] not in result, "Duplicate or invalid dependency")
        result[package["name"]] = {"version": package.get("version"),
                                  "source_reference": package.get("source", {}).get("reference"),
                                  "dist_reference": package.get("dist", {}).get("reference")}
    return result


def locked_dependencies(root: Path) -> tuple[dict, dict]:
    raw = read_file(root / "composer.lock")
    lock = json_data(raw, MAX_FILE)
    require(isinstance(lock, dict), "Invalid Composer lock")
    expected = package_references(lock.get("packages", []) + lock.get("packages-dev", []))
    return expected, {"package_count": len(expected), "installed_identity_sha256": digest(canonical(expected)),
                      "composer_lock_sha256": digest(raw)}


def installed_dependencies(root: Path) -> dict:
    expected, identity = locked_dependencies(root)
    installed = json_data(read_file(root / "vendor/composer/installed.json"), MAX_FILE)
    require(isinstance(installed, dict) and installed.get("dev") is True,
            "Installed Composer dependency shape or development mode differs")
    observed = package_references(installed.get("packages"))
    require(expected == observed, "Installed dependencies differ from the committed Composer lock")
    return identity


def validate_discovered_files(root: Path, files: set[str]) -> None:
    entries = run(root, ["git", "ls-tree", "-z", "HEAD", "--", *sorted(files)], "tracked discovered tests").split(b"\0")[:-1]
    require(len(entries) == len(files) and all(entry.startswith(b"100644 blob ") for entry in entries)
            and {entry.split(b"\t", 1)[1].decode() for entry in entries} == files,
            "Discovered tests include an untracked or non-ordinary source file")


def validate_test_environment(engine: str, env: dict) -> None:
    require(env.get("APP_ENV") == "testing" and env.get("DB_CONNECTION") == engine
            and env.get("DB_URL", "") == env.get("DB_SOCKET", "") == "",
            "Explicit synthetic testing database required")
    if engine == "mysql":
        require(env.get("DB_HOST") == "127.0.0.1" and str(env.get("DB_PORT")) == "3306"
                and env.get("DB_DATABASE") == "vaseyaudio_test", "Unexpected synthetic MySQL target")
    else:
        require(engine == "sqlite" and env.get("DB_DATABASE") == ":memory:", "Only synthetic in-memory SQLite supported")


def runtime_identity(root: Path, engine: str, env: dict) -> dict:
    validate_test_environment(engine, env)
    run(root, ["php", "scripts/ci/verify-php-test-runtime.php"], "bounded PHP test capabilities")
    # Print only selected public version/settings fields, never phpinfo or environment/DSN dumps.
    php_probe = r'''$e = []; foreach (get_loaded_extensions() as $x) { $e[$x] = phpversion($x) ?: null; } ksort($e);
echo json_encode(['version'=>PHP_VERSION,'integer_size'=>PHP_INT_SIZE,'memory_limit'=>ini_get('memory_limit'),'extensions'=>$e], JSON_THROW_ON_ERROR);'''
    php = json_data(run(root, ["php", "-r", php_probe], "PHP runtime"))
    require(isinstance(php, dict) and isinstance(php.get("extensions"), dict), "Invalid PHP runtime evidence")
    tools = {}
    for name, argument in (("php", "--version"), ("composer", "--version"), ("ffmpeg", "-version"),
                           ("qpdf", "--version"), ("pdftocairo", "-v"), ("flock", "--version")):
        path = shutil.which(name)
        require(path is not None and Path(path).resolve().is_file(), "Missing runtime tool: " + name)
        completed = subprocess.run([name, argument], cwd=root, capture_output=True, check=False, timeout=30)
        output = completed.stdout + completed.stderr
        require(completed.returncode == 0 and 0 < len(output) <= 16384, "Invalid version probe: " + name)
        tools[name] = {"binary_sha256": digest(Path(path).resolve().read_bytes()), "version_output_sha256": digest(output)}
    if engine == "mysql":
        require(env.get("DB_CONNECTION") == "mysql", "MySQL receipt uses the wrong configured driver")
        probe = r'''$p=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$r=$p->query("SELECT @@version AS version, @@version_comment AS version_comment, @@sql_mode AS sql_mode, @@character_set_server AS character_set_server, @@collation_server AS collation_server, @@transaction_isolation AS transaction_isolation, @@default_storage_engine AS default_storage_engine, @@lower_case_table_names AS lower_case_table_names, @@innodb_strict_mode AS innodb_strict_mode, @@performance_schema AS performance_schema")->fetch(PDO::FETCH_ASSOC); echo json_encode($r,JSON_THROW_ON_ERROR);'''
        database = json_data(run(root, ["php", "-r", probe], "MySQL settings"))
        container = env.get("MYSQL_CONTAINER_ID", "")
        require(re.fullmatch(r"[0-9a-f]{12,64}", container) is not None, "Missing MySQL service identity")
        image_id = run(root, ["docker", "inspect", "--format={{.Image}}", container], "MySQL image identity").decode().strip()
        require(re.fullmatch(r"sha256:[0-9a-f]{64}", image_id) is not None, "Invalid MySQL image identity")
        database["container_image_id"] = image_id
    else:
        require(env.get("DB_CONNECTION") == "sqlite", "SQLite receipt uses the wrong configured driver")
        database = json_data(run(root, ["php", "-r", "echo json_encode(['version'=>(new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn()],JSON_THROW_ON_ERROR);"], "SQLite runtime"))
    runner = {name: env.get(name) for name in ("RUNNER_OS", "RUNNER_ARCH", "ImageOS", "ImageVersion")}
    require(all(isinstance(value, str) and 0 < len(value) <= 200 for value in runner.values()), "Incomplete runner identity")
    return {"engine": engine, "runner": runner, "os_release_sha256": digest(read_file(Path("/etc/os-release").resolve())),
            "php": php, "tools": tools, "database": database, "dependencies": installed_dependencies(root)}


def validate_runtime(value: dict, engine: str) -> None:
    require(set(value) == {"engine", "runner", "os_release_sha256", "php", "tools", "database", "dependencies"}
            and value["engine"] == engine and HASH.fullmatch(value["os_release_sha256"]) is not None,
            "Incomplete runtime receipt")
    require(set(value["runner"]) == {"RUNNER_OS", "RUNNER_ARCH", "ImageOS", "ImageVersion"}
            and all(isinstance(item, str) and 0 < len(item) <= 200 for item in value["runner"].values()), "Incomplete runner receipt")
    require(set(value["php"]) == {"version", "integer_size", "memory_limit", "extensions"}
            and type(value["php"]["integer_size"]) is int and value["php"]["integer_size"] == 8
            and value["php"]["memory_limit"] == "512M"
            and isinstance(value["php"]["version"], str)
            and re.fullmatch(r"8\.4\.[0-9]+", value["php"]["version"]) is not None
            and isinstance(value["php"]["extensions"], dict)
            and {"fileinfo", "mbstring", "intl", "PDO", "pdo_mysql", "pdo_sqlite", "bcmath", "gd", "zip", "curl", "dom", "xml", "xmlwriter", "posix", "pcntl"} <= set(value["php"]["extensions"]),
            "Incomplete PHP runtime receipt")
    require(set(value["tools"]) == {"php", "composer", "ffmpeg", "qpdf", "pdftocairo", "flock"}, "Incomplete tool receipt")
    for tool in value["tools"].values():
        require(set(tool) == {"binary_sha256", "version_output_sha256"}
                and all(isinstance(item, str) and HASH.fullmatch(item) is not None for item in tool.values()), "Invalid tool fingerprint")
    dependencies = value["dependencies"]
    require(set(dependencies) == {"package_count", "installed_identity_sha256", "composer_lock_sha256"}
            and positive(dependencies["package_count"])
            and all(isinstance(dependencies[key], str) and HASH.fullmatch(dependencies[key]) is not None
                    for key in ("installed_identity_sha256", "composer_lock_sha256")), "Incomplete dependency fingerprint")
    database = value["database"]
    require(isinstance(database, dict) and isinstance(database.get("version"), str), "Incomplete engine identity")
    if engine == "sqlite":
        require(set(database) == {"version"} and re.fullmatch(r"3\.[0-9]+\.[0-9]+", database["version"]) is not None, "Invalid SQLite identity")
    else:
        require(set(database) == {"version", "version_comment", "sql_mode", "character_set_server", "collation_server",
                                  "transaction_isolation", "default_storage_engine", "lower_case_table_names", "innodb_strict_mode", "performance_schema", "container_image_id"}
                and re.fullmatch(r"8\.4\.[0-9]+", database["version"]) is not None
                and isinstance(database["version_comment"], str) and isinstance(database["sql_mode"], str)
                and {"STRICT_TRANS_TABLES", "STRICT_ALL_TABLES"}.intersection(database["sql_mode"].split(","))
                and database["character_set_server"] == "utf8mb4" and isinstance(database["collation_server"], str)
                and database["collation_server"].startswith("utf8mb4_")
                and type(database["lower_case_table_names"]) in (int, str) and str(database["lower_case_table_names"]) == "0"
                and type(database["innodb_strict_mode"]) in (int, str) and str(database["innodb_strict_mode"]) == "1"
                and type(database["performance_schema"]) is int and database["performance_schema"] == 1
                and database["transaction_isolation"] == "REPEATABLE-READ" and database["default_storage_engine"] == "InnoDB"
                and isinstance(database["container_image_id"], str)
                and re.fullmatch(r"sha256:[0-9a-f]{64}", database["container_image_id"]) is not None, "Invalid MySQL service identity")


def xml(raw: bytes) -> ET.Element:
    require(len(raw) <= MAX_FILE and b"\0" not in raw and b"<!doctype" not in raw.lower() and b"<!entity" not in raw.lower(),
            "Unsafe or oversized XML")
    try:
        raw.decode("utf-8")
        root = ET.fromstring(raw)
    except (ET.ParseError, UnicodeError, RecursionError) as error:
        raise ReceiptError("Invalid evidence XML") from error
    stack, count = [(root, 1)], 0
    while stack:
        node, depth = stack.pop()
        count += 1
        require(depth <= MAX_XML_DEPTH and count <= MAX_XML_NODES, "Evidence XML exceeds its depth or node bound")
        stack.extend((child, depth + 1) for child in node)
    return root


def relative_file(value: str, checkout_root: str) -> str:
    require(isinstance(value, str) and "\\" not in value, "Invalid inventory file")
    path = PurePosixPath(value)
    if path.is_absolute():
        try:
            path = path.relative_to(PurePosixPath(checkout_root))
        except ValueError as error:
            raise ReceiptError("Inventory file escapes its recorded checkout") from error
    name = path.as_posix()
    require(name.startswith("tests/") and ".." not in path.parts and name.endswith(".php"), "Invalid inventory file")
    return name


def inventory(raw: bytes, checkout_root: str) -> dict:
    root = xml(raw)
    require(root.tag == NS + "testSuite" and [child.tag for child in root] == [NS + "tests", NS + "groups"],
            "Unknown inventory shape")
    cases, names, methods, groups = {}, {}, {}, Counter()
    for suite in root[0]:
        require(suite.tag == NS + "testClass" and set(suite.attrib) == {"name", "file"} and len(suite) > 0,
                "Unsupported or empty test class")
        owning_file = relative_file(suite.attrib["file"], checkout_root)
        for method in suite:
            require(method.tag == NS + "testMethod" and set(method.attrib) == {"id", "name"} and not list(method),
                    "Unknown inventory method")
            identifier_, name = method.attrib["id"], method.attrib["name"]
            prefix = suite.attrib["name"] + "::" + name
            require(identifier_.startswith(prefix) and identifier_ not in cases, "Duplicate or invalid inventory identity")
            suffix = identifier_[len(prefix):]
            require(not suffix or suffix.startswith("#"), "Unknown dataset identity")
            label = suffix[1:]
            numeric = re.fullmatch(r"0|[1-9][0-9]*|-[1-9][0-9]*", label) is not None and -(1 << 63) <= int(label) < (1 << 63)
            junit_name = name if not suffix else name + " with data set " + (suffix if numeric else '"' + label + '"')
            key = (suite.attrib["name"], junit_name)
            require(key not in names, "Ambiguous JUnit identity")
            cases[identifier_] = owning_file
            names[key] = identifier_
            methods[identifier_] = (suite.attrib["name"], name)
    require(cases, "Empty inventory")
    for group in root[1]:
        require(group.tag == NS + "group" and set(group.attrib) == {"name"}, "Unknown inventory group")
        for item in group:
            require(item.tag == NS + "test" and set(item.attrib) == {"id"} and not list(item)
                    and item.attrib["id"] in cases, "Unknown grouped case")
            groups[(group.attrib["name"], item.attrib["id"])] += 1
    return {"cases": cases, "names": names, "methods": methods, "groups": groups}


def sqlite_skip_pairs(root: Path) -> set[tuple[str, str]]:
    value = json_data(read_file(root / SQLITE_SKIP_POLICY))
    require(isinstance(value, dict) and set(value) == {"schema_version", "purpose", "methods"}
            and type(value["schema_version"]) is int and value["schema_version"] == 1 and value["purpose"] == "reviewed-mysql-only-sqlite-skip-methods"
            and isinstance(value["methods"], list) and value["methods"], "Unknown SQLite skip policy")
    result = set()
    for pair in value["methods"]:
        require(isinstance(pair, list) and len(pair) == 2 and all(isinstance(item, str) and item for item in pair)
                and tuple(pair) not in result, "Invalid or duplicate SQLite skip method")
        result.add(tuple(pair))
    return result


def mysql_skip_pairs(root: Path) -> set[tuple[str, str]]:
    """Return the reviewed SQLite-only (class, method) pairs that skip on MySQL; sorted, unique, may be empty."""
    value = json_data(read_file(root / MYSQL_SKIP_POLICY))
    require(isinstance(value, dict) and set(value) == {"schema_version", "purpose", "methods"}
            and type(value["schema_version"]) is int and value["schema_version"] == 1 and value["purpose"] == "reviewed-sqlite-only-mysql-skip-methods"
            and isinstance(value["methods"], list), "Unknown MySQL skip policy")
    result = set()
    for pair in value["methods"]:
        require(isinstance(pair, list) and len(pair) == 2 and all(isinstance(item, str) and item for item in pair)
                and tuple(pair) not in result, "Invalid or duplicate MySQL skip method")
        result.add(tuple(pair))
    require(value["methods"] == sorted(value["methods"]), "MySQL skip policy methods must be sorted")
    return result


def mysql_selection(root: Path) -> dict:
    """Return the committed, reviewed MySQL-native selection rules: migration file pattern and include list."""
    value = json_data(read_file(root / SELECTION_POLICY))
    require(isinstance(value, dict)
            and set(value) == {"schema_version", "purpose", "sqlite_skip_policy", "mysql_skip_policy", "file_pattern", "include_files"}
            and type(value["schema_version"]) is int and value["schema_version"] == 1
            and value["purpose"] == "reviewed-mysql-native-selection" and value["sqlite_skip_policy"] == SQLITE_SKIP_POLICY
            and value["mysql_skip_policy"] == MYSQL_SKIP_POLICY
            and isinstance(value["file_pattern"], str) and value["file_pattern"].startswith("^")
            and value["file_pattern"].endswith("$"), "Unknown MySQL selection policy")
    try:
        re.compile(value["file_pattern"])
    except re.error as error:
        raise ReceiptError("MySQL selection file pattern does not compile") from error
    include = value["include_files"]
    require(isinstance(include, list)
            and all(isinstance(name, str) and re.fullmatch(r"tests/[A-Za-z0-9_/]+\.php", name) is not None and ".." not in name for name in include)
            and include == sorted(set(include)), "MySQL selection include_files must be sorted, unique repository test paths")
    return {"file_pattern": value["file_pattern"], "include_files": tuple(include)}


def native_selection(full: dict, skip_pairs: set[tuple[str, str]], selection: dict | None) -> dict[str, str]:
    """Recompute the MySQL-native cases (identifier -> owning file) from the complete inventory.

    Whole files are selected: each file owning a reviewed SQLite-skipped method, each file whose
    repository path matches the reviewed migration pattern, and each reviewed include-list file.
    A listed file that is not in the complete inventory is refused.
    """
    require(isinstance(selection, dict) and set(selection) == {"file_pattern", "include_files"}
            and isinstance(selection["file_pattern"], str) and selection["file_pattern"]
            and isinstance(selection["include_files"], tuple), "Missing MySQL selection policy")
    require(skip_pairs <= set(full["methods"].values()), "Reviewed SQLite skip policy contains an undiscovered method")
    discovered = set(full["cases"].values())
    require(set(selection["include_files"]) <= discovered, "Reviewed MySQL include file is not in the complete inventory")
    files = {owner for identifier_, owner in full["cases"].items() if full["methods"][identifier_] in skip_pairs}
    files |= {owner for owner in discovered if re.fullmatch(selection["file_pattern"], owner)}
    files |= set(selection["include_files"])
    selected = {identifier_: owner for identifier_, owner in full["cases"].items() if owner in files}
    require(selected, "Empty MySQL-native selection")
    return selected


def census(value: dict) -> dict:
    return {"files": len(set(value["cases"].values())), "cases": len(value["cases"]),
            "case_identity_sha256": digest(json.dumps(sorted(value["cases"].items())).encode()),
            "group_identity_sha256": digest(json.dumps(sorted(value["groups"].items())).encode())}


def junit(raw: bytes, expected: dict, checkout_root: str, engine: str, skip_pairs: set[tuple[str, str]]) -> dict:
    root = xml(raw)
    require(root.tag in {"testsuites", "testsuite"}, "Unknown JUnit root")
    seen, skipped, assertions = set(), [], 0
    def visit(node: ET.Element, owner: tuple[str, str] | None = None) -> None:
        nonlocal assertions
        if node.tag == "testsuite" and "file" in node.attrib:
            owner = (node.get("name", ""), relative_file(node.attrib["file"], checkout_root))
        if node.tag == "testcase":
            require(owner is not None and owner[0] == node.get("class"), "JUnit case has no matching owning class suite")
            key = (node.get("class"), node.get("name"))
            identifier_ = expected["names"].get(key)
            require(identifier_ is not None and identifier_ not in seen and expected["cases"][identifier_] == owner[1],
                    "Missing, duplicate or misattributed JUnit identity")
            require(not any(child.tag in {"failure", "error"} for child in node), "JUnit records a failure or error")
            require(all(child.tag in {"skipped", "system-out", "system-err"} for child in node), "Unknown JUnit case result")
            require(all(not list(child) for child in node), "Nested JUnit case result")
            value = node.get("assertions", "")
            require(re.fullmatch(r"[0-9]+", value) is not None, "Missing or invalid assertion count")
            assertions += int(value)
            try:
                time = float(node.get("time", ""))
            except ValueError as error:
                raise ReceiptError("Invalid JUnit time") from error
            require(math.isfinite(time) and time >= 0, "Invalid JUnit time")
            seen.add(identifier_)
            if node.find("skipped") is not None:
                # A skip may carry setUp assertions (PHPUnit counts them); the exact reviewed skip census below bounds which cases skip.
                require(sum(child.tag == "skipped" for child in node) == 1, "Skipped case has duplicate result nodes")
                skipped.append(identifier_)
        elif node.tag in {"testsuite", "testsuites"}:
            require(all(child.tag in {"testsuite", "testcase", "system-out", "system-err"} for child in node), "Unknown JUnit suite result")
            require(all(not list(child) for child in node if child.tag in {"system-out", "system-err"}), "Nested JUnit suite output")
        for child in node:
            if child.tag in {"testsuite", "testcase"}:
                visit(child, owner)
    visit(root)
    require(seen == set(expected["cases"]) and len(seen) > len(skipped), "Incomplete or all-skipped JUnit census")
    # skip_pairs is this engine's own reviewed census: SQLite skips MySQL-only methods, MySQL skips
    # SQLite-only methods. Missing and unlisted skips are both refused.
    expected_skipped = {identifier_ for identifier_, method in expected["methods"].items() if method in skip_pairs}
    require(set(skipped) == expected_skipped, "SQLite skip identities differ from the reviewed MySQL-only policy" if engine == "sqlite"
            else "MySQL skip identities differ from the reviewed SQLite-only policy")
    for suite in root.iter("testsuite"):
        children = list(suite.iter("testcase"))
        actual = {"tests": len(children), "assertions": sum(int(case.get("assertions", "0")) for case in children),
                  "errors": sum(case.find("error") is not None for case in children),
                  "failures": sum(case.find("failure") is not None for case in children),
                  "skipped": sum(case.find("skipped") is not None for case in children)}
        require(all(re.fullmatch(r"[0-9]+", suite.get(key, "")) is not None and int(suite.get(key)) == value
                    for key, value in actual.items()), "JUnit suite counters disagree with its actual descendant cases")
    return {"reported_cases": len(seen), "executed_cases": len(seen) - len(skipped), "skipped_cases": len(skipped),
            "skipped_ids": sorted(skipped), "assertions": assertions, "failures": 0, "errors": 0,
            "warning_evidence": "not represented by JUnit; existing PHPUnit-warning exit flag retained"}


def evidence_names(engine: str, shard: int, *, shard_count: int) -> set[str]:
    # The provider supplies its committed count; never trust an artifact or environment count.
    require(engine in COUNTS and positive(shard_count) and positive(shard) and shard <= shard_count,
            "Unknown database shard")
    prefix = "phpunit-ci-" + engine
    return {prefix + "-manifest.json", prefix + "-source-tests.xml",
            *(f"{prefix}-{index}{suffix}" for index in range(1, shard_count + 1) for suffix in (".xml", "-tests.xml")),
            f"{prefix}-{shard}-results.xml", f"{prefix}-{shard}-start.json", f"{prefix}-{shard}-receipt.json"}


def database_evidence(files: dict[str, bytes], source: dict, engine: str, shard: int, skip_pairs: set[tuple[str, str]], *, shard_count: int,
                      selection: dict | None = None, mysql_skips: set[tuple[str, str]] | None = None) -> dict:
    evidence_names(engine, shard, shard_count=shard_count)
    prefix = "phpunit-ci-" + engine
    full = inventory(files[prefix + "-source-tests.xml"], source["checkout_root"])
    require(skip_pairs <= set(full["methods"].values()), "Reviewed SQLite skip policy contains an undiscovered method")
    # SQLite partitions the complete inventory. MySQL partitions only the selection recomputed
    # here from the complete inventory and the committed policies; the manifest is not trusted.
    target_cases = full["cases"] if engine == "sqlite" else native_selection(full, skip_pairs, selection)
    target_groups = full["groups"] if engine == "sqlite" else Counter(
        {key: value for key, value in full["groups"].items() if key[1] in target_cases})
    if engine == "mysql":
        require(isinstance(mysql_skips, (set, frozenset)), "Missing MySQL skip policy")
        require(mysql_skips <= set(full["methods"].values()), "Reviewed MySQL skip policy contains an undiscovered method")
        require(not mysql_skips & skip_pairs, "A method is in both the SQLite and the MySQL skip policies")
        require(all(identifier_ in target_cases for identifier_, method in full["methods"].items() if method in mysql_skips),
                "Reviewed MySQL skip policy names a method outside the MySQL-native selection")
    shards = [inventory(files[f"{prefix}-{index}-tests.xml"], source["checkout_root"]) for index in range(1, shard_count + 1)]
    cases, groups, owners = Counter(), Counter(), Counter()
    for item in shards:
        require(all(full["cases"].get(identifier_) == owning_file for identifier_, owning_file in item["cases"].items()),
                "Shard case ownership differs from the complete source inventory")
        cases.update(item["cases"].keys())
        groups.update(item["groups"])
        owners.update(set(item["cases"].values()))
    require(cases == Counter({key: 1 for key in target_cases}) and groups == target_groups
            and owners == Counter({name: 1 for name in set(target_cases.values())}), "Partition loses or duplicates cases, files or groups")
    manifest = json_data(files[prefix + "-manifest.json"])
    full_census = census(full)
    manifest_keys = {"schema_version", "source_configuration_sha256", "source_files", "source_test_cases",
                     "source_case_identity_sha256", "source_group_identity_sha256", "proof", "weighting", "shards"}
    proof_text = "every expanded source case, file and group appears exactly once"
    if engine == "mysql":
        manifest_keys.add("selection")
        proof_text = "every selected case, file and group appears exactly once"
    require(isinstance(manifest, dict) and set(manifest) == manifest_keys
            and type(manifest.get("schema_version")) is int and manifest["schema_version"] == 1
            and manifest.get("proof") == proof_text
            and manifest.get("source_configuration_sha256") == source["policy_sha256"]["phpunit.xml"]
            and manifest.get("source_files") == full_census["files"] and manifest.get("source_test_cases") == full_census["cases"]
            and manifest.get("source_case_identity_sha256") == full_census["case_identity_sha256"]
            and manifest.get("source_group_identity_sha256") == full_census["group_identity_sha256"], "Manifest differs from its complete source census")
    weighting = manifest["weighting"]
    timing_path = f"scripts/ci/phpunit-timings-{engine}.json"
    require(isinstance(weighting, dict) and set(weighting) == {"basis", "timings_file", "timings_sha256", "timings_driver", "timings_source", "untimed_files"}
            and weighting["basis"] == "measured-milliseconds" and weighting["timings_file"] == timing_path
            and weighting["timings_sha256"] == source["policy_sha256"][timing_path] and weighting["timings_driver"] == engine
            and isinstance(weighting["timings_source"], str) and weighting["timings_source"]
            and isinstance(weighting["untimed_files"], list) and len(set(weighting["untimed_files"])) == len(weighting["untimed_files"])
            and set(weighting["untimed_files"]) <= set(target_cases.values()), "Partition weighting differs from the source policy")
    if engine == "mysql":
        expected_selection = {"policy": SELECTION_POLICY, "policy_sha256": source["policy_sha256"][SELECTION_POLICY],
                              "sqlite_skip_policy_sha256": source["policy_sha256"][SQLITE_SKIP_POLICY],
                              "mysql_skip_policy_sha256": source["policy_sha256"][MYSQL_SKIP_POLICY],
                              "files": len(set(target_cases.values())), "test_cases": len(target_cases),
                              "case_identity_sha256": digest(json.dumps(sorted(target_cases.items())).encode())}
        require(manifest["selection"] == expected_selection, "Manifest selection differs from the recomputed MySQL-native selection")
    entries = manifest.get("shards")
    require(isinstance(entries, list) and len(entries) == shard_count, "Missing partition entry")
    for index, item in enumerate(shards, 1):
        entry = entries[index - 1]
        require(isinstance(entry, dict) and set(entry) == {"index", "configuration", "files", "test_cases", "weight"}
                and positive(entry.get("weight")) and entry.get("index") == index and entry.get("files") == sorted(set(item["cases"].values()))
                and entry.get("test_cases") == len(item["cases"])
                and entry.get("configuration") == f"{prefix}-{index}.xml", "Manifest shard differs from its inventory")
    result_name = f"{prefix}-{shard}-results.xml"
    return {"source_census": full_census, "shard_census": census(shards[shard - 1]),
            "result_sha256": digest(files[result_name]), "file_sha256": {name: digest(raw) for name, raw in files.items()},
            "results": junit(files[result_name], shards[shard - 1], source["checkout_root"], engine, skip_pairs if engine == "sqlite" else mysql_skips)}


def write_json(path: Path, value: dict) -> None:
    raw = canonical(value) + b"\n"
    require(len(raw) <= MAX_JSON, "Receipt exceeds its bounded size")
    with path.open("xb") as output:
        output.write(raw)


def start(root: Path, engine: str, shard: int, env: dict) -> None:
    value = {"schema_version": 1, "purpose": "database-runtime-start-not-acceptance", "engine": engine, "shard": shard,
             "source": source_identity(root, env), "runtime": runtime_identity(root, engine, env),
             "started_at": datetime.now(timezone.utc).isoformat()}
    validate_runtime(value["runtime"], engine)
    write_json(root / f"phpunit-ci-{engine}-{shard}-start.json", value)


def finish(root: Path, engine: str, shard: int, env: dict) -> None:
    require(env.get("DATABASE_TEST_OUTCOME") == "success", "Database test step did not succeed")
    prefix = f"phpunit-ci-{engine}-{shard}"
    initial = json_data(read_file(root / (prefix + "-start.json")))
    source, runtime = source_identity(root, env), runtime_identity(root, engine, env)
    validate_runtime(runtime, engine)
    require(type(initial.get("schema_version")) is int and initial["schema_version"] == 1 and initial.get("purpose") == "database-runtime-start-not-acceptance"
            and initial.get("engine") == engine and initial.get("shard") == shard
            and initial.get("source") == source and initial.get("runtime") == runtime, "Source or runtime changed during the database job")
    names = evidence_names(engine, shard, shard_count=COUNTS[engine]) - {prefix + "-receipt.json"}
    files = {name: read_file(root / name) for name in names}
    validate_discovered_files(root, set(inventory(files[f"phpunit-ci-{engine}-source-tests.xml"], source["checkout_root"])["cases"].values()))
    value = {"schema_version": 1, "purpose": "database-job-receipt-not-acceptance", "engine": engine, "shard": shard,
             "source": source, "runtime": runtime, "runtime_sha256": digest(canonical(runtime)),
             "test_step_outcome": "success", "started_at": initial["started_at"],
             "finished_at": datetime.now(timezone.utc).isoformat(),
             **database_evidence(files, source, engine, shard, sqlite_skip_pairs(root), shard_count=COUNTS[engine],
                                 selection=mysql_selection(root) if engine == "mysql" else None,
                                 mysql_skips=mysql_skip_pairs(root) if engine == "mysql" else None)}
    write_json(root / (prefix + "-receipt.json"), value)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def download_host(url: str) -> bool:
    parsed = urllib.parse.urlsplit(url)
    host = parsed.hostname or ""
    return (parsed.scheme == "https" and parsed.username is None and parsed.password is None
            and parsed.port in {None, 443} and not parsed.fragment
            and any(host.endswith(suffix) and host != suffix[1:] for suffix in
                    (".blob.core.windows.net", ".actions.githubusercontent.com", ".githubusercontent.com")))


class Github:
    def __init__(self, token: str):
        require(isinstance(token, str) and token, "Missing read-only Actions token")
        self.token = token
        self.opener = urllib.request.build_opener(NoRedirect)

    def request(self, url: str, authenticated: bool, limit: int) -> tuple[int, dict, bytes]:
        require(url.startswith("https://api.github.com/repos/" + REPOSITORY + "/") if authenticated else download_host(url),
                "Untrusted API or artifact download origin")
        headers = {"User-Agent": "vaseyaudio-current-run-receipts", "Accept": "application/vnd.github+json"}
        if authenticated:
            headers.update(Authorization="Bearer " + self.token, **{"X-GitHub-Api-Version": "2026-03-10"})
        try:
            response = self.opener.open(urllib.request.Request(url, headers=headers), timeout=20)
        except urllib.error.HTTPError as error:
            if error.code not in {301, 302, 303, 307, 308}:
                raise ReceiptError("GitHub evidence request failed") from None
            response = error
        with response:
            raw = response.read(limit + 1)
            require(len(raw) <= limit, "Evidence download exceeds its bounded size")
            return response.status, {key.lower(): value for key, value in response.headers.items()}, raw

    def get(self, path: str) -> dict:
        status, _, raw = self.request("https://api.github.com/repos/" + REPOSITORY + path, True, MAX_FILE)
        require(status == 200, "GitHub JSON request was redirected")
        value = json_data(raw, MAX_FILE)
        require(isinstance(value, dict), "Unknown GitHub response shape")
        return value

    def pages(self, path: str, key: str) -> list:
        rows, total = [], None
        for page in range(1, 11):
            value = self.get(path + f"?per_page=100&page={page}")
            count, entries = value.get("total_count"), value.get(key)
            require(type(count) is int and 0 <= count < 1000 and isinstance(entries, list) and len(entries) <= 100,
                    "Unknown, capped or invalid pagination evidence")
            require(total is None or total == count, "Evidence changed during pagination")
            total = count
            rows.extend(entries)
            require(len({row.get("id") for row in rows}) == len(rows), "Duplicate paginated evidence")
            if len(rows) == total:
                return rows
            require(len(entries) == 100 and len(rows) < total, "Incomplete pagination")
        raise ReceiptError("Incomplete bounded pagination")

    def artifact(self, artifact_id: int) -> bytes:
        status, headers, _ = self.request(f"https://api.github.com/repos/{REPOSITORY}/actions/artifacts/{artifact_id}/zip", True, MAX_ZIP)
        require(status in {302, 307} and "location" in headers, "Missing artifact download redirect")
        # A new unauthenticated request; the API token never reaches a signed host.
        status, _, raw = self.request(headers["location"], False, MAX_ZIP)
        require(status == 200, "Unexpected artifact-host redirect")
        return raw


def archive(raw: bytes, expected: set[str]) -> dict[str, bytes]:
    require(len(raw) <= MAX_ZIP, "Oversized artifact archive")
    try:
        with zipfile.ZipFile(io.BytesIO(raw)) as zip_:
            entries = zip_.infolist()
            require(len(entries) == len(expected) and len(entries) <= 32
                    and {entry.filename for entry in entries} == expected, "Missing, duplicate or unexpected ZIP entry")
            require(sum(entry.file_size for entry in entries) <= MAX_UNPACKED, "Oversized expanded artifact")
            result = {}
            for entry in entries:
                require(entry.filename == PurePosixPath(entry.filename).name and "\\" not in entry.filename
                        and not entry.is_dir() and stat.S_IFMT(entry.external_attr >> 16) in {0, stat.S_IFREG}
                        and not (entry.flag_bits & 1) and entry.file_size <= MAX_FILE
                        and entry.compress_type in {zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED},
                        "Unsafe artifact member")
                result[entry.filename] = zip_.read(entry)
            return result
    except (zipfile.BadZipFile, RuntimeError, EOFError) as error:
        raise ReceiptError("Invalid artifact archive") from error


def shadow_decision() -> dict:
    return {"execution_mode": "full", "reuse_enabled": False, "prior_full_acceptance": "unknown",
            "source_comparison": "not performed", "runtime_comparison": "not performed",
            "protection_requirements": "unknown", "reason": "Receipt collection only; every existing full gate runs"}


def collect(root: Path, env: dict, api: Github) -> dict:
    source = source_identity(root, env)
    run_id, attempt = source["run_id"], source["run_attempt"]
    run_path = f"/actions/runs/{run_id}"
    # Resolve this repository's native workflow ID through its canonical path.
    # Repository copies receive new numeric IDs; source SHA/ref proof remains exact.
    workflow = api.get("/actions/workflows/" + WORKFLOW_PATH.rsplit("/", 1)[1])
    require(positive(workflow.get("id")) and workflow.get("path") == WORKFLOW_PATH
            and workflow.get("name") == "Foundation CI" and workflow.get("state") == "active",
            "Canonical Foundation workflow identity is unavailable")
    run_ = api.get(run_path)
    require(run_.get("id") == run_id and run_.get("run_attempt") == attempt
            and run_.get("workflow_id") == workflow["id"] and run_.get("path") == WORKFLOW_PATH
            and run_.get("event") == source["event"]["name"] and run_.get("head_sha") == source["event"]["head"]
            and run_.get("repository", {}).get("id") == REPOSITORY_ID
            and run_.get("head_repository", {}).get("id") == source["event"]["head_repository_id"],
            "Current-run API provenance differs from the checkout")
    jobs = api.pages(run_path + f"/attempts/{attempt}/jobs", "jobs")
    artifacts = api.pages(run_path + "/artifacts", "artifacts")
    receipts, artifact_proof = [], []
    executed = {engine: [] for engine in COUNTS}
    for engine, count in COUNTS.items():
        for shard in range(1, count + 1):
            job_name = f"backend-{engine} ({shard}/{count})"
            matched = [job for job in jobs if job.get("name") == job_name]
            require(len(matched) == 1, "Missing or duplicate required database job")
            job = matched[0]
            require(job.get("run_id") == run_id and job.get("run_attempt") == attempt
                    and job.get("head_sha") == source["event"]["head"] and job.get("status") == "completed"
                    and job.get("conclusion") == "success", "Database job is not successful in this explicit attempt")
            engine_label = "MySQL" if engine == "mysql" else "SQLite"
            for name in (f"Record source and {engine_label} runtime before tests", PARTITION_STEPS[engine],
                         f"Run all tests assigned to this {engine_label} shard", f"Verify complete {engine_label} shard receipt", "Retain partition and test evidence"):
                steps = [step for step in job.get("steps", []) if step.get("name") == name]
                require(len(steps) == 1 and steps[0].get("status") == "completed" and steps[0].get("conclusion") == "success",
                        "Required database evidence step is missing or unsuccessful")
            artifact_name = f"backend-{engine}-{shard}-{run_id}-{attempt}"
            matched = [item for item in artifacts if item.get("name") == artifact_name]
            require(len(matched) == 1, "Missing or duplicate required database artifact")
            metadata = matched[0]
            require(positive(metadata.get("id")) and metadata.get("expired") is False
                    and metadata.get("workflow_run", {}).get("id") == run_id
                    and metadata.get("workflow_run", {}).get("repository_id") == REPOSITORY_ID
                    and metadata.get("workflow_run", {}).get("head_repository_id") == source["event"]["head_repository_id"]
                    and metadata.get("workflow_run", {}).get("head_sha") == source["event"]["head"]
                    and isinstance(metadata.get("digest"), str) and metadata["digest"].startswith("sha256:")
                    and HASH.fullmatch(metadata["digest"][7:]) is not None,
                    "Invalid database artifact provenance or digest")
            require(datetime.fromisoformat(metadata["expires_at"].replace("Z", "+00:00")) > datetime.now(timezone.utc),
                    "Database artifact has expired")
            raw = api.artifact(metadata["id"])
            require(digest(raw) == metadata["digest"][7:], "Database artifact digest differs")
            files = archive(raw, evidence_names(engine, shard, shard_count=count))
            validate_discovered_files(root, set(inventory(files[f"phpunit-ci-{engine}-source-tests.xml"], source["checkout_root"])["cases"].values()))
            prefix = f"phpunit-ci-{engine}-{shard}"
            receipt = json_data(files.pop(prefix + "-receipt.json"))
            require(isinstance(receipt, dict) and set(receipt) == {"schema_version", "purpose", "engine", "shard", "source", "runtime", "runtime_sha256",
                    "test_step_outcome", "started_at", "finished_at", "source_census", "shard_census", "result_sha256", "file_sha256", "results"}
                    and type(receipt.get("schema_version")) is int and receipt["schema_version"] == 1
                    and receipt.get("purpose") == "database-job-receipt-not-acceptance"
                    and receipt.get("engine") == engine and receipt.get("shard") == shard
                    and receipt.get("source") == source and receipt.get("test_step_outcome") == "success"
                    and isinstance(receipt.get("runtime"), dict)
                    and receipt.get("runtime_sha256") == digest(canonical(receipt["runtime"])),
                    "Database receipt differs from current source or runtime identity")
            validate_runtime(receipt["runtime"], engine)
            require(receipt["runtime"]["dependencies"] == locked_dependencies(root)[1]
                    and receipt["runtime"]["dependencies"]["composer_lock_sha256"] == source["policy_sha256"]["composer.lock"],
                    "Runtime dependency references differ from the actual committed lock")
            proof = database_evidence(files, source, engine, shard, sqlite_skip_pairs(root), shard_count=count,
                                      selection=mysql_selection(root) if engine == "mysql" else None,
                                      mysql_skips=mysql_skip_pairs(root) if engine == "mysql" else None)
            require(all(receipt.get(key) == value for key, value in proof.items()), "Database receipt does not match retained inventories and results")
            executed[engine].append(inventory(files[f"phpunit-ci-{engine}-{shard}-tests.xml"], source["checkout_root"]))
            initial = json_data(files[prefix + "-start.json"])
            require(isinstance(initial, dict) and set(initial) == {"schema_version", "purpose", "engine", "shard", "source", "runtime", "started_at"}
                    and type(initial.get("schema_version")) is int and initial["schema_version"] == 1
                    and initial["purpose"] == "database-runtime-start-not-acceptance"
                    and initial["engine"] == engine and initial["shard"] == shard
                    and initial["source"] == source and initial["runtime"] == receipt["runtime"]
                    and initial["started_at"] == receipt["started_at"], "Pre-test runtime receipt differs")
            started = datetime.fromisoformat(receipt["started_at"].replace("Z", "+00:00"))
            finished = datetime.fromisoformat(receipt["finished_at"].replace("Z", "+00:00"))
            job_started = datetime.fromisoformat(job["started_at"].replace("Z", "+00:00"))
            job_finished = datetime.fromisoformat(job["completed_at"].replace("Z", "+00:00"))
            require(started.tzinfo is not None and finished.tzinfo is not None
                    and job_started <= started <= finished <= job_finished <= datetime.now(timezone.utc), "Receipt timestamps differ from its actual job interval")
            receipts.append(receipt)
            artifact_proof.append({"id": metadata["id"], "name": artifact_name, "digest": metadata["digest"], "job_id": job["id"]})
    require(len({canonical(receipt["source_census"]) for receipt in receipts}) == 1, "Database engines have different complete source censuses")
    # Each engine's original inventories plus exact JUnit matching prove complete coverage.
    # JUnit cannot distinguish skip versus incomplete; record SQLite identities, never invent a cause.
    sqlite_skipped = set()
    for receipt in receipts:
        if receipt["engine"] == "sqlite":
            sqlite_skipped.update(receipt["results"]["skipped_ids"])
    full = inventory(files["phpunit-ci-sqlite-source-tests.xml"], source["checkout_root"])
    full_ids = set(full["cases"])
    sqlite_census, mysql_census = sqlite_skip_pairs(root), mysql_skip_pairs(root)
    require(not sqlite_census & mysql_census, "A method is in both the SQLite and the MySQL skip policies")
    # Recompute the MySQL-native selection from the shared complete census and committed policies.
    selected_ids = set(native_selection(full, sqlite_census, mysql_selection(root)))
    mysql_expected_skips = {identifier_ for identifier_ in selected_ids if full["methods"][identifier_] in mysql_census}
    expected_ids = {"sqlite": full_ids, "mysql": selected_ids}
    for engine, inventories in executed.items():
        observed = Counter(identifier_ for item in inventories for identifier_ in item["cases"])
        require(observed == Counter({identifier_: 1 for identifier_ in expected_ids[engine]}),
                "Actual database shard executions lose or duplicate source cases" if engine == "sqlite"
                else "Actual MySQL shard executions differ from the MySQL-native selection")
        require(len({receipt["file_sha256"][f"phpunit-ci-{engine}-manifest.json"] for receipt in receipts if receipt["engine"] == engine}) == 1,
                "Database jobs derived inconsistent partition manifests")
    require(sqlite_skipped <= full_ids, "Unknown SQLite skipped identity")
    mysql_skipped = {identifier_ for receipt in receipts if receipt["engine"] == "mysql" for identifier_ in receipt["results"]["skipped_ids"]}
    require(mysql_skipped == mysql_expected_skips, "MySQL skip identities differ from the reviewed SQLite-only policy")
    # MySQL's exact selected partition and reviewed skips prove every other selected identity executed.
    require(sum(receipt["results"]["executed_cases"] for receipt in receipts if receipt["engine"] == "mysql") == len(selected_ids - mysql_skipped),
            "MySQL did not execute the complete MySQL-native selection")
    # No case may go unexecuted on both engines: every SQLite skip executed on MySQL and every MySQL skip on SQLite.
    mysql_executed = {identifier_ for item in executed["mysql"] for identifier_ in item["cases"]} - mysql_skipped
    require(mysql_skipped <= full_ids - sqlite_skipped, "A MySQL-skipped identity was not executed on SQLite")
    require(sqlite_skipped <= mysql_executed, "A SQLite-skipped identity was not executed on MySQL")
    require(api.get(run_path).get("run_attempt") == attempt, "Current run attempt changed during collection")
    return {"schema_version": 1, "purpose": "database-receipt-collection-shadow-only",
            "outer_acceptance": "pending; producing aggregate and workflow must subsequently finish successfully",
            "source": source, "artifacts": artifact_proof, "database_receipts": receipts,
            "sqlite_skip_policy": "exact identities match reviewed MySQL-only methods; JUnit cannot distinguish skip from incomplete; all counterparts executed on MySQL",
            "mysql_skip_policy": "exact identities match reviewed SQLite-only methods inside the selection; all counterparts executed on SQLite",
            "mysql_scope": "SQLite executed every source case exactly once (less reviewed skips); MySQL executed exactly the reviewed "
                           "native selection (files owning SQLite-skipped methods, migration test files and the reviewed include list) once, skipping only the reviewed SQLite-only methods",
            "shadow": shadow_decision()}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("start", "finish", "collect"))
    parser.add_argument("--engine", choices=tuple(COUNTS))
    parser.add_argument("--shard", type=int)
    args = parser.parse_args()
    root = Path.cwd()
    env = dict(os.environ)
    try:
        if args.command == "collect":
            require(args.engine is None and args.shard is None, "Collector has no shard arguments")
            value = collect(root, env, Github(env.get("RECEIPT_GITHUB_TOKEN", "")))
            write_json(root / "phpunit-ci-shadow-receipt.json", value)
            print(f"{sum(COUNTS.values())} current-run database receipts verified. Shadow decision: full; reuse disabled; outer acceptance pending.")
        else:
            require(args.engine in COUNTS and args.shard is not None and 1 <= args.shard <= COUNTS[args.engine], "Unknown database shard")
            (start if args.command == "start" else finish)(root, args.engine, args.shard, env)
            print("Database " + args.command + " receipt recorded; no CI reuse is authorized.")
        return 0
    except (ReceiptError, OSError, ValueError, KeyError, TypeError, AttributeError, RecursionError, subprocess.SubprocessError) as error:
        # No tokens, signed URLs, provider data, SQL bindings or subprocess output are printed.
        print("Database receipt evidence rejected: " + (str(error) if isinstance(error, ReceiptError) else type(error).__name__), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
