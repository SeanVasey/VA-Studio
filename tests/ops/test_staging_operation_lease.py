"""Real root-control flock and child lifecycle; host ownership/services/Artisan are fixtures."""
import fcntl
import os
from pathlib import Path
import pwd
import signal
import shutil
import subprocess
import tempfile
import time
import unittest

REPO = Path(__file__).resolve().parents[2]


class OperationLeaseTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.conf = self.root / "conf"
        self.conf.mkdir()
        for name, mode in (("ctl.lock", 0o600), ("writer.lock", 0o644)):
            (self.conf / name).touch(mode=mode)
            (self.conf / name).chmod(mode)
        (self.conf / "writer-admission").write_text("closed\n")
        (self.conf / "writer-admission").chmod(0o644)
        self.sha = "a" * 40
        self.release = self.root / "releases" / self.sha
        (self.release / "storage/framework").mkdir(parents=True)
        self.evidence = self.root / "evidence/owned-test"
        self.evidence.mkdir(parents=True)
        self.entered = self.root / "entered"
        self.finish = self.root / "finish"
        self.trace = self.root / "trace"
        self.operation = self.conf / "operation-in-progress"
        config = self.conf / "staging.conf"
        php = self.root / "php-fixture"
        php.write_text('''#!/bin/bash
if [ "$1" = -r ]; then exit 0; fi
printf '%s\\n' "$2" >> TRACE_PATH
if [ "$2" = install ]; then
  mkdir -p RELEASE_PATH/vendor/composer
  printf 'approved artifact' > RELEASE_PATH/vendor/autoload.php
  printf '{}' > RELEASE_PATH/vendor/composer/installed.json
fi
if { [ "$2" = migrate ] && [ "${3:-}" = --force ]; } || [ "$2" = install ]; then
  echo $$ > ENTERED_PATH
  while [ ! -e FINISH_PATH ]; do sleep 0.01; done
  [ ! -e FAIL_PATH ] || exit 23
fi
'''.replace("TRACE_PATH", repr(str(self.trace))).replace("ENTERED_PATH", repr(str(self.entered)))
                       .replace("FINISH_PATH", repr(str(self.finish))).replace("FAIL_PATH", repr(str(self.root / "fail")))
                       .replace("RELEASE_PATH", repr(str(self.release))))
        php.chmod(0o755)
        self.binary = self.root / "bin"
        self.binary.mkdir()
        for name, text in (("composer", '#!/bin/bash\nexit 0\n'), ("npm", '#!/bin/bash\nmkdir -p ' + repr(str(self.release / 'public/build')) + '\nprintf "{}" > ' + repr(str(self.release / 'public/build/manifest.json')) + '\n')):
            (self.binary / name).write_text(text)
            (self.binary / name).chmod(0o755)
        config.write_text(f"VASEY_ROOT={self.root}\nVASEY_PHP={php}\nVASEY_MIRROR={self.root}/mirror\nVASEY_APP_USER={pwd.getpwuid(os.getuid()).pw_name}\nVASEY_EXPECTED_APP_ENV=local\nVASEY_STAGING_HOST=fixture.invalid\nVASEY_DB_NAME=fixture_database\nPATH={self.binary}:/usr/bin:/bin\n")
        self.helper = self.root / "release-step.sh"
        self.helper.write_text((REPO / "ops/staging/release-step.sh").read_text().replace("/etc/vasey-staging/staging.conf", str(config)))
        self.helper.chmod(0o644)
        source = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()
        definitions = source[source.index("valid_sha() {"):source.index("# The sudo entry point")]
        dispatch = source[source.index("# The sudo entry point"):]
        self.control = self.root / "control.sh"
        fixture = '''die() { echo "$*" >&2; exit 1; }
protected_dir() { [ -d "$1" ] && [ ! -L "$1" ]; }
sealed_release() { [ -d "$RELEASES/$1" ]; }
workers_stopped() { return 0; }
web_stopped() { return 0; }
as_app() { "$@" 8>&- 7<&-; }
stat() {
  local path=${@: -1}
  if [ "$2" = '%u %a %h' ]; then printf '0 %s %s\\n' "$(command stat -c %a "$path")" "$(command stat -c %h "$path")";
  elif [ "$2" = '%u %a' ]; then printf '0 %s\\n' "$(command stat -c %a "$path")";
  else command stat "$@"; fi
}
cmd_quiesce() { writer_gate_write closed; writer_barrier; echo quiesce >> "$TRACE"; }
cmd_switch() { echo switch >> "$TRACE"; ln -s "$RELEASES/$1" "$CURRENT"; }
cmd_resume() { echo resume >> "$TRACE"; writer_gate_write open; }
seal_tool() { cp -- "$3" "$4"; }
chown() { :; }
cmd_allocate() { mkdir -p "$RELEASES/$1"; echo allocate >> "$TRACE"; }
cmd_attach() { echo attach >> "$TRACE"; }
'''
        self.control.write_text("#!/bin/bash\nset -eu\numask 077\n" + definitions + fixture + dispatch)
        self.control.write_text(self.control.read_text().replace("/etc/vasey-staging", str(self.conf))
                                .replace("/usr/local/libexec/vasey-staging", str(self.root)))
        backup = self.root / "backup"
        backup.write_text('#!/bin/bash\necho snapshot >> "$TRACE"\n')
        backup.chmod(0o755)
        self.environment = {"PATH": "/usr/bin:/bin", "ROOT": str(self.root), "RELEASES": str(self.root / "releases"),
                            "CURRENT": str(self.root / "current"), "RELEASE_STEP": str(self.helper),
                            "OPERATION_MARKER": str(self.operation), "BACKUP_BIN": str(backup), "TRACE": str(self.trace)}
        self.environment.update(VASEY_APP_USER=pwd.getpwuid(os.getuid()).pw_name, VASEY_APP_GROUP="fixture-group")

    def tearDown(self):
        self.finish.touch()
        self.temporary.cleanup()

    def start(self, *arguments):
        return subprocess.Popen(["bash", str(self.control), *arguments], env=self.environment,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, start_new_session=True)

    def wait_entered(self, process):
        deadline = time.monotonic() + 5
        while not self.entered.exists() and process.poll() is None and time.monotonic() < deadline:
            time.sleep(0.01)
        if not self.entered.exists():
            out, err = process.communicate(timeout=2)
            self.fail("migration child did not enter: " + out + err)

    def cleanup_process(self, process):
        self.finish.touch()
        try: os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError: pass
        process.communicate(timeout=5)

    def test_migration_holds_control_and_writer_locks_until_switch_and_resume(self):
        activation = self.start("activate", self.sha, str(self.evidence))
        resume = None
        try:
            self.wait_entered(activation)
            for name in ("ctl.lock", "writer.lock"):
                with (self.conf / name).open() as held:
                    with self.assertRaises(BlockingIOError): fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            child = int(self.entered.read_text())
            for fd in (7, 8): self.assertFalse(Path(f"/proc/{child}/fd/{fd}").exists(), "app child inherited privileged lease")
            resume = self.start("resume")
            time.sleep(0.1)
            self.assertIsNone(resume.poll(), "independent resume passed the active root control lease")
            self.assertEqual(self.trace.read_text().splitlines()[:2], ["quiesce", "snapshot"])
            self.assertNotIn("resume", self.trace.read_text().splitlines())
            self.assertEqual((self.conf / "writer-admission").read_text(), "closed\n")
            self.finish.touch()
            out, err = activation.communicate(timeout=5)
            self.assertEqual(activation.returncode, 0, out + err)
            resume.communicate(timeout=5)
            self.assertFalse(self.operation.exists())
            self.assertLess(self.trace.read_text().splitlines().index("migrate"), self.trace.read_text().splitlines().index("switch"))
        finally:
            self.cleanup_process(activation)
            if resume is not None: self.cleanup_process(resume)

    def test_killed_root_parent_blocks_resume_while_migration_child_survives(self):
        activation = self.start("activate", self.sha, str(self.evidence))
        try:
            self.wait_entered(activation)
            child = int(self.entered.read_text())
            activation.kill()
            activation.wait(timeout=2)
            self.assertTrue(Path(f"/proc/{child}").exists(), "orphan child boundary was not exercised")
            resumed = subprocess.run(["bash", str(self.control), "resume"], env=self.environment,
                                     capture_output=True, text=True, timeout=3)
            self.assertNotEqual(resumed.returncode, 0, resumed.stdout + resumed.stderr)
            self.assertTrue(self.operation.is_file())
            self.assertEqual((self.conf / "writer-admission").read_text(), "closed\n")
            self.assertNotIn("resume", self.trace.read_text().splitlines())
        finally: self.cleanup_process(activation)

    def test_failed_migration_keeps_marker_closed_and_refuses_mutating_retries(self):
        (self.root / "fail").touch()
        activation = self.start("activate", self.sha, str(self.evidence))
        try:
            self.wait_entered(activation)
            self.finish.touch()
            out, err = activation.communicate(timeout=5)
            self.assertNotEqual(activation.returncode, 0, out + err)
            self.assertTrue(self.operation.is_file())
            self.assertFalse((self.root / "current").exists())
            for arguments in (("resume",), ("switch", self.sha), ("activate", self.sha, str(self.evidence)), ("prune",)):
                run = subprocess.run(["bash", str(self.control), *arguments], env=self.environment,
                                     capture_output=True, text=True, timeout=3)
                self.assertNotEqual(run.returncode, 0, run.stdout + run.stderr)
            self.assertEqual((self.conf / "writer-admission").read_text(), "closed\n")
        finally: self.cleanup_process(activation)

    def test_prepare_retains_control_lease_until_writable_build_child_is_reaped(self):
        shutil.rmtree(self.release)
        mirror = self.root / "mirror"
        mirror.mkdir()
        for name, content in ((".gitignore", "/vendor/\n/public/build/\n.env\n"), ("composer.lock", "{}\n"), ("package-lock.json", "{}\n")):
            (mirror / name).write_text(content)
        subprocess.run(["git", "init", "-q", str(mirror)], check=True)
        subprocess.run(["git", "-C", str(mirror), "add", "."], check=True)
        subprocess.run(["git", "-C", str(mirror), "-c", "user.name=Test", "-c", "user.email=test@example.invalid", "commit", "-qm", "synthetic build"], check=True)
        sha = subprocess.check_output(["git", "-C", str(mirror), "rev-parse", "HEAD"], text=True).strip()
        self.helper.write_text(self.helper.read_text().replace(self.sha, sha))
        php = self.root / "php-fixture"
        php.write_text(php.read_text().replace(self.sha, sha))
        npm = self.binary / "npm"
        npm.write_text(npm.read_text().replace(self.sha, sha))
        candidate = self.root / "evidence/candidate.env"
        candidate.write_text("synthetic candidate\n")
        operation = self.start("prepare", sha, str(candidate), str(self.evidence))
        resume = None
        try:
            self.wait_entered(operation)
            resume = self.start("resume")
            time.sleep(0.1)
            self.assertIsNone(resume.poll(), "resume crossed a live app-writable build")
            self.assertNotIn("resume", self.trace.read_text())
            self.assertEqual((self.conf / "writer-admission").read_text(), "closed\n")
            self.finish.touch()
            out, err = operation.communicate(timeout=5)
            self.assertEqual(operation.returncode, 0, out + err)
            resume.communicate(timeout=5)
            self.assertFalse(self.operation.exists())
            self.assertLess(self.trace.read_text().splitlines().index("install"), self.trace.read_text().splitlines().index("attach"))
            self.assertTrue((self.evidence / "build.sha256").exists())
        finally:
            self.cleanup_process(operation)
            if resume is not None: self.cleanup_process(resume)

    def test_refresh_keeps_one_root_lease_through_snapshot_configuration_and_resume(self):
        child = self.root / "configuration-child"
        child.write_text('#!/bin/bash\necho $$ > ' + repr(str(self.entered)) + '\nwhile [ ! -e ' + repr(str(self.finish)) + ' ]; do sleep 0.01; done\n')
        fixture = '\ncmd_configure() { as_app /bin/bash ' + repr(str(child)) + '; }\n'
        self.control.write_text(self.control.read_text().replace('# The sudo entry point', fixture + '\n# The sudo entry point'))
        operation = self.start("refresh", self.sha, str(self.root / "candidate.env"))
        resume = None
        try:
            self.wait_entered(operation)
            resume = self.start("resume")
            time.sleep(0.1)
            self.assertIsNone(resume.poll(), "resume crossed configuration refresh")
            self.assertEqual(self.trace.read_text().splitlines(), ["quiesce", "snapshot"])
            self.assertEqual((self.conf / "writer-admission").read_text(), "closed\n")
            self.finish.touch()
            out, err = operation.communicate(timeout=5)
            self.assertEqual(operation.returncode, 0, out + err)
            resume.communicate(timeout=5)
            self.assertFalse(self.operation.exists())
        finally:
            self.cleanup_process(operation)
            if resume is not None: self.cleanup_process(resume)


if __name__ == "__main__": unittest.main(verbosity=2)
