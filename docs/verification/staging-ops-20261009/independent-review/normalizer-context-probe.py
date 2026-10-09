#!/usr/bin/env python3
"""Independent byte-preservation probes, including mysqldump routine delimiters."""
import importlib.util
from pathlib import Path
import unittest

REPO = Path(__file__).resolve().parents[4]
HELPER = REPO / "ops/staging/normalize-mysql-dump.py"
SPEC = importlib.util.spec_from_file_location("independent_dump_normalizer", HELPER)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def normalize(data):
    return b"".join(MODULE.normalize(data.splitlines(keepends=True)))


DDL = (b"DROP TABLE IF EXISTS `terms`;\nCREATE TABLE `terms` (\n"
       b"  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\n);\n")


class DumpContextProbe(unittest.TestCase):
    def test_top_level_schema_attribute_can_normalize(self):
        self.assertEqual(normalize(DDL), DDL.replace(b" CHARACTER SET utf8mb4", b""))

    def test_mysql_dump_procedure_body_remains_byte_exact(self):
        data = (b"DELIMITER ;;\n/*!50003 CREATE*/ /*!50017 DEFINER=`synthetic`@`localhost`*/ "
                b"/*!50003 PROCEDURE `synthetic_review`()\nBEGIN\n" + DDL
                + b"END */;;\nDELIMITER ;\n")
        self.assertEqual(normalize(data), data, "matching DROP/CREATE inside a procedure body was normalized")

    def test_mysql_dump_routine_literal_remains_byte_exact(self):
        data = (b"DELIMITER $$\nCREATE PROCEDURE `synthetic_review`()\nBEGIN\n"
                b"SET @sql = '\n" + DDL + b"';\nEND$$\nDELIMITER ;\n")
        self.assertEqual(normalize(data), data, "SQL-looking bytes inside a routine literal were normalized")

    def test_delimiter_looking_line_inside_routine_literal_is_not_a_directive(self):
        data = (b"DELIMITER ;;\nCREATE PROCEDURE `synthetic_review`()\nBEGIN\n"
                b"SET @sql = '\nDELIMITER ;\n" + DDL + b"';\nEND;;\nDELIMITER ;\n")
        self.assertEqual(normalize(data), data, "a literal line reset delimiter context and exposed literal bytes to normalization")


if __name__ == "__main__":
    unittest.main(verbosity=2)
