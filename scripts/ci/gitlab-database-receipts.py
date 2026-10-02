#!/usr/bin/env python3
"""Native current-pipeline receipts. No prior-run reuse or outer acceptance claim."""
from __future__ import annotations

import argparse
from collections import Counter
from datetime import datetime, timezone
import importlib.util
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("database_proofs", ROOT / "scripts/ci/database-receipts.py")
proof = importlib.util.module_from_spec(spec)
spec.loader.exec_module(proof)
PROJECT = 87181037
PATH = "vaseydev/va-studio"
API = "https://gitlab.com/api/v4"
POLICY = tuple(dict.fromkeys((".gitlab-ci.yml", "scripts/ci/gitlab-database-receipts.py",
    "scripts/ci/test-gitlab-database-receipts.py", "scripts/ci/setup-gitlab-php.sh",
    "scripts/ci/setup-gitlab-node.sh", "scripts/ci/setup-gitlab-related-scanner.sh",
    "scripts/ci/test-gitlab-setup.py", "scripts/dev/test-bootstrap-macos.py", *proof.POLICY_FILES)))
DATABASE_JOBS = {f"backend-{engine}: [{shard}]": (engine, shard)
                 for engine, count in proof.COUNTS.items() for shard in range(1, count + 1)}
UPSTREAM = {"provenance-access", "frontend", "backend-quality", "related-browser",
            "operator-browser: [chromium-desktop]", "operator-browser: [webkit-mobile]", *DATABASE_JOBS}


def number(env: dict, key: str) -> int:
    value = env.get(key, "")
    proof.require(isinstance(value, str) and re.fullmatch(r"[1-9][0-9]{0,19}", value) is not None,
                  "Missing native numeric identity: " + key)
    return int(value)


def source(root: Path, env: dict) -> dict:
    proof.require(env.get("GITLAB_CI") == "true" and number(env, "CI_PROJECT_ID") == PROJECT
                  and env.get("CI_PROJECT_PATH") == PATH and env.get("CI_API_V4_URL") == API,
                  "Unexpected native project or API")
    event = env.get("CI_PIPELINE_SOURCE")
    proof.require(event in {"merge_request_event", "push", "web", "api"}
                  and env.get("CI_CONFIG_PATH", ".gitlab-ci.yml") == ".gitlab-ci.yml", "Unexpected event/configuration")
    if event == "push":
        proof.require(env.get("CI_COMMIT_BRANCH") == env.get("CI_DEFAULT_BRANCH") == "main", "Only main push acceptance is supported")
    if event == "merge_request_event":
        number(env, "CI_MERGE_REQUEST_IID")
        proof.require(number(env, "CI_MERGE_REQUEST_PROJECT_ID") == PROJECT
                      and env.get("CI_MERGE_REQUEST_EVENT_TYPE") == "detached", "Only same-project detached MR acceptance is supported")
    ref = f"refs/merge-requests/{number(env, 'CI_MERGE_REQUEST_IID')}/head" if event == "merge_request_event" else env.get("CI_COMMIT_REF_NAME")
    proof.require(isinstance(ref, str) and 0 < len(ref) <= 1024 and "\n" not in ref and "\r" not in ref, "Missing native source ref")
    commit = proof.run(root, ["git", "rev-parse", "HEAD"], "checkout commit").decode().strip()
    tree = proof.run(root, ["git", "rev-parse", "HEAD^{tree}"], "checkout tree").decode().strip()
    proof.require(proof.identifier(commit) and proof.identifier(tree) and commit == env.get("CI_COMMIT_SHA"), "Checkout differs from native event SHA")
    proof.run(root, ["git", "diff", "--quiet", "HEAD", "--"], "clean tracked checkout")
    proof.require(not proof.run(root, ["git", "ls-files", "--others", "--exclude-standard", "-z"], "untracked source"), "Untracked source in checkout")
    entries = proof.run(root, ["git", "ls-tree", "-z", "HEAD", "--", *POLICY], "tracked policy").split(b"\0")[:-1]
    proof.require(len(entries) == len(POLICY) and all(entry.startswith((b"100644 blob ", b"100755 blob ")) for entry in entries), "Missing or nonordinary tracked policy")
    return {"provider": "gitlab", "project_id": PROJECT, "project_path": PATH, "commit": commit, "tree": tree,
            "event": event, "ref": ref, "pipeline_id": number(env, "CI_PIPELINE_ID"),
            "policy_sha256": {name: proof.digest(proof.read_file(root / name)) for name in POLICY}}


def producer(env: dict) -> dict:
    name = env.get("CI_JOB_NAME")
    proof.require(name in DATABASE_JOBS, "Unexpected database producer job")
    proof.require(env.get("CI_JOB_IMAGE") == "php:8.4-cli-bookworm"
                  and env.get("CI_DISPOSABLE_ENVIRONMENT") == "true", "Disposable configured PHP image required")
    return {"job_id": number(env, "CI_JOB_ID"), "job_name": name, "runner_id": number(env, "CI_RUNNER_ID"),
            "job_image_reference": env["CI_JOB_IMAGE"]}


def runtime(root: Path, engine: str, env: dict) -> dict:
    proof.require(env.get("DB_CONNECTION") == engine and env.get("APP_ENV") == "testing", "Explicit testing database required")
    php = proof.json_data(proof.run(root, ["php", "-r", 'echo json_encode(["version"=>PHP_VERSION,"integer_size"=>PHP_INT_SIZE,"memory_limit"=>ini_get("memory_limit"),"extensions"=>get_loaded_extensions(),"binary_sha256"=>hash_file("sha256",PHP_BINARY)],JSON_THROW_ON_ERROR);'], "PHP identity"))
    tools = {}
    for name, option in (("composer", "--version"), ("ffmpeg", "-version"), ("qpdf", "--version"), ("pdftocairo", "-v"), ("flock", "--version")):
        path = shutil.which(name)
        proof.require(path is not None and Path(path).resolve().is_file(), "Missing runtime tool: " + name)
        result = subprocess.run([name, option], cwd=root, capture_output=True, timeout=30, check=False)
        raw = result.stdout + result.stderr
        proof.require(result.returncode == 0 and 0 < len(raw) <= 16384, "Invalid runtime tool probe: " + name)
        tools[name] = {"binary_sha256": proof.digest(Path(path).resolve().read_bytes()), "version_sha256": proof.digest(raw)}
    if engine == "mysql":
        proof.require(env.get("DB_HOST") == "mysql" and env.get("DB_DATABASE") == "vaseyaudio_test"
                      and env.get("DB_SOCKET", "") == env.get("DB_URL", "") == "", "Unexpected synthetic MySQL target")
        php_probe = r'''$p=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); echo json_encode($p->query("SELECT @@version AS version, @@version_comment AS version_comment, @@sql_mode AS sql_mode, @@character_set_server AS character_set_server, @@collation_server AS collation_server, @@transaction_isolation AS isolation, @@default_storage_engine AS storage_engine, @@lower_case_table_names AS lower_case_table_names, @@innodb_strict_mode AS innodb_strict_mode, @@performance_schema AS performance_schema")->fetch(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);'''
        database = proof.json_data(proof.run(root, ["php", "-r", php_probe], "genuine MySQL settings"))
        database["service_image_reference"] = "mysql:8.4"
        database["service_image_digest"] = "not exposed by GitLab service API; no reuse enabled"
    else:
        proof.require(env.get("DB_DATABASE") == ":memory:", "Only synthetic in-memory SQLite supported")
        database = proof.json_data(proof.run(root, ["php", "-r", 'echo json_encode(["version"=>(new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn()],JSON_THROW_ON_ERROR);'], "SQLite settings"))
    value = {"engine": engine, "php": php, "tools": tools, "database": database,
             "dependencies": proof.installed_dependencies(root), "os_release_sha256": proof.digest(proof.read_file(Path("/etc/os-release").resolve()))}
    validate_runtime(value, engine)
    return value


def validate_runtime(value: dict, engine: str) -> None:
    proof.require(isinstance(value, dict) and set(value) == {"engine", "php", "tools", "database", "dependencies", "os_release_sha256"}
                  and value["engine"] == engine and isinstance(value["os_release_sha256"], str)
                  and proof.HASH.fullmatch(value["os_release_sha256"]) is not None, "Incomplete native runtime")
    php = value["php"]
    proof.require(isinstance(php, dict) and set(php) == {"version", "integer_size", "memory_limit", "extensions", "binary_sha256"}
                  and isinstance(php["version"], str) and re.fullmatch(r"8\.4\.[0-9]+", php["version"]) is not None
                  and type(php["integer_size"]) is int and php["integer_size"] == 8 and php["memory_limit"] == "512M"
                  and isinstance(php["binary_sha256"], str) and proof.HASH.fullmatch(php["binary_sha256"]) is not None
                  and isinstance(php["extensions"], list) and all(isinstance(x, str) for x in php["extensions"])
                  and {"fileinfo", "pdo_mysql", "pdo_sqlite", "mbstring", "intl", "bcmath", "gd", "zip", "curl", "dom", "xml", "xmlwriter", "posix"} <= set(php["extensions"]), "Unsupported PHP runtime")
    proof.require(isinstance(value["tools"], dict) and set(value["tools"]) == {"composer", "ffmpeg", "qpdf", "pdftocairo", "flock"}
                  and all(isinstance(v, dict) and set(v) == {"binary_sha256", "version_sha256"}
                          and all(isinstance(x, str) and proof.HASH.fullmatch(x) for x in v.values()) for v in value["tools"].values()), "Incomplete tool identity")
    db = value["database"]
    if engine == "mysql":
        proof.require(isinstance(db, dict) and set(db) == {"version", "version_comment", "sql_mode", "character_set_server", "collation_server", "isolation", "storage_engine", "lower_case_table_names", "innodb_strict_mode", "performance_schema", "service_image_reference", "service_image_digest"}
                      and isinstance(db["version"], str) and re.fullmatch(r"8\.4\.[0-9]+", db["version"]) is not None
                      and isinstance(db["version_comment"], str) and isinstance(db["sql_mode"], str)
                      and {"STRICT_TRANS_TABLES", "STRICT_ALL_TABLES"}.intersection(db["sql_mode"].split(","))
                      and db["character_set_server"] == "utf8mb4" and isinstance(db["collation_server"], str)
                      and db["collation_server"].startswith("utf8mb4_") and str(db["lower_case_table_names"]) == "0"
                      and str(db["innodb_strict_mode"]) == "1" and type(db["performance_schema"]) is int and db["performance_schema"] == 1
                      and db.get("isolation") == "REPEATABLE-READ" and db.get("storage_engine") == "InnoDB"
                      and db.get("service_image_reference") == "mysql:8.4"
                      and db.get("service_image_digest") == "not exposed by GitLab service API; no reuse enabled", "Unsupported genuine MySQL runtime")
    else:
        proof.require(isinstance(db, dict) and set(db) == {"version"} and isinstance(db["version"], str) and db["version"].startswith("3."), "Unsupported SQLite runtime")


def start(root: Path, engine: str, shard: int, env: dict) -> None:
    identity, job = source(root, env), producer(env)
    proof.require(DATABASE_JOBS[job["job_name"]] == (engine, shard), "Producer/shard mismatch")
    prefix = f"phpunit-ci-{engine}-{shard}"
    for suffix in ("-start.json", "-receipt.json", "-results.xml"):
        (root / (prefix + suffix)).unlink(missing_ok=True)
    value = {"schema_version": 1, "purpose": "gitlab-database-start-not-acceptance", "engine": engine, "shard": shard,
             "source": identity, "producer": job, "checkout_root": str(root.resolve()), "runtime": runtime(root, engine, env),
             "started_at": datetime.now(timezone.utc).isoformat()}
    proof.write_json(root / (prefix + "-start.json"), value)


def evidence(files: dict, identity: dict, checkout_root: str, engine: str, shard: int, root: Path) -> dict:
    return proof.database_evidence(files, {"checkout_root": checkout_root, "policy_sha256": identity["policy_sha256"]}, engine, shard, proof.sqlite_skip_pairs(root))


def finish(root: Path, engine: str, shard: int, env: dict) -> None:
    proof.require(env.get("DATABASE_TEST_OUTCOME") == "success", "PHPUnit did not finish successfully")
    identity, job = source(root, env), producer(env)
    prefix = f"phpunit-ci-{engine}-{shard}"
    initial = proof.json_data(proof.read_file(root / (prefix + "-start.json")))
    observed = runtime(root, engine, env)
    proof.require(DATABASE_JOBS[job["job_name"]] == (engine, shard), "Producer/shard mismatch")
    proof.require(initial.get("schema_version") == 1 and type(initial.get("schema_version")) is int
                  and initial.get("purpose") == "gitlab-database-start-not-acceptance"
                  and initial.get("source") == identity and initial.get("producer") == job and initial.get("runtime") == observed
                  and initial.get("engine") == engine and type(initial.get("shard")) is int and initial["shard"] == shard
                  and initial.get("checkout_root") == str(root.resolve()), "Source, producer, shard or runtime changed during execution")
    files = {name: proof.read_file(root / name) for name in proof.evidence_names(engine, shard) - {prefix + "-receipt.json"}}
    proof.validate_discovered_files(root, set(proof.inventory(files[f"phpunit-ci-{engine}-source-tests.xml"], str(root.resolve()))["cases"].values()))
    value = initial | {"purpose": "gitlab-database-receipt-not-acceptance", "runtime_sha256": proof.digest(proof.canonical(observed)),
                       "test_step_outcome": "success", "finished_at": datetime.now(timezone.utc).isoformat(),
                       **evidence(files, identity, initial["checkout_root"], engine, shard, root)}
    proof.write_json(root / (prefix + "-receipt.json"), value)


def endpoint_label(path: str, external: bool = False) -> str:
    # Fixed labels only: never print token-bearing URLs, headers or HTTP bodies.
    if external:
        return "signed artifact download"
    if path == "/job":
        return "GET /job"
    if path.endswith("/artifacts"):
        return "GET /projects/:id/jobs/:id/artifacts"
    if "/jobs?" in path:
        return "GET /projects/:id/pipelines/:id/jobs"
    return "GET /projects/:id/pipelines/:id"


class Gitlab:
    def __init__(self, token: str):
        proof.require(isinstance(token, str) and token and "\n" not in token and "\r" not in token, "Missing native job token")
        self.token = token
        self.opener = urllib.request.build_opener(proof.NoRedirect)

    def request(self, path: str, limit: int = proof.MAX_JSON, external: bool = False) -> tuple[int, dict, bytes]:
        if external:
            parsed = urllib.parse.urlsplit(path)
            host = parsed.hostname or ""
            allowed = host == "cdn.artifacts.gitlab-static.net" or (host == "storage.googleapis.com" and parsed.path.startswith("/gitlab-gprd-artifacts/"))
            proof.require(parsed.scheme == "https" and allowed and parsed.port in {None, 443}
                          and parsed.username is None and parsed.password is None and not parsed.fragment, "Unapproved artifact host")
            url, headers = path, {}
        else:
            proof.require(path == "/job" or re.fullmatch(r"/projects/87181037/(?:pipelines/[1-9][0-9]*(?:/jobs(?:\?per_page=100&page=[1-9][0-9]*&include_retried=false)?)?|jobs/[1-9][0-9]*/artifacts)", path) is not None, "Unapproved native API path")
            url, headers = API + path, {"JOB-TOKEN": self.token}
        try:
            response = self.opener.open(urllib.request.Request(url, headers=headers), timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        except (urllib.error.URLError, OSError) as error:
            raise proof.ReceiptError("Native provenance transport unavailable: " + endpoint_label(path, external)) from error
        with response:
            raw = response.read(limit + 1)
            proof.require(len(raw) <= limit, "Oversized native API/artifact response")
            return response.status, {k.lower(): v for k, v in response.headers.items()}, raw

    def get(self, path: str) -> dict:
        status, _, raw = self.request(path)
        proof.require(status == 200, "Native provenance API denied: " + endpoint_label(path) + " HTTP " + str(status)
                      + "; project job-token READ_JOBS/READ_PIPELINES access is required for pipeline/jobs endpoints")
        return proof.json_data(raw)

    def jobs(self, pipeline: int) -> list[dict]:
        result = []
        for page in range(1, 11):
            status, headers, raw = self.request(f"/projects/{PROJECT}/pipelines/{pipeline}/jobs?per_page=100&page={page}&include_retried=false")
            proof.require(status == 200, "Native jobs API denied: GET /projects/:id/pipelines/:id/jobs HTTP " + str(status)
                          + "; READ_JOBS access is required")
            rows = proof.json_data(raw)
            proof.require(isinstance(rows, list) and len(rows) <= 100 and all(isinstance(row, dict) and proof.positive(row.get("id")) for row in rows), "Malformed jobs page")
            result.extend(rows)
            proof.require(len({row["id"] for row in result}) == len(result), "Duplicate native job ID")
            next_page = headers.get("x-next-page", "")
            if not next_page:
                total = headers.get("x-total")
                proof.require((total is not None or len(rows) < 100) and (total is None or (re.fullmatch(r"[0-9]+", total) is not None and int(total) == len(result))), "Incomplete native job pagination")
                return result
            proof.require(next_page == str(page + 1) and len(rows) == 100, "Malformed native pagination")
        raise proof.ReceiptError("Native jobs exceed bounded pagination")

    def artifact(self, job: int) -> bytes:
        status, headers, raw = self.request(f"/projects/{PROJECT}/jobs/{job}/artifacts", proof.MAX_ZIP)
        if status in {302, 307}:
            proof.require("location" in headers, "Artifact redirect missing target")
            status, _, raw = self.request(headers["location"], proof.MAX_ZIP, external=True)
        proof.require(status == 200, "Current job artifact is unavailable: exact-job archive HTTP " + str(status))
        return raw


def pipeline_provenance(api: Gitlab, identity: dict, env: dict) -> dict:
    own = api.get("/job")
    pipeline = api.get(f"/projects/{PROJECT}/pipelines/{identity['pipeline_id']}")
    proof.require(own.get("name") == env.get("CI_JOB_NAME") and own.get("status") == "running"
                  and own.get("runner", {}).get("id") == number(env, "CI_RUNNER_ID")
                  and own.get("id") == number(env, "CI_JOB_ID") and own.get("pipeline", {}).get("id") == identity["pipeline_id"]
                  and own.get("pipeline", {}).get("project_id") == PROJECT and own.get("pipeline", {}).get("sha") == identity["commit"]
                  and own.get("ref") == identity["ref"] and own.get("commit", {}).get("id") == identity["commit"], "Native current job differs from checkout")
    proof.require(pipeline.get("id") == identity["pipeline_id"] and pipeline.get("project_id") == PROJECT
                  and pipeline.get("sha") == identity["commit"] and pipeline.get("source") == identity["event"]
                  and pipeline.get("ref") == identity["ref"] and pipeline.get("status") == "running"
                  and timestamp(pipeline.get("created_at")) <= timestamp(own.get("started_at")) <= datetime.now(timezone.utc), "Native pipeline differs from event source")
    return pipeline


def timestamp(value: object) -> datetime:
    proof.require(isinstance(value, str), "Missing native time")
    try:
        date = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as error:
        raise proof.ReceiptError("Malformed native time") from error
    proof.require(date.tzinfo is not None, "Time has no timezone")
    return date


def accept_jobs(rows: list[dict], identity: dict, own_id: int) -> dict:
    proof.require(isinstance(rows, list) and all(isinstance(row, dict) and proof.positive(row.get("id")) for row in rows)
                  and len({row["id"] for row in rows}) == len(rows), "Missing or duplicate native job identity")
    by_name = {}
    for row in rows:
        name = row.get("name")
        proof.require(isinstance(name, str) and name not in by_name, "Duplicate native job name")
        by_name[name] = row
        proof.require(row.get("pipeline", {}).get("id") == identity["pipeline_id"]
                      and row.get("pipeline", {}).get("project_id") == PROJECT
                      and row.get("pipeline", {}).get("sha") == identity["commit"]
                      and row.get("pipeline", {}).get("ref") == row.get("ref") == identity["ref"]
                      and row.get("commit", {}).get("id") == identity["commit"] and row.get("allow_failure") is False
                      and row.get("erased_at") is None, "Foreign/optional native job")
    proof.require(set(by_name) == UPSTREAM | {"backend"}, "Missing or unexpected full acceptance job")
    proof.require(by_name["backend"].get("id") == own_id and by_name["backend"].get("status") == "running", "Collection is not its current running aggregate")
    for name in UPSTREAM:
        row = by_name[name]
        proof.require(row.get("status") == "success" and timestamp(row.get("pipeline", {}).get("created_at")) <= timestamp(row.get("created_at"))
                      <= timestamp(row.get("started_at")) <= timestamp(row.get("finished_at"))
                      <= timestamp(by_name["backend"].get("started_at")) <= datetime.now(timezone.utc), "Mandatory upstream job is not successfully completed: " + name)
    return by_name


def archive_metadata(job: dict, identity: dict, engine: str, shard: int) -> dict:
    artifacts = job.get("artifacts")
    proof.require(isinstance(artifacts, list) and all(isinstance(item, dict) for item in artifacts), "Missing native artifacts metadata")
    archives = [item for item in artifacts if item.get("file_type") == "archive"]
    proof.require(len(archives) == 1, "Missing or ambiguous native archive")
    metadata = archives[0]
    expected_name = f"database-{engine}-{shard}-{identity['pipeline_id']}-{job['id']}.zip"
    proof.require(metadata.get("filename") == expected_name and metadata.get("file_format") == "zip"
                  and proof.positive(metadata.get("size")) and metadata["size"] <= proof.MAX_ZIP, "Missing or oversized native database archive")
    legacy = job.get("artifacts_file")
    proof.require(legacy is None or (isinstance(legacy, dict) and legacy.get("filename") == expected_name
                  and legacy.get("size") == metadata["size"]), "Archive metadata disagrees")
    proof.require(timestamp(job.get("artifacts_expire_at")) > datetime.now(timezone.utc), "Expired native archive")
    return {"filename": expected_name, "size": metadata["size"]}


def validate_receipt(root: Path, identity: dict, job: dict, engine: str, shard: int, raw: bytes) -> tuple[dict, dict]:
    files = proof.archive(raw, proof.evidence_names(engine, shard))
    prefix = f"phpunit-ci-{engine}-{shard}"
    receipt = proof.json_data(files.pop(prefix + "-receipt.json"))
    keys = {"schema_version", "purpose", "engine", "shard", "source", "producer", "checkout_root", "runtime", "runtime_sha256", "test_step_outcome", "started_at", "finished_at", "source_census", "shard_census", "result_sha256", "file_sha256", "results"}
    proof.require(isinstance(receipt, dict) and set(receipt) == keys and type(receipt["schema_version"]) is int and receipt["schema_version"] == 1
                  and receipt["purpose"] == "gitlab-database-receipt-not-acceptance" and receipt["engine"] == engine
                  and type(receipt["shard"]) is int and receipt["shard"] == shard and receipt["source"] == identity
                  and receipt["test_step_outcome"] == "success", "Receipt is not this pipeline/source/shard")
    producer_ = receipt["producer"]
    proof.require(isinstance(producer_, dict) and set(producer_) == {"job_id", "job_name", "runner_id", "job_image_reference"}
                  and all(proof.positive(producer_[key]) for key in ("job_id", "runner_id"))
                  and producer_["job_id"] == job["id"] and producer_["job_name"] == job["name"]
                  and producer_["runner_id"] == job.get("runner", {}).get("id")
                  and producer_["job_image_reference"] == "php:8.4-cli-bookworm", "Receipt producer differs from native metadata")
    validate_runtime(receipt["runtime"], engine)
    proof.require(receipt["runtime_sha256"] == proof.digest(proof.canonical(receipt["runtime"]))
                  and receipt["runtime"]["dependencies"] == proof.locked_dependencies(root)[1], "Changed runtime or unlocked dependencies")
    checkout = receipt["checkout_root"]
    proof.require(isinstance(checkout, str) and Path(checkout).is_absolute() and len(checkout) <= 4096, "Invalid producer checkout root")
    full = proof.inventory(files[f"phpunit-ci-{engine}-source-tests.xml"], checkout)
    proof.validate_discovered_files(root, set(full["cases"].values()))
    observed = evidence(files, identity, checkout, engine, shard, root)
    proof.require(all(receipt[k] == v for k, v in observed.items()), "Receipt differs from retained discovery, configuration or JUnit")
    initial = proof.json_data(files[prefix + "-start.json"])
    expected = {k: receipt[k] for k in ("schema_version", "engine", "shard", "source", "producer", "checkout_root", "runtime", "started_at")}
    proof.require(initial == expected | {"purpose": "gitlab-database-start-not-acceptance"}, "Pre-test source/runtime differs from final receipt")
    proof.require(timestamp(job["started_at"]) <= timestamp(receipt["started_at"]) <= timestamp(receipt["finished_at"])
                  <= timestamp(job["finished_at"]) <= datetime.now(timezone.utc), "Receipt outside native job interval")
    return receipt, proof.inventory(files[prefix + "-tests.xml"], checkout)


def collect(root: Path, env: dict, api: Gitlab) -> dict:
    identity = source(root, env)
    pipeline_provenance(api, identity, env)
    jobs = accept_jobs(api.jobs(identity["pipeline_id"]), identity, number(env, "CI_JOB_ID"))
    receipts, artifacts = [], []
    inventories = {engine: [] for engine in proof.COUNTS}
    for name, (engine, shard) in DATABASE_JOBS.items():
        job = jobs[name]
        metadata = archive_metadata(job, identity, engine, shard)
        expected_name = metadata["filename"]
        raw = api.artifact(job["id"])
        proof.require(len(raw) == metadata["size"], "Native archive size differs from metadata")
        receipt, inventory = validate_receipt(root, identity, job, engine, shard, raw)
        receipts.append(receipt)
        inventories[engine].append(inventory)
        artifacts.append({"job_id": job["id"], "filename": expected_name, "download_sha256": proof.digest(raw),
                          "digest_origin": "computed from authenticated exact-job download; GitLab exposes no archive digest here"})
    proof.require(len({proof.canonical(receipt["source_census"]) for receipt in receipts}) == 1, "Different complete source censuses")
    mysql_ids = Counter(key for item in inventories["mysql"] for key in item["cases"])
    for engine, sets in inventories.items():
        observed = Counter(key for item in sets for key in item["cases"])
        proof.require(observed == mysql_ids and set(observed.values()) == {1}, "Executed partitions lose or duplicate source cases")
        proof.require(len({r["file_sha256"][f"phpunit-ci-{engine}-manifest.json"] for r in receipts if r["engine"] == engine}) == 1, "Different per-engine partition manifests")
    proof.require(sum(r["results"]["executed_cases"] for r in receipts if r["engine"] == "mysql") == len(mysql_ids), "Incomplete genuine MySQL execution")
    final_jobs = accept_jobs(api.jobs(identity["pipeline_id"]), identity, number(env, "CI_JOB_ID"))
    for name, (engine, shard) in DATABASE_JOBS.items():
        proof.require(archive_metadata(final_jobs[name], identity, engine, shard) == archive_metadata(jobs[name], identity, engine, shard)
                      and final_jobs[name].get("runner") == jobs[name].get("runner")
                      and final_jobs[name].get("started_at") == jobs[name].get("started_at")
                      and final_jobs[name].get("finished_at") == jobs[name].get("finished_at"), "Native producer metadata changed during collection")
    proof.require({name: j["id"] for name, j in final_jobs.items()} == {name: j["id"] for name, j in jobs.items()}, "Native job attempt changed during collection")
    pipeline_provenance(api, identity, env)
    proof.require(source(root, env) == identity, "Collector source changed")
    return {"schema_version": 1, "purpose": "gitlab-current-pipeline-database-collection",
            "outer_acceptance": "pending; this aggregate and pipeline must subsequently succeed",
            "source": identity, "upstream_jobs": {name: jobs[name]["id"] for name in sorted(UPSTREAM)},
            "artifacts": artifacts, "database_receipts": receipts, "reuse_enabled": False,
            "sqlite_skip_policy": "exact reviewed methods only; every counterpart executed on genuine MySQL"}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("probe", "start", "finish", "collect"))
    parser.add_argument("--engine", choices=tuple(proof.COUNTS))
    parser.add_argument("--shard", type=int)
    args = parser.parse_args()
    env = dict(os.environ)
    try:
        if args.command in {"probe", "collect"}:
            proof.require(args.engine is None and args.shard is None, "No shard arguments supported here")
            api = Gitlab(env.get("CI_JOB_TOKEN", ""))
            if args.command == "probe":
                identity = source(ROOT, env)
                pipeline_provenance(api, identity, env)
                api.jobs(identity["pipeline_id"])
                print("Native current-project/pipeline job-token provenance API is available.")
            else:
                proof.write_json(ROOT / "phpunit-ci-gitlab-collection.json", collect(ROOT, env, api))
                print("All mandatory native upstream jobs and six complete database receipts verified; outer acceptance pending; reuse disabled.")
        else:
            proof.require(args.engine in proof.COUNTS and args.shard is not None and 1 <= args.shard <= proof.COUNTS[args.engine], "Invalid database shard")
            (start if args.command == "start" else finish)(ROOT, args.engine, args.shard, env)
            print("Native database " + args.command + " evidence recorded; no acceptance or reuse claim.")
        return 0
    except (proof.ReceiptError, OSError, ValueError, KeyError, TypeError, AttributeError, RecursionError, subprocess.SubprocessError) as error:
        print("GitLab evidence rejected: " + (str(error) if isinstance(error, proof.ReceiptError) else type(error).__name__), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
