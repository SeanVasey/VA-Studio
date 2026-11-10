"""The scheduler dead-man's switch host job (FP-028): ops/staging/scheduler-watch.sh and where provisioning installs it.

The script is run for real in bash against a throwaway release directory and conf. `runuser` is a stand-in earlier on PATH that
records its arguments (the script's own PATH line is rewritten for that), so nothing needs PHP, a database or a real host.
The script refuses to run unless root, and the conf it trusts must be root-owned, so the behavioural tests skip otherwise."""
import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
SCRIPT = (REPO / "ops/staging/scheduler-watch.sh").read_text()
PROVISION = (REPO / "ops/staging/provision.sh").read_text()
IS_ROOT = os.geteuid() == 0


class StaticWiring(unittest.TestCase):
    def test_provisioning_installs_the_helper_and_a_five_minute_cron_job_outside_the_scheduler(self):
        self.assertIn('install -m 0755 -o root -g root "$KIT/scheduler-watch.sh" /usr/local/sbin/vasey-staging-scheduler-watch', PROVISION)
        self.assertRegex(PROVISION, r"(?m)^\*/5 \* \* \* \* root /usr/local/sbin/vasey-staging-scheduler-watch >> /var/log/vasey-staging/scheduler-watch\.log 2>&1$")
        self.assertIn("scheduler-watch.sh normalize-mysql-dump.py", PROVISION, "the kit-file existence check lists it")

    def test_the_command_is_never_scheduled_by_the_application(self):
        code = "\n".join(line for line in (REPO / "routes/console.php").read_text().splitlines() if not line.lstrip().startswith("//"))
        self.assertNotIn("scheduler-heartbeat-check", code)

    def test_the_script_carries_no_address_secret_or_url(self):
        self.assertNotRegex(SCRIPT, r"@[a-z0-9-]+\.[a-z]{2,}")
        self.assertNotIn("http", SCRIPT)

    def test_the_deploy_gate_pins_the_installed_copy(self):
        deploy = (REPO / "ops/staging/forge-deploy.sh").read_text()
        self.assertIn("required scheduler-watch.sh /usr/local/sbin/vasey-staging-scheduler-watch\n", deploy)


@unittest.skipUnless(IS_ROOT, "the script requires root and a root-owned conf")
class RunScript(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-sched-watch-")
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
        stub.write_text('#!/bin/bash\nprintf "%s\\n" "PWD=$PWD" "$@" >> "$CALLS"\necho "${STUB_OUT:-OK scheduler heartbeat 5s old}"\nexit "${STUB_EXIT:-0}"\n')
        stub.chmod(0o755)
        self.conf = self.root / "staging.conf"
        self.conf.write_text(f"VASEY_APP_USER=nobody\nVASEY_ROOT={self.root / 'app'}\nVASEY_PHP=/usr/bin/php8.4\n")
        self.script = self.root / "watch.sh"
        self.script.write_text(re.sub(r"(?m)^export LC_ALL=C PATH=.*$", f"export LC_ALL=C PATH={self.bin}:/usr/bin:/bin", SCRIPT))

    def run_script(self, **extra):
        env = {"PATH": "/usr/bin:/bin", "VASEY_STAGING_CONF": str(self.conf), "CALLS": str(self.calls)}
        env.update({k: str(v) for k, v in extra.items()})
        return subprocess.run(["bash", str(self.script)], env=env, capture_output=True, text=True, timeout=60)

    def test_it_runs_the_check_with_notify_as_the_app_user_on_the_served_release(self):
        run = self.run_script()
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn("exit 0: OK scheduler heartbeat 5s old", run.stdout)
        lines = self.calls.read_text().splitlines()
        self.assertEqual(lines[0], f"PWD={self.release}")
        self.assertEqual(lines[1:], ["-u", "nobody", "--", "env", "-i", "PATH=/usr/bin:/bin", "LC_ALL=C", "/usr/bin/php8.4",
                                     f"{self.release}/artisan", "vasey:scheduler-heartbeat-check", "--notify", "--no-interaction", "--no-ansi"])

    def test_a_stale_result_is_passed_through_as_the_exit_status(self):
        run = self.run_script(STUB_EXIT=1, STUB_OUT="STALE scheduler heartbeat: too old, 2000s old (limit 900s)")
        self.assertEqual(run.returncode, 1)
        self.assertIn("exit 1: STALE", run.stdout)

    def test_no_served_release_or_untrusted_conf_is_unknown_and_runs_nothing(self):
        (self.root / "app/current").unlink()
        run = self.run_script()
        self.assertEqual(run.returncode, 3)
        self.assertIn("UNKNOWN no served release", run.stdout)
        self.assertFalse(self.calls.exists())
        link = self.root / "link.conf"
        link.symlink_to(self.conf)
        run = self.run_script(VASEY_STAGING_CONF=link)
        self.assertEqual(run.returncode, 3)
        self.assertIn("untrusted", run.stdout)
        self.assertFalse(self.calls.exists())

    def test_an_active_previous_run_is_skipped_not_stacked(self):
        holder = subprocess.Popen(["bash", "-c", f'exec 8>"{self.root}/app/.scheduler-watch.lock"; flock 8; sleep 20'])
        self.addCleanup(holder.kill)
        for _ in range(50):
            if (self.root / "app/.scheduler-watch.lock").exists():
                break
            subprocess.run(["sleep", "0.1"])
        subprocess.run(["sleep", "0.3"])
        run = self.run_script()
        self.assertEqual(run.returncode, 0)
        self.assertIn("previous run still active", run.stdout)
        self.assertFalse(self.calls.exists())


if __name__ == "__main__":
    unittest.main()
