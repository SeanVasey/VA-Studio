"""The opt-in ClamAV profile (ops/clamav): configuration values, systemd drop-ins, installer rendering and the verifier.

No daemon is started here. The verifier runs against scripted stand-ins for clamdscan and clamscan, so what it concludes
from each kind of daemon answer is tested without a host; the run against a real clamd is recorded in
docs/verification/production-ops-kit-20261017/."""
import datetime
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
CLAMAV = REPO / "ops/clamav"
CONF = (CLAMAV / "clamd.conf").read_text()
GUIDE = (REPO / "docs/media-processing.md").read_text()


def settings(text):
    found = {}
    for line in text.splitlines():
        if line and not line.startswith("#"):
            key, _, value = line.partition(" ")
            found.setdefault(key, []).append(value.strip())
    return found


class Configuration(unittest.TestCase):
    def test_every_option_is_set_once(self):
        self.assertTrue(all(len(values) == 1 for values in settings(CONF).values()), settings(CONF))

    def test_first_line_is_the_marker_provision_looks_for(self):
        self.assertEqual(CONF.splitlines()[0], "# VASEY.AUDIO clamd profile")

    def test_documented_limits_and_socket(self):
        s = {key: values[0] for key, values in settings(CONF).items()}
        self.assertEqual(s["MaxFileSize"], "1280M")
        self.assertEqual(s["MaxScanSize"], "1280M")
        self.assertEqual(s["MaxFileSize"], s["MaxScanSize"])
        self.assertEqual(s["AlertExceedsMax"], "yes")
        self.assertEqual(s["MaxScanTime"], "240000")
        self.assertEqual(s["LocalSocket"], "/run/clamav/clamd.ctl")
        self.assertEqual((s["LocalSocketGroup"], s["LocalSocketMode"]), ("clamav", "660"))
        self.assertEqual(s["EnableVersionCommand"], "yes")
        self.assertEqual(s["ConcurrentDatabaseReload"], "yes")
        self.assertEqual(s["TemporaryDirectory"], "/var/tmp/clamd")
        self.assertEqual(s["User"], "clamav")

    def test_values_match_the_guide_the_application_documents(self):
        for needle in ("`1280M` and `1280M`", "`AlertExceedsMax` | `yes`", "`MaxScanTime` | `240000`",
                       "`LocalSocket` | `/run/clamav/clamd.ctl`", "`clamav`, the daemon's own group, and `660`",
                       "`EnableVersionCommand` | `yes`", "`TemporaryDirectory` | a disk-backed directory"):
            self.assertIn(needle, GUIDE)

    def test_max_threads_is_at_least_twice_the_media_workers_of_the_kit(self):
        workers = (REPO / "ops/staging/workers/vasey-staging-workers.conf").read_text()
        media_workers = len(re.findall(r"--queue=media ", workers))
        self.assertEqual(media_workers, 1)
        self.assertGreaterEqual(int(settings(CONF)["MaxThreads"][0]), 2 * media_workers)

    def test_every_scanning_engine_stays_on_and_nothing_fails_open(self):
        s = {key: values[0] for key, values in settings(CONF).items()}
        for engine in ("ScanArchive", "ScanPE", "ScanELF", "ScanMail", "ScanHTML", "ScanOLE2", "ScanPDF", "ScanSWF",
                       "ScanXMLDOCS", "ScanHWP3", "ScanOneNote", "ScanImage"):
            self.assertEqual(s[engine], "yes", engine)
        self.assertNotIn("LocalSocketMode", {k for k, v in s.items() if v == "666"})
        self.assertNotIn("LogFile", s)

    @unittest.skipUnless(shutil.which("clamconf"), "clamconf is not installed")
    def test_the_installed_clamav_parses_the_file(self):
        with tempfile.TemporaryDirectory() as directory:
            shutil.copy(CLAMAV / "clamd.conf", Path(directory) / "clamd.conf")
            result = subprocess.run(["clamconf", "-c", directory, "-n"], capture_output=True, text=True)
            self.assertNotIn("ERROR", result.stdout + result.stderr)
            self.assertIn('MaxScanSize = "1342177280"', result.stdout)  # 1280 MiB
            self.assertIn('AlertExceedsMax = "yes"', result.stdout)
            (Path(directory) / "clamd.conf").write_text(CONF + "NotAnOption yes\n")
            rejected = subprocess.run(["clamconf", "-c", directory, "-n"], capture_output=True, text=True)
            self.assertIn("Unknown option", rejected.stdout + rejected.stderr)


class Dropins(unittest.TestCase):
    def test_service_runs_in_utc(self):
        text = (CLAMAV / "systemd/clamav-daemon.service.d/timezone.conf").read_text()
        self.assertEqual([l for l in text.splitlines() if l and not l.startswith("#")], ["[Service]", "Environment=TZ=UTC"])

    def test_socket_is_not_world_accessible(self):
        text = (CLAMAV / "systemd/clamav-daemon.socket.d/vasey-socket.conf").read_text()
        body = [l for l in text.splitlines() if l and not l.startswith("#")]
        self.assertEqual(body, ["[Socket]", "SocketUser=clamav", "SocketGroup=clamav", "SocketMode=0660"])


def run_bash(script, **env):
    return subprocess.run(["bash", "-c", script], env={"PATH": "/usr/bin:/bin", "LC_ALL": "C", **env},
                          capture_output=True, text=True, timeout=60)


class InstallerRendering(unittest.TestCase):
    def source(self, body):
        return run_bash(f'source "{CLAMAV}/install-clamd.sh"\n{body}')

    def test_max_threads_follows_the_media_workers(self):
        for workers, expected in ((1, 4), (2, 4), (3, 6), (8, 16)):
            with self.subTest(workers=workers):
                run = self.source(f'render_conf "{CLAMAV}/clamd.conf" {workers} | grep "^MaxThreads"')
                self.assertEqual(run.stdout.strip(), f"MaxThreads {expected}", run.stderr)

    def test_rendering_changes_nothing_else(self):
        run = self.source(f'render_conf "{CLAMAV}/clamd.conf" 5')
        original = [l for l in CONF.splitlines() if not l.startswith("MaxThreads ")]
        rendered = [l for l in run.stdout.splitlines() if not l.startswith("MaxThreads ")]
        self.assertEqual(rendered, original)
        self.assertEqual(sum(l.startswith("MaxThreads ") for l in run.stdout.splitlines()), 1)

    def test_apparmor_block_covers_the_temp_directory_and_the_private_tree_only(self):
        run = self.source("apparmor_block /srv/vasey-production")
        rules = [l for l in run.stdout.splitlines() if l and not l.startswith("#")]
        self.assertEqual(rules, ["/var/tmp/clamd/ r,", "/var/tmp/clamd/** krw,", "/srv/vasey-production/private/** r,",
                                 "/srv/vasey-production/releases/*/storage/app/private/** r,"])

    @unittest.skipUnless(shutil.which("apparmor_parser") and Path("/etc/apparmor.d/usr.sbin.clamd").exists(),
                         "apparmor_parser and the packaged clamd profile are not installed")
    def test_apparmor_block_is_accepted_by_the_parser_and_a_bad_rule_is_not(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            shutil.copytree("/etc/apparmor.d/tunables", root / "tunables")
            shutil.copytree("/etc/apparmor.d/abstractions", root / "abstractions")
            (root / "local").mkdir()
            shutil.copy("/etc/apparmor.d/usr.sbin.clamd", root / "usr.sbin.clamd")
            block = self.source("apparmor_block /srv/vasey-production").stdout
            (root / "local/usr.sbin.clamd").write_text(block)
            ok = subprocess.run(["apparmor_parser", "-Q", "-K", "-I", str(root), "-r", str(root / "usr.sbin.clamd")],
                                capture_output=True, text=True)
            self.assertEqual(ok.returncode, 0, ok.stderr)
            (root / "local/usr.sbin.clamd").write_text(block + "badrule ///,\n")
            bad = subprocess.run(["apparmor_parser", "-Q", "-K", "-I", str(root), "-r", str(root / "usr.sbin.clamd")],
                                 capture_output=True, text=True)
            self.assertNotEqual(bad.returncode, 0)

    def test_installer_refuses_bad_arguments_before_changing_anything(self):
        for args in (["--worker-user", "root"], ["--worker-user", "nobody", "--media-workers", "0"],
                     ["--worker-user", "nobody", "--root", "/srv/../etc"], ["--bogus"]):
            with self.subTest(args=args):
                run = subprocess.run(["bash", str(CLAMAV / "install-clamd.sh"), *args, "--dry-run"],
                                     capture_output=True, text=True)
                self.assertNotEqual(run.returncode, 0)

    @unittest.skipUnless(os.geteuid() == 0, "the installer insists on root even for a dry run")
    def test_dry_run_prints_every_change_and_makes_none(self):
        before = subprocess.run("sha256sum /etc/clamav/clamd.conf 2>/dev/null; ls /etc/systemd/system/clamav-daemon* 2>&1; id -nG nobody",
                                shell=True, capture_output=True, text=True).stdout
        run = subprocess.run(["bash", str(CLAMAV / "install-clamd.sh"), "--worker-user", "nobody", "--root", "/srv/vasey-production",
                              "--media-workers", "3", "--dry-run"], capture_output=True, text=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        for expected in ("clamav-daemon clamdscan clamav-freshclam", "MaxThreads 6", "usermod -aG clamav nobody",
                         "SocketMode", "dry run complete: nothing was changed"):
            if expected == "SocketMode":
                expected = "vasey-socket.conf"
            self.assertIn(expected, run.stdout)
        self.assertNotIn("--no-install-recommends clamav-daemon clamav-freshclam", run.stdout)  # clamdscan is a separate package
        after = subprocess.run("sha256sum /etc/clamav/clamd.conf 2>/dev/null; ls /etc/systemd/system/clamav-daemon* 2>&1; id -nG nobody",
                               shell=True, capture_output=True, text=True).stdout
        self.assertEqual(before, after)


STUB_CLAMDSCAN = r"""#!/bin/bash
mode=${STUB_MODE:-good}
stamp() { date -u -d "@$(( ${STUB_NOW} + $1 ))" '+%a %b %e %H:%M:%S %Y'; }
for arg in "$@"; do
  if [ "$arg" = --version ]; then
    case "$mode" in
      noversion) echo "ClamAV 1.5.4"; exit 0 ;;
      down) echo "ERROR: Could not connect to clamd" >&2; echo "ClamAV 1.5.4"; exit 0 ;;
      stale) echo "ClamAV 1.5.4/28100/$(stamp -259200)"; exit 0 ;;
      future) echo "ClamAV 1.5.4/28100/$(stamp 18000)"; exit 0 ;;
      tokyo) echo "ClamAV 1.5.4/28100/$(stamp $((-21600 + 32400)))"; exit 0 ;;
      *) echo "ClamAV 1.5.4/28100/$(stamp -21600)"; exit 0 ;;
    esac
  fi
done
file=${@: -1}
[ "$mode" = down ] && { echo "ERROR: Could not connect to clamd: Permission denied"; exit 2; }
case "$(basename "$file")" in
  clean.txt) echo "$file: OK"; exit 0 ;;
  eicar.com) [ "$mode" = blind ] && { echo "$file: OK"; exit 0; }; echo "$file: Eicar-Test-Signature FOUND"; exit 1 ;;
  big-eicar.zip) [ "$mode" = packaged ] || [ "$mode" = noarchive ] && { echo "$file: OK"; exit 0; }; echo "$file: Eicar-Test-Signature FOUND"; exit 1 ;;
  big.bin) [ "$mode" = packaged ] && { echo "$file: OK"; exit 0; }; echo "$file: Heuristics.Limits.Exceeded.MaxFileSize FOUND"; exit 1 ;;
esac
exit 2
"""
STUB_CLAMSCAN = r"""#!/bin/bash
stamp() { date -u -d "@$(( ${STUB_NOW} + $1 ))" '+%a %b %e %H:%M:%S %Y'; }
echo "ClamAV 1.5.4/28100/$(stamp -21600)"
"""


class Verifier(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="va-clamav-")
        self.addCleanup(self.tmp.cleanup)
        self.bin = Path(self.tmp.name)
        self.now = int(datetime.datetime.now(datetime.timezone.utc).timestamp())  # both stubs read the same instant
        for name, body in (("clamdscan", STUB_CLAMDSCAN), ("clamscan", STUB_CLAMSCAN)):
            (self.bin / name).write_text(body)
            (self.bin / name).chmod(0o755)

    def verify(self, mode, *extra):
        return subprocess.run(["bash", str(CLAMAV / "verify-clamd.sh"), "--clamdscan", str(self.bin / "clamdscan"),
                               "--clamscan", str(self.bin / "clamscan"), *extra],
                              env={"PATH": "/usr/bin:/bin", "STUB_MODE": mode, "STUB_NOW": str(self.now)}, capture_output=True, text=True, timeout=120)

    def test_a_correctly_configured_daemon_passes_every_automated_step(self):
        run = self.verify("good")
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(run.stdout.count("PASS"), 6, run.stdout)
        self.assertNotIn("FAIL", run.stdout)
        self.assertIn("Steps 5-8 of the guide remain", run.stdout)

    def test_packaged_limits_fail_open_and_are_caught(self):
        run = self.verify("packaged")
        self.assertEqual(run.returncode, 1)
        self.assertIn("FAIL 3", run.stdout)
        self.assertIn("FAIL 4", run.stdout)
        self.assertIn("PASS 2", run.stdout)

    def test_archive_scanning_off_is_caught_by_the_zip_step(self):
        run = self.verify("noarchive")
        self.assertEqual(run.returncode, 1)
        self.assertIn("FAIL 3", run.stdout)
        self.assertIn("PASS 4", run.stdout)

    def test_a_daemon_that_misses_eicar_fails_step_two(self):
        run = self.verify("blind")
        self.assertEqual(run.returncode, 1)
        self.assertIn("FAIL 2 the EICAR test file was not FOUND", run.stdout)

    def test_signature_age_and_future_dates_fail_step_one(self):
        stale = self.verify("stale")
        self.assertEqual(stale.returncode, 1)
        self.assertRegex(stale.stdout, r"FAIL 1 signatures are \d+ hours old")
        future = self.verify("future")
        self.assertIn("minutes in the future", future.stdout)
        self.assertEqual(future.returncode, 1)

    def test_a_daemon_in_another_time_zone_is_caught_by_the_comparison(self):
        run = self.verify("tokyo")
        self.assertEqual(run.returncode, 1)
        self.assertIn("different signature times", run.stdout)

    def test_a_missing_version_date_or_unreachable_daemon_fails_closed(self):
        for mode in ("noversion", "down"):
            with self.subTest(mode=mode):
                run = self.verify(mode)
                self.assertEqual(run.returncode, 1)
                self.assertIn("FAIL 1", run.stdout)
                self.assertNotIn("FAIL 1 signatures are", run.stdout)

    def test_the_scratch_directory_is_removed(self):
        before = set(Path("/var/tmp").glob("vasey-clamd-verify.*"))
        self.verify("good")
        self.assertEqual(set(Path("/var/tmp").glob("vasey-clamd-verify.*")), before)

    def test_bad_options_are_usage_errors(self):
        for args in (["--max-age", "soon"], ["--bogus"]):
            self.assertEqual(self.verify("good", *args).returncode, 2)


class Docs(unittest.TestCase):
    def test_readme_names_the_files_it_ships(self):
        readme = (CLAMAV / "README.md").read_text()
        for path in ("clamd.conf", "systemd/clamav-daemon.service.d/timezone.conf",
                     "systemd/clamav-daemon.socket.d/vasey-socket.conf", "install-clamd.sh", "verify-clamd.sh"):
            self.assertIn(f"`{path}`", readme)
            self.assertTrue((CLAMAV / path).exists(), path)

    def test_nothing_in_the_staging_kit_installs_or_enables_it(self):
        for name in ("provision.sh", "forge-deploy.sh", "release-step.sh", "backup.sh"):
            self.assertNotIn("install-clamd", (REPO / "ops/staging" / name).read_text())


if __name__ == "__main__":
    unittest.main()
