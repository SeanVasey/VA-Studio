"""Actual deploy ordering with owned Git/files/processes; host service/mount/build tools are fixtures."""
from pathlib import Path
import getpass
import hashlib
import json
import shlex
import shutil
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]


class BuildAdmissionTest(unittest.TestCase):
    def test_composer_hooks_receive_frozen_environment_before_bootstrap(self):
        """Run the actual helper; Composer/npm/runtime effects are bounded fixtures."""
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP is required for the Composer hook fixture")
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mirror = root / "mirror"
            mirror.mkdir()
            profile = "APP_ENV=staging\nAPP_DEBUG=false\nAPP_KEY=synthetic-test-key\n"
            for name, content in ((".gitignore", "/vendor/\n/public/build/\n.env\n"),
                                  ("composer.lock", "{}\n"), ("package-lock.json", "{}\n")):
                (mirror / name).write_text(content)
            runtime = mirror / "ops/staging/validate-runtime.php"
            runtime.parent.mkdir(parents=True)
            runtime.write_text("<?php exit(is_file($argv[1]) ? 0 : 1);\n")
            subprocess.run(["git", "init", "-q", str(mirror)], check=True)
            subprocess.run(["git", "-C", str(mirror), "add", "."], check=True)
            subprocess.run(["git", "-C", str(mirror), "-c", "user.name=Test",
                            "-c", "user.email=test@example.invalid", "commit", "-qm", "synthetic hooks"], check=True)
            sha = subprocess.check_output(["git", "-C", str(mirror), "rev-parse", "HEAD"], text=True).strip()
            release = root / "releases" / sha
            release.parent.mkdir()
            evidence = root / "evidence"
            evidence.mkdir()
            candidate = root / "candidate.env"
            candidate.write_text(profile)
            candidate.chmod(0o600)
            commands = root / "bin"
            commands.mkdir()
            composer = commands / "composer"
            composer.write_text("""<?php
$profile = file_exists('.env') ? file_get_contents('.env') : '';
if ($profile !== EXPECTED_PROFILE || (fileperms('.env') & 0777) !== 0600) {
    fwrite(STDERR, "Composer hooks booted without the frozen private staging profile\\n");
    exit(23);
}
file_put_contents('hook-profile.sha256', hash('sha256', $profile));
mkdir('vendor/composer', 0755, true);
file_put_contents('vendor/autoload.php', '<?php');
file_put_contents('vendor/composer/installed.json', '{}');
""".replace("EXPECTED_PROFILE", json.dumps(profile)))
            composer.chmod(0o755)
            npm = commands / "npm"
            npm.write_text('#!/bin/bash\nset -eu\nmkdir -p public/build\nprintf "{}" > public/build/manifest.json\n')
            npm.chmod(0o755)
            config = root / "staging.conf"
            config.write_text("\n".join(name + "=" + shlex.quote(value) for name, value in {
                "VASEY_ROOT": str(root), "VASEY_MIRROR": str(mirror), "VASEY_PHP": php,
                "VASEY_APP_USER": getpass.getuser(), "VASEY_EXPECTED_APP_ENV": "staging",
                "VASEY_STAGING_HOST": "fixture.invalid", "VASEY_DB_NAME": "fixture_database"
            }.items()) + "\n")
            helper = root / "release-step.sh"
            helper.write_text((REPO / "ops/staging/release-step.sh").read_text().replace(
                "/etc/vasey-staging/staging.conf", str(config)))
            run = subprocess.run(["bash", str(helper), "build", sha, str(evidence), str(candidate)],
                                 env={"PATH": str(commands) + ":/usr/bin:/bin", "LC_ALL": "C"},
                                 capture_output=True, text=True)
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertEqual((release / "hook-profile.sha256").read_text(), hashlib.sha256(profile.encode()).hexdigest())
            self.assertEqual(candidate.read_text(), profile, "build changed the captured profile")
            self.assertEqual((release / ".env").read_text(), profile)

    def test_running_application_cannot_inject_ignored_vendor_before_sealing(self):
        source = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()
        body = source[source.index('cmd_prepare()'):source.index('cmd_activate()')]
        helper = (REPO / "ops/staging/release-step.sh").read_text()
        build = helper[helper.index('    [ "$#" = 4 ]'):helper.index('    ;;\n  activate)')]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mirror = root / "mirror"
            mirror.mkdir()
            for name, content in ((".gitignore", "/vendor/\n/public/build/\n.env\n"),
                                  ("composer.lock", "{}\n"), ("package-lock.json", "{}\n")):
                (mirror / name).write_text(content)
            subprocess.run(["git", "init", "-q", str(mirror)], check=True)
            subprocess.run(["git", "-C", str(mirror), "add", "."], check=True)
            subprocess.run(["git", "-C", str(mirror), "-c", "user.name=Test",
                            "-c", "user.email=test@example.invalid", "commit", "-qm", "synthetic build"], check=True)
            sha = subprocess.check_output(["git", "-C", str(mirror), "rev-parse", "HEAD"], text=True).strip()
            release = root / "releases" / sha
            served = root / "releases" / ("b" * 40)
            served.mkdir(parents=True)
            (root / "current").symlink_to(served)
            (root / "private").mkdir()
            (root / "evidence").mkdir()
            env = root / "candidate.env"
            env.write_text("APP_DEBUG=false\n")
            (served / ".env").write_text(env.read_text())
            live = root / "live"
            live.touch()
            injected = root / "injected"
            actor = '''import pathlib,sys,time
release,live,receipt=map(pathlib.Path,sys.argv[1:])
while True:
    target=release/'vendor/autoload.php'
    if target.exists() and live.exists():
        target.write_text('injected ignored application code')
        receipt.touch()
        break
    time.sleep(0.005)
'''
            child = subprocess.Popen(["python3", "-c", actor, str(release), str(live), str(injected)])
            try:
                shell = '''step() { :; }
die() { echo "$*" >&2; exit 1; }
runtime_gate() { :; }
valid_sha() { return 0; }
protected_dir() { :; }
release_step_file() { :; }
operation_evidence() { :; }
operation_begin() { :; }
operation_finish() { :; }
seal_tool() { cp -- "$3" "$4"; }
chown() { :; }
composer() { :; }
env() { :; }
ctl_fixture() {
  echo "$1" >> "$TRACE"
  case "$1" in
    quiesce) rm -f "$LIVE"; kill "$ACTOR_PID" 2>/dev/null || true ;;
    allocate) mkdir -p "$REL" ;;
    attach) mkdir -p "$REL/storage/app/private" ;;
  esac
}
CTL=(ctl_fixture)
cmd_quiesce() { ctl_fixture quiesce; }
cmd_allocate() { ctl_fixture allocate; }
cmd_attach() { ctl_fixture attach; }
php_fixture() {
  mkdir -p "$REL/vendor/composer"
  printf 'approved ignored artifact' > "$REL/vendor/autoload.php"
  printf '{}' > "$REL/vendor/composer/installed.json"
  sleep 0.2
}
npm() { mkdir -p "$REL/public/build"; printf '{}' > "$REL/public/build/manifest.json"; }
stat() { if [ "$2" = %d:%i ]; then echo 1:1; else command stat "$@"; fi; }
'''
                shell += 'unprivileged_build() {\n' + build + '\n}\nas_app() { unprivileged_build build "$SHA" "$EVIDENCE" "${@: -1}"; }\n'
                environment = {"PATH": "/usr/bin:/bin", "ROOT": str(root), "REL": str(release),
                               "RELEASES": str(root / "releases"),
                               "CURRENT": str(root / "current"), "RUNTIME_ENV": str(env), "SHA": sha,
                               "VASEY_MIRROR": str(mirror), "STAMP": "synthetic", "EVIDENCE": str(root / ("evidence/synthetic-" + sha[:12])),
                               "PHP": "php_fixture", "COMPOSER_BIN": "synthetic-composer", "LIVE": str(live),
                               "VASEY_ROOT": str(root), "VASEY_EXPECTED_APP_ENV": "local", "VASEY_STAGING_HOST": "fixture.invalid",
                               "VASEY_DB_NAME": "fixture_database", "VASEY_APP_USER": "fixture-app", "VASEY_APP_GROUP": "fixture-group",
                               "RELEASE_STEP": "fixture-helper",
                               "ACTOR_PID": str(child.pid), "TRACE": str(root / "trace")}
                Path(environment["EVIDENCE"]).mkdir()
                run = subprocess.run(["bash", "-eu", "-c", shell + body + '\ncmd_prepare "$SHA" "$RUNTIME_ENV" "$EVIDENCE"'], env=environment, capture_output=True, text=True)
                self.assertEqual(run.returncode, 0, run.stderr)
                self.assertFalse(injected.exists(), "live application injected ignored vendor bytes that Git accepted")
                self.assertEqual((release / "vendor/autoload.php").read_text(), "approved ignored artifact")
                self.assertTrue((Path(environment["EVIDENCE"]) / "build.sha256").is_file())
                trace = (root / "trace").read_text().splitlines()
                self.assertLess(trace.index("quiesce"), trace.index("allocate"))
            finally:
                if child.poll() is None:
                    child.terminate()
                child.wait(timeout=2)

    def test_provision_refuses_app_owned_or_writable_ancestors_before_host_changes(self):
        source = (REPO / "ops/staging/provision.sh").read_text()
        guard = ""
        if "root_ancestry()" in source:
            guard = source[source.index("root_ancestry()"):source.index('\n[[ "$HOST"')]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            parent = root / "parent"
            parent.mkdir()
            shell = '''die() { echo "$*" >&2; exit 1; }
stat() {
  if [ "$2" = %u ]; then
    if [ "$3" = "$BAD" ]; then echo 1000; else echo 0; fi
  elif [ "$2" = %a ] && [ "$3" = /tmp ]; then echo 755;
  else command stat "$@"; fi
}
'''
            invocation = source[source.index('[[ "$ROOT"'):source.index('[[ "$DB_NAME"')]
            def probe(bad="", root_value=None):
                return subprocess.run(["bash", "-eu", "-c", shell + guard + invocation],
                                      env={"PATH": "/usr/bin:/bin", "ROOT": root_value or str(parent / "new-root"), "BAD": bad},
                                      capture_output=True, text=True)
            good = probe()
            self.assertEqual(good.returncode, 0, good.stderr)
            self.assertNotEqual(probe(str(parent)).returncode, 0, "app-owned existing parent admitted")
            parent.chmod(0o777)
            self.assertNotEqual(probe().returncode, 0, "writable existing parent admitted")
            parent.chmod(0o755)
            for value in (str(parent) + "//new-root", str(parent) + "/./new-root", str(parent / "new-root") + "/",
                          str(parent) + "/new-root/./child"):
                with self.subTest(root=value):
                    self.assertNotEqual(probe(root_value=value).returncode, 0, "noncanonical new root admitted")


if __name__ == "__main__":
    unittest.main(verbosity=2)
