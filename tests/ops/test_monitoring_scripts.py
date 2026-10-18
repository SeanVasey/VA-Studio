"""ops/monitoring: queue age and heartbeat, scheduler liveness, signature age and the host baseline.

Each script runs for real in bash against scripted stand-ins for mysql, supervisorctl, journalctl, ufw and the like, so
what it concludes from each kind of answer is tested without a host. The queue SQL itself is exercised against a real
MySQL 8.4 in docs/verification/production-ops-kit-20261017/."""
import datetime
import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
MON = REPO / "ops/monitoring"
IS_ROOT = os.geteuid() == 0
SECRET = "SYNTHETIC_PASSWORD_DO_NOT_REPORT"


class Sandbox(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-monitoring-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.bin = self.root / "bin"
        self.bin.mkdir()

    def stub(self, name, body):
        path = self.bin / name
        path.write_text("#!/bin/bash\n" + body)
        path.chmod(0o755)
        return path

    def run_script(self, script, *args, env=None, input=None):
        environment = {"PATH": f"{self.bin}:/usr/bin:/bin", "HOME": str(self.root), "LC_ALL": "C", **(env or {})}
        return subprocess.run(["bash", str(MON / script), *args], env=environment, capture_output=True, text=True,
                              timeout=60, input=input)


class QueueAgeCheck(Sandbox):
    def setUp(self):
        super().setUp()
        self.defaults = self.root / "backup.my.cnf"
        self.defaults.write_text(f"[client]\nhost=127.0.0.1\nuser=vasey_backup\npassword={SECRET}\n")
        self.defaults.chmod(0o600)
        self.conf = self.root / "production.conf"
        self.conf.write_text(f"VASEY_DB_NAME=vasey_production\nVASEY_BACKUP_MYCNF={self.defaults}\n")
        self.calls = self.root / "mysql.calls"
        # Prints the rows in STUB_ROWS, logging every argument and the SQL. Exit status from STUB_MYSQL_STATUS.
        self.stub("mysql", f'printf "%s\\n" "$@" >> "{self.calls}"\n[ "${{STUB_MYSQL_STATUS:-0}}" = 0 ] || {{ echo "ERROR 1045 using password {SECRET}" >&2; exit "$STUB_MYSQL_STATUS"; }}\nprintf "%b" "${{STUB_ROWS:-}}"\n')
        self.curl_calls = self.root / "curl.calls"
        self.stub("curl", f'printf "%s\\n" "$@" >> "{self.curl_calls}"\nexit "${{STUB_CURL_STATUS:-0}}"\n')

    def check(self, rows="", *args, status=0, extra_env=None):
        env = {"STUB_ROWS": rows, "STUB_MYSQL_STATUS": str(status), "VASEY_MYSQL_BIN": str(self.bin / "mysql"),
               "VASEY_CURL_BIN": str(self.bin / "curl"), **(extra_env or {})}
        run = self.run_script("queue-age-check.sh", "--conf", str(self.conf), *args, env=env)
        self.assertNotIn(SECRET, run.stdout + run.stderr)
        return run

    def test_an_empty_queue_is_ok(self):
        run = self.check("")
        self.assertEqual(run.returncode, 0, run.stdout)
        self.assertIn("SUMMARY OK queues=0", run.stdout)

    def test_ready_job_age_thresholds(self):
        for age, code, word in ((10, 0, "OK"), (299, 0, "OK"), (300, 1, "WARN"), (899, 1, "WARN"), (900, 2, "CRIT")):
            with self.subTest(age=age):
                run = self.check(f"ready\\tpayments\\t3\\t{age}\\n")
                self.assertEqual(run.returncode, code, run.stdout)
                self.assertIn(f"{word} queue=payments ready=3 oldest_ready_s={age}", run.stdout)

    def test_per_queue_threshold_overrides_the_default(self):
        rows = "ready\\tmedia\\t1\\t400\\nready\\tpayments\\t1\\t400\\n"
        run = self.check(rows, "--queue-threshold", "media=900:3600")
        self.assertEqual(run.returncode, 1)
        self.assertIn("OK queue=media", run.stdout)
        self.assertIn("WARN queue=payments", run.stdout)

    def test_a_reservation_held_past_retry_after_is_flagged(self):
        self.assertEqual(self.check("reserved\\tmedia\\t1\\t1199\\n").returncode, 0)
        warn = self.check("reserved\\tmedia\\t1\\t1300\\n")
        self.assertEqual(warn.returncode, 1)
        self.assertIn("reserved 1300s", warn.stdout)
        self.assertEqual(self.check("reserved\\tmedia\\t1\\t2400\\n").returncode, 2)

    def test_recent_failures_warn_then_go_critical(self):
        self.assertEqual(self.check("failed\\tpayments\\t1\\t0\\n").returncode, 1)
        self.assertEqual(self.check("failed\\tpayments\\t9\\t0\\n").returncode, 1)
        critical = self.check("failed\\tpayments\\t10\\t0\\n")
        self.assertEqual(critical.returncode, 2)
        self.assertIn("failed_24h=10", critical.stdout)
        self.assertEqual(self.check("failed\\tpayments\\t1\\t0\\n", "--failed-warn", "0").returncode, 0)

    def test_the_worst_queue_decides_and_every_queue_is_reported(self):
        run = self.check("ready\\tmedia\\t1\\t5\\nready\\tpayments\\t1\\t1000\\nfailed\\tdefault\\t1\\t0\\n")
        self.assertEqual(run.returncode, 2)
        self.assertIn("SUMMARY CRIT queues=3", run.stdout)
        self.assertEqual(len(re.findall(r"^(OK|WARN|CRIT) queue=", run.stdout, re.M)), 3)

    def test_database_failure_is_unknown_never_ok(self):
        run = self.check("", status=1)
        self.assertEqual(run.returncode, 3)
        self.assertIn("UNKNOWN", run.stdout)

    def test_unreadable_rows_are_unknown(self):
        for rows in ("ready\\tmedia\\tmany\\t5\\n", "bogus\\tmedia\\t1\\t5\\n", "ready\\tme dia\\t1\\t5\\n"):
            with self.subTest(rows=rows):
                self.assertEqual(self.check(rows).returncode, 3)

    def test_the_session_is_read_only_selects_only_and_never_reads_payloads(self):
        self.check("")
        text = self.calls.read_text()
        self.assertIn("--init-command=SET SESSION TRANSACTION READ ONLY", text)
        self.assertIn(f"--defaults-file={self.defaults}", text)
        sql = text[text.index("SELECT"):]
        for verb in ("INSERT", "UPDATE", "DELETE", "DROP", "ALTER", "CREATE", "TRUNCATE", "GRANT", "LOCK"):
            self.assertNotRegex(sql, rf"\b{verb}\b")
        self.assertNotIn("payload", sql)
        self.assertNotIn("password", text.replace(SECRET, "").lower().replace("defaults-file", ""))

    def test_bad_configuration_is_unknown(self):
        self.defaults.chmod(0o644)
        self.assertEqual(self.check("").returncode, 3)
        self.defaults.chmod(0o600)
        self.conf.write_text(f"VASEY_DB_NAME=x; DROP DATABASE y\nVASEY_BACKUP_MYCNF={self.defaults}\n")
        self.assertEqual(self.check("").returncode, 3)
        self.conf.unlink()
        self.assertEqual(self.check("").returncode, 3)

    def test_bad_arguments_are_unknown(self):
        for args in (["--warn", "900", "--crit", "300"], ["--queue-threshold", "media=oops"], ["--bogus"], ["--kit", "dev"]):
            with self.subTest(args=args):
                self.assertEqual(self.check("", *args).returncode, 3)

    @unittest.skipUnless(IS_ROOT, "the heartbeat file must be root-owned")
    def test_heartbeat_pings_on_ok_and_warn_but_not_on_critical_and_never_prints_the_url(self):
        url = "https://hc.example.invalid/ping/SYNTHETIC-TOKEN-1247"
        beat = self.root / "heartbeat.url"
        beat.write_text(url + "\n")
        beat.chmod(0o600)
        ok = self.check("", "--heartbeat-url-file", str(beat))
        self.assertEqual(ok.returncode, 0)
        self.assertIn(url, self.curl_calls.read_text())
        self.assertNotIn("SYNTHETIC-TOKEN", ok.stdout + ok.stderr)
        self.curl_calls.unlink()
        warn = self.check("ready\\tmedia\\t1\\t400\\n", "--heartbeat-url-file", str(beat))
        self.assertEqual(warn.returncode, 1)
        self.assertTrue(self.curl_calls.exists())
        self.curl_calls.unlink()
        crit = self.check("ready\\tmedia\\t1\\t4000\\n", "--heartbeat-url-file", str(beat))
        self.assertEqual(crit.returncode, 2)
        self.assertFalse(self.curl_calls.exists(), "a critical result must stay silent")

    @unittest.skipUnless(IS_ROOT, "the heartbeat file must be root-owned")
    def test_a_failed_ping_or_an_unsafe_url_file_downgrades_to_warn(self):
        beat = self.root / "heartbeat.url"
        beat.write_text("https://hc.example.invalid/ping/x\n")
        beat.chmod(0o600)
        failed = self.check("", "--heartbeat-url-file", str(beat), extra_env={"STUB_CURL_STATUS": "22"})
        self.assertEqual(failed.returncode, 1)
        self.assertNotIn("hc.example.invalid", failed.stdout)
        beat.chmod(0o644)
        loose = self.check("", "--heartbeat-url-file", str(beat))
        self.assertEqual(loose.returncode, 1)
        beat.chmod(0o600)
        beat.write_text("http://hc.example.invalid/insecure\n")
        self.assertEqual(self.check("", "--heartbeat-url-file", str(beat)).returncode, 1)


class SchedulerLivenessCheck(Sandbox):
    def setUp(self):
        super().setUp()
        self.kit = self.root / "srv"
        self.release = self.kit / "releases" / ("a" * 40)
        self.release.mkdir(parents=True)
        (self.kit / "current").symlink_to(self.release)
        self.stale = self.kit / "releases" / ("b" * 40)
        self.stale.mkdir()
        self.proc = self.root / "proc"
        for pid, target in (("101", self.release), ("102", self.release), ("103", self.release), ("104", self.release), ("105", self.release),
                            ("200", self.stale)):
            (self.proc / pid).mkdir(parents=True)
            (self.proc / pid / "cwd").symlink_to(target)
        self.conf = self.root / "production.conf"
        self.conf.write_text(f"VASEY_ROOT={self.kit}\nVASEY_APP_USER=forge\n")
        # Status lines per program from STUB_<NAME> (names with dashes mapped to underscores), else RUNNING.
        self.stub("supervisorctl", r"""name=${2#*:}; var=STUB_${name//-/_}
line=${!var:-}
if [ -z "$line" ]; then case "$name" in
  *-scheduler) line="$name RUNNING pid 105, uptime 1:02:03" ;;
  *-media) line="$name RUNNING pid 101, uptime 1:02:03" ;;
  *-payments) line="$name RUNNING pid 102, uptime 1:02:03" ;;
  *-contracts) line="$name RUNNING pid 103, uptime 1:02:03" ;;
  *-default) line="$name RUNNING pid 104, uptime 1:02:03" ;;
esac; fi
[ "$line" = none ] || echo "$line"
""")
        self.stub("journalctl", 'printf "%b" "${STUB_JOURNAL:-}"\n')

    def check(self, *args, env=None):
        base = {"VASEY_SUPERVISORCTL": str(self.bin / "supervisorctl"), "VASEY_JOURNALCTL": str(self.bin / "journalctl"),
                "VASEY_PROC_ROOT": str(self.proc),
                "STUB_JOURNAL": "vasey-production-scheduler 2026-10-17 12:00:00 Running ['artisan' vasey:publish-scheduled-site-release]\\n"}
        return self.run_script("scheduler-liveness-check.sh", "--conf", str(self.conf), *args, env={**base, **(env or {})})

    def test_healthy_scheduler_is_ok(self):
        run = self.check()
        self.assertEqual(run.returncode, 0, run.stdout)
        self.assertIn("SUMMARY OK", run.stdout)
        self.assertIn("OK tick: 1 scheduler tick", run.stdout)

    def test_stopped_or_unknown_scheduler_is_critical(self):
        for line in ("vasey-production-scheduler STOPPED Oct 17 11:00 AM", "vasey-production-scheduler FATAL Exited too quickly", "none"):
            with self.subTest(line=line):
                run = self.check(env={"STUB_vasey_production_scheduler": line})
                self.assertEqual(run.returncode, 2, run.stdout)

    def test_a_just_restarted_scheduler_only_warns(self):
        run = self.check(env={"STUB_vasey_production_scheduler": "vasey-production-scheduler RUNNING pid 105, uptime 0:00:12"})
        self.assertEqual(run.returncode, 1)
        self.assertIn("only 12s", run.stdout)

    def test_days_of_uptime_parse(self):
        run = self.check(env={"STUB_vasey_production_scheduler": "vasey-production-scheduler RUNNING pid 105, uptime 3 days, 4:05:06"})
        self.assertEqual(run.returncode, 0)
        self.assertIn(f"for {3 * 86400 + 4 * 3600 + 5 * 60 + 6}s", run.stdout)

    def test_a_scheduler_left_on_an_old_release_is_flagged(self):
        run = self.check(env={"STUB_vasey_production_scheduler": "vasey-production-scheduler RUNNING pid 200, uptime 1:00:00"})
        self.assertEqual(run.returncode, 1)
        self.assertIn("does not run from the served release", run.stdout)

    def test_no_tick_in_the_journal_is_critical(self):
        for journal in ("", "vasey-production-media Processing job\\n", "vasey-production-default Running vasey:publish-scheduled-site-release\\n"):
            with self.subTest(journal=journal):
                run = self.check(env={"STUB_JOURNAL": journal})
                self.assertEqual(run.returncode, 2, run.stdout)
                self.assertIn("no scheduler tick", run.stdout)

    def test_an_unreadable_journal_warns(self):
        self.stub("journalctl", "exit 1\n")
        self.assertEqual(self.check().returncode, 1)

    def test_log_file_source_checks_age_and_content(self):
        log = self.root / "scheduler.log"
        log.write_text("  2026-10-17 12:00:00 Running ['artisan' vasey:publish-scheduled-site-release] ... DONE\n")
        self.assertEqual(self.check("--log-file", str(log)).returncode, 0)
        old = datetime.datetime.now().timestamp() - 600
        os.utime(log, (old, old))
        stale = self.check("--log-file", str(log))
        self.assertEqual(stale.returncode, 2)
        self.assertIn("last written", stale.stdout)
        log.write_text("something else entirely\n")
        self.assertEqual(self.check("--log-file", str(log)).returncode, 1)
        log.unlink()
        self.assertEqual(self.check("--log-file", str(log)).returncode, 2)

    def test_workers_flag_requires_every_worker_running_on_the_served_release(self):
        self.assertEqual(self.check("--workers").returncode, 0)
        down = self.check("--workers", env={"STUB_vasey_production_payments": "vasey-production-payments FATAL spawn error"})
        self.assertEqual(down.returncode, 2)
        self.assertIn("worker payments", down.stdout)
        stale = self.check("--workers", env={"STUB_vasey_production_media": "vasey-production-media RUNNING pid 200, uptime 1:00:00"})
        self.assertEqual(stale.returncode, 1)
        self.assertIn("worker media: RUNNING but not from the served release", stale.stdout)

    def test_staging_kit_uses_its_own_group(self):
        self.conf = self.root / "staging.conf"
        self.conf.write_text(f"VASEY_ROOT={self.kit}\nVASEY_APP_USER=forge\n")
        self.stub("supervisorctl", 'echo "$2 RUNNING pid 105, uptime 1:02:03"\n')
        run = self.check("--kit", "staging", env={"STUB_JOURNAL": "vasey-staging-scheduler Running vasey:publish-scheduled-site-release\\n"})
        self.assertEqual(run.returncode, 0, run.stdout)

    def test_bad_configuration_or_arguments_are_unknown(self):
        self.conf.write_text("VASEY_ROOT=relative\nVASEY_APP_USER=forge\n")
        self.assertEqual(self.check().returncode, 3)
        self.assertEqual(self.check("--max-silence", "soon").returncode, 3)
        self.assertEqual(self.check("--bogus").returncode, 3)


class SignatureAgeCheck(Sandbox):
    def scanner(self, offset_seconds=None, line=None):
        now = int(datetime.datetime.now(datetime.timezone.utc).timestamp())
        if line is None:
            stamp = datetime.datetime.fromtimestamp(now + offset_seconds, datetime.timezone.utc).strftime("%a %b %e %H:%M:%S %Y")
            line = f"ClamAV 1.5.4/28100/{stamp}"
        return self.stub("scanner", f'echo "{line}"\n')

    def check(self, scanner, *args):
        return self.run_script("signature-age-check.sh", "--scanner", str(scanner), *args)

    def test_thresholds(self):
        for hours, code, word in ((2, 0, "OK"), (35, 0, "OK"), (37, 1, "WARN"), (43, 1, "WARN"), (45, 2, "CRIT"), (60, 2, "CRIT")):
            with self.subTest(hours=hours):
                run = self.check(self.scanner(-hours * 3600 - 60))
                self.assertEqual(run.returncode, code, run.stdout)
                self.assertTrue(run.stdout.startswith(word), run.stdout)

    def test_a_future_date_is_critical(self):
        run = self.check(self.scanner(+5 * 3600))
        self.assertEqual(run.returncode, 2)
        self.assertIn("in the future", run.stdout)

    def test_missing_or_garbled_dates(self):
        self.assertEqual(self.check(self.scanner(line="ClamAV 1.5.4")).returncode, 2)
        self.assertEqual(self.check(self.scanner(line="ClamAV 1.5.4/28100/not a date")).returncode, 3)
        self.assertEqual(self.check(self.root / "absent").returncode, 3)

    def test_custom_thresholds_and_validation(self):
        run = self.check(self.scanner(-5 * 3600), "--warn-hours", "4", "--crit-hours", "8")
        self.assertEqual(run.returncode, 1)
        self.assertEqual(self.check(self.scanner(-3600), "--warn-hours", "9", "--crit-hours", "3").returncode, 3)

    def test_the_scanner_is_run_with_utc_like_the_application(self):
        self.stub("scanner", 'echo "ClamAV 1.5.4/28100/$(TZ=UTC date -d @$(( $(date +%s) - 3600 )) "+%a %b %e %H:%M:%S %Y")"; echo "TZ=$TZ" >&2\n')
        run = self.run_script("signature-age-check.sh", "--scanner", str(self.bin / "scanner"))
        self.assertEqual(run.returncode, 0, run.stdout)


class HostBaseline(Sandbox):
    UFW_OK = ("Status: active\nLogging: on (low)\nDefault: deny (incoming), allow (outgoing), disabled (routed)\n"
              "New profiles: skip\n\nTo                         Action      From\n--                         ------      ----\n"
              "22/tcp                     ALLOW IN    Anywhere\n80/tcp                     ALLOW IN    Anywhere\n"
              "443/tcp                    ALLOW IN    Anywhere\n22/tcp (v6)                ALLOW IN    Anywhere (v6)\n"
              "80/tcp (v6)                ALLOW IN    Anywhere (v6)\n443/tcp (v6)               ALLOW IN    Anywhere (v6)\n")
    SS_OK = ("LISTEN 0 4096 127.0.0.1:3306 0.0.0.0:*\nLISTEN 0 511 0.0.0.0:80 0.0.0.0:*\nLISTEN 0 511 0.0.0.0:443 0.0.0.0:*\n"
             "LISTEN 0 128 0.0.0.0:22 0.0.0.0:*\nLISTEN 0 128 [::]:22 [::]:*\nLISTEN 0 511 [::1]:6379 [::]:*\n")
    APT_OK = ('APT::Periodic::Unattended-Upgrade "1";\nUnattended-Upgrade::Allowed-Origins:: "${distro_id}:${distro_codename}-security";\n')
    SSHD_OK = "passwordauthentication no\npermitrootlogin prohibit-password\n"

    def setUp(self):
        super().setUp()
        self.files = {}
        for name, text in (("ufw", self.UFW_OK), ("ss", self.SS_OK), ("apt", self.APT_OK), ("sshd", self.SSHD_OK)):
            self.files[name] = self.root / f"{name}.txt"
            self.files[name].write_text(text)
        self.log = self.root / "mutations.log"
        self.stub("ufw", f'if [ "$1" = status ]; then cat "{self.files["ufw"]}"; else echo "ufw $*" >> "{self.log}"; fi\n')
        self.stub("ss", f'cat "{self.files["ss"]}"\n')
        self.stub("apt-config", f'cat "{self.files["apt"]}"\n')
        self.stub("sshd", f'cat "{self.files["sshd"]}"\n')
        self.stub("dpkg", "exit 0\n")
        self.stub("apt-get", f'echo "apt-get $*" >> "{self.log}"\n')
        self.stub("systemctl", f'echo "systemctl $*" >> "{self.log}"\n')
        self.env = {"VASEY_UFW": str(self.bin / "ufw"), "VASEY_SS": str(self.bin / "ss"), "VASEY_APT_CONFIG": str(self.bin / "apt-config"),
                    "VASEY_SSHD": str(self.bin / "sshd"), "VASEY_REBOOT_FLAG": str(self.root / "reboot-required"),
                    "VASEY_APT_DROPIN": str(self.root / "52vasey"), "VASEY_DPKG": str(self.bin / "dpkg"),
                    "VASEY_APT_GET": str(self.bin / "apt-get"), "VASEY_SYSTEMCTL": str(self.bin / "systemctl")}

    def check(self, *args, **env):
        return self.run_script("host-baseline.sh", "check", *args, env={**self.env, **{k: str(v) for k, v in env.items()}})

    def test_a_conforming_host_is_ok(self):
        run = self.check()
        self.assertEqual(run.returncode, 0, run.stdout)
        self.assertIn("SUMMARY OK", run.stdout)
        self.assertNotIn("WARN", run.stdout)
        self.assertEqual(self.log.exists(), False)

    def test_firewall_findings(self):
        self.files["ufw"].write_text(self.UFW_OK.replace("Status: active", "Status: inactive"))
        self.assertEqual(self.check().returncode, 2)
        self.files["ufw"].write_text(self.UFW_OK.replace("deny (incoming), allow (outgoing)", "allow (incoming), allow (outgoing)"))
        self.assertEqual(self.check().returncode, 2)
        self.files["ufw"].write_text(self.UFW_OK + "8080/tcp                   ALLOW IN    Anywhere\n")
        run = self.check()
        self.assertEqual(run.returncode, 1)
        self.assertIn("also allows 8080", run.stdout)
        self.files["ufw"].write_text(self.UFW_OK.replace("443/tcp                    ALLOW IN    Anywhere\n", "").replace("443/tcp (v6)               ALLOW IN    Anywhere (v6)\n", ""))
        self.assertIn("does not allow 443", self.check().stdout)

    def test_a_custom_ssh_port_is_expected_instead_of_22(self):
        self.files["ufw"].write_text(self.UFW_OK.replace("22/tcp", "2222/tcp"))
        self.files["ss"].write_text(self.SS_OK.replace(":22 ", ":2222 "))
        self.assertEqual(self.check("--ssh-port", "2222").returncode, 0)
        self.assertEqual(self.check().returncode, 1)

    def test_mysql_must_never_listen_beyond_loopback(self):
        self.files["ss"].write_text(self.SS_OK.replace("127.0.0.1:3306", "0.0.0.0:3306"))
        run = self.check()
        self.assertEqual(run.returncode, 2)
        self.assertIn("MySQL (3306) listens on 0.0.0.0", run.stdout)

    def test_unexpected_public_listener_warns(self):
        self.files["ss"].write_text(self.SS_OK + "LISTEN 0 511 0.0.0.0:8080 0.0.0.0:*\n")
        run = self.check()
        self.assertEqual(run.returncode, 1)
        self.assertIn("unexpected non-loopback ports 8080", run.stdout)

    def test_unattended_upgrade_findings(self):
        self.stub("dpkg", "exit 1\n")
        self.assertEqual(self.check().returncode, 1)
        self.stub("dpkg", "exit 0\n")
        self.files["apt"].write_text('APT::Periodic::Unattended-Upgrade "0";\n')
        run = self.check()
        self.assertEqual(run.returncode, 1)
        self.assertIn("is '0', not 1", run.stdout)
        self.assertIn("no -security origin", run.stdout)
        (self.root / "reboot-required").write_text("")
        (self.root / "reboot-required.pkgs").write_text("linux-image-6.8\n")
        self.files["apt"].write_text(self.APT_OK)
        pending = self.check()
        self.assertEqual(pending.returncode, 1)
        self.assertIn("a reboot is pending (linux-image-6.8", pending.stdout)

    def test_ssh_findings(self):
        self.files["sshd"].write_text("passwordauthentication yes\npermitrootlogin prohibit-password\n")
        self.assertEqual(self.check().returncode, 1)
        self.files["sshd"].write_text("passwordauthentication no\npermitrootlogin yes\n")
        self.assertEqual(self.check().returncode, 2)

    def test_apply_actions_are_dry_runs_without_yes(self):
        for args in (["apply-unattended-upgrades"], ["apply-firewall", "--ssh-port", "22"]):
            with self.subTest(args=args):
                run = self.run_script("host-baseline.sh", *args, env=self.env)
                self.assertEqual(run.returncode, 0, run.stderr)
                self.assertIn("dry run; repeat with --yes to apply", run.stdout)
        self.assertFalse(self.log.exists())
        self.assertFalse((self.root / "52vasey").exists())

    def test_apply_firewall_needs_an_explicit_port_and_never_drops_the_current_session(self):
        self.assertEqual(self.run_script("host-baseline.sh", "apply-firewall", "--yes", env=self.env).returncode, 2)
        run = self.run_script("host-baseline.sh", "apply-firewall", "--ssh-port", "22", "--yes",
                              env={**self.env, "SSH_CONNECTION": "203.0.113.5 50000 192.0.2.10 2222"})
        self.assertEqual(run.returncode, 2)
        self.assertIn("connected on port 2222", run.stderr)
        self.assertFalse(self.log.exists())

    @unittest.skipUnless(IS_ROOT, "apply needs root")
    def test_apply_firewall_allows_ssh_before_it_enables_anything(self):
        run = self.run_script("host-baseline.sh", "apply-firewall", "--ssh-port", "22", "--yes", env=self.env)
        self.assertEqual(run.returncode, 0, run.stderr)
        calls = self.log.read_text().splitlines()
        self.assertEqual(calls, ["ufw allow 22/tcp", "ufw allow 80/tcp", "ufw allow 443/tcp", "ufw default deny incoming",
                                 "ufw default allow outgoing", "ufw --force enable"])

    @unittest.skipUnless(IS_ROOT, "apply needs root")
    def test_apply_unattended_upgrades_writes_a_dropin_apt_accepts(self):
        run = self.run_script("host-baseline.sh", "apply-unattended-upgrades", "--yes", env=self.env)
        self.assertEqual(run.returncode, 0, run.stderr)
        dropin = self.root / "52vasey"
        text = dropin.read_text()
        self.assertEqual(oct(dropin.stat().st_mode & 0o777), "0o644")
        self.assertIn('Unattended-Upgrade::Automatic-Reboot "false";', text)
        self.assertIn('"mysql-.*";', text)
        calls = self.log.read_text()
        self.assertIn("apt-get install -y -q unattended-upgrades", calls)
        self.assertIn("systemctl enable --now apt-daily.timer apt-daily-upgrade.timer", calls)
        # The real apt parser must accept the file and see the settings the check looks for.
        if Path("/usr/bin/apt-config").exists():
            dump = subprocess.run(["/usr/bin/apt-config", "dump"], env={"APT_CONFIG": str(dropin), "PATH": "/usr/bin:/bin"},
                                  capture_output=True, text=True)
            self.assertEqual(dump.returncode, 0, dump.stderr)
            self.assertIn('APT::Periodic::Unattended-Upgrade "1";', dump.stdout)
            self.assertRegex(dump.stdout, r'Unattended-Upgrade::Allowed-Origins:: ".*-security";')
            self.assertIn('Unattended-Upgrade::Automatic-Reboot "false";', dump.stdout)

    def test_unknown_action_and_port_are_refused(self):
        self.assertEqual(self.run_script("host-baseline.sh", "burn", env=self.env).returncode, 2)
        self.assertEqual(self.run_script("host-baseline.sh", "check", "--ssh-port", "99999", env=self.env).returncode, 2)


class Hygiene(unittest.TestCase):
    def test_every_script_is_executable_syntactically_valid_and_names_no_host_or_secret(self):
        for script in sorted(MON.glob("*.sh")):
            with self.subTest(script=script.name):
                self.assertTrue(os.access(script, os.X_OK))
                self.assertEqual(subprocess.run(["bash", "-n", str(script)], capture_output=True).returncode, 0)
                text = script.read_text()
                self.assertNotRegex(text, r"https?://(?!\S*example)(?!healthchecks)[a-z0-9.-]+\.[a-z]{2,}/\S*ping")
                self.assertNotRegex(text, r"(sk|rk|pk)_(live|test)_|whsec_|AGE-SECRET-KEY-[A-Z0-9]")


if __name__ == "__main__":
    unittest.main()
