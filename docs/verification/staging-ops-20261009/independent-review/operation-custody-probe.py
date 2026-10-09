"""Independent operation probes; canonical fixture infrastructure is explicit and host authority modeled."""
import argparse
import fcntl
import grp
import os
from pathlib import Path
import pwd
import shlex
import subprocess
import tempfile
import time
import types
import unittest


REPO = Path(__file__).resolve().parents[4]
PARSER = argparse.ArgumentParser()
PARSER.add_argument("--source-sha")
ARGUMENTS, TEST_ARGUMENTS = PARSER.parse_known_args()
SOURCE_SHA = ARGUMENTS.source_sha or subprocess.check_output(
    ["git", "-C", str(REPO), "rev-parse", "HEAD"], text=True
).strip()


def source(path):
    return subprocess.check_output(["git", "-C", str(REPO), "show", f"{SOURCE_SHA}:{path}"], text=True)


class OperationProbe(unittest.TestCase):
    def setUp(self):
        self.snapshot = tempfile.TemporaryDirectory()
        snapshot = Path(self.snapshot.name)
        for path in ("ops/staging/bin/vasey-staging-ctl", "ops/staging/release-step.sh"):
            target = snapshot / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(source(path))
        fixture_source = source("tests/ops/test_staging_operation_lease.py")
        module = types.ModuleType("bounded_canonical_operation_fixture")
        module.__file__ = str(snapshot / "tests/ops/test_staging_operation_lease.py")
        exec(compile(fixture_source, module.__file__, "exec"), module.__dict__)
        module.REPO = snapshot
        self.fixture = module.OperationLeaseTest()
        self.fixture.setUp()
        self.fixture.environment.update({
            "VASEY_APP_USER": pwd.getpwuid(os.getuid()).pw_name,
            "VASEY_APP_GROUP": grp.getgrgid(os.getgid()).gr_name,
            "VASEY_PHP": str(self.fixture.root / "php-fixture"),
            "VASEY_PHP_FPM_SERVICE": "bounded-fpm",
            "VASEY_EXPECTED_APP_ENV": "local", "VASEY_DB_NAME": "synthetic_database",
            "VASEY_STAGING_HOST": "synthetic.example.invalid",
        })
        php = self.fixture.root / "php-fixture"
        if 'if [ "$2" = down ]; then' not in php.read_text():
            down = 'if [ "$2" = down ]; then mkdir -p ' + shlex.quote(str(self.fixture.release / "storage/framework"))
            down += '; touch ' + shlex.quote(str(self.fixture.release / "storage/framework/maintenance.php")) + '; fi\n'
            php.write_text(php.read_text().replace('if [ "$1" = -r ]; then exit 0; fi\n',
                'if [ "$1" = -r ]; then exit 0; fi\n' + down))
        text = self.fixture.control.read_text().replace("set -eu\n", "set -euo pipefail\n", 1)
        # Retain the actual source as_app descriptor closures, modeling runuser identity only.
        text = text.replace('as_app() { "$@" 8>&- 7<&-; }', 'runuser() { shift 3; "$@"; }')
        dispatch = "# The sudo entry point"
        position = text.index(dispatch)
        extra = '''chown() { [ "${@: -1}" != / ] && [[ "${@: -1}" = "$ROOT/"* ]]; }
seal_tool() {
  [ "$1" = stage-env ] || die 'unexpected privileged fixture operation'
  cp -- "$3" "$4"; chmod 0440 "$4"
}
cmd_attach() { echo sealed >> "$TRACE"; }
cmd_configure() { as_app "$VASEY_PHP" "$RELEASES/$1/artisan" config:cache; }
cmd_status() { echo inspected-pending-operation; }
'''
        self.fixture.control.write_text(text[:position] + extra + text[position:])

    def tearDown(self):
        self.fixture.tearDown()
        self.snapshot.cleanup()

    def run_control(self, *arguments):
        return subprocess.run(["bash", str(self.fixture.control), *arguments], env=self.fixture.environment,
                              capture_output=True, text=True, timeout=3)

    def assert_leases_held(self):
        for name in ("ctl.lock", "writer.lock"):
            with (self.fixture.conf / name).open() as descriptor:
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        pid = int(self.fixture.entered.read_text())
        for descriptor in (7, 8):
            self.assertFalse(Path(f"/proc/{pid}/fd/{descriptor}").exists(), "application inherited root lock descriptor")

    def test_killed_activation_parent_blocks_every_mutator_but_allows_inspection(self):
        fixture = self.fixture
        activation = fixture.start("activate", fixture.sha, str(fixture.evidence))
        try:
            fixture.wait_entered(activation)
            self.assert_leases_held()
            child = int(fixture.entered.read_text())
            activation.kill()
            activation.wait(timeout=2)
            self.assertTrue(Path(f"/proc/{child}").exists(), "surviving child boundary not exercised")
            self.assertEqual(fixture.operation.stat().st_mode & 0o777, 0o600)
            actions = (("resume",), ("healthy", fixture.sha), ("snapshot",), ("allocate", "b" * 40),
                       ("attach", fixture.sha), ("detach", fixture.sha), ("switch", fixture.sha),
                       ("prune",), ("configure", fixture.sha, "/synthetic"),
                       ("prepare", fixture.sha, "/synthetic", str(fixture.evidence)),
                       ("activate", fixture.sha, str(fixture.evidence)), ("refresh", fixture.sha, "/synthetic"))
            for arguments in actions:
                with self.subTest(action=arguments[0]):
                    result = self.run_control(*arguments)
                    self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
                    self.assertIn("operation marker present", result.stderr)
            for action in ("status", "quiesce"):
                result = self.run_control(action)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertTrue(fixture.operation.is_file())
            self.assertEqual((fixture.conf / "writer-admission").read_text(), "closed\n")
            self.assertNotIn("resume", fixture.trace.read_text().splitlines())
        finally:
            fixture.cleanup_process(activation)

    def test_refresh_retains_leases_through_configuration_child_and_resume(self):
        fixture = self.fixture
        (fixture.root / "current").symlink_to(fixture.release)
        php = fixture.root / "php-fixture"
        php.write_text(php.read_text().replace('[ "$2" = migrate ] && [ "${3:-}" = --force ]', '[ "$2" = config:cache ]'))
        refresh = fixture.start("refresh", fixture.sha, "/synthetic-candidate")
        contender = None
        try:
            fixture.wait_entered(refresh)
            self.assert_leases_held()
            contender = fixture.start("resume")
            time.sleep(0.1)
            self.assertIsNone(contender.poll())
            self.assertEqual((fixture.conf / "writer-admission").read_text(), "closed\n")
            self.assertNotIn("resume", fixture.trace.read_text().splitlines())
            fixture.finish.touch()
            out, err = refresh.communicate(timeout=5)
            self.assertEqual(refresh.returncode, 0, out + err)
            contender.communicate(timeout=5)
            self.assertFalse(fixture.operation.exists())
            self.assertEqual((fixture.conf / "writer-admission").read_text(), "open\n")
            self.assertLess(fixture.trace.read_text().splitlines().index("config:cache"), fixture.trace.read_text().splitlines().index("resume"))
        finally:
            fixture.cleanup_process(refresh)
            if contender:
                fixture.cleanup_process(contender)

    def test_standalone_configure_cannot_leave_an_unfenced_orphan(self):
        fixture = self.fixture
        text = fixture.control.read_text()
        text = text.replace('cmd_configure() { as_app "$VASEY_PHP" "$RELEASES/$1/artisan" config:cache; }\n', "")
        fixture.control.write_text(text)
        (fixture.root / "current").symlink_to(fixture.release)
        (fixture.release / "storage/framework/maintenance.php").touch()
        (fixture.release / ".env").write_text("synthetic retained profile\n")
        candidate = fixture.evidence / "candidate.env"
        candidate.write_text("synthetic admitted profile\n")
        candidate.chmod(0o600)
        php = fixture.root / "php-fixture"
        php.write_text(php.read_text().replace('[ "$2" = migrate ] && [ "${3:-}" = --force ]', '[ "$2" = config:cache ]'))
        configure = fixture.start("configure", fixture.sha, str(candidate))
        try:
            deadline = time.monotonic() + 5
            while not fixture.entered.exists() and configure.poll() is None and time.monotonic() < deadline:
                time.sleep(0.01)
            if not fixture.entered.exists():
                out, err = configure.communicate(timeout=2)
                self.assertNotEqual(configure.returncode, 0, "unsafe old public mode was silently accepted: " + out + err)
                self.assertEqual((fixture.conf / "writer-admission").read_text(), "closed\n")
                return
            self.assert_leases_held()
            child = int(fixture.entered.read_text())
            configure.kill()
            configure.wait(timeout=2)
            self.assertTrue(Path(f"/proc/{child}").exists())
            resumed = self.run_control("resume")
            self.assertNotEqual(resumed.returncode, 0, "public resume passed a surviving standalone configuration child")
            self.assertTrue(fixture.operation.exists())
            self.assertEqual((fixture.conf / "writer-admission").read_text(), "closed\n")
        finally:
            fixture.cleanup_process(configure)

    def test_prepare_keeps_build_and_sealing_under_one_lease(self):
        fixture = self.fixture
        (fixture.root / "current").symlink_to(fixture.release)
        candidate = fixture.evidence / "candidate.env"
        candidate.write_text("synthetic private candidate\n")
        candidate.chmod(0o600)
        new_sha = "b" * 40
        fixture.helper.write_text('''#!/bin/bash
set -eu
echo $$ > ENTERED
while [ ! -e FINISH ]; do sleep 0.01; done
'''.replace("ENTERED", shlex.quote(str(fixture.entered))).replace("FINISH", shlex.quote(str(fixture.finish))))
        prepare = fixture.start("prepare", new_sha, str(candidate), str(fixture.evidence))
        contender = None
        try:
            fixture.wait_entered(prepare)
            self.assert_leases_held()
            contender = fixture.start("resume")
            time.sleep(0.1)
            self.assertIsNone(contender.poll())
            self.assertEqual((fixture.conf / "writer-admission").read_text(), "closed\n")
            self.assertNotIn("sealed", fixture.trace.read_text().splitlines())
            fixture.finish.touch()
            out, err = prepare.communicate(timeout=5)
            self.assertEqual(prepare.returncode, 0, out + err)
            contender.communicate(timeout=5)
            trace = fixture.trace.read_text().splitlines()
            self.assertLess(trace.index("quiesce"), trace.index("allocate"))
            self.assertLess(trace.index("allocate"), trace.index("sealed"))
            self.assertFalse(fixture.operation.exists())
            self.assertFalse(list(fixture.root.glob(".build-env.*")))
        finally:
            fixture.cleanup_process(prepare)
            if contender:
                fixture.cleanup_process(contender)

    def test_fixed_helper_refuses_writable_links_and_nonregular_files_before_operation(self):
        fixture = self.fixture
        original = fixture.helper.read_bytes()
        for case in ("writable", "symlink", "hardlink", "fifo", "directory"):
            with self.subTest(case=case):
                fixture.helper.unlink(missing_ok=True)
                other = fixture.root / "retained-helper"
                other.unlink(missing_ok=True)
                if case in ("fifo", "directory"):
                    os.mkfifo(fixture.helper) if case == "fifo" else fixture.helper.mkdir()
                else:
                    fixture.helper.write_bytes(original)
                    fixture.helper.chmod(0o666 if case == "writable" else 0o644)
                    if case == "symlink":
                        fixture.helper.rename(other)
                        fixture.helper.symlink_to(other)
                    elif case == "hardlink":
                        other.hardlink_to(fixture.helper)
                result = self.run_control("activate", fixture.sha, str(fixture.evidence))
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(fixture.operation.exists())
                self.assertFalse(fixture.entered.exists())
                if fixture.helper.is_dir():
                    fixture.helper.rmdir()
                else:
                    fixture.helper.unlink()

    def test_actual_public_operations_keep_marker_when_application_child_survives(self):
        fixture = self.fixture
        (fixture.root / "current").symlink_to(fixture.release)
        actual = source("ops/staging/bin/vasey-staging-ctl")
        bodies = actual[actual.index("cmd_quiesce() {"):actual.index("cmd_switch() {")]
        bodies += actual[actual.index("cmd_resume() {"):actual.index("cmd_healthy() {")]
        original_control = fixture.control.read_text()
        original_php = (fixture.root / "php-fixture").read_text()
        cwd_process = subprocess.Popen(["sleep", "30"], cwd=fixture.release)
        modeled_host = f'''
PROGRAMS=(bounded-worker)
systemctl() {{ return 0; }}
supervisorctl() {{ [ "$1" = pid ] && echo {cwd_process.pid}; }}
program_state() {{ echo RUNNING; }}
sleep() {{ :; }}
probe() {{
  if [ -f "$RELEASES/{fixture.sha}/storage/framework/maintenance.php" ]; then echo 503; else echo 200; fi
}}
stat() {{
  local path=${{@: -1}}
  if [ "$2" = '%d:%i' ]; then echo modeled-attached-inode;
  elif [ "$2" = '%u %a %h' ]; then printf '0 %s %s\\n' "$(command stat -c %a "$path")" "$(command stat -c %h "$path")";
  elif [ "$2" = '%u %a' ]; then printf '0 %s\\n' "$(command stat -c %a "$path")";
  else command stat "$@"; fi
}}
'''
        try:
            for action, command in (("quiesce", "down"), ("resume", "up")):
                with self.subTest(action=action):
                    fixture.entered.unlink(missing_ok=True)
                    fixture.operation.unlink(missing_ok=True)
                    fixture.finish.unlink(missing_ok=True)
                    (fixture.release / "storage/framework/maintenance.php").touch()
                    (fixture.conf / "writer-admission").write_text("closed\n")
                    php = original_php.replace(
                        '[ "$2" = migrate ] && [ "${3:-}" = --force ]', f'[ "$2" = {command} ]'
                    )
                    (fixture.root / "php-fixture").write_text(php)
                    fixture.environment["PRIVATE"] = str(fixture.root / "modeled-private")
                    fixture.control.write_text(original_control.replace(
                        "# The sudo entry point", bodies + modeled_host + "\n# The sudo entry point"
                    ))
                    operation = fixture.start(action)
                    try:
                        fixture.wait_entered(operation)
                        self.assert_leases_held()
                        self.assertTrue(fixture.operation.is_file(), "public operation never acquired recovery custody")
                        child = int(fixture.entered.read_text())
                        operation.kill()
                        operation.wait(timeout=2)
                        self.assertTrue(Path(f"/proc/{child}").exists())
                        resumed = self.run_control("resume")
                        self.assertNotEqual(resumed.returncode, 0, resumed.stdout + resumed.stderr)
                        self.assertIn("operation marker present", resumed.stderr)
                        self.assertEqual((fixture.conf / "writer-admission").read_text(), "closed\n")
                        if action == "resume":
                            recovered = self.run_control("quiesce")
                            self.assertEqual(recovered.returncode, 0, recovered.stdout + recovered.stderr)
                            self.assertTrue(fixture.operation.exists(), "recovery stop cleared the interrupted marker")
                    finally:
                        fixture.cleanup_process(operation)
        finally:
            cwd_process.terminate()
            cwd_process.wait(timeout=2)

    def test_actual_requiesce_uses_stopped_proof_and_refuses_partial_host_state(self):
        fixture = self.fixture
        current = fixture.root / "current"
        current.symlink_to(fixture.release)
        actual = source("ops/staging/bin/vasey-staging-ctl")
        bodies = actual[actual.index("workers_stopped() {"):actual.index("persist_private_bind() {")]
        bodies += actual[actual.index("cmd_quiesce() {"):actual.index("cmd_switch() {")]
        modeled_host = '''
PROGRAMS=(bounded-worker)
probe() { echo probe >> "$HOST_TRACE"; echo "$HTTP_CODE"; }
systemctl() {
  if [ "$1" = is-active ]; then [ "$(cat "$WEB_ACTIVE")" = 1 ]; return; fi
  [ "$1" = stop ] || return 93
  echo fpm-stop >> "$HOST_TRACE"
  echo 0 > "$WEB_ACTIVE"
  [ "$ORPHAN_AFTER_STOP" = 0 ] || echo 1 > "$WEB_ORPHAN"
}
supervisorctl() {
  if [ "$1" = status ]; then printf 'bounded-worker %s\\n' "$(cat "$WORKER_STATE")"; return; fi
  [ "$1" = stop ] || return 94
  echo worker-stop >> "$HOST_TRACE"
  [ "$WORKER_STOP_OK" = 1 ] || return 95
  echo STOPPED > "$WORKER_STATE"
}
pgrep() {
  case "$*" in
    *php-fpm*) [ "$(cat "$WEB_ORPHAN")" = 1 ] ;;
    *) [ "$WORKER_ORPHAN" = 1 ] ;;
  esac
}
'''
        fixture.control.write_text(fixture.control.read_text().replace(
            "# The sudo entry point", bodies + modeled_host + "\n# The sudo entry point"
        ))
        web_active = fixture.root / "web-active"
        web_orphan = fixture.root / "web-orphan"
        worker_state = fixture.root / "worker-state"
        host_trace = fixture.root / "host-trace"
        cases = (
            ("cold-stopped", "0", "0", "STOPPED", "0", "502", "1", "0", True),
            ("warm-maintenance", "1", "0", "RUNNING", "0", "503", "1", "0", True),
            ("cold-unmanaged-web", "0", "1", "STOPPED", "0", "502", "1", "0", False),
            ("warm-wrong-http", "1", "0", "RUNNING", "0", "200", "1", "0", False),
            ("unmanaged-worker", "0", "0", "STOPPED", "1", "502", "1", "0", False),
            ("worker-stop-fails", "1", "0", "RUNNING", "0", "503", "0", "0", False),
            ("web-survives-stop", "1", "0", "STOPPED", "0", "503", "1", "1", False),
        )
        for name, active, orphan, worker, worker_orphan, http, stop_ok, orphan_after, admitted in cases:
            with self.subTest(case=name):
                fixture.operation.unlink(missing_ok=True)
                host_trace.unlink(missing_ok=True)
                web_active.write_text(active + "\n")
                web_orphan.write_text(orphan + "\n")
                worker_state.write_text(worker + "\n")
                fixture.environment.update({"WEB_ACTIVE": str(web_active), "WEB_ORPHAN": str(web_orphan),
                    "WORKER_STATE": str(worker_state), "HOST_TRACE": str(host_trace), "HTTP_CODE": http,
                    "WORKER_ORPHAN": worker_orphan, "WORKER_STOP_OK": stop_ok, "ORPHAN_AFTER_STOP": orphan_after})
                result = self.run_control("quiesce")
                self.assertEqual(result.returncode == 0, admitted, result.stdout + result.stderr)
                self.assertEqual((fixture.conf / "writer-admission").read_text(), "closed\n")
                self.assertEqual(fixture.operation.exists(), not admitted)
                self.assertTrue((fixture.release / "storage/framework/maintenance.php").is_file())
                trace = host_trace.read_text().splitlines() if host_trace.exists() else []
                if name == "cold-stopped":
                    self.assertEqual(trace, ["fpm-stop"])
                if name == "warm-maintenance":
                    self.assertEqual(trace, ["probe", "worker-stop", "fpm-stop"])

    def test_resume_readonly_refusal_never_creates_operation_custody(self):
        fixture = self.fixture
        actual = source("ops/staging/bin/vasey-staging-ctl")
        body = actual[actual.index("cmd_resume() {"):actual.index("cmd_healthy() {")]
        fixture.control.write_text(fixture.control.read_text().replace(
            "# The sudo entry point", body + "\n# The sudo entry point"
        ))
        current = fixture.root / "current"
        maintenance = fixture.release / "storage/framework/maintenance.php"
        for case in ("open", "not-maintenance", "dangling", "missing-current", "active-writer"):
            with self.subTest(case=case):
                fixture.operation.unlink(missing_ok=True)
                current.unlink(missing_ok=True)
                maintenance.unlink(missing_ok=True)
                fixture.trace.unlink(missing_ok=True)
                gate = "open\n" if case == "open" else "closed\n"
                (fixture.conf / "writer-admission").write_text(gate)
                if case != "missing-current":
                    current.symlink_to(fixture.root / "absent" if case == "dangling" else fixture.release)
                if case in ("open", "active-writer"):
                    maintenance.touch()
                with (fixture.conf / "writer.lock").open() as writer:
                    if case == "active-writer":
                        fcntl.flock(writer, fcntl.LOCK_SH | fcntl.LOCK_NB)
                    result = self.run_control("resume")
                self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertFalse(fixture.operation.exists(), "readonly refusal published false interrupted state")
                self.assertFalse(fixture.entered.exists())
                self.assertFalse(fixture.trace.exists(), "an application command started before admission")
                self.assertEqual((fixture.conf / "writer-admission").read_text(), gate)


class DatabaseCredentialProbe(unittest.TestCase):
    def test_new_identity_never_overwrites_orphan_credentials_or_uses_bad_generation(self):
        script = source("ops/staging/provision.sh")
        start = script.index("app_credential_custody()") if "app_credential_custody()" in script else script.index("if ! user_exists vasey_app;")
        body = script[start:script.index("# Backup-only account")]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            credential = root / "db-app.env"
            trace = root / "database-effects"
            shell = '''die() { echo "$*" >&2; exit 1; }
user_exists() { return 1; }
mysql_q() { echo mutation >> "$TRACE"; }
new_secret() { echo invalid-short; }
info() { :; }
'''
            for case in ("orphan-regular", "orphan-dangling", "bad-generation"):
                with self.subTest(case=case):
                    credential.unlink(missing_ok=True)
                    trace.unlink(missing_ok=True)
                    if case == "orphan-regular":
                        credential.write_text("synthetic retained private file\n")
                    elif case == "orphan-dangling":
                        credential.symlink_to(root / "absent")
                    run = subprocess.run(["bash", "-eu", "-c", shell + body],
                                         env={"PATH": "/usr/bin:/bin", "SECRETS": str(root),
                                              "DB_NAME": "synthetic_database", "TRACE": str(trace)},
                                         capture_output=True, text=True, timeout=3)
                    self.assertNotEqual(run.returncode, 0)
                    self.assertFalse(trace.exists(), "account mutated before private custody was admissible")
                    if case == "orphan-regular":
                        self.assertEqual(credential.read_text(), "synthetic retained private file\n")
                    elif case == "orphan-dangling":
                        self.assertTrue(credential.is_symlink())
                        self.assertFalse((root / "absent").exists())
                    else:
                        self.assertFalse(credential.exists())

    def test_existing_identity_refuses_ambiguous_private_credentials_without_rotation(self):
        script = source("ops/staging/provision.sh")
        start = script.index("app_credential_custody()") if "app_credential_custody()" in script else script.index("if ! user_exists vasey_app;")
        body = script[start:script.index("# Backup-only account")]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            credential = root / "db-app.env"
            trace = root / "database-effects"
            shell = '''die() { echo "$*" >&2; exit 1; }
user_exists() { return 0; }
mysql_q() { echo mutation >> "$TRACE"; }
info() { :; }
stat() {
  if [ "$2" = '%u %a %h' ]; then
    printf '0 %s %s\\n' "$(command stat -c %a "$3")" "$(command stat -c %h "$3")";
  else command stat "$@"; fi
}
'''
            valid = "DB_USERNAME=vasey_app\nDB_PASSWORD=" + "A" * 40 + "\n"
            contents = {
                "valid": valid.encode(), "missing": None, "empty": b"", "no-final-newline": valid.rstrip().encode(),
                "crlf": valid.replace("\n", "\r\n").encode(), "extra-line": (valid + "EXTRA=1\n").encode(),
                "wrong-user": valid.replace("vasey_app", "other_user").encode(),
                "embedded-nul": valid.replace("vasey_app", "vasey_\x00app").encode(),
                "shell-substitution": valid.replace("A" * 40, "$(touch injected)").encode(),
                "oversized": (valid + "X" * 256).encode(), "short-password": valid.replace("A" * 40, "A").encode(),
            }
            for case, content in contents.items():
                with self.subTest(case=case):
                    credential.unlink(missing_ok=True)
                    trace.unlink(missing_ok=True)
                    if content is not None:
                        credential.write_bytes(content)
                        credential.chmod(0o600)
                    run = subprocess.run(["bash", "-eu", "-c", shell + body],
                                         env={"PATH": "/usr/bin:/bin", "SECRETS": str(root),
                                              "DB_NAME": "synthetic_database", "TRACE": str(trace)},
                                         capture_output=True, text=True, timeout=3)
                    self.assertEqual(run.returncode == 0, case == "valid", run.stdout + run.stderr)
                    self.assertEqual(trace.exists(), case == "valid")
                    self.assertNotIn("A" * 40, run.stdout + run.stderr)
                    self.assertFalse((REPO / "injected").exists())
                    if content is not None:
                        self.assertEqual(credential.read_bytes(), content)


if __name__ == "__main__":
    print(f"Exact assessed source: {SOURCE_SHA}", flush=True)
    unittest.main(argv=[__file__] + TEST_ARGUMENTS, verbosity=2)
