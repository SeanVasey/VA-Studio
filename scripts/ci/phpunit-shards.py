#!/usr/bin/env python3
"""Partition whole PHPUnit test files and prove the expanded cases/groups exactly.

The authoritative inventory comes from PHPUnit, not a test-name regex. Generated
configurations stay beside phpunit.xml so every relative bootstrap/source/env/
extension path keeps its meaning. Existing directory and exclude declarations
are retained; additional file exclusions select each shard.

CLI contract: https://docs.phpunit.de/en/12.5/textui.html#listing-tests
Source checked at composer.lock's PHPUnit 12.5.34 reference
6cbff63d670de92cb1cb3d2ff9f40327e9da9c7f:
src/TextUI/Command/Commands/ListTestsAsXmlCommand.php and
src/TextUI/Configuration/Xml/TestSuiteMapper.php.
"""

from __future__ import annotations

import argparse
from collections import Counter
from copy import deepcopy
from dataclasses import dataclass
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET


NS = "{https://xml.phpunit.de/testSuite}"
XSI = "http://www.w3.org/2001/XMLSchema-instance"
ET.register_namespace("xsi", XSI)


class PartitionError(Exception):
    pass


@dataclass
class Inventory:
    cases: dict[str, str]
    groups: Counter

    @property
    def files(self) -> set[str]:
        return set(self.cases.values())


def relative_file(value: str, root: Path) -> str:
    path = Path(value)
    if not path.is_absolute():
        path = root / path
    path = path.resolve(strict=True)
    if not path.is_file() or not path.is_relative_to(root):
        raise PartitionError("PHPUnit discovered a file outside the repository")
    return path.relative_to(root).as_posix()


def read_inventory(path: Path, root: Path) -> Inventory:
    tree = ET.parse(path).getroot()
    if tree.tag != NS + "testSuite" or [x.tag for x in tree] != [NS + "tests", NS + "groups"]:
        raise PartitionError("Unknown PHPUnit test-list XML shape")
    cases: dict[str, str] = {}
    for item in tree[0]:
        if item.tag == NS + "testClass" and set(item.attrib) == {"name", "file"}:
            file = relative_file(item.attrib["file"], root)
            if not list(item):
                raise PartitionError("PHPUnit listed an empty test class")
            identifiers = []
            for method in item:
                if method.tag != NS + "testMethod" or set(method.attrib) != {"id", "name"} or list(method):
                    raise PartitionError("Unknown PHPUnit test method shape")
                identifiers.append(method.attrib["id"])
        elif item.tag == NS + "phpt" and set(item.attrib) == {"file"} and not list(item):
            file = relative_file(item.attrib["file"], root)
            identifiers = ["phpt:" + file]
        else:
            raise PartitionError("Unknown PHPUnit test-list entry")
        for identifier in identifiers:
            if not identifier or identifier in cases:
                raise PartitionError("Empty or duplicate test identifier in source inventory")
            cases[identifier] = file
    groups: Counter = Counter()
    for group in tree[1]:
        if group.tag != NS + "group" or set(group.attrib) != {"name"}:
            raise PartitionError("Unknown PHPUnit group shape")
        for test in group:
            if test.tag != NS + "test" or set(test.attrib) != {"id"} or list(test) or test.attrib["id"] not in cases:
                raise PartitionError("Unknown PHPUnit grouped test")
            groups[(group.attrib["name"], test.attrib["id"])] += 1
    if not cases:
        raise PartitionError("Source inventory has no tests")
    return Inventory(cases, groups)


def partition(source: Inventory, count: int) -> list[set[str]]:
    if count < 1 or count > len(source.files):
        raise PartitionError("Shard count would create an empty shard")
    weights = Counter(source.cases.values())
    shards: list[set[str]] = [set() for _ in range(count)]
    loads = [0] * count
    # Largest expanded-case files first; lexical and shard-index ties are stable.
    for file in sorted(weights, key=lambda name: (-weights[name], name)):
        index = min(range(count), key=lambda candidate: (loads[candidate], candidate))
        shards[index].add(file)
        loads[index] += weights[file]
    return shards


def refuse_cross_file_dependencies(root: Path, files: set[str]) -> None:
    # Whole-file partitioning preserves local Depends attributes. External/class
    # dependencies require an explicitly reviewed co-location strategy instead.
    for file in files:
        if re.search(r"\bDepends(?:External|OnClass)\w*\b|@depends\b", (root / file).read_text()):
            raise PartitionError("Cross-file test dependency requires a reviewed shard strategy: " + file)


def shard_configuration(source: ET.ElementTree, root: Path, all_files: set[str], selected: set[str]) -> ET.ElementTree:
    if not selected or not selected <= all_files:
        raise PartitionError("Empty shard or unknown assigned file")
    result = deepcopy(source)
    suites = result.getroot().find("testsuites")
    if suites is None or not list(suites):
        raise PartitionError("Source configuration has no testsuites")
    omitted = sorted(all_files - selected)
    for suite in suites:
        if suite.tag != "testsuite":
            raise PartitionError("Unsupported testsuites child; cannot silently ignore configuration")
        for definition in list(suite):
            if definition.tag not in {"directory", "file", "exclude"}:
                raise PartitionError("Unsupported testsuite declaration")
            if definition.tag == "file":
                file = relative_file((definition.text or "").strip(), root)
                # PHPUnit excludes apply to directories, not explicit file declarations.
                # Retain conditionally inactive definitions that were absent from discovery.
                if file in all_files and file not in selected:
                    suite.remove(definition)
        for file in omitted:
            ET.SubElement(suite, "exclude").text = file
    return result


def prove(source: Inventory, assignments: list[set[str]], observed: list[Inventory]) -> None:
    if len(assignments) != len(observed) or not assignments:
        raise PartitionError("Missing shard discovery")
    files: Counter = Counter()
    cases: Counter = Counter()
    groups: Counter = Counter()
    for selected, shard in zip(assignments, observed, strict=True):
        expected = {identifier: file for identifier, file in source.cases.items() if file in selected}
        if not selected or shard.files != selected or shard.cases != expected:
            raise PartitionError("Shard has missing, unknown, remapped or empty test files/cases")
        expected_groups = Counter({key: value for key, value in source.groups.items() if key[1] in expected})
        if shard.groups != expected_groups:
            raise PartitionError("Shard changed PHPUnit groups")
        files.update(shard.files)
        cases.update(shard.cases.keys())
        groups.update(shard.groups)
    if files != Counter({file: 1 for file in source.files}) or cases != Counter({case: 1 for case in source.cases}):
        raise PartitionError("The shards do not cover every source file and expanded test exactly once")
    if groups != source.groups:
        raise PartitionError("The shards do not preserve source group membership")


def discover(config: Path, output: Path, root: Path) -> Inventory:
    result = subprocess.run(
        # PHPUnit's listing command exits before test-result warning handling.
        # The actual shard execution applies --fail-on-phpunit-warning in CI.
        ["php", "vendor/bin/phpunit", "--configuration", str(config), "--list-tests-xml", str(output)],
        cwd=root, text=True, capture_output=True, check=False, timeout=120,
    )
    if result.returncode != 0 or not output.is_file():
        # Diagnostics name synthetic test definitions, not application/private order data.
        sys.stderr.write(result.stdout + result.stderr)
        raise PartitionError("PHPUnit test discovery failed")
    return read_inventory(output, root)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--shards", type=int, required=True)
    parser.add_argument("--prefix", required=True)
    args = parser.parse_args()
    if not re.fullmatch(r"phpunit-ci-[a-z0-9-]+", args.prefix):
        raise PartitionError("Invalid generated configuration prefix")
    root = Path(__file__).resolve().parents[2]
    source_path = root / "phpunit.xml"
    source = ET.parse(source_path)
    if source.getroot().tag != "phpunit":
        raise PartitionError("Unknown PHPUnit configuration root")
    with tempfile.TemporaryDirectory(prefix="phpunit-partition-") as temp:
        full = discover(source_path, Path(temp) / "all.xml", root)
        refuse_cross_file_dependencies(root, full.files)
        assignments = partition(full, args.shards)
        observed = []
        lists = [(Path(temp) / "all.xml", root / f"{args.prefix}-source-tests.xml")]
        for index, selected in enumerate(assignments, 1):
            config = root / f"{args.prefix}-{index}.xml"
            # Refuse accidental overwriting of any tracked or preexisting configuration.
            with config.open("xb") as output:
                shard_configuration(source, root, full.files, selected).write(output, encoding="utf-8", xml_declaration=True)
            listing = Path(temp) / f"shard-{index}.xml"
            observed.append(discover(config, listing, root))
            lists.append((listing, root / f"{args.prefix}-{index}-tests.xml"))
        prove(full, assignments, observed)
        for listing, evidence in lists:
            with evidence.open("xb") as output:
                output.write(listing.read_bytes())
    manifest = {
        "schema_version": 1,
        "source_configuration_sha256": hashlib.sha256(source_path.read_bytes()).hexdigest(),
        "source_files": len(full.files),
        "source_test_cases": len(full.cases),
        "source_case_identity_sha256": hashlib.sha256(json.dumps(sorted(full.cases.items())).encode()).hexdigest(),
        "source_group_identity_sha256": hashlib.sha256(json.dumps(sorted(full.groups.items())).encode()).hexdigest(),
        "proof": "every expanded source case, file and group appears exactly once",
        "shards": [
            {"index": index, "configuration": f"{args.prefix}-{index}.xml", "files": sorted(selected),
             "test_cases": len(shard.cases)}
            for index, (selected, shard) in enumerate(zip(assignments, observed, strict=True), 1)
        ],
    }
    with (root / f"{args.prefix}-manifest.json").open("x") as output:
        json.dump(manifest, output, indent=2)
        output.write("\n")
    print(f"Proved {len(full.cases)} expanded tests in {len(full.files)} files across {args.shards} nonempty shards.")
    for item in manifest["shards"]:
        print(f"Shard {item['index']}: {item['test_cases']} tests; {len(item['files'])} complete files.")


if __name__ == "__main__":
    try:
        main()
    except (PartitionError, ET.ParseError, OSError, subprocess.TimeoutExpired) as error:
        sys.exit(f"PHPUnit partition rejected: {error}")
