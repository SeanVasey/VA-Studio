"""Copy only the explicitly released, hash-pinned public artifacts in release-plan.json.

This is offline evidence assembly, not an application/test/database command.
Changing or adding a runtime receipt requires an explicit release-plan update.
"""
from pathlib import Path
import argparse
import hashlib
import json

HERE = Path(__file__).resolve().parent
PLAN = json.loads((HERE / "release-plan.json").read_text())
parser = argparse.ArgumentParser()
parser.add_argument("--new-only", action="store_true", help="Verify prior archived bytes and copy only newly released paths; do not reread old mutable runtime notes.")
args = parser.parse_args()
prior = {}
if args.new_only:
    prior = {item["packet_path"]: item for item in json.loads((HERE / "archive-index.json").read_text())["files"]}
ENVIRONMENT_KEYS = {
    "APP_ENV", "APP_DEBUG", "DB_CONNECTION", "DB_DATABASE", "DB_HOST", "DB_PORT",
    "DB_SOCKET", "CACHE_STORE", "SESSION_DRIVER", "QUEUE_CONNECTION", "TMPDIR",
    "VA_MEMBERSHIP_RESERVATION_RACE_EVIDENCE_DIRECTORY",
}


def sha(data):
    return hashlib.sha256(data).hexdigest()


def project(value, omitted, path=""):
    if isinstance(value, dict):
        result = {}
        for key, item in value.items():
            child = f"{path}/{key}"
            if key == "environment" and isinstance(item, dict):
                result[key] = {k: v for k, v in item.items() if k in ENVIRONMENT_KEYS}
                omitted.extend(f"{child}/{k}" for k in item if k not in ENVIRONMENT_KEYS)
            else:
                result[key] = project(item, omitted, child)
        return result
    if isinstance(value, list):
        return [project(item, omitted, f"{path}/{i}") for i, item in enumerate(value)]
    return value


index = []
for artifact in PLAN["artifacts"]:
    source = Path(artifact["runtime_path"])
    target = HERE / artifact["path"]
    assert target.resolve().is_relative_to(HERE), target
    assert source.suffix not in {".sqlite", ".db", ".env"}, source
    assert source.name != "connection.json", source
    existing = prior.get("evidence/" + artifact["path"])
    if existing:
        assert existing["original_sha256"] == artifact["released_sha256"], target
        data = target.read_bytes()
        assert len(data) == existing["bytes"] and sha(data) == existing["sha256"], target
        index.append(existing)
        continue
    raw = source.read_bytes()
    assert sha(raw) == artifact["released_sha256"], f"Receipt changed after release: {source}"
    omitted = []
    if source.suffix == ".json" and not artifact.get("verbatim_non_json"):
        data = project(json.loads(raw), omitted)
        copied = (json.dumps(data, indent=2) + "\n").encode() if omitted else raw
    else:
        copied = raw
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(copied)
    index.append({"runtime_path": str(source), "packet_path": "evidence/" + artifact["path"],
                  "original_sha256": sha(raw), "original_bytes": len(raw),
                  "sha256": sha(copied), "bytes": len(copied),
                  "transformation": "explicit environment allowlist" if omitted else "byte-for-byte",
                  "omitted_json_paths": omitted})
(HERE / "archive-index.json").write_text(json.dumps({
    "scope": "Explicitly released public artifacts only; no database, key, environment file, private row capture, or private connection metadata.",
    "files": index,
}, indent=2) + "\n")
print(json.dumps({"copied_released_artifacts": len(index),
                  "projected_json_artifacts": sum(bool(item["omitted_json_paths"]) for item in index)}))
