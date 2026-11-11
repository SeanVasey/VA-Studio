"""Dashboard evidence from ops/staging/backup.sh (FP-028): `record_evidence` and where the nightly run calls it.

The function is sliced out of the shipped script and run in bash against a throwaway release directory. `runuser` is a
stand-in on PATH that records its arguments, so nothing here needs root, PHP, a database or a real host."""
import os
import re
import subprocess
import time
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
SOURCE = (REPO / "ops/staging/backup.sh").read_text()
START = SOURCE.index("record_evidence() {")
FUNCTION = SOURCE[START:SOURCE.index("\n}\n", START) + 3]
NIGHTLY = SOURCE[SOURCE.index("  nightly)"):SOURCE.index("  predeploy)")]
FUNCTIONS_SHIP_PENDING = SOURCE[SOURCE.index("ship_pending() {"):SOURCE.index("ship_pending_start() {")]
SHA = "0123456789abcdef" * 4

PRELUDE = """set -euo pipefail
log() { echo "LOG: $*"; }
"""


class RecordEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-evidence-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        release = self.root / "app/releases" / ("a" * 40)
        release.mkdir(parents=True)
        (release / "artisan").write_text("<?php\n")
        (self.root / "app/current").symlink_to(release)
        self.release = release
        self.bin = self.root / "bin"
        self.bin.mkdir()
        self.calls = self.root / "calls"
        stub = self.bin / "runuser"
        stub.write_text('#!/bin/bash\nprintf "%s\\n" "PWD=$PWD" "$@" >> "$CALLS"\nexit "${STUB_EXIT:-0}"\n')
        stub.chmod(0o755)
        self.set = self.root / "backups/20261109T031700Z-aaaaaaaaaaaa"
        self.set.mkdir(parents=True)
        (self.set / "database.sql").write_bytes(b"x" * 1000)
        (self.set / "database.sql.sha256").write_text(f"{SHA}  database.sql\n")
        (self.set / "private.tar").write_bytes(b"y" * 2345)

    def run_function(self, call, function=FUNCTION, **extra):
        env = {"PATH": f"{self.bin}:/usr/bin:/bin", "LC_ALL": "C", "CALLS": str(self.calls),
               "VASEY_ROOT": str(self.root / "app"), "VASEY_APP_USER": "vaseyapp", "VASEY_PHP": "/usr/bin/php8.4"}
        env.update({k: str(v) for k, v in extra.items()})
        return subprocess.run(["bash", "-c", PRELUDE + function + "\n" + call + "\necho AFTER $?"],
                              env=env, capture_output=True, text=True, timeout=60)

    def recorded(self):
        return self.calls.read_text().splitlines() if self.calls.exists() else []

    def test_a_successful_run_sends_kind_status_size_and_dump_hash_as_the_app_user_on_the_served_release(self):
        run = self.run_function(f'record_evidence daily ok "{self.set}"')
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("LOG: recorded daily ok on the dashboard", run.stdout)
        args = self.recorded()
        self.assertEqual(args[0], f"PWD={self.release}")
        size = sum(p.stat().st_size for p in self.set.iterdir())
        self.assertEqual(args[1:], ["-u", "vaseyapp", "--", "env", "-i", "PATH=/usr/bin:/bin", "LC_ALL=C", "/usr/bin/php8.4",
                                    f"{self.release}/artisan", "vasey:backup-record", "--kind=daily", "--status=ok",
                                    f"--bytes={size}", f"--sha256={SHA}", "--no-interaction", "--no-ansi"])
        # No path of the backup set or its files leaves the script.
        self.assertFalse(any(str(self.set) in a or "backups" in a for a in args))

    def test_a_failed_run_sends_no_size_or_hash(self):
        run = self.run_function(f'record_evidence daily failed "{self.set}"')
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual([a for a in self.recorded() if a.startswith("--") and a != "--"],
                         ["--kind=daily", "--status=failed", "--no-interaction", "--no-ansi"])

    def test_a_malformed_hash_file_or_an_empty_set_is_left_out_for_the_command_to_refuse(self):
        (self.set / "database.sql.sha256").write_text("not-a-hash  database.sql\n")
        empty = self.root / "backups/empty"
        empty.mkdir()
        for target in (self.set, empty):
            self.run_function(f'record_evidence daily ok "{target}"')
        sent = [a for a in self.recorded() if a.startswith("--sha256") or a.startswith("--bytes")]
        self.assertEqual(sent, [f"--bytes={sum(p.stat().st_size for p in self.set.iterdir())}"])

    def test_a_failure_to_record_never_fails_the_caller(self):
        run = self.run_function(f'record_evidence daily ok "{self.set}"', STUB_EXIT=1)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("WARNING: could not record daily ok on the dashboard", run.stdout)
        self.assertIn("AFTER 0", run.stdout)
        (self.root / "app/current").unlink()
        self.calls.unlink()
        run = self.run_function(f'record_evidence daily ok "{self.set}"')
        self.assertIn("AFTER 0", run.stdout)
        self.assertIn("WARNING: no served release", run.stdout)
        self.assertEqual(self.recorded(), [])
        (self.root / "app/current").symlink_to(self.release)
        run = self.run_function("record_evidence daily ok", VASEY_PHP="")
        self.assertIn("AFTER 0", run.stdout)
        self.assertEqual(self.recorded(), [])

    def test_a_recorder_that_hangs_or_ignores_term_is_killed_and_never_changes_the_outcome(self):
        # The shipped bound is `timeout -k 10 120`: TERM after 120 s, KILL 10 s later. The same function with the bounds
        # scaled to 2 s and 1 s proves the shape without a two-minute test; a bound without -k would wait for the stub.
        self.assertIn("timeout -k 10 120 runuser", FUNCTION)
        scaled = re.sub(r"timeout (-k [0-9]+ )?120 ", lambda m: "timeout " + ("-k 1 " if m.group(1) else "") + "2 ", FUNCTION)
        self.assertNotEqual(scaled, FUNCTION)
        stub = self.bin / "runuser"
        for name, body in (("ignores TERM", "trap '' TERM\nsleep 60 & wait\nsleep 60\n"), ("sleeps", "sleep 60\n")):
            with self.subTest(name):
                stub.write_text("#!/bin/bash\n" + body)
                started = time.monotonic()
                run = self.run_function(f'record_evidence daily ok "{self.set}"', scaled)
                elapsed = time.monotonic() - started
                self.assertEqual(run.returncode, 0, run.stderr)
                self.assertIn("WARNING: could not record daily ok on the dashboard; the backup result stands", run.stdout)
                self.assertIn("AFTER 0", run.stdout)
                self.assertLess(elapsed, 10, f"{name}: the recorder held the backup for {elapsed:.1f} s")

    def test_the_nightly_run_records_after_the_resume_and_before_it_reports_a_failure(self):
        resume = NIGHTLY.index('"$CTL" resume')
        ok = NIGHTLY.index('record_evidence daily ok "$(cat -- "$VASEY_BACKUP_DIR/.last"')
        failed = NIGHTLY.index("record_evidence daily failed")
        die = NIGHTLY.index('[ "$snapshot_status" = 0 ] || die')
        self.assertLess(resume, ok)
        self.assertLess(ok, failed)
        self.assertLess(failed, die)
        # The nightly run records the local result (and defers "ok" while an off-host copy is still owed); ship-pending records the
        # off-host result. predeploy also runs inside the nightly (ctl snapshot) and inside every deploy: it must record nothing.
        self.assertEqual(SOURCE.count("record_evidence daily"), 4)
        self.assertEqual(NIGHTLY.count("record_evidence daily"), 2)
        self.assertIn("record_evidence daily failed", FUNCTIONS_SHIP_PENDING)
        self.assertIn('record_evidence daily ok "$VASEY_BACKUP_DIR/$newest_ok"', FUNCTIONS_SHIP_PENDING)
        predeploy = SOURCE[SOURCE.index("  predeploy)"):SOURCE.index("  space-check)")]
        self.assertNotIn("record_evidence", predeploy)
        # Starting the shipment is after the resume and before the nightly run reports a local failure.
        self.assertLess(resume, NIGHTLY.index("ship_pending_start"))
        self.assertLess(NIGHTLY.index("ship_pending_start"), die)


if __name__ == "__main__":
    unittest.main()
