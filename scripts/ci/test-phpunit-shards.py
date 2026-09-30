#!/usr/bin/env python3
"""Executable safeguards for CI selection; PHP itself proves runtime discovery."""

from collections import Counter
from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET

spec = importlib.util.spec_from_file_location("phpunit_shards", Path(__file__).with_name("phpunit-shards.py"))
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)
Inventory, PartitionError = module.Inventory, module.PartitionError

timings_spec = importlib.util.spec_from_file_location("phpunit_timings", Path(__file__).with_name("phpunit-timings.py"))
timings_module = importlib.util.module_from_spec(timings_spec)
sys.modules[timings_spec.name] = timings_module
timings_spec.loader.exec_module(timings_module)


class PartitionProofTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        for file in ["tests/A.php", "tests/B.php", "tests/C.php", "tests/D.phpt"]:
            path = self.root / file
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("<?php // isolated proof fixture\n")
        self.source = Inventory(
            {"A::one": "tests/A.php", "A::two with data set #0": "tests/A.php", "A::two with data set #1": "tests/A.php",
             "B::one": "tests/B.php", "C::one": "tests/C.php", "phpt:tests/D.phpt": "tests/D.phpt"},
            Counter({("money", "A::one"): 1, ("money", "B::one"): 1}),
        )

    def selections(self, count=2, weights=None):
        assignments = module.partition(self.source, count, weights)
        observed = []
        for selected in assignments:
            cases = {name: file for name, file in self.source.cases.items() if file in selected}
            groups = Counter({key: value for key, value in self.source.groups.items() if key[1] in cases})
            observed.append(Inventory(cases, groups))
        return assignments, observed

    def test_partition_is_deterministic_and_keeps_data_sets_in_one_file(self):
        assignments, observed = self.selections()
        reversed_source = Inventory(dict(reversed(list(self.source.cases.items()))), self.source.groups)
        self.assertEqual(assignments, module.partition(reversed_source, 2))
        self.assertEqual([3, 3], [len(shard.cases) for shard in observed])
        module.prove(self.source, assignments, observed)

    def test_four_shards_cover_every_file_once(self):
        assignments, observed = self.selections(4)
        module.prove(self.source, assignments, observed)
        self.assertTrue(all(len(selected) == 1 for selected in assignments))

    def test_empty_shards_rejected(self):
        for count in [0, 5]:
            with self.assertRaises(PartitionError):
                module.partition(self.source, count)

    def test_missing_case_rejected(self):
        assignments, observed = self.selections()
        observed[0].cases.pop(next(iter(observed[0].cases)))
        with self.assertRaises(PartitionError):
            module.prove(self.source, assignments, observed)

    def test_extra_case_rejected(self):
        assignments, observed = self.selections()
        observed[0].cases["Unknown::test"] = next(iter(assignments[0]))
        with self.assertRaises(PartitionError):
            module.prove(self.source, assignments, observed)

    def test_duplicate_shard_rejected(self):
        assignments, observed = self.selections()
        with self.assertRaises(PartitionError):
            module.prove(self.source, [assignments[0], assignments[0]], [observed[0], observed[0]])

    def test_remapped_case_rejected(self):
        assignments, observed = self.selections()
        observed[0].cases[next(iter(observed[0].cases))] = next(iter(assignments[1]))
        with self.assertRaises(PartitionError):
            module.prove(self.source, assignments, observed)

    def test_missing_or_extra_group_rejected(self):
        assignments, observed = self.selections()
        for change in [Counter(), Counter({("invented", next(iter(observed[0].cases))): 1})]:
            broken = deepcopy(observed)
            broken[0].groups = change
            with self.assertRaises(PartitionError):
                module.prove(self.source, assignments, broken)

    def test_missing_and_empty_observed_shards_rejected(self):
        assignments, observed = self.selections()
        with self.assertRaises(PartitionError):
            module.prove(self.source, assignments, observed[:-1])
        with self.assertRaises(PartitionError):
            module.prove(self.source, assignments, [Inventory({}, Counter()), observed[1]])

    def test_configuration_retains_every_unrelated_setting_and_original_exclusion(self):
        source = ET.ElementTree(ET.fromstring('''<phpunit bootstrap="vendor/autoload.php" colors="true" cacheDirectory="cache">
          <testsuites><testsuite name="Feature"><directory suffix=".php" prefix="" groups="money">tests</directory>
          <exclude>tests/IntentionallyExcluded.php</exclude><file groups="money">tests/B.php</file></testsuite></testsuites>
          <source><include><directory>app</directory></include></source>
          <php><env name="DB_CONNECTION" value="sqlite"/><ini name="memory_limit" value="512M"/></php>
          <extensions><bootstrap class="CustomExtension"/></extensions>
        </phpunit>'''))
        untouched = ET.tostring(source.getroot())
        generated = module.shard_configuration(source, self.root, self.source.files, {"tests/A.php"})
        self.assertEqual(untouched, ET.tostring(source.getroot()))
        self.assertEqual(source.getroot().attrib, generated.getroot().attrib)
        for tag in ["source", "php", "extensions"]:
            self.assertEqual(ET.tostring(source.getroot().find(tag)), ET.tostring(generated.getroot().find(tag)))
        suite = generated.getroot().find("testsuites/testsuite")
        original_suite = source.getroot().find("testsuites/testsuite")
        self.assertEqual(original_suite.attrib, suite.attrib)
        self.assertEqual(ET.tostring(original_suite.find("directory")), ET.tostring(suite.find("directory")))
        self.assertEqual("tests/IntentionallyExcluded.php", suite.findall("exclude")[0].text)
        self.assertEqual(sorted(self.source.files - {"tests/A.php"}), [node.text for node in suite.findall("exclude")[1:]])
        self.assertIsNone(suite.find("file"))

    def test_unknown_configuration_definition_rejected(self):
        source = ET.ElementTree(ET.fromstring('<phpunit><testsuites><testsuite name="Feature"><unknown>tests</unknown></testsuite></testsuites></phpunit>'))
        with self.assertRaises(PartitionError):
            module.shard_configuration(source, self.root, self.source.files, {"tests/A.php"})

    def test_unknown_assigned_file_rejected(self):
        source = ET.ElementTree(ET.fromstring('<phpunit><testsuites><testsuite name="Feature"><directory>tests</directory></testsuite></testsuites></phpunit>'))
        with self.assertRaises(PartitionError):
            module.shard_configuration(source, self.root, self.source.files, {"tests/Unknown.php"})

    def inventory_xml(self, body):
        path = self.root / "inventory.xml"
        path.write_text('<testSuite xmlns="https://xml.phpunit.de/testSuite">' + body + '</testSuite>')
        return path

    def test_xml_discovery_preserves_data_sets_files_groups_and_phpt(self):
        path = self.inventory_xml('''<tests><testClass name="A" file="tests/A.php">
          <testMethod id="A::one with data set #0" name="one"/><testMethod id="A::one with data set #1" name="one"/>
          </testClass><phpt file="tests/D.phpt"/></tests>
          <groups><group name="money"><test id="A::one with data set #0"/></group></groups>''')
        result = module.read_inventory(path, self.root)
        self.assertEqual(3, len(result.cases))
        self.assertEqual({"tests/A.php", "tests/D.phpt"}, result.files)
        self.assertEqual(Counter({("money", "A::one with data set #0"): 1}), result.groups)

    def test_unknown_or_duplicate_xml_cases_rejected(self):
        for body in ['<tests><unrecognized/></tests><groups/>',
                     '<tests><testClass name="A" file="tests/A.php"><testMethod id="same" name="one"/><testMethod id="same" name="two"/></testClass></tests><groups/>',
                     '<tests/><groups/>']:
            with self.assertRaises(PartitionError):
                module.read_inventory(self.inventory_xml(body), self.root)

    def test_external_dependencies_require_review_instead_of_becoming_skips(self):
        path = self.root / "tests/A.php"
        path.write_text("<?php #[Depends('localMethod')] function testOne() {}")
        module.refuse_cross_file_dependencies(self.root, {"tests/A.php"})
        for spelling in ["DependsExternal", "DependsOnClass", "DependsExternalUsingDeepClone", "@depends"]:
            path.write_text("<?php // " + spelling)
            with self.assertRaises(PartitionError):
                module.refuse_cross_file_dependencies(self.root, {"tests/A.php"})

    def timings(self, files, fallback=1000):
        return module.Timings("mysql", "fixture", fallback, files, "0" * 64)

    def test_measured_duration_outweighs_case_count(self):
        # A holds three cheap cases; B and C are one slow case each. Counting cases puts both slow files together.
        timings = self.timings({"tests/A.php": (3, 30), "tests/B.php": (1, 10_000), "tests/C.php": (1, 10_000), "tests/D.phpt": (1, 10)})
        slow = {"tests/B.php", "tests/C.php"}
        self.assertEqual(1, sum(slow <= shard for shard in module.partition(self.source, 2)))
        weights, untimed = module.file_weights(self.source, timings)
        self.assertEqual([], untimed)
        assignments, observed = self.selections(2, weights)
        self.assertTrue(all(len(shard & slow) == 1 for shard in assignments))
        module.prove(self.source, assignments, observed)

    def test_timed_partition_is_deterministic_and_independent_of_input_order(self):
        weights = {"tests/A.php": 30, "tests/B.php": 10_000, "tests/C.php": 10_000, "tests/D.phpt": 10}
        reversed_source = Inventory(dict(reversed(list(self.source.cases.items()))), self.source.groups)
        self.assertEqual(module.partition(self.source, 2, weights), module.partition(reversed_source, 2, dict(reversed(list(weights.items())))))

    def test_timed_files_keep_their_per_case_cost_and_unknown_files_use_the_fallback(self):
        timings = self.timings({"tests/A.php": (1, 100), "tests/C.php": (2, 50)}, fallback=700)
        weights, untimed = module.file_weights(self.source, timings)
        # A gained cases since it was timed (1 to 3), so its 100 ms per case now counts three times; C shrank (2 to 1).
        self.assertEqual({"tests/A.php": 300, "tests/B.php": 700, "tests/C.php": 25, "tests/D.phpt": 700}, weights)
        self.assertEqual(["tests/B.php", "tests/D.phpt"], untimed)
        self.assertEqual(({"tests/A.php": 3, "tests/B.php": 1, "tests/C.php": 1, "tests/D.phpt": 1}, []), module.file_weights(self.source, None))

    def test_zero_millisecond_timings_still_leave_no_shard_empty(self):
        weights, _ = module.file_weights(self.source, self.timings({name: (1, 0) for name in self.source.files}))
        self.assertEqual({1}, set(weights.values()))
        self.assertTrue(all(len(shard) == 1 for shard in module.partition(self.source, 4, weights)))

    def test_weights_must_be_positive_whole_numbers_for_exactly_the_discovered_files(self):
        good = {name: 1 for name in self.source.files}
        without_a = {name: weight for name, weight in good.items() if name != "tests/A.php"}
        for bad in [{**good, "tests/Extra.php": 1}, without_a, {**good, "tests/A.php": 0}, {**good, "tests/A.php": 1.5}, {**good, "tests/A.php": True}]:
            with self.assertRaises(PartitionError):
                module.partition(self.source, 2, bad)

    def test_timing_file_rejects_unknown_shapes_and_values(self):
        valid = {"schema_version": 1, "driver": "mysql", "source": "fixture", "fallback_ms_per_case": 5,
                 "files": {"tests/A.php": {"cases": 1, "ms": 0}}}
        path = self.root / "timings.json"

        def read(document):
            path.write_text(document if isinstance(document, str) else json.dumps(document))
            return module.read_timings(path)

        parsed = read(valid)
        self.assertEqual({"tests/A.php": (1, 0)}, parsed.files)
        self.assertEqual(64, len(parsed.sha256))
        broken = [
            "not json", "[]", {**valid, "schema_version": 2}, {**valid, "schema_version": True}, {**valid, "schema_version": 1.0}, {**valid, "extra": 1},
            {key: value for key, value in valid.items() if key != "source"},
            {**valid, "driver": ""}, {**valid, "source": 3}, {**valid, "fallback_ms_per_case": 0}, {**valid, "fallback_ms_per_case": True},
            {**valid, "files": {}}, {**valid, "files": {"tests/A.php": [1, 1]}},
            {**valid, "files": {"tests/A.php": {"cases": 0, "ms": 1}}}, {**valid, "files": {"tests/A.php": {"cases": 1, "ms": -1}}},
            {**valid, "files": {"tests/A.php": {"cases": 1, "ms": 1.5}}}, {**valid, "files": {"tests/A.php": {"cases": 1, "ms": 1, "extra": 2}}},
        ]
        for document in broken:
            with self.assertRaises(PartitionError, msg=str(document)):
                read(document)

    def test_committed_timing_files_are_well_formed(self):
        # Shape only: a renamed or deleted test file leaves a harmless stale entry until the next refresh.
        for driver in ["mysql", "sqlite"]:
            parsed = module.read_timings(Path(__file__).with_name(f"phpunit-timings-{driver}.json"))
            self.assertEqual(driver, parsed.driver)
            self.assertTrue(all(name.startswith("tests/") and name.endswith(".php") for name in parsed.files))


class TimingGeneratorTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        for file in ["tests/A.php", "tests/B.php"]:
            (self.root / file).parent.mkdir(parents=True, exist_ok=True)
            (self.root / file).write_text("<?php // isolated timing fixture\n")

    def log(self, name, cases):
        body = "".join(f'<testcase name="t{index}" file="{file}" time="{seconds}"/>' for index, (file, seconds) in enumerate(cases))
        path = self.root / name
        path.write_text(f'<?xml version="1.0"?><testsuites><testsuite name="fixture">{body}</testsuite></testsuites>')
        return path

    def test_runner_paths_map_to_repository_files_and_vanished_files_are_reported(self):
        log = self.log("runner.xml", [("/home/runner/work/app/app/tests/A.php", "1.5"), ("/home/runner/work/app/app/tests/A.php", "0.5"),
                                      ("/home/runner/work/app/app/tests/Gone.php", "9")])
        totals, gone = timings_module.read_junit(log, self.root)
        self.assertEqual({"tests/A.php": [2, 2.0]}, totals)
        self.assertEqual({"/home/runner/work/app/app/tests/Gone.php"}, gone)

    def test_local_absolute_paths_resolve_and_parent_traversal_is_refused(self):
        log = self.log("local.xml", [(str(self.root / "tests/B.php"), "1"), ("/elsewhere/../tests/A.php", "1")])
        totals, gone = timings_module.read_junit(log, self.root)
        self.assertEqual({"tests/B.php": [1, 1.0]}, totals)
        self.assertEqual({"/elsewhere/../tests/A.php"}, gone)

    def test_a_case_without_a_file_is_refused_instead_of_undercounted(self):
        log = self.root / "nofile.xml"
        log.write_text('<testsuites><testsuite><testcase name="t" time="1"/></testsuite></testsuites>')
        with self.assertRaises(timings_module.TimingError):
            timings_module.read_junit(log, self.root)

    def test_several_runs_are_averaged_and_render_as_a_timing_file_the_partitioner_accepts(self):
        first = timings_module.read_junit(self.log("a.xml", [("tests/A.php", "2"), ("tests/A.php", "2"), ("tests/B.php", "1")]), self.root)[0]
        second = timings_module.read_junit(self.log("b.xml", [("tests/A.php", "4"), ("tests/A.php", "4"), ("tests/B.php", "3")]), self.root)[0]
        combined = timings_module.combine([first, second])
        self.assertEqual({"tests/A.php": (2, 6000), "tests/B.php": (1, 2000)}, combined)
        path = self.root / "rendered.json"
        path.write_text(timings_module.render("sqlite", "fixture runs", combined))
        parsed = module.read_timings(path)
        self.assertEqual((combined, "sqlite", "fixture runs"), (parsed.files, parsed.driver, parsed.source))
        self.assertEqual(round(8000 / 3), parsed.fallback_ms_per_case)
        with self.assertRaises(timings_module.TimingError):
            timings_module.render("mysql", "nothing", {})


if __name__ == "__main__":
    unittest.main()
