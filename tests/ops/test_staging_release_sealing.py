"""Owned filesystem probes; root ownership is modeled, actual inode/mode/link I/O is retained."""
import importlib.util
import os
import pwd
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch, Mock

REPO = Path(__file__).resolve().parents[2]
SEALER = REPO / "ops/staging/seal-release.py"
CTL = REPO / "ops/staging/bin/vasey-staging-ctl"


class ReleaseSealingTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        self.release = self.root / ("a" * 40)
        for directory in ("app", "vendor", "public/build", "bootstrap/cache", "storage/app/private",
                          "storage/framework", "storage/logs"):
            (self.release / directory).mkdir(parents=True, exist_ok=True)
        (self.release / "app/source.php").write_text("synthetic approved code")
        (self.release / ".env").write_text("synthetic private configuration")
        (self.release / ".env").chmod(0o600)

    def seal(self):
        if SEALER.exists():
            spec = importlib.util.spec_from_file_location("staging_sealer", SEALER)
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            # This sandbox has no root capability. Only ownership authority is substituted.
            with patch.object(module, "ROOT_UID", os.getuid()), patch.object(module, "ROOT_GID", os.getgid()):
                module.seal_release(self.release, os.getuid(), os.getgid())
            return
        source = CTL.read_text()
        body = source[source.index("valid_sha()"):source.index("# HTTP status")]
        shell = """set -eu
die() { echo "$*" >&2; exit 1; }
chown() { :; }
stat() {
  if [ "$2" = %u ]; then echo 0;
  elif [ "$2" = %U ]; then id -un;
  elif [ "$2" = %a ] && [ "${@: -1}" = /tmp ]; then echo 755;
  else command stat "$@"; fi
}
"""
        run = subprocess.run(["bash", "-c", shell + body + '\nseal_release "' + "a" * 40 + '"'],
                             env={"PATH": "/usr/bin:/bin", "RELEASES": str(self.root),
                                  "VASEY_APP_USER": pwd.getpwuid(os.getuid()).pw_name},
                             capture_output=True, text=True)
        if run.returncode:
            raise RuntimeError(run.stderr)

    def test_source_vendor_build_and_env_are_read_only_and_runtime_paths_stay_writable(self):
        for path in ("vendor/autoload.php", "public/build/asset.js", "bootstrap/cache/config.php",
                     "storage/app/private/retained", "storage/framework/compiled.php"):
            (self.release / path).write_text("synthetic")
        self.seal()
        for path in ("app/source.php", "vendor/autoload.php", "public/build/asset.js", ".env"):
            self.assertEqual((self.release / path).stat().st_mode & 0o222, 0, path)
        for path in ("bootstrap/cache/config.php", "storage/app/private/retained", "storage/framework/compiled.php"):
            self.assertTrue((self.release / path).stat().st_mode & 0o200, path)
        self.assertEqual((self.release / ".env").stat().st_mode & 0o777, 0o440)

    def test_old_open_writer_cannot_change_the_published_inode(self):
        path = self.release / "app/source.php"
        with path.open("r+b") as held:
            self.seal()
            held.seek(0)
            held.write(b"stray old descriptor")
            held.flush()
            self.assertEqual(path.read_text(), "synthetic approved code")
            self.assertNotEqual(os.fstat(held.fileno()).st_ino, path.stat().st_ino)

    def test_hard_links_refuse_without_mutating_the_external_inode(self):
        external = self.root / "external"
        external.write_text("external authority")
        mode = external.stat().st_mode
        os.link(external, self.release / "app/linked")
        with self.assertRaises((RuntimeError, ValueError, OSError)):
            self.seal()
        self.assertEqual(external.read_text(), "external authority")
        self.assertEqual(external.stat().st_mode, mode)

    def test_external_and_runtime_code_symlinks_refuse(self):
        for target in (self.root / "outside", self.release / "storage/framework/writable.php"):
            target.write_text("untrusted executable target")
            link = self.release / "app/link.php"
            link.symlink_to(target)
            with self.assertRaises((RuntimeError, ValueError, OSError)):
                self.seal()
            link.unlink()

    def test_internal_code_symlinks_and_executable_bits_are_preserved(self):
        executable = self.release / "vendor/tool"
        executable.write_text("synthetic executable")
        executable.chmod(0o755)
        link = self.release / "app/alias"
        link.symlink_to("../vendor/tool")
        private = self.release / "storage/app/private"
        before = private.stat().st_ino
        self.seal()
        self.assertEqual(link.readlink(), Path("../vendor/tool"))
        self.assertEqual(executable.stat().st_mode & 0o777, 0o555)
        self.assertEqual(private.stat().st_ino, before)

    def test_environment_capture_refuses_links_escapes_and_writable_ancestry(self):
        spec = importlib.util.spec_from_file_location("staging_environment_capture", SEALER)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        evidence = self.root / "evidence"
        evidence.mkdir(mode=0o750)
        source = evidence / "candidate"
        source.write_text("synthetic exact environment")
        source.chmod(0o600)
        output = self.root / "protected-output"
        output.write_text("")
        output.chmod(0o600)
        with patch.object(module, "ROOT_UID", os.getuid()), patch.object(module, "ROOT_GID", os.getgid()):
            module.stage_environment(evidence, source, output, os.getuid(), os.getgid())
            self.assertEqual(output.read_text(), "synthetic exact environment")
            self.assertEqual(output.stat().st_mode & 0o777, 0o440)
            outside = self.root / "outside-env"
            outside.write_text("must not be copied")
            outside.chmod(0o600)
            output.chmod(0o600)
            source.unlink()
            source.symlink_to(outside)
            with self.assertRaises((RuntimeError, ValueError, OSError)):
                module.stage_environment(evidence, source, output, os.getuid(), os.getgid())
            self.assertEqual(output.read_text(), "synthetic exact environment")
            with self.assertRaises((RuntimeError, ValueError, OSError)):
                module.stage_environment(evidence, outside, output, os.getuid(), os.getgid())
            source.unlink()
            os.link(outside, source)
            with self.assertRaises((RuntimeError, ValueError, OSError)):
                module.stage_environment(evidence, source, output, os.getuid(), os.getgid())
            source.unlink()
            source.write_text("candidate")
            source.chmod(0o600)
            evidence.chmod(0o770)
            with self.assertRaises((RuntimeError, ValueError, OSError)):
                module.stage_environment(evidence, source, output, os.getuid(), os.getgid())

    def test_post_seal_revision_check_detects_source_changed_during_build(self):
        subprocess.run(["git", "init", "-q", str(self.release)], check=True)
        subprocess.run(["git", "-C", str(self.release), "add", "app/source.php"], check=True)
        subprocess.run(["git", "-C", str(self.release), "-c", "user.name=Synthetic Test",
                        "-c", "user.email=synthetic@example.invalid", "commit", "-qm", "synthetic approved source"],
                       check=True)
        (self.release / "app/source.php").write_text("changed during build window")
        self.seal()
        run = subprocess.run(["git", "-c", "safe.directory=" + str(self.release),
                              "--git-dir=" + str(self.release / ".git"), "--work-tree=" + str(self.release),
                              "diff", "--quiet", "--no-ext-diff", "--no-textconv", "HEAD", "--"],
                             env={"PATH": "/usr/bin:/bin", "GIT_NO_REPLACE_OBJECTS": "1",
                                  "GIT_CONFIG_GLOBAL": "/dev/null"}, capture_output=True, text=True)
        self.assertEqual(run.returncode, 1, run.stderr)

    def test_untrusted_environment_fifo_refuses_without_waiting_for_a_writer(self):
        evidence = self.root / "evidence"
        evidence.mkdir(mode=0o750)
        source = evidence / "candidate"
        os.mkfifo(source, 0o600)
        output = self.root / "protected-output"
        output.write_text("")
        output.chmod(0o600)
        driver = """import importlib.util, os, sys
spec = importlib.util.spec_from_file_location('fifo_sealer', sys.argv[1])
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
module.ROOT_UID = os.getuid(); module.ROOT_GID = os.getgid()
try:
    module.stage_environment(sys.argv[2], sys.argv[3], sys.argv[4], os.getuid(), os.getgid())
except (ValueError, OSError):
    sys.exit(0)
sys.exit(1)
"""
        try:
            run = subprocess.run(["python3", "-c", driver, str(SEALER), str(evidence), str(source), str(output)],
                                 capture_output=True, text=True, timeout=1)
        except subprocess.TimeoutExpired:
            self.fail("untrusted FIFO blocked before regular-file admission")
        self.assertEqual(run.returncode, 0, run.stderr)

    def test_privileged_cli_refuses_root_as_the_application_account(self):
        spec = importlib.util.spec_from_file_location("root_account_sealer", SEALER)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        for argv in ([str(SEALER), "verify", str(self.release), "root", "root"],
                     [str(SEALER), "stage-env", str(self.root), str(self.root / "candidate"),
                      str(self.root / "target"), "root", "root"]):
            with patch.object(module.os, "geteuid", return_value=0), patch.object(module.sys, "argv", argv), \
                    patch.object(module.pwd, "getpwnam", return_value=Mock(pw_uid=0)), \
                    patch.object(module.grp, "getgrnam", return_value=Mock(gr_gid=0)), \
                    patch.object(module, "run") as run, patch.object(module, "stage_environment") as stage:
                with self.assertRaises(ValueError):
                    module.main()
                run.assert_not_called()
                stage.assert_not_called()


if __name__ == "__main__":
    unittest.main(verbosity=2)
