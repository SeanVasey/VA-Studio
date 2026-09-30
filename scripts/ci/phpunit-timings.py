#!/usr/bin/env python3
"""Build the per-file timing weights that phpunit-shards.py balances the CI shards on.

Every backend CI job already retains a JUnit log for its shard (the "Retain partition
and test evidence" artifact, phpunit-ci-<driver>-<n>-results.xml, kept for 7 days).
Download the backend-<driver>-* artifacts of one or more recent runs with the GitHub CLI,
which extracts each artifact into its own directory, and pass all their logs:

    gh run download <run-id> --dir /tmp/phpunit-runs/<run-id> --pattern 'backend-mysql-*'
    python3 scripts/ci/phpunit-timings.py --driver mysql \\
        --source "CI run 36674655225 attempt 2 and run 36682188373, tree 03f45e3" \\
        --output scripts/ci/phpunit-timings-mysql.json \\
        /tmp/phpunit-runs/*/backend-mysql-*/phpunit-ci-mysql-*-results.xml

For SQLite, download 'backend-sqlite-*' and pass --driver sqlite with the
phpunit-ci-sqlite-*-results.xml logs. The script refuses any log whose file name lacks
-<driver>-, so a glob cannot mix the two drivers. It also refuses a test time that is not
a finite number of seconds of at least 0, so it never writes a file that the partitioner
would reject.

Refresh a driver's file when tests are added or the database setup changes speed. A
file that appears in several logs (several runs) is averaged. Whole test files are
timed, because the partitioner never splits a file. PHPUnit records a test method's file
as the file that declares it, which is a trait or a parent class for an inherited method,
while the partitioner keys on the class that runs it. So each case is credited to its
enclosing test class suite, and declaring files that are not test classes are listed.
Paths recorded on the CI runner are matched to the repository by their longest existing
suffix; files that no longer exist are dropped and reported. Timings only balance the
shards. The partition proof still requires every expanded test exactly once whatever
these numbers say.
"""

from __future__ import annotations

import argparse
from collections import defaultdict
from collections.abc import Iterator
import json
import math
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


def cases_in(log: Path) -> Iterator[tuple[ET.Element, str | None]]:
    """Yield each testcase of a JUnit log with the file of its nearest enclosing test class suite, if any.

    PHPUnit sets `file` on a testsuite only for a test class. The suites above it (the
    configuration and its named suites) have none, and neither has the class::method suite
    around a data provider's sets, which therefore belongs to the class outside it.
    """
    enclosing: list[str | None] = []
    with log.open("rb") as handle:
        for event, node in ET.iterparse(handle, events=("start", "end")):
            if node.tag == "testsuite":
                if event == "start":
                    enclosing.append(node.get("file") or (enclosing[-1] if enclosing else None))
                else:
                    enclosing.pop()
            elif node.tag == "testcase" and event == "end":
                yield node, enclosing[-1] if enclosing else None


def recorded_file(log: Path, case: ET.Element, class_file: str | None) -> str:
    """The recorded path of the file a test case is timed under: its test class, or itself for a PHPT test."""
    if case.get("class") is not None:
        if not class_file:
            raise TimingError(f"{log.name}: test case {case.get('name')!r} is not inside a test class suite")
        return class_file
    # PHPUnit sets `class` on test methods only, so this is a PHPT test, and the .phpt file is the unit.
    recorded = case.get("file")
    if not recorded:
        # A case without a file cannot be attributed to a whole file, so refuse rather than undercount.
        raise TimingError(f"{log.name}: a test case has no file attribute")
    return recorded


def seconds_of(log: Path, case: ET.Element) -> float:
    """A case's time, refusing a missing, malformed, infinite, NaN or negative value."""
    raw = case.get("time")
    try:
        seconds = float(raw or "")
    except ValueError:
        seconds = math.nan
    if not math.isfinite(seconds) or seconds < 0:
        raise TimingError(f"{log.name}: test case {case.get('name')!r} has time {raw!r}; expected a finite number of seconds, 0 or more")
    return seconds


def read_junit(log: Path, root: Path) -> tuple[dict[str, list[float]], set[str], set[str]]:
    """Return {repository file: [cases, seconds]} for one JUnit log, the recorded paths that are gone,
    and the repository files that declare test methods run by other classes (traits and parent classes).

    Each case is credited to its enclosing test class, not to the file PHPUnit records for the
    method, because that is the declaring file and the partitioner keys on the class.
    """
    totals: dict[str, list[float]] = {}
    gone: set[str] = set()
    shared: set[str] = set()
    known: dict[str, str | None] = {}

    def repository(recorded: str) -> str | None:
        if recorded not in known:
            known[recorded] = repository_file(recorded, root)
        return known[recorded]

    for case, class_file in cases_in(log):
        recorded = recorded_file(log, case, class_file)
        seconds = seconds_of(log, case)
        file = repository(recorded)
        if file is None:
            gone.add(recorded)
            continue
        entry = totals.setdefault(file, [0, 0.0])
        entry[0] += 1
        entry[1] += seconds
        declared = case.get("file")
        if declared and declared != recorded:
            declaring = repository(declared)
            if declaring is not None:
                shared.add(declaring)
    return totals, gone, shared


def require_driver(logs: list[Path], driver: str) -> None:
    """Refuse a log whose file name lacks -<driver>-, so a glob cannot average MySQL and SQLite times together."""
    for log in logs:
        if f"-{driver}-" not in log.name:
            raise TimingError(f"{log.name}: the file name does not contain -{driver}-, so it is not a {driver} log (phpunit-ci-{driver}-<n>-results.xml)")


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


def main(argv: list[str] | None = None, root: Path | None = None) -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--driver", required=True, choices=["mysql", "sqlite"])
    parser.add_argument("--source", required=True, help="Provenance to record: CI run ids and the tested tree")
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("logs", nargs="+", type=Path, help="PHPUnit --log-junit files from the shards of one or more runs; each name must contain -<driver>-")
    args = parser.parse_args(argv)
    root = root or Path(__file__).resolve().parents[2]
    require_driver(args.logs, args.driver)
    parsed, gone, shared = [], set(), set()
    for log in args.logs:
        totals, missing, declaring = read_junit(log, root)
        parsed.append(totals)
        gone |= missing
        shared |= declaring
    files = combine(parsed)
    args.output.write_text(render(args.driver, args.source, files), encoding="utf-8")
    cases = sum(cases for cases, _ in files.values())
    seconds = sum(ms for _, ms in files.values()) / 1000
    print(f"Wrote {args.output}: {len(files)} files, {cases} cases, {seconds:.0f}s of {args.driver} test time.")
    if gone:
        print(f"Dropped {len(gone)} recorded file(s) that no longer exist: {', '.join(sorted(gone))}", file=sys.stderr)
    # A declaring file that is itself a test class keeps its own entry, so only the ones that are not are worth naming.
    not_test_classes = sorted(shared - set(files))
    if not_test_classes:
        print(f"Credited the test methods declared in {', '.join(not_test_classes)} to the test classes that run them; "
              "those files are not test classes themselves.", file=sys.stderr)


if __name__ == "__main__":
    try:
        main()
    except (TimingError, ET.ParseError, OSError) as error:
        sys.exit(f"PHPUnit timings rejected: {error}")
