#!/usr/bin/env python3
"""Reviewer delta probe for B2 C1 and the 5ad7546 fixture repair (not part of the branch).

Usage: python3 reviewer_c1_configure_probe.py <export-root>
Reuses the branch's real ProtectedConfigurationTest fixture (real cmd_configure source, real sealer, real
validate-runtime.php) and adds adversarial cases:
  1. route:cache failure after config:cache -> configure exits non-zero, never prints "configured", never
     reaches sealed_release (admission stays closed; the release stays in maintenance).
  2. mutant ctl without the route:cache step -> the branch's own assertion fails (the test would catch a revert).
  3. order: config:cache strictly before route:cache.
  4. validator, run directly: each unsafe body is refused for its intended reason (not only for the CLI binary),
     a candidate without VASEY_PHP_CLI_BINARY and one naming a non-PHP binary are refused (no weakening).
  5. sealing still verifies after configure (sealed_release ran its real verify).
"""
import importlib.util, os, subprocess, sys, unittest
from pathlib import Path

ROOT = Path(sys.argv.pop(1)).resolve()
spec = importlib.util.spec_from_file_location("pc", ROOT / "tests/ops/test_staging_protected_configuration.py")
pc = importlib.util.module_from_spec(spec); spec.loader.exec_module(pc)
assert pc.REPO == ROOT, (pc.REPO, ROOT)


class Probe(pc.ProtectedConfigurationTest):
    def _artisan(self, php_body):
        (self.release / "artisan").write_text("<?php\n" + php_body)

    def test_probe_route_cache_failure_keeps_admission_closed(self):
        self._artisan("file_put_contents(__DIR__.'/storage/framework/cache-command', $argv[1].PHP_EOL, FILE_APPEND);\n"
                      "if ($argv[1] === 'route:cache') { exit(1); }\n")
        run = self.configure(self.previous + "CONTACT_FROM_NAME=probe\n")
        print("\nPROBE route:cache failure: rc=%d stdout=%r stderr=%r" % (run.returncode, run.stdout.strip(), run.stderr.strip()))
        self.assertNotEqual(run.returncode, 0)
        self.assertNotIn("configured", run.stdout)
        self.assertIn("route cache failed", run.stderr)
        self.assertEqual((self.release / "storage/framework/cache-command").read_text(), "config:cache\nroute:cache\n")
        self.assertTrue((self.release / "storage/framework/maintenance.php").exists(), "maintenance must remain")

    def test_probe_config_cache_failure_does_not_attempt_route_cache(self):
        self._artisan("file_put_contents(__DIR__.'/storage/framework/cache-command', $argv[1].PHP_EOL, FILE_APPEND);\n"
                      "if ($argv[1] === 'config:cache') { exit(1); }\n")
        run = self.configure(self.previous + "CONTACT_FROM_NAME=probe\n")
        print("\nPROBE config:cache failure: rc=%d stderr=%r" % (run.returncode, run.stderr.strip()))
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual((self.release / "storage/framework/cache-command").read_text(), "config:cache\n")

    def test_probe_mutant_without_route_cache_is_caught(self):
        start = self.definitions.index("  # The panel's MFA page middleware")
        end = self.definitions.index("  sealed_release \"$sha\"", start)
        original = self.definitions
        self.definitions = original[:start] + original[end:]
        try:
            run = self.configure(self.previous + "CONTACT_FROM_NAME=probe\n")
            got = (self.release / "storage/framework/cache-command").read_text()
        finally:
            self.definitions = original
        print("\nPROBE mutant (route:cache removed): rc=%d cache-commands=%r" % (run.returncode, got))
        self.assertEqual(run.returncode, 0)
        self.assertNotEqual(got, "config:cache\nroute:cache\n", "the branch assertion would fail on this mutant")

    def test_probe_seal_verified_after_successful_configure(self):
        calls = self.root / "seal-calls"
        self.driver.write_text(self.driver.read_text().replace(
            "if sys.argv[2]=='stage-env':",
            "open(%r,'a').write(sys.argv[2]+'\\n')\nif sys.argv[2]=='stage-env':" % str(calls)))
        run = self.configure(self.previous + "CONTACT_FROM_NAME=probe\n")
        print("\nPROBE seal operations: rc=%d calls=%r stdout=%r" % (run.returncode, calls.read_text(), run.stdout.strip()))
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(calls.read_text(), "stage-env\nverify\n")
        self.assertIn("configured", run.stdout)

    def _validate(self, body):
        candidate = self.root / "direct.env"
        candidate.write_text(body); candidate.chmod(0o600)
        r = subprocess.run([pc.PHP, str(ROOT / "ops/staging/validate-runtime.php"), str(candidate), "local",
                            "staging.synthetic.invalid", "vasey_staging", str(self.release / ".env")],
                           capture_output=True, text=True, env={"PATH": "/usr/bin:/bin", "LC_ALL": "C"}, timeout=60)
        return r.returncode, sorted(l[5:] for l in r.stderr.splitlines() if l.startswith("FAIL "))

    def test_probe_validator_reasons_and_cli_binary_still_enforced(self):
        no_binary = "\n".join(l for l in self.previous.splitlines() if not l.startswith("VASEY_PHP_CLI_BINARY=")) + "\n"
        cases = {
            "fixture previous (valid)": self.previous,
            "APP_DEBUG=true": self.previous + "APP_DEBUG=true\n",
            "PRODUCTION_CHECKOUT_ENABLED": self.previous + 'PRODUCTION_CHECKOUT_ENABLED="true"\n',
            "APP_KEY rotation": self.previous.replace("A" * 43, "B" * 43),
            "no VASEY_PHP_CLI_BINARY": no_binary,
            "VASEY_PHP_CLI_BINARY=/bin/true": self.previous.replace("VASEY_PHP_CLI_BINARY=" + os.path.realpath(pc.PHP), "VASEY_PHP_CLI_BINARY=/bin/true"),
            "VASEY_PHP_CLI_BINARY=relative": self.previous.replace("VASEY_PHP_CLI_BINARY=" + os.path.realpath(pc.PHP), "VASEY_PHP_CLI_BINARY=php"),
        }
        results = {k: self._validate(v) for k, v in cases.items()}
        for k, v in results.items():
            print("PROBE validator %-36s rc=%d fails=%s" % (k, v[0], v[1]))
        self.assertEqual(results["fixture previous (valid)"], (0, []))
        for k in ("APP_DEBUG=true", "PRODUCTION_CHECKOUT_ENABLED", "APP_KEY rotation"):
            self.assertNotEqual(results[k][0], 0, k)
            self.assertNotIn("runtime.php_cli_binary", results[k][1], k + " refused for its own reason")
        for k in ("no VASEY_PHP_CLI_BINARY", "VASEY_PHP_CLI_BINARY=/bin/true", "VASEY_PHP_CLI_BINARY=relative"):
            self.assertEqual(results[k][1], ["runtime.php_cli_binary"], k)


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromNames(
        ["__main__.Probe." + n for n in dir(Probe) if n.startswith("test_probe_")])
    sys.exit(0 if unittest.TextTestRunner(verbosity=2).run(suite).wasSuccessful() else 1)
