"""Host prerequisites the production identity layer needs from the staging kit.

* skip_name_resolve: CatalogSocketBinding admits the metadata companion connection only when PROCESSLIST names the
  client by a literal IP. MySQL's default (name resolution on) shows a 127.0.0.1 client as localhost:<port>, and the
  first staging deploy stopped at 2026_10_07_247000_production_customer_identity with "Current private catalog
  admission required". provision.sh must persist skip_name_resolve and verify it after the one restart.
* No migrate --pretend: identity migrations run DDL through the raw PDO, which pretend mode cannot intercept, so a
  "preview" would change the schema before the guarded migration.
Reads the real files; no host."""
import re
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
PROVISION = (REPO / "ops/staging/provision.sh").read_text()


class MysqlIdentityPrerequisitesTest(unittest.TestCase):
    def test_provision_persists_and_verifies_skip_name_resolve_before_reporting_mysql_ready(self):
        persist = PROVISION.index("SET PERSIST_ONLY skip_name_resolve = ON")
        guard = PROVISION.index("MySQL accounts addressed by host name exist")
        restart = PROVISION.index("systemctl restart mysql", persist)
        verify = PROVISION.index("skip_name_resolve did not take effect", restart)
        ready = PROVISION.index('info "MySQL $myver: schema')
        self.assertLess(guard, persist)
        self.assertLess(persist, restart)
        self.assertLess(restart, verify)
        self.assertLess(verify, ready)
        self.assertIn("if [ \"$(mysql_q 'SELECT @@skip_name_resolve')\" != 1 ]; then", PROVISION)

    def test_no_kit_or_runbook_runs_migrate_pretend(self):
        for path in ["ops/staging/release-step.sh", "ops/staging/forge-deploy.sh", "docs/ops/production-activation-packet.md"]:
            commands = [line for line in (REPO / path).read_text().splitlines()
                        if not line.lstrip().startswith("#") and re.search(r"migrate\s+--pretend", line)]
            self.assertEqual([], commands, path)


if __name__ == "__main__":
    unittest.main()
