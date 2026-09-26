#!/usr/bin/env python3
"""Executable safeguards for CI selection; PHP itself proves runtime discovery."""

from collections import Counter
from copy import deepcopy
import importlib.util
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

    def selections(self, count=2):
        assignments = module.partition(self.source, count)
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


if __name__ == "__main__":
    unittest.main()
