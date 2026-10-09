"""Restore comparisons may ignore a schema rendering difference, never retained data."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

HELPER = Path(__file__).resolve().parents[2] / "ops/staging/normalize-mysql-dump.py"
SPEC = importlib.util.spec_from_file_location("dump_normalizer", HELPER)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def normalize(data):
    return b"".join(MODULE.normalize(data.splitlines(keepends=True)))


def table(column):
    return (b"DROP TABLE IF EXISTS `terms`;\n"
            b"/*!40101 SET @saved_cs_client = @@character_set_client */;\n"
            b"/*!50503 SET character_set_client = utf8mb4 */;\n"
            b"CREATE TABLE `terms` (\n" + column + b"\n) ENGINE=InnoDB;\n")


class DumpNormalizationTest(unittest.TestCase):
    def test_first_generation_and_redump_string_columns_match(self):
        for datatype in (b"varchar(255)", b"char(10)", b"tinytext", b"text", b"mediumtext", b"longtext",
                         b"enum('one','two')", b"set('one','two')"):
            with self.subTest(datatype=datatype):
                first = table(b"  `value` " + datatype + b" COLLATE utf8mb4_unicode_ci DEFAULT NULL")
                second = table(b"  `value` " + datatype + b" CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL")
                self.assertEqual(normalize(second), first)

    def test_changed_insert_rows_remain_different(self):
        original = table(b"  `value` text COLLATE utf8mb4_unicode_ci") + b"INSERT INTO `terms` VALUES ('A CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');\n"
        changed = original.replace(b"A CHARACTER SET utf8mb4", b"A")
        self.assertNotEqual(normalize(original), normalize(changed))

    def test_defaults_comments_and_escaped_identifiers_remain_exact(self):
        for tail in (b"DEFAULT ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'",
                     b"COMMENT ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'"):
            with self.subTest(tail=tail):
                data = table(b"  `name`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` text COLLATE utf8mb4_unicode_ci " + tail)
                self.assertEqual(normalize(data), data)

    def test_enum_strings_remain_exact_while_real_attribute_is_normalized(self):
        datatype = b"enum(' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci','paren)(', 'escaped\\\'quote','double''quote')"
        data = table(b"  `value` " + datatype + b" CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        expected = table(b"  `value` " + datatype + b" COLLATE utf8mb4_unicode_ci")
        self.assertEqual(normalize(data), expected)

    def test_routine_ddl_and_nonmatching_table_names_are_not_normalized(self):
        for prefix in (b"", b"DROP TABLE IF EXISTS `other`;\n", b"DROP TABLE IF EXISTS `terms`;\nDELIMITER ;;\n"):
            with self.subTest(prefix=prefix):
                data = prefix + b"CREATE TABLE `terms` (\n  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\n);\n"
                self.assertEqual(normalize(data), data)

    def test_other_charsets_malformed_types_and_later_attributes_remain_exact(self):
        for column in (b"  `value` text CHARACTER SET latin1 COLLATE latin1_swedish_ci",
                       b"  `value` varchar(255 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
                       b"  `value` text DEFAULT 'x' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
                       b"  `value` int CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"):
            with self.subTest(column=column):
                data = table(column)
                self.assertEqual(normalize(data), data)

    def test_binary_row_bytes_and_unknown_layout_remain_exact(self):
        data = b"INSERT INTO `terms` VALUES ('\xff CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');\r\n"
        self.assertEqual(normalize(data), data)

    def test_cli_refuses_unreadable_input_instead_of_a_successful_empty_dump(self):
        with tempfile.TemporaryDirectory() as directory:
            process = subprocess.run(["python3", "-I", str(HELPER), str(Path(directory) / "absent")], capture_output=True)
            self.assertEqual(process.returncode, 1)
            self.assertEqual(process.stdout, b"")
            self.assertEqual(process.stderr, b"dump normalization unavailable\n")


if __name__ == "__main__":
    unittest.main(verbosity=2)
