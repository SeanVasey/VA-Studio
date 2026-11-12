"""The isolated build: a dedicated build account builds the candidate with the site up, and root seals it before the
running application can touch it (ops/staging/bin/vasey-staging-ctl prepare_isolated, release-step.sh build-isolated,
seal-release.py seal-built / release-runtime).

The integration tests create two real throwaway system accounts (an application and a build account), so every
permission below is the kernel's own: the application account really tries to write into the candidate while it is
built and sealed, the build account really tries to read the environment, and the real sealer runs as root. Host
services (quiesce, PHP-FPM, supervisor, the bind mount, the snapshot) are fixtures, as in test_staging_operation_lease.py.
They need root and useradd, like the CI step that runs this directory (which allows no skip)."""
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shlex
import shutil
import stat
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[2]
CTL = REPO / "ops/staging/bin/vasey-staging-ctl"
HELPER = REPO / "ops/staging/release-step.sh"
SEALER = REPO / "ops/staging/seal-release.py"
DEPLOY = REPO / "ops/staging/forge-deploy.sh"
IS_ROOT = os.geteuid() == 0
CAN_MAKE_ACCOUNTS = IS_ROOT and shutil.which("useradd") is not None and shutil.which("runuser") is not None


def load_sealer():
    spec = importlib.util.spec_from_file_location("isolated_sealer", SEALER)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def sh(*arguments, **kwargs):
    return subprocess.run(list(arguments), check=True, capture_output=True, text=True, **kwargs)


COMPOSER = r'''<?php
// Composer stand-in. Probes what the account running it can read, writes the build output, and (as the build account only)
// leaves a survivor that keeps rewriting vendor/autoload.php once the build step has exited.
$uid = posix_getuid();
$probe = [];
foreach (json_decode(SECRETS_JSON, true) as $path) {
    if (@file_get_contents($path) !== false) { $probe[] = $path; }
}
foreach (glob(ROOT_PATH . '/.build-env.*') ?: [] as $path) {
    if (@file_get_contents($path) !== false) { $probe[] = $path; }
}
if (file_exists('.env')) { $probe[] = '.env'; }
file_put_contents('secret-probe.json', json_encode($probe));
@mkdir('vendor/composer', 0755, true);
file_put_contents('vendor/autoload.php', '<?php // approved');
file_put_contents('vendor/composer/installed.json', '{}');
file_put_contents('bootstrap/cache/packages.php', '<?php return [];');
file_put_contents(TRACE_PATH, "build $uid\n", FILE_APPEND);
if ($uid === BUILDER_UID && file_exists(PLANT_FLAG)) {
    // A malicious dependency leaves a set-id file of the build account in a shared directory (review P1 on PR #354).
    file_put_contents(PLANT_DIR . '/.helper', 'helper');
    chmod(PLANT_DIR . '/.helper', 06755);
}
if ($uid === BUILDER_UID) {
    $home = getenv('HOME');
    $target = getcwd() . '/vendor/autoload.php';
    $loop = "while [ -d '$home' ]; do sleep 0.02; done; sleep 0.4; while :; do printf survivor > '$target' 2>/dev/null; sleep 0.02; done";
    exec('setsid sh -c ' . escapeshellarg($loop) . ' >/dev/null 2>&1 </dev/null &');
}
'''

ARTISAN = r'''<?php
// Artisan stand-in for the release-step activate mode, run by the application account.
$command = $argv[1] ?? '';
file_put_contents(TRACE_PATH, "artisan $command\n", FILE_APPEND);
if ($command === 'down' && file_put_contents(__DIR__ . '/storage/framework/maintenance.php', '<?php') === false) { exit(31); }
if ($command === 'config:cache' && file_put_contents(__DIR__ . '/bootstrap/cache/config.php', '<?php return [];') === false) { exit(32); }
if ($command === 'vasey:doctor') { echo json_encode(['checks' => []]); }
'''

VALIDATOR = r'''<?php
exit(is_file($argv[1]) && str_contains((string) @file_get_contents($argv[1]), 'APP_KEY=candidate-secret') ? 0 : 1);
'''

ACTOR = r'''import os, pathlib, sys, time
release, build, stop, receipt = map(pathlib.Path, sys.argv[1:])
targets = [release / "vendor/autoload.php", release / "injected.php", release / "bootstrap/cache/services.php",
           release / "storage/framework/planted.php", release / "public/build/manifest.json", build / "planted"]
while not stop.exists():
    for target in targets:
        try:
            with open(target, "w") as handle:
                handle.write("written by the live application")
            with open(receipt, "a") as log:
                log.write(str(target) + "\n")
        except OSError:
            pass
    time.sleep(0.003)
'''


@unittest.skipUnless(CAN_MAKE_ACCOUNTS, "needs root, useradd and runuser (the CI step runs as root)")
class IsolatedBuildIntegration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        tag = secrets.token_hex(3)
        cls.app, cls.builder = f"vtb{tag}a", f"vtb{tag}b"
        for name in (cls.app, cls.builder):
            sh("useradd", "--system", "--user-group", "--no-create-home", "--home-dir", "/nonexistent",
               "--shell", "/usr/sbin/nologin", name)
            cls.addClassCleanup(cls.remove_account, name)
        cls.app_uid, cls.app_gid = int(sh("id", "-u", cls.app).stdout), int(sh("id", "-g", cls.app).stdout)
        cls.build_uid, cls.build_gid = int(sh("id", "-u", cls.builder).stdout), int(sh("id", "-g", cls.builder).stdout)

    @classmethod
    def remove_account(cls, name):
        subprocess.run(["pkill", "-KILL", "-u", name], capture_output=True)
        time.sleep(0.1)
        subprocess.run(["userdel", name], capture_output=True)
        subprocess.run(["groupdel", name], capture_output=True)

    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.addCleanup(lambda: [subprocess.run(["pkill", "-KILL", "-u", n], capture_output=True) for n in (self.app, self.builder)])
        self.root = Path(self.scratch.name)
        self.root.chmod(0o755)
        self.conf = self.root / "conf"
        self.conf.mkdir(mode=0o755)
        for name, mode in (("ctl.lock", 0o600), ("writer.lock", 0o644)):
            (self.conf / name).touch()
            (self.conf / name).chmod(mode)
        (self.conf / "writer-admission").write_text("closed\n")
        (self.conf / "writer-admission").chmod(0o644)
        self.operation = self.conf / "operation-in-progress"
        self.trace = self.root / "trace"
        self.trace.touch()
        self.trace.chmod(0o666)
        self.bin = self.root / "bin"
        self.bin.mkdir(mode=0o755)
        self.releases = self.root / "releases"
        self.releases.mkdir(mode=0o755)
        build = self.root / "build"
        build.mkdir()
        os.chown(build, 0, self.build_gid)
        build.chmod(0o770)
        private = self.root / "private"
        private.mkdir()
        (private / ".gitignore").write_text("*\n!.gitignore\n")
        self.give(private, 0o700)
        self.shared = self.root / "shared"   # a world-writable directory like /tmp, inside the scanned tree
        self.shared.mkdir()
        self.shared.chmod(0o1777)
        self.plant_flag = self.root / "plant"
        temporary = self.root / "tmp"
        temporary.mkdir()
        self.give(temporary, 0o700)
        evidence = self.root / "evidence"
        evidence.mkdir()
        self.give(evidence, 0o750)
        self.evidence = evidence / "run-isolated"
        self.evidence.mkdir()
        self.give(self.evidence, 0o750)
        self.candidate = evidence / "candidate.env"
        self.candidate.write_text("APP_ENV=staging\nAPP_KEY=candidate-secret\n")
        self.give(self.candidate, 0o600)
        actor_home = self.root / "actor"
        actor_home.mkdir()
        self.give(actor_home, 0o700)
        self.receipt = actor_home / "receipt"
        self.stop = self.root / "stop-actor"

        self.mirror = self.root / "mirror"
        self.mirror.mkdir()
        files = {
            ".gitignore": "/vendor/\n/public/build/\n/node_modules/\n.env\n/secret-probe.json\n",
            "composer.lock": "{}\n", "package-lock.json": "{}\n", "app/source.php": "<?php // approved source\n",
            "artisan": ARTISAN.replace("TRACE_PATH", repr(str(self.trace))),
            "ops/staging/validate-runtime.php": VALIDATOR,
        }
        for runtime in ("bootstrap/cache", "storage/framework", "storage/logs", "storage/app/private"):
            files[runtime + "/.gitignore"] = "*\n!.gitignore\n"
        for name, content in files.items():
            (self.mirror / name).parent.mkdir(parents=True, exist_ok=True)
            (self.mirror / name).write_text(content)
        sh("git", "init", "-q", str(self.mirror))
        sh("git", "-C", str(self.mirror), "add", ".")
        sh("git", "-C", str(self.mirror), "-c", "user.name=Test", "-c", "user.email=test@example.invalid",
           "commit", "-qm", "synthetic release")
        self.sha = sh("git", "-C", str(self.mirror), "rev-parse", "HEAD").stdout.strip()
        (self.mirror / ".env").write_text("APP_KEY=mirror-secret\n")
        (self.mirror / ".env").chmod(0o600)
        sh("chown", "-R", f"{self.app}:{self.app}", str(self.mirror))
        self.release = self.releases / self.sha

        secrets_list = [str(self.mirror / ".env"), str(self.candidate), str(private / ".gitignore"), str(evidence)]
        composer = (COMPOSER.replace("SECRETS_JSON", repr(json.dumps(secrets_list)))
                    .replace("ROOT_PATH", repr(str(self.root))).replace("TRACE_PATH", repr(str(self.trace)))
                    .replace("BUILDER_UID", str(self.build_uid)).replace("PLANT_FLAG", repr(str(self.plant_flag)))
                    .replace("PLANT_DIR", repr(str(self.shared))))
        (self.bin / "composer").write_text(composer)
        (self.bin / "npm").write_text('#!/bin/bash\nset -eu\nmkdir -p public/build\nprintf "{}" > public/build/manifest.json\n')
        for name in ("composer", "npm"):
            (self.bin / name).chmod(0o755)

        self.config = self.conf / "staging.conf"
        self.write_config(self.builder)
        self.helper = self.root / "release-step.sh"
        self.helper.write_text(HELPER.read_text().replace("/etc/vasey-staging/staging.conf", str(self.config)))
        self.helper.chmod(0o644)
        shutil.copyfile(SEALER, self.root / "seal-release.py")
        (self.root / "seal-release.py").chmod(0o644)
        backup = self.root / "backup"
        backup.write_text('#!/bin/bash\ncase "${1:-}" in predeploy) echo snapshot >> "$TRACE" ;; '
                          'ship-pending-start) echo ship-start >> "$TRACE" ;; *) exit 9 ;; esac\n')
        backup.chmod(0o755)

        source = CTL.read_text()
        definitions = source[source.index("valid_sha() {"):source.index("# The sudo entry point")]
        dispatch = source[source.index("# The sudo entry point"):]
        fixture = '''
die() { echo "vasey-staging-ctl: $*" >&2; exit 1; }
protected_dir() { [ -d "$1" ] && [ ! -L "$1" ]; }   # the sandbox lives under /tmp; covered by the ancestry tests
workers_stopped() { return 0; }
web_stopped() { return 0; }
cmd_space_check() { :; }
cmd_quiesce() { writer_gate_write closed; writer_barrier; echo quiesce >> "$TRACE"; }
cmd_switch() { echo switch >> "$TRACE"; ln -s "$RELEASES/$1" "$CURRENT"; }
cmd_resume() { echo resume >> "$TRACE"; writer_gate_write open; }
build_unit_ready() { [ ! -e "$ROOT/no-systemd" ]; }
build_scan_mounts() { printf '%s\\n' "$ROOT"; }   # the sandbox stands in for the host's local filesystems
systemd_run() {   # no systemd here: record the unit's command line, then run its command as the unit's user
  local user="" ; local -a envs=()
  printf '%s\\n' "$@" > "$ROOT/unit-args"
  while [ "$#" -gt 0 ]; do
    case "$1" in --) shift; break ;; --uid=*) user=${1#--uid=} ;; -E) envs+=("$2"); shift ;; -p) shift ;; esac
    shift
  done
  runuser -u "$user" -- env -i "${envs[@]}" "$@"
}
attach_private() {   # the bind mount and fstab are host state; the hand-over to the application is not
  [ "$(stat -c %U -- "$RELEASES/$1/storage/app/private")" = "$VASEY_APP_USER" ] || die "private mount target is not the application's"
  echo attach >> "$TRACE"
}
'''
        self.control = self.root / "control.sh"
        self.control.write_text(("#!/bin/bash\nset -euo pipefail\numask 077\n" + definitions + fixture + dispatch)
                                .replace("/etc/vasey-staging", str(self.conf))
                                .replace("/usr/local/libexec/vasey-staging", str(self.root)))
        self.environment = {
            "PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C",
            "ROOT": str(self.root), "RELEASES": str(self.releases), "CURRENT": str(self.root / "current"),
            "PRIVATE": str(private), "SEALER": str(self.root / "seal-release.py"), "RELEASE_STEP": str(self.helper),
            "OPERATION_MARKER": str(self.operation), "BACKUP_BIN": str(backup), "TRACE": str(self.trace),
            "VASEY_APP_USER": self.app, "VASEY_APP_GROUP": self.app, "VASEY_BUILD_USER": self.builder,
            "VASEY_ROOT": str(self.root), "VASEY_MIRROR": str(self.mirror), "VASEY_PHP": shutil.which("php"),
            "VASEY_PHP_FPM_SERVICE": "fixture-fpm", "VASEY_EXPECTED_APP_ENV": "staging",
            "VASEY_STAGING_HOST": "fixture.invalid", "VASEY_DB_NAME": "fixture_database",
        }

    def give(self, path, mode):
        os.chown(path, self.app_uid, self.app_gid)
        path.chmod(mode)

    def write_config(self, build_user):
        values = {"VASEY_ROOT": str(self.root), "VASEY_MIRROR": str(self.mirror), "VASEY_PHP": shutil.which("php"),
                  "VASEY_APP_USER": self.app, "VASEY_EXPECTED_APP_ENV": "staging", "VASEY_STAGING_HOST": "fixture.invalid",
                  "VASEY_DB_NAME": "fixture_database", "PATH": f"{self.bin}:/usr/bin:/bin"}
        if build_user:
            values["VASEY_BUILD_USER"] = build_user
        self.config.write_text("".join(f"{name}={shlex.quote(value)}\n" for name, value in values.items()))
        self.config.chmod(0o644)

    def ctl(self, *arguments, environment=None):
        return subprocess.run(["bash", str(self.control), *arguments], env=environment or self.environment,
                              capture_output=True, text=True, timeout=120)

    def prepare(self, environment=None):
        return self.ctl("prepare", self.sha, str(self.candidate), str(self.evidence), environment=environment)

    def start_actor(self):
        actor = subprocess.Popen(["runuser", "-u", self.app, "--", "python3", "-I", "-c", ACTOR, str(self.release),
                                  str(self.root / "build"), str(self.stop), str(self.receipt)])
        self.addCleanup(lambda: (self.stop.touch(), actor.wait(timeout=5)))
        return actor

    def trace_lines(self):
        return self.trace.read_text().splitlines()

    def builder_processes(self):
        return subprocess.run(["pgrep", "-u", str(self.build_uid)], capture_output=True, text=True).stdout.split()

    def mode(self, path):
        info = os.lstat(path)
        return info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)

    # (a) and (b): the build runs with the site up, the live application cannot write into it, and the pause holds no build.
    def test_build_runs_before_the_quiesce_and_the_live_application_cannot_write_into_the_candidate(self):
        actor = self.start_actor()
        run = self.prepare()
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn(f"building {self.sha} as {self.builder} with the site up", run.stdout)
        self.assertEqual(self.trace_lines(), [f"build {self.build_uid}", "attach"], "prepare must not quiesce")
        self.assertFalse(self.operation.exists())
        self.assertEqual(self.builder_processes(), [], "a build-account process outlived the build")
        # Keep trying for a while after sealing too: the candidate must stay closed to the application until activate.
        time.sleep(0.3)
        self.stop.touch()
        actor.wait(timeout=5)
        self.assertFalse(self.receipt.exists() and self.receipt.read_text(),
                         "the live application wrote into the candidate or the build area:\n"
                         + (self.receipt.read_text() if self.receipt.exists() else ""))
        self.assertEqual((self.release / "vendor/autoload.php").read_text(), "<?php // approved")
        self.assertFalse((self.release / "injected.php").exists())
        self.assertEqual(self.mode(self.release), (0, 0, 0o755))
        self.assertEqual(self.mode(self.release / "vendor/autoload.php"), (0, 0, 0o444))
        self.assertEqual(self.mode(self.release / ".env"), (0, self.app_gid, 0o440))
        self.assertEqual((self.release / ".env").read_text(), self.candidate.read_text())
        for held in ("bootstrap/cache", "storage/framework", "storage/logs"):
            self.assertEqual(self.mode(self.release / held), (0, 0, 0o755), held)
        self.assertEqual(self.mode(self.release / "bootstrap/cache/packages.php"), (0, 0, 0o644))
        self.assertEqual(self.mode(self.release / "storage/app/private"), (self.app_uid, self.app_gid, 0o700))
        self.assertTrue((self.evidence / "build.sha256").is_file())
        self.assertEqual(list((self.root / "build").iterdir()), [], "the build home was not removed")

        self.trace.write_text("")
        run = self.ctl("activate", self.sha, str(self.evidence))
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        lines = self.trace_lines()
        self.assertEqual(lines[0], "quiesce")
        self.assertEqual(lines[-3:], ["switch", "resume", "ship-start"])
        self.assertIn("snapshot", lines)
        self.assertIn("artisan migrate", lines)
        self.assertFalse([line for line in lines if line.startswith("build")], "the pause contains a build")
        for runtime in ("bootstrap/cache", "storage/framework", "storage/logs"):
            self.assertEqual(self.mode(self.release / runtime), (self.app_uid, self.app_gid, 0o700), runtime)
        self.assertEqual(self.mode(self.release / "bootstrap/cache/packages.php")[:2], (self.app_uid, self.app_gid))
        self.assertTrue((self.release / "storage/framework/maintenance.php").exists())
        self.assertFalse(self.operation.exists())

    # (e) the build account reads nothing of the application's environment, evidence or private store.
    def test_the_build_account_cannot_read_the_environment(self):
        run = self.prepare()
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(json.loads((self.release / "secret-probe.json").read_text()), [],
                         "the build read a secret (or had a .env) during the build")
        for secret in (self.release / ".env", self.candidate, self.mirror / ".env", self.root / "private/.gitignore"):
            probe = subprocess.run(["runuser", "-u", self.builder, "--", "cat", "--", str(secret)], capture_output=True, text=True)
            self.assertNotEqual(probe.returncode, 0, f"{self.builder} read {secret}")
            self.assertNotIn("secret", probe.stdout)
        # The application can still read its own environment in the candidate.
        probe = subprocess.run(["runuser", "-u", self.app, "--", "cat", "--", str(self.release / ".env")], capture_output=True, text=True)
        self.assertEqual(probe.returncode, 0, probe.stderr)

    # (c) anything that changes the candidate after sealing is refused before the activation stops the site.
    def test_a_candidate_changed_after_sealing_is_refused_before_the_quiesce(self):
        def tamper_code():
            os.chown(self.release / "app/source.php", self.app_uid, self.app_gid)

        def plant_held():
            planted = self.release / "bootstrap/cache/services.php"
            planted.write_text("<?php // planted")
            os.chown(planted, self.app_uid, self.app_gid)

        def open_held():
            (self.release / "storage/framework").chmod(0o777)

        def replace_vendor():
            target = self.release / "vendor/autoload.php"
            target.unlink()
            target.write_text("<?php // replaced")
            target.chmod(0o644)

        def expose_environment():
            (self.release / ".env").chmod(0o444)

        for tamper in (tamper_code, plant_held, open_held, replace_vendor, expose_environment):
            with self.subTest(tamper=tamper.__name__):
                if self.release.exists():
                    shutil.rmtree(self.release)
                self.trace.write_text("")
                run = self.prepare()
                self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
                tamper()
                self.trace.write_text("")
                run = self.ctl("activate", self.sha, str(self.evidence))
                self.assertNotEqual(run.returncode, 0, run.stdout + run.stderr)
                self.assertIn("protected release operation refused", run.stderr)
                self.assertEqual(self.trace_lines(), [], "the activation stopped something for a tampered candidate")
                self.assertFalse(self.operation.exists())
                self.assertFalse((self.root / "current").exists())

    # (d) no build account: exactly the build inside the pause, as the application, as before.
    def test_without_a_build_account_the_build_stays_inside_the_pause_as_the_application(self):
        cases = (("unset", None), ("missing", f"vtbnone{secrets.token_hex(2)}"))
        for label, build_user in cases:
            with self.subTest(case=label):
                if self.release.exists():
                    shutil.rmtree(self.release)
                self.trace.write_text("")
                (self.conf / "writer-admission").write_text("closed\n")
                self.write_config(build_user)
                environment = dict(self.environment)
                environment.pop("VASEY_BUILD_USER")
                if build_user:
                    environment["VASEY_BUILD_USER"] = build_user
                run = self.prepare(environment)
                self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
                self.assertEqual(self.trace_lines(), ["quiesce", f"build {self.app_uid}", "attach"])
                if build_user:
                    self.assertIn(f"build account {build_user} does not exist", run.stdout)
                else:
                    self.assertNotIn("build account", run.stdout)
                for runtime in ("bootstrap/cache", "storage/framework", "storage/logs", "storage/app/private"):
                    self.assertEqual(self.mode(self.release / runtime)[:2], (self.app_uid, self.app_gid), runtime)
                self.assertEqual(self.mode(self.release / ".env"), (0, self.app_gid, 0o440))
                self.assertTrue((self.evidence / "build.sha256").is_file())
                self.assertFalse(self.operation.exists())

    # A build account that exists but could share a write path with the application refuses before anything starts.
    def test_an_unsafe_build_account_refuses_before_any_quiesce_or_build(self):
        sh("usermod", "-aG", self.builder, self.app)
        try:
            run = self.prepare()
        finally:
            sh("gpasswd", "-d", self.app, self.builder)
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("must not be a member of the build group", run.stderr)
        self.assertEqual(self.trace_lines(), [])
        self.assertFalse(self.release.exists())
        self.assertFalse(self.operation.exists())

    def test_a_failed_isolated_build_leaves_the_site_untouched_and_kills_the_build_account(self):
        (self.bin / "npm").write_text("#!/bin/bash\nsetsid sleep 30 </dev/null >/dev/null 2>&1 &\nexit 7\n")
        run = self.prepare()
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("isolated build failed with the site up", run.stderr)
        self.assertNotIn("quiesce", self.trace_lines())
        self.assertEqual(self.builder_processes(), [])
        self.assertTrue(self.operation.exists(), "root recovery keeps the marker and the unsealed slot")
        self.assertEqual(self.mode(self.release)[0], 0)


    # Review P1 on PR #354: the build runs in a hardened unit that can write only its slot and build area, cannot make
    # set-id files and is killed as a whole cgroup.
    def test_the_build_runs_as_a_hardened_transient_unit(self):
        run = self.prepare()
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        arguments = (self.root / "unit-args").read_text().splitlines()
        command = arguments[arguments.index("--") + 1:]
        self.assertEqual(command, ["/bin/bash", str(self.helper), "build-isolated", self.sha])
        options = arguments[:arguments.index("--")]
        for flag in ("--pipe", "--wait", "--collect", f"--uid={self.builder}", f"--gid={self.build_gid}"):
            self.assertIn(flag, options)
        properties = [options[i + 1] for i, value in enumerate(options) if value == "-p"]
        for required in ("NoNewPrivileges=yes", "RestrictSUIDSGID=yes", "ProtectSystem=strict", "PrivateTmp=yes",
                         "PrivateDevices=yes", "ProtectHome=yes", "RestrictNamespaces=yes", "KillMode=control-group",
                         "RestrictAddressFamilies=AF_INET AF_INET6", "PrivateIPC=yes", "RemoveIPC=yes",
                         f"ReadWritePaths={self.release} {self.root / 'build'}"):
            self.assertIn(required, properties)
        for limit in ("TasksMax=", "MemoryMax=", "CPUQuota=", "RuntimeMaxSec="):
            self.assertTrue([p for p in properties if p.startswith(limit)], limit)
        self.assertEqual([p for p in properties if p.startswith("ReadWritePaths=")],
                         [f"ReadWritePaths={self.release} {self.root / 'build'}"])

    def test_a_configured_build_account_without_systemd_refuses_before_anything_starts(self):
        (self.root / "no-systemd").touch()
        run = self.prepare()
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("needs systemd as init", run.stderr)
        self.assertEqual(self.trace_lines(), [])
        self.assertFalse(self.release.exists())
        self.assertFalse(self.operation.exists())

    def test_a_file_the_build_account_left_outside_its_area_refuses_the_next_build(self):
        helper = self.shared / ".helper"
        helper.write_text("helper")
        os.chown(helper, self.build_uid, self.build_gid)
        helper.chmod(0o6755)
        run = self.prepare()
        self.assertNotEqual(run.returncode, 0)
        self.assertIn(f"the build account owns {helper} outside its build area", run.stderr)
        self.assertEqual(self.trace_lines(), [], "a build ran, or the site was stopped, despite the leftover")
        self.assertFalse(self.release.exists())

    def test_a_file_only_in_the_build_group_outside_its_area_also_refuses(self):
        planted = self.shared / ".group-only"
        planted.write_text("x")
        os.chown(planted, 0, self.build_gid)
        planted.chmod(0o2755)
        run = self.prepare()
        self.assertNotEqual(run.returncode, 0)
        self.assertIn(f"the build account owns {planted} outside its build area", run.stderr)
        self.assertEqual(self.trace_lines(), [])

    def test_a_build_that_leaves_a_file_outside_its_area_is_not_sealed(self):
        self.plant_flag.touch()
        run = self.prepare()
        self.assertNotEqual(run.returncode, 0)
        self.assertIn(f"the build account owns {self.shared / '.helper'} outside its build area", run.stderr)
        self.assertEqual(self.trace_lines(), [f"build {self.build_uid}"], "sealed, attached or stopped despite the leftover")
        self.assertEqual(self.mode(self.release), (0, 0, 0o700), "the slot was not taken from the build group")
        self.assertNotIn("attach", self.trace_lines())
        self.assertEqual(self.builder_processes(), [])
        self.assertTrue(self.operation.exists(), "root recovery keeps the marker and the unsealed slot")

    # Review P2 on PR #354: processes that keep forking escaped a pkill snapshot; kill(-1) as the account cannot be raced.
    # Each chaser forks and exits, so any one process lives for microseconds and pgrep alone can miss all of them: liveness
    # is the heartbeat file every generation touches.
    def test_the_reap_kills_processes_that_keep_forking(self):
        heartbeat = self.root / "heartbeat"
        heartbeat.touch()
        heartbeat.chmod(0o666)
        chaser = ("import os, sys\nwhile True:\n    os.utime(sys.argv[1])\n    if os.fork():\n        os._exit(0)\n")
        for _ in range(4):
            subprocess.Popen(["setpriv", f"--reuid={self.build_uid}", f"--regid={self.build_gid}", "--clear-groups", "--",
                              "python3", "-I", "-c", chaser, str(heartbeat)], cwd="/",
                             stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                             start_new_session=True).wait(timeout=10)

        def beating():
            before = heartbeat.stat().st_mtime_ns
            time.sleep(0.3)
            return heartbeat.stat().st_mtime_ns != before

        self.assertTrue(beating(), "the chasers did not start")
        source = CTL.read_text()
        definitions = source[source.index("valid_sha() {"):source.index("# The sudo entry point")]
        script = ('die() { echo "$*" >&2; exit 1; }\n' + definitions
                  + f"\nBUILD_UID={self.build_uid} BUILD_GID={self.build_gid}\nreap_builder\necho REAPED\n")
        run = subprocess.run(["bash", "-eu", "-c", script], capture_output=True, text=True, timeout=60,
                             env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"})
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("REAPED", run.stdout)
        self.assertFalse(beating(), "a forking process of the build account survived the reap")
        self.assertEqual(self.builder_processes(), [])

    # Review P3 on PR #354: an interrupted hand-over (bootstrap/cache already handed, the rest still held) is completed.
    def test_an_interrupted_runtime_hand_over_is_completed_by_the_activation(self):
        run = self.prepare()
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        cache = self.release / "bootstrap/cache"
        for path in [cache, *cache.rglob("*")]:
            os.chown(path, self.app_uid, self.app_gid)
        cache.chmod(0o700)
        self.trace.write_text("")
        run = self.ctl("activate", self.sha, str(self.evidence))
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        for runtime in ("storage/framework", "storage/logs"):
            self.assertEqual(self.mode(self.release / runtime), (self.app_uid, self.app_gid, 0o700), runtime)

    # Review of PR #356: a candidate from an isolated build is never attached by hand. Only prepare attaches it, after the
    # runtime admission, and nothing later repeats that admission, so a hand attach could carry a refused candidate to
    # activate. Refused before anything about the release changes.
    def test_attach_refuses_an_isolated_candidate_even_once_sealed(self):
        run = self.prepare()
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.trace.write_text("")
        run = self.ctl("attach", self.sha)
        self.assertNotEqual(run.returncode, 0, run.stdout)
        self.assertIn("comes from an isolated build and is not attached by hand", run.stderr)
        self.assertEqual(self.trace_lines(), [])
        self.assertEqual(self.mode(self.release), (0, 0, 0o755))

    def test_a_candidate_refused_by_admission_cannot_be_attached_and_activated(self):
        self.candidate.write_text("APP_ENV=staging\nAPP_KEY=refused-by-admission\n")
        run = self.prepare()
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("built candidate refused", run.stderr)
        self.operation.unlink()   # root clears the marker but keeps the slot (the case the review reproduced)
        self.trace.write_text("")
        run = self.ctl("attach", self.sha)
        self.assertNotEqual(run.returncode, 0, run.stdout)
        self.assertNotIn("attach", self.trace_lines())
        run = self.ctl("activate", self.sha, str(self.evidence))
        self.assertNotEqual(run.returncode, 0, run.stdout)
        self.assertFalse((self.root / "current").exists())

    def test_attach_refuses_an_unsealed_isolated_slot_without_opening_it(self):
        (self.bin / "npm").write_text("#!/bin/bash\nexit 7\n")
        run = self.prepare()
        self.assertNotEqual(run.returncode, 0)
        before = self.mode(self.release)
        self.operation.unlink()
        self.trace.write_text("")
        run = self.ctl("attach", self.sha)
        self.assertNotEqual(run.returncode, 0, run.stdout)
        self.assertIn("comes from an isolated build", run.stderr)
        self.assertNotIn("attach", self.trace_lines())
        self.assertEqual(self.mode(self.release), before, "the refused attach changed the slot's owner or mode")

class BuildAccountGuards(unittest.TestCase):
    """The account checks in build_account(), with name-service answers as fixtures: each unsafe shape refuses."""

    def run_guard(self, **overrides):
        values = {"name": "vasey-staging-build", "exists": "1", "uid": "990", "gid": "990", "groups": "990",
                  "shell": "/usr/sbin/nologin", "app_uid": "1000", "app_gid": "1000", "app_groups": "1000 27",
                  "build_dir": "0 990 770", "members": "", "others": "", "unit": "1", "root": None, "linger": "0"}
        values.update(overrides)
        source = CTL.read_text()
        function = source[source.index("build_account() {"):source.index("build_unit_ready() {")]
        shell = '''die() { echo "$*" >&2; exit 1; }
protected_dir() { :; }
getent() {
  case "$1:${2:-}" in
    "passwd:$VASEY_BUILD_USER") [ "$EXISTS" = 1 ] || return 2; echo "$VASEY_BUILD_USER:x:$B_UID:$B_GID::/nonexistent:$SHELL_FIXTURE" ;;
    "passwd:") echo "$VASEY_BUILD_USER:x:$B_UID:$B_GID::/nonexistent:$SHELL_FIXTURE"; echo "forge:x:$A_UID:$A_GID::/home/forge:/bin/bash"
               [ -z "$OTHERS" ] || echo "$OTHERS" ;;
    "group:$VASEY_APP_GROUP") echo "$VASEY_APP_GROUP:x:$A_GID:" ;;
    "group:$B_GID") echo "build:x:$B_GID:$B_MEMBERS" ;;
    *) return 2 ;;
  esac
}
id() {
  local who=${@: -1}
  case "$1:$who" in
    "-u:$VASEY_BUILD_USER") echo "$B_UID" ;; "-g:$VASEY_BUILD_USER") echo "$B_GID" ;; "-gn:$VASEY_BUILD_USER") echo build ;;
    "-G:$VASEY_BUILD_USER") echo "$B_GROUPS" ;; "-u:$VASEY_APP_USER") echo "$A_UID" ;; "-G:$VASEY_APP_USER") echo "$A_GROUPS" ;;
    *) return 1 ;;
  esac
}
stat() { echo "$BUILD_DIR"; }
build_unit_ready() { [ "$UNIT" = 1 ]; }
build_account_lingers() { [ "$LINGER" = 1 ]; }
'''
        script = shell + function + '\nif build_account; then echo "usable $BUILD_UID $BUILD_GID"; else echo legacy; fi\n'
        with tempfile.TemporaryDirectory() as directory:
            Path(directory, "build").mkdir()
            return subprocess.run(["bash", "-eu", "-c", script], capture_output=True, text=True, env={
                "PATH": "/usr/bin:/bin", "ROOT": values["root"] or directory, "B_MEMBERS": values["members"],
                "OTHERS": values["others"], "UNIT": values["unit"], "LINGER": values["linger"], "VASEY_BUILD_USER": values["name"], "EXISTS": values["exists"],
                "B_UID": values["uid"], "B_GID": values["gid"], "B_GROUPS": values["groups"], "SHELL_FIXTURE": values["shell"],
                "VASEY_APP_USER": "forge", "VASEY_APP_GROUP": "forge", "A_UID": values["app_uid"], "A_GID": values["app_gid"],
                "A_GROUPS": values["app_groups"], "BUILD_DIR": values["build_dir"]})

    def test_a_separate_account_is_usable(self):
        run = self.run_guard()
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(run.stdout.strip().splitlines()[-1], "usable 990 990")

    def test_no_account_configured_or_present_falls_back_to_the_build_inside_the_pause(self):
        for overrides in ({"name": ""}, {"exists": "0"}):
            with self.subTest(**overrides):
                run = self.run_guard(**overrides)
                self.assertEqual(run.returncode, 0, run.stderr)
                self.assertEqual(run.stdout.strip().splitlines()[-1], "legacy")

    def test_every_shared_write_path_refuses(self):
        for overrides, message in (
                ({"uid": "0"}, "nonroot"), ({"uid": "1000"}, "not the application account"),
                ({"gid": "1000", "groups": "1000"}, "its own group only"), ({"groups": "990 1000"}, "its own group only"),
                ({"app_groups": "1000 990"}, "must not be a member of the build group"),
                ({"shell": "/bin/bash"}, "no login shell"), ({"build_dir": "0 990 775"}, "0770"),
                ({"build_dir": "990 990 770"}, "0770"), ({"build_dir": "0 1000 770"}, "0770"),
                ({"name": "bad name"}, "invalid build account name"),
                ({"members": "www-data"}, "the build group must have no members"),
                ({"others": "www-data:x:33:990::/var/www:/usr/sbin/nologin"}, "shares the build account's uid or group"),
                ({"others": "twin:x:990:33::/:/usr/sbin/nologin"}, "shares the build account's uid or group"),
                ({"unit": "0"}, "needs systemd as init"),
                ({"linger": "1"}, "lingering enabled"),
                ({"root": "/home/forge/vasey"}, "must not be under /home")):
            with self.subTest(**overrides):
                run = self.run_guard(**overrides)
                self.assertNotEqual(run.returncode, 0, run.stdout)
                self.assertIn(message, run.stderr)
                self.assertNotIn("usable", run.stdout)


class ReapAndScanUnits(unittest.TestCase):
    """reap_builder and refuse_build_leftovers with their system commands replaced by recording functions."""

    def run_functions(self, stubs, call, environment=None):
        source = CTL.read_text()
        definitions = source[source.index("valid_sha() {"):source.index("# The sudo entry point")]
        script = 'die() { echo "$*" >&2; exit 1; }\n' + definitions + "\n" + stubs + "\n" + call + "\n"
        with tempfile.TemporaryDirectory() as directory:
            log = Path(directory) / "log"
            env = {"PATH": "/usr/bin:/bin", "LC_ALL": "C", "LOG": str(log), "SCRATCH": directory}
            env.update(environment or {})
            run = subprocess.run(["bash", "-eu", "-c", script], capture_output=True, text=True, timeout=60, env=env)
            return run, (log.read_text().splitlines() if log.exists() else [])

    KILL_STUBS = ('setpriv() { echo "setpriv $*" >> "$LOG"; }\npkill() { echo "pkill $*" >> "$LOG"; }\n'
                  'pgrep() { echo "pgrep $*" >> "$LOG"; return 1; }\nsleep() { :; }')

    def test_the_reap_refuses_without_a_build_account_and_kills_nothing(self):
        for uid, gid in (("0", "990"), ("990", "0"), ("", "990"), ("abc", "990")):
            with self.subTest(uid=uid, gid=gid):
                run, log = self.run_functions(self.KILL_STUBS, f'BUILD_UID="{uid}" BUILD_GID="{gid}"\nreap_builder\necho REAPED')
                self.assertNotEqual(run.returncode, 0)
                self.assertIn("no build account to reap", run.stderr)
                self.assertEqual(log, [], "something was signalled without a valid build account")

    def test_the_reap_kills_before_it_looks(self):
        # pgrep finds nothing (a chaser can always slip between snapshots): the kill must still have been sent.
        run, log = self.run_functions(self.KILL_STUBS, "BUILD_UID=990 BUILD_GID=991\nreap_builder\necho REAPED")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("REAPED", run.stdout)
        self.assertEqual(log[0], "setpriv --reuid=990 --regid=991 --clear-groups -- kill -KILL -1")
        self.assertLess(log.index(log[0]), next(i for i, line in enumerate(log) if line.startswith("pgrep")))

    def scan(self, find_stub):
        stubs = ('build_scan_mounts() { printf "%s\\n" "$SCRATCH"; }\n' + find_stub)
        return self.run_functions(stubs, "BUILD_UID=990 BUILD_GID=991 RELEASES=/nonexistent/releases ROOT=/nonexistent\n"
                                         f"refuse_build_leftovers {'a' * 40}\necho CLEAN")

    def test_a_scan_error_refuses_but_a_vanished_file_does_not(self):
        run, _ = self.scan('find() { echo "find: \'$SCRATCH/x\': Permission denied" >&2; return 1; }')
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("cannot scan", run.stderr)
        self.assertIn("Permission denied", run.stderr)
        self.assertNotIn("CLEAN", run.stdout)
        run, _ = self.scan('find() { echo "find: \'$SCRATCH/gone\': No such file or directory" >&2; return 1; }')
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("CLEAN", run.stdout)

@unittest.skipUnless(IS_ROOT, "the sealer's ownership changes need root (the CI step runs as root)")
class SealBuiltTree(unittest.TestCase):
    """The sealer itself, on a tree owned by a synthetic build UID (real chown, real modes)."""
    APP, BUILD = 64901, 64902

    def setUp(self):
        self.module = load_sealer()
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        base = Path(scratch.name)
        base.chmod(0o755)
        self.release = base / ("c" * 40)
        for directory in ("app", "vendor", "public/build", "bootstrap/cache", "storage/framework/views", "storage/logs",
                          "storage/app/private"):
            (self.release / directory).mkdir(parents=True, exist_ok=True)
        for name, content in (("app/source.php", "code"), ("vendor/autoload.php", "vendor"), ("public/build/manifest.json", "{}"),
                              ("bootstrap/cache/packages.php", "<?php return [];"), ("storage/framework/views/v.php", "view"),
                              ("storage/app/private/.gitignore", "*\n")):
            (self.release / name).write_text(content)
        for path in [self.release, *self.release.rglob("*")]:
            os.chown(path, self.BUILD, self.BUILD, follow_symlinks=False)
        os.chown(self.release, 0, self.BUILD)
        self.release.chmod(0o770)

    def seal(self):
        self.module.seal_release(self.release, self.APP, self.APP, self.BUILD)

    def owner(self, relative):
        info = os.lstat(self.release / relative)
        return info.st_uid, stat.S_IMODE(info.st_mode)

    def test_code_is_sealed_runtime_is_held_and_released_only_on_request(self):
        self.seal()
        self.assertEqual(self.owner(""), (0, 0o755))
        self.assertEqual(self.owner("vendor/autoload.php"), (0, 0o444))
        self.assertEqual(self.owner("bootstrap/cache"), (0, 0o755))
        self.assertEqual(self.owner("bootstrap/cache/packages.php"), (0, 0o644))
        self.assertEqual(self.owner("storage/framework/views"), (0, 0o755))
        self.assertEqual(self.owner("storage/app/private"), (self.APP, 0o700))
        self.assertEqual(self.owner("storage/app/private/.gitignore")[0], self.APP)
        self.module.run(self.release, self.APP, self.APP, False)   # a held candidate verifies
        self.module.release_runtime(self.release, self.APP, self.APP)
        for runtime in ("bootstrap/cache", "storage/framework", "storage/logs"):
            self.assertEqual(self.owner(runtime), (self.APP, 0o700), runtime)
        self.assertEqual(self.owner("storage/framework/views/v.php")[0], self.APP)
        self.module.release_runtime(self.release, self.APP, self.APP)   # idempotent once released
        self.assertEqual(self.owner("vendor/autoload.php"), (0, 0o444))

    # Cursor Bugbot on PR #354: a symlink's mode is always 0777; a held tree may contain one.
    def test_a_symlink_in_a_held_runtime_tree_verifies_and_is_released(self):
        link = self.release / "bootstrap/cache/current.php"
        link.symlink_to("packages.php")
        os.chown(link, self.BUILD, self.BUILD, follow_symlinks=False)
        self.seal()
        self.assertEqual(os.lstat(link).st_uid, 0)
        self.module.run(self.release, self.APP, self.APP, False)
        self.module.release_runtime(self.release, self.APP, self.APP)
        self.assertEqual(os.lstat(link).st_uid, self.APP)

    def test_a_symlink_in_a_held_runtime_tree_must_still_be_root_owned(self):
        link = self.release / "bootstrap/cache/current.php"
        link.symlink_to("packages.php")
        os.chown(link, self.BUILD, self.BUILD, follow_symlinks=False)
        self.seal()
        os.chown(link, self.APP, self.APP, follow_symlinks=False)
        with self.assertRaises(ValueError):
            self.module.run(self.release, self.APP, self.APP, False)

    def test_the_release_stays_closed_to_everyone_but_root_until_the_walk_is_done(self):
        seen = []
        original = self.module.adopt

        def recording(descriptor, *arguments):
            seen.append(stat.S_IMODE(os.stat(self.release).st_mode))
            return original(descriptor, *arguments)

        with patch.object(self.module, "adopt", recording):
            self.seal()
        self.assertTrue(seen)
        self.assertEqual(set(seen), {0o700})
        self.assertEqual(self.owner(""), (0, 0o755))

    def test_anything_the_application_owns_in_an_isolated_build_refuses(self):
        for relative in ("vendor/autoload.php", "vendor", "bootstrap/cache/packages.php", "storage/logs"):
            with self.subTest(relative=relative):
                self.setUp()
                os.chown(self.release / relative, self.APP, self.APP)
                with self.assertRaises((ValueError, OSError)):
                    self.seal()

    def test_an_environment_file_or_a_runtime_hard_link_refuses(self):
        (self.release / ".env").write_text("APP_KEY=planted\n")
        os.chown(self.release / ".env", self.BUILD, self.BUILD)
        with self.assertRaises(ValueError):
            self.seal()
        self.setUp()
        os.link(self.release / "bootstrap/cache/packages.php", self.release / "storage/logs/linked")
        with self.assertRaises(ValueError):
            self.seal()

    def test_the_build_account_must_differ_from_root_and_the_application(self):
        for build in (0, self.APP):
            with self.subTest(build=build):
                with self.assertRaises(ValueError):
                    self.module.seal_release(self.release, self.APP, self.APP, build)

    def test_a_changed_held_runtime_tree_fails_verification(self):
        self.seal()
        planted = self.release / "storage/framework/views/planted.php"
        planted.write_text("<?php // planted")
        os.chown(planted, self.APP, self.APP)
        with self.assertRaises(ValueError):
            self.module.run(self.release, self.APP, self.APP, False)
        planted.unlink()
        (self.release / "storage/framework/views").chmod(0o775)
        with self.assertRaises(ValueError):
            self.module.run(self.release, self.APP, self.APP, False)
        with self.assertRaises(ValueError):
            self.module.release_runtime(self.release, self.APP, self.APP)


class DeployMessages(unittest.TestCase):
    """forge-deploy.sh: with the build account, a prepare failure happened with the site up; the activation still counts."""

    def run_deploy(self, fail_at, build_account):
        source = DEPLOY.read_text()
        on_exit = source[source.index("on_exit() {"):source.index("trap on_exit EXIT")]
        start = source.index("FIRST_INSTALL=1\n")
        tail = source[start:source.index('[ "$(readlink -f "$CURRENT")" = "$REL" ]', start)]
        with tempfile.TemporaryDirectory() as directory:
            script = ("set -Eeuo pipefail\nACTIVATION_STARTED=0\nISOLATED_BUILD=0\nENV_SNAPSHOT=''\n"
                      "die() { echo \"forge-deploy: ERROR: $*\" >&2; exit 1; }\nstep() { echo \"STEP $*\"; }\n"
                      "getent() { [ \"$1\" = passwd ] && [ \"$2\" = \"$PRESENT\" ]; }\n"
                      "stat() { echo 1:1; }\n"
                      "env() { case \" $* \" in *' rev-parse HEAD '*) echo \"$SHA\" ;; *) return 0 ;; esac; }\n"
                      "ctl_stub() { echo \"$1\" >> \"$TRACE\"; [ \"$1\" != \"$FAIL_AT\" ]; }\nCTL=(ctl_stub)\n"
                      + on_exit + "trap on_exit EXIT\n" + tail + "\necho REACHED-AFTER-ACTIVATE\n")
            trace = Path(directory) / "trace"
            environment = {"PATH": "/usr/bin:/bin", "TRACE": str(trace), "FAIL_AT": fail_at, "ROOT": directory,
                           "CURRENT": str(Path(directory) / "current"), "REL": str(Path(directory) / "rel"),
                           "STAMP": "20261112T000000Z", "SHA": "a" * 40, "RUNTIME_ENV": "/dev/null",
                           "EVIDENCE": str(Path(directory) / "evidence"), "PRESENT": "vasey-staging-build"}
            if build_account is not None:
                environment["VASEY_BUILD_USER"] = build_account
            run = subprocess.run(["bash", "-c", script], capture_output=True, text=True, env=environment)
            return run, (trace.read_text().split() if trace.exists() else [])

    def test_a_failed_isolated_prepare_says_the_site_was_not_stopped(self):
        run, calls = self.run_deploy("prepare", "vasey-staging-build")
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(calls, ["space-check", "prepare"])
        self.assertIn("with the site up: the served release was not touched", run.stderr)
        self.assertNotIn("unconfirmed", run.stderr)
        self.assertIn("vasey-staging-build builds", run.stdout)

    def test_the_activation_after_an_isolated_prepare_is_an_activation(self):
        run, calls = self.run_deploy("activate", "vasey-staging-build")
        self.assertNotEqual(run.returncode, 0)
        self.assertEqual(calls, ["space-check", "prepare", "activate"])
        self.assertIn("service and writer state is unconfirmed", run.stderr)

    def test_without_the_account_a_failed_prepare_is_still_unconfirmed_state(self):
        for account in (None, "vasey-staging-missing"):
            with self.subTest(account=account):
                run, calls = self.run_deploy("prepare", account)
                self.assertNotEqual(run.returncode, 0)
                self.assertEqual(calls, ["space-check", "prepare"])
                self.assertIn("service and writer state is unconfirmed", run.stderr)
                self.assertIn("quiesce, allocate, build, attach and seal", run.stdout)

    def test_a_successful_isolated_deploy_reaches_activation(self):
        run, calls = self.run_deploy("none", "vasey-staging-build")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(calls, ["space-check", "prepare", "activate"])
        self.assertIn("REACHED-AFTER-ACTIVATE", run.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
