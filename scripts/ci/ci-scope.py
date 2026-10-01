#!/usr/bin/env python3
"""Conservative Foundation CI routing and explicit acceptance of its results.

Only the listed prose/ledger files can use documentation mode. Missing evidence,
unknown paths, unexpected Git metadata and manual runs select the complete suite.
No GitHub API diff limit, user-controlled shell command or skip annotation is used.
"""

from __future__ import annotations

import argparse
import csv
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys


DOCUMENTS = frozenset({
    "README.md",
    "CHANGELOG.md",
    "docs/development-order.md",
    "docs/architecture/decision-register.md",
    "docs/remaining-development-plan.md",
    "docs/remaining-development-tasks.csv",
    "docs/remaining-parity-coverage.csv",
    "docs/ci-development-strategy.md",
    "docs/verification/ci-throughput.md",
    "docs/verification/focused-ci.md",
    "docs/verification/ci-trigger-efficiency.md",
    "docs/verification/ci-scope.md",
})
RUNTIME_JOBS = (
    "frontend", "backend-quality", "backend-mysql", "backend-sqlite", "operator-browser",
)
SHA = re.compile(r"[0-9a-f]{40}\Z")
MAX_DIFF_BYTES = 4 * 1024 * 1024


class EvidenceError(Exception):
    pass


def git(root: Path, *arguments: str) -> bytes:
    result = subprocess.run(
        ["git", *arguments], cwd=root, capture_output=True, timeout=30, check=False,
    )
    if result.returncode or len(result.stdout) > MAX_DIFF_BYTES:
        raise EvidenceError("Git evidence is unavailable or exceeds the bounded diff size")
    return result.stdout


def changed_paths(root: Path, base: str, head: str) -> list[str]:
    if not SHA.fullmatch(base) or not SHA.fullmatch(head):
        raise EvidenceError("Missing or invalid source identity")
    git(root, "merge-base", "--is-ancestor", base, head)
    raw = git(root, "diff", "--name-status", "--no-renames", "-z", base, head, "--")
    if not raw or not raw.endswith(b"\0"):
        raise EvidenceError("Empty or incomplete diff")
    fields = raw[:-1].split(b"\0")
    if len(fields) % 2:
        raise EvidenceError("Invalid diff record")
    paths = []
    for status, encoded in zip(fields[::2], fields[1::2]):
        if status not in {b"A", b"M", b"D"}:
            raise EvidenceError("Non-regular file change")
        path = encoded.decode("utf-8", errors="strict")
        if path not in DOCUMENTS:
            raise EvidenceError("A changed path requires complete runtime verification")
        # Renames are represented as deletion/addition; both endpoints are inspected.
        revisions = [head] if status == b"A" else [base] if status == b"D" else [base, head]
        for revision in revisions:
            entry = git(root, "ls-tree", "-z", revision, "--", path)
            expected_suffix = b"\t" + encoded + b"\0"
            if not entry.startswith(b"100644 blob ") or not entry.endswith(expected_suffix) or entry.count(b"\0") != 1:
                raise EvidenceError("Documentation must be an ordinary non-executable tracked file")
        paths.append(path)
    if len(paths) != len(set(paths)):
        raise EvidenceError("Duplicate diff records")
    return paths


def classify(root: Path, event_name: str, event: dict, expected_head: str) -> dict:
    decision = {"mode": "full", "reason": "This event requires complete runtime verification", "paths": []}
    if event_name not in {"pull_request", "push"}:
        return decision
    try:
        head = git(root, "rev-parse", "HEAD").decode().strip()
        if head != expected_head or not SHA.fullmatch(head):
            raise EvidenceError("Checkout does not match the event's tested commit")
        if event_name == "pull_request":
            base = event["pull_request"]["base"]["sha"]
        else:
            if event.get("after") != head or event.get("ref") != "refs/heads/main":
                raise EvidenceError("Unexpected push source")
            base = event["before"]
        paths = changed_paths(root, base, head)
        decision = {"mode": "docs", "reason": "Every changed path is an explicit documentation-only entry", "paths": paths, "base": base, "head": head}
    except (EvidenceError, UnicodeError, KeyError, TypeError, subprocess.SubprocessError, OSError):
        decision["reason"] = "Incomplete evidence or a non-documentation change; full suite required"
    return decision


def validate_documents(root: Path, decision: dict) -> int:
    if decision.get("mode") != "docs":
        raise EvidenceError("Documentation validation requires explicit docs mode")
    paths = changed_paths(root, decision.get("base", ""), decision.get("head", ""))
    if sorted(paths) != sorted(decision.get("paths", [])):
        raise EvidenceError("Changed-file evidence differs from classification")
    if git(root, "rev-parse", "HEAD").decode().strip() != decision["head"]:
        raise EvidenceError("Documentation checkout differs from classified source")
    git(root, "diff", "--check", decision["base"], decision["head"], "--")
    checked = 0
    # Check the complete allowlist so removing a document cannot leave another
    # retained document with an unnoticed dangling local link.
    for name in sorted(DOCUMENTS):
        path = root / name
        if not path.exists():
            continue
        if path.is_symlink() or not path.is_file():
            raise EvidenceError(f"Not a regular document: {name}")
        content = path.read_text(encoding="utf-8")
        if "\0" in content or not content.endswith("\n"):
            raise EvidenceError(f"Invalid document text: {name}")
        if path.suffix == ".csv":
            rows = list(csv.reader(io.StringIO(content), strict=True))
            if not rows or not rows[0] or len(set(rows[0])) != len(rows[0]) or any(not h for h in rows[0]):
                raise EvidenceError(f"Invalid CSV header: {name}")
            if any(len(row) != len(rows[0]) for row in rows[1:]):
                raise EvidenceError(f"Inconsistent CSV width: {name}")
        else:
            for target in re.findall(r"\[[^\]]*\]\(([^)]+)\)", content):
                if re.match(r"[A-Za-z][A-Za-z0-9+.-]*:", target) or target.startswith("#"):
                    continue
                target = target.split("#", 1)[0]
                if not target:
                    continue
                candidate = (path.parent / target).resolve()
                if not candidate.is_relative_to(root.resolve()) or not candidate.exists():
                    raise EvidenceError(f"Missing or escaping local link in {name}: {target}")
        checked += 1
    return checked


def accept(needs: dict) -> str:
    required = {"scope", "documentation", *RUNTIME_JOBS}
    if set(needs) != required or any(not isinstance(value, dict) for value in needs.values()):
        raise EvidenceError("Missing or unexpected acceptance job")
    if needs["scope"].get("result") != "success":
        raise EvidenceError("Scope classification did not succeed")
    mode = needs["scope"].get("outputs", {}).get("mode")
    if mode == "docs":
        expected = {"documentation": "success", **{job: "skipped" for job in RUNTIME_JOBS}}
    elif mode == "full":
        expected = {"documentation": "skipped", **{job: "success" for job in RUNTIME_JOBS}}
    else:
        raise EvidenceError("Unknown acceptance mode")
    for job, result in expected.items():
        if needs[job].get("result") != result:
            raise EvidenceError(f"Required {mode} evidence missing or unsuccessful: {job}")
    return mode


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("classify", "docs", "gate"))
    args = parser.parse_args()
    try:
        if args.command == "classify":
            event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text())
            decision = classify(Path.cwd(), os.environ.get("GITHUB_EVENT_NAME", ""), event, os.environ.get("GITHUB_SHA", ""))
            Path("ci-scope.json").write_text(json.dumps(decision, indent=2) + "\n")
            with Path(os.environ["GITHUB_OUTPUT"]).open("a") as output:
                output.write(f"mode={decision['mode']}\n")
            with Path(os.environ["GITHUB_STEP_SUMMARY"]).open("a") as summary:
                summary.write(f"### Foundation CI mode: {decision['mode']}\n\n{decision['reason']}.\n")
            print(json.dumps(decision))
        elif args.command == "docs":
            # Recompute from the same event and checked-out commit; do not trust
            # a mutable artifact or require cross-job filesystem state.
            event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text())
            decision = classify(Path.cwd(), os.environ.get("GITHUB_EVENT_NAME", ""), event, os.environ.get("GITHUB_SHA", ""))
            count = validate_documents(Path.cwd(), decision)
            print(f"Documentation-only acceptance: {count} retained documents checked. Runtime suites were not executed.")
        else:
            mode = accept(json.loads(os.environ["NEEDS_JSON"]))
            print("Documentation checks accepted; runtime suites were not required or executed." if mode == "docs" else "All runtime, database, frontend, browser and quality gates passed.")
    except (EvidenceError, ValueError, KeyError, TypeError, AttributeError, OSError, subprocess.SubprocessError, csv.Error) as error:
        print(f"CI evidence rejected: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
