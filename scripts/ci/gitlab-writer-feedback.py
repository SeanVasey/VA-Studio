#!/usr/bin/env python3
"""Source-bound GitLab candidate feedback; never a full acceptance/reuse receipt."""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("writer_database_proofs", ROOT / "scripts/ci/database-receipts.py")
proof = importlib.util.module_from_spec(spec)
spec.loader.exec_module(proof)
FILES = (
    "CatalogWriterAuthorityTest", "OfferWriterAuthorityTest", "TrackMetadataTest", "OfferRevisionTest",
    "RightsDeclarationWriterTest", "RightsDeclarationWriterActionTest", "RightsDeclarationWriterConcurrencyTest",
    "TrackPublicationManifestTest", "TrackPublicationGuardTest",
)


def number(env: dict, name: str) -> int:
    value = env.get(name, "")
    proof.require(isinstance(value, str) and re.fullmatch(r"[1-9][0-9]{0,19}", value) is not None,
                  "Missing GitLab numeric identity: " + name)
    return int(value)


def source(root: Path, env: dict) -> dict:
    proof.require(env.get("GITLAB_CI") == "true" and number(env, "CI_PROJECT_ID") == 87181037
                  and env.get("CI_PROJECT_PATH") == "vaseydev/va-studio", "Unexpected GitLab project")
    proof.require(env.get("CI_PIPELINE_SOURCE") in {"merge_request_event", "push", "web", "api"}, "Unsupported GitLab event")
    proof.require(env.get("CI_CONFIG_PATH", ".gitlab-ci.yml") == ".gitlab-ci.yml", "Unexpected CI configuration")
    head = proof.run(root, ["git", "rev-parse", "HEAD"], "commit").decode().strip()
    tree = proof.run(root, ["git", "rev-parse", "HEAD^{tree}"], "tree").decode().strip()
    proof.require(proof.identifier(head) and proof.identifier(tree) and head == env.get("CI_COMMIT_SHA"), "Checkout differs from GitLab event SHA")
    proof.run(root, ["git", "diff", "--quiet", "HEAD", "--"], "clean checkout")
    proof.require(not proof.run(root, ["git", "ls-files", "--others", "--exclude-standard", "-z"], "untracked source"), "Untracked source in checkout")
    return {"provider": "gitlab", "project_id": 87181037, "project_path": "vaseydev/va-studio",
            "commit": head, "tree": tree, "pipeline_id": number(env, "CI_PIPELINE_ID"), "job_id": number(env, "CI_JOB_ID"),
            "event": env["CI_PIPELINE_SOURCE"], "config_sha256": proof.digest(proof.read_file(root / ".gitlab-ci.yml"))}


def runtime(root: Path, engine: str) -> dict:
    php = proof.json_data(proof.run(root, ["php", "-r", 'echo json_encode(["version"=>PHP_VERSION,"extensions"=>get_loaded_extensions()],JSON_THROW_ON_ERROR);'], "PHP runtime"))
    proof.require(php["version"].startswith("8.4."), "PHP 8.4 required")
    _, dependencies = proof.locked_dependencies(root)
    database = {"engine": engine}
    if engine == "mysql":
        probe = '''require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(Illuminate\\Support\\Facades\\DB::selectOne("SELECT VERSION() AS version, @@performance_schema AS performance_schema, @@transaction_isolation AS isolation"),JSON_THROW_ON_ERROR);'''
        database.update(proof.json_data(proof.run(root, ["php", "-r", probe], "MySQL runtime")))
        proof.require(database["version"].startswith("8.4.") and database["performance_schema"] == 1, "Genuine MySQL 8.4 performance_schema required")
    else:
        database["version"] = proof.run(root, ["php", "-r", 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn();'], "SQLite runtime").decode()
    return {"php": php, "database": database, "dependencies": dependencies}


def result(root: Path, engine: str, discovery: bytes, junit: bytes) -> dict:
    proof.require(engine in {"mysql", "sqlite"}, "Unsupported database engine")
    inventory = proof.inventory(discovery, str(root))
    expected_files = {"tests/Feature/" + name + ".php" for name in FILES}
    proof.require(set(inventory["cases"].values()) == expected_files, "Selected suite discovery is incomplete")
    return proof.junit(junit, inventory, str(root), engine, proof.sqlite_skip_pairs(root))


def main() -> int:
    env = dict(os.environ)
    engine = env.get("DB_CONNECTION")
    proof.require(engine in {"mysql", "sqlite"}, "Select MySQL or SQLite explicitly")
    if engine == "sqlite":
        os.environ["DB_DATABASE"] = ":memory:"
    evidence = ROOT / "gitlab-ci-evidence"
    evidence.mkdir(exist_ok=True)
    for name in ("start.json", "result.json", "discovery.xml", "results.xml"):
        (evidence / name).unlink(missing_ok=True)
    identity = source(ROOT, env)
    if engine == "mysql":
        deadline = time.monotonic() + 60
        while True:
            try:
                observed_runtime = runtime(ROOT, engine)
                break
            except proof.ReceiptError:
                if time.monotonic() >= deadline:
                    raise
                time.sleep(1)
    else:
        observed_runtime = runtime(ROOT, engine)
    start = {"purpose": "gitlab-writer-feedback-not-acceptance", "source": identity, "runtime": observed_runtime}
    proof.write_json(evidence / "start.json", start)
    tests = ["tests/Feature/" + name + ".php" for name in FILES]
    subprocess.run(["php", "vendor/bin/phpunit", *tests, "--list-tests-xml=" + str(evidence / "discovery.xml")], cwd=ROOT, check=True)
    subprocess.run(["php", "vendor/bin/phpunit", *tests, "--log-junit=" + str(evidence / "results.xml"),
                    "--fail-on-phpunit-warning", "--display-warnings"], cwd=ROOT, check=True)
    proof.require(identity == source(ROOT, env) and observed_runtime == runtime(ROOT, engine), "Source/runtime changed during tests")
    outcome = result(ROOT, engine, proof.read_file(evidence / "discovery.xml"), proof.read_file(evidence / "results.xml"))
    proof.write_json(evidence / "result.json", start | {"result": outcome,
        "discovery_sha256": proof.digest(proof.read_file(evidence / "discovery.xml")),
        "junit_sha256": proof.digest(proof.read_file(evidence / "results.xml"))})
    print(json.dumps(outcome))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (proof.ReceiptError, subprocess.CalledProcessError) as error:
        print("GitLab candidate feedback failed: " + str(error), file=sys.stderr)
        sys.exit(1)
