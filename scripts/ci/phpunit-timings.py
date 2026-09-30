#!/usr/bin/env python3
"""Build the per-file timing weights that phpunit-shards.py balances the CI shards on.

Every backend CI job already retains a JUnit log for its shard (the "Retain partition
and test evidence" artifact, phpunit-ci-<driver>-<n>-results.xml). Download the
backend-<driver>-* artifacts of one or more recent runs and pass all their logs:

    python3 scripts/ci/phpunit-timings.py --driver mysql \\
        --source "CI run 36674655225 attempt 2 and run 36682188373, tree 03f45e3" \\
        --output scripts/ci/phpunit-timings-mysql.json path/to/*/phpunit-ci-mysql-*-results.xml

Refresh a driver's file when tests are added or the database setup changes speed. A
file that appears in several logs (several runs) is averaged. Whole test files are
timed, because the partitioner never splits a file. Paths recorded on the CI runner
are matched to the repository by their longest existing suffix; files that no longer
exist are dropped and reported. Timings only balance the shards. The partition proof
still requires every expanded test exactly once whatever these numbers say.
"""

from __future__ import annotations

import argparse
from collections import defaultdict
import json
from pathlib import Path
import sys
import xml.etree.ElementTree as ET


SCHEMA_VERSION = 1


class TimingError(Exception):
    pass


def repository_file(recorded: str, root: Path) -> str | None:
    """Map a path recorded on another machine to a file that exists in this repository."""
    path = Path(recorded)
    if path.is_absolute() and path.is_relative_to(root) and path.is_file():
        return path.relative_to(root).as_posix()
    parts = path.parts[1:] if path.is_absolute() else path.parts
    if ".." in parts:
        return None
    for start in range(len(parts)):
        candidate = Path(*parts[start:])
        if (root / candidate).is_file():
            return candidate.as_posix()
    return None


def read_junit(log: Path, root: Path) -> tuple[dict[str, list[float]], set[str]]:
    """Return {repository file: [cases, seconds]} for one JUnit log and the recorded paths that are gone."""
    totals: dict[str, list[float]] = {}
    gone: set[str] = set()
    known: dict[str, str | None] = {}
    for case in ET.parse(log).getroot().iter("testcase"):
        recorded = case.get("file")
        if not recorded:
            # A case without a file cannot be attributed to a whole file, so refuse rather than undercount.
            raise TimingError(f"{log.name}: a test case has no file attribute")
        if recorded not in known:
            known[recorded] = repository_file(recorded, root)
        file = known[recorded]
        if file is None:
            gone.add(recorded)
            continue
        entry = totals.setdefault(file, [0, 0.0])
        entry[0] += 1
        entry[1] += float(case.get("time") or 0)
    return totals, gone


def combine(logs: list[dict[str, list[float]]]) -> dict[str, tuple[int, int]]:
    """Mean expanded cases and whole milliseconds per file over the logs that mention it."""
    samples: dict[str, list[list[float]]] = defaultdict(list)
    for log in logs:
        for file, measured in log.items():
            samples[file].append(measured)
    combined = {}
    for file in sorted(samples):
        cases = round(sum(cases for cases, _ in samples[file]) / len(samples[file]))
        milliseconds = round(sum(seconds for _, seconds in samples[file]) * 1000 / len(samples[file]))
        combined[file] = (max(cases, 1), milliseconds)
    return combined


def render(driver: str, source: str, files: dict[str, tuple[int, int]]) -> str:
    """One line per file keeps refresh diffs reviewable; the output is plain JSON."""
    if not files:
        raise TimingError("No timed test files")
    fallback = max(1, round(sum(ms for _, ms in files.values()) / sum(cases for cases, _ in files.values())))
    entries = ",\n".join(
        f"    {json.dumps(file)}: {{\"cases\": {cases}, \"ms\": {ms}}}" for file, (cases, ms) in sorted(files.items())
    )
    return (
        "{\n"
        f"  \"schema_version\": {SCHEMA_VERSION},\n"
        f"  \"driver\": {json.dumps(driver)},\n"
        f"  \"source\": {json.dumps(source)},\n"
        f"  \"fallback_ms_per_case\": {fallback},\n"
        "  \"files\": {\n"
        f"{entries}\n"
        "  }\n"
        "}\n"
    )


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--driver", required=True, choices=["mysql", "sqlite"])
    parser.add_argument("--source", required=True, help="Provenance to record: CI run ids and the tested tree")
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("logs", nargs="+", type=Path, help="PHPUnit --log-junit files from the shards of one or more runs")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    parsed, gone = [], set()
    for log in args.logs:
        totals, missing = read_junit(log, root)
        parsed.append(totals)
        gone |= missing
    files = combine(parsed)
    args.output.write_text(render(args.driver, args.source, files), encoding="utf-8")
    cases = sum(cases for cases, _ in files.values())
    seconds = sum(ms for _, ms in files.values()) / 1000
    print(f"Wrote {args.output}: {len(files)} files, {cases} cases, {seconds:.0f}s of {args.driver} test time.")
    if gone:
        print(f"Dropped {len(gone)} recorded file(s) that no longer exist: {', '.join(sorted(gone))}", file=sys.stderr)


if __name__ == "__main__":
    try:
        main()
    except (TimingError, ET.ParseError, OSError) as error:
        sys.exit(f"PHPUnit timings rejected: {error}")
