"""The production kit (ops/production): how it is derived from the staging kit, and what it ships.

Covers the builder (counted anchors, forbidden leftovers, byte-identical copies), cross-file path consistency, the
supervisor and PHP-FPM templates (parsed by supervisor's and php-fpm's own parsers when installed), the nginx template
(structure, equality of the paid-delivery contract with staging, `nginx -t` when installed), the env composer, and the
provisioning wrapper's refusals. Nothing is installed and no host is touched."""
import difflib
import hashlib
import importlib.util
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
STAGING = REPO / "ops/staging"
PROD = REPO / "ops/production"
BUILDER = PROD / "build-kit.py"
PHP = shutil.which("php8.4") or shutil.which("php")
IS_ROOT = os.geteuid() == 0

spec = importlib.util.spec_from_file_location("build_kit", BUILDER)
build_kit = importlib.util.module_from_spec(spec)
sys.modules["build_kit"] = build_kit
spec.loader.exec_module(build_kit)

SCRIPTS = ["provision.sh", "forge-deploy.sh", "release-step.sh", "backup.sh", "bin/vasey-production-ctl"]
EXPECTED_FILES = {
    "provision.sh", "forge-deploy.sh", "release-step.sh", "backup.sh", "bin/vasey-production-ctl", "seal-release.py",
    "normalize-mysql-dump.py", "validate-runtime.php", "nginx/vasey-production.conf", "php-fpm/vasey-production.conf",
    "php-fpm/vasey-paid-delivery.conf", "workers/vasey-production-workers.conf", "MANIFEST.sha256",
}


def build_into(directory, **kwargs):
    return build_kit.build(Path(directory) / "kit", **kwargs)


class KitBuild(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix="va-prod-kit-")
        cls.kit = Path(cls.tmp.name) / "kit"
        cls.hashes = build_kit.build(cls.kit)

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def text(self, name):
        return (self.kit / name).read_text()

    def test_the_kit_has_exactly_the_expected_files(self):
        found = {str(p.relative_to(self.kit)) for p in self.kit.rglob("*") if p.is_file()}
        self.assertEqual(found, EXPECTED_FILES)

    def test_no_test_commerce_units_and_no_basic_auth_ship(self):
        names = " ".join(str(p) for p in self.kit.rglob("*"))
        self.assertNotIn("test-commerce", names)
        self.assertNotIn("stripe-listen", names)
        for path in self.kit.rglob("*"):
            if path.is_file():
                body = path.read_text(errors="replace")
                self.assertNotIn("auth_basic", body, path.name)
                self.assertNotIn("htpasswd", body, path.name)
                self.assertNotIn("netrc", body.lower(), path.name)

    def test_nothing_staging_specific_is_left_in_the_derived_files(self):
        for name in SCRIPTS + ["php-fpm/vasey-production.conf", "workers/vasey-production-workers.conf"]:
            body = "\n".join(l for l in self.text(name).splitlines() if "DERIVED by ops/production/build-kit.py" not in l)
            for token in build_kit.FORBIDDEN:
                self.assertNotIn(token, body, f"{name}: {token}")

    def test_copies_are_byte_identical_to_their_sources(self):
        for name, source in (("seal-release.py", STAGING / "seal-release.py"),
                             ("normalize-mysql-dump.py", STAGING / "normalize-mysql-dump.py"),
                             ("php-fpm/vasey-paid-delivery.conf", STAGING / "php-fpm/vasey-paid-delivery.conf"),
                             ("validate-runtime.php", PROD / "validate-runtime.php"),
                             ("nginx/vasey-production.conf", PROD / "nginx/vasey-production.conf")):
            self.assertEqual((self.kit / name).read_bytes(), source.read_bytes(), name)

    def test_the_manifest_lists_every_file_with_its_hash(self):
        lines = self.text("MANIFEST.sha256").splitlines()
        self.assertEqual(len(lines), len(EXPECTED_FILES) - 1)
        for line in lines:
            digest, name = line.split("  ", 1)
            self.assertEqual(hashlib.sha256((self.kit / name).read_bytes()).hexdigest(), digest, name)

    def test_the_build_is_deterministic(self):
        with tempfile.TemporaryDirectory() as other:
            self.assertEqual(build_into(other), self.hashes)

    def test_modes_are_owner_controlled_and_scripts_executable(self):
        self.assertEqual(oct(self.kit.stat().st_mode & 0o777), "0o700")
        for name in ("provision.sh", "forge-deploy.sh", "backup.sh", "bin/vasey-production-ctl"):
            self.assertTrue(os.access(self.kit / name, os.X_OK), name)
        for path in self.kit.rglob("*"):
            if path.is_file():
                self.assertEqual((path.stat().st_mode & 0o022), 0, path)

    def test_derived_scripts_parse_and_pass_shellcheck(self):
        for name in SCRIPTS:
            self.assertEqual(subprocess.run(["bash", "-n", str(self.kit / name)], capture_output=True).returncode, 0, name)
            if shutil.which("shellcheck"):
                run = subprocess.run(["shellcheck", "-S", "warning", str(self.kit / name)], capture_output=True, text=True)
                self.assertEqual(run.returncode, 0, run.stdout)
        for name in ("seal-release.py", "normalize-mysql-dump.py"):
            compile(self.text(name), name, "exec")

    def test_every_derived_file_says_where_it_comes_from(self):
        for name in SCRIPTS + ["php-fpm/vasey-production.conf", "workers/vasey-production-workers.conf"]:
            self.assertIn("DERIVED by ops/production/build-kit.py from ops/staging/", self.text(name), name)
        self.assertTrue(self.text("provision.sh").startswith("#!/usr/bin/env bash\n# DERIVED"))

    def test_semantic_delta_is_small_and_exactly_where_the_differences_are(self):
        delta = build_kit.delta()
        sections = {}
        current = None
        for line in delta.splitlines():
            if line.startswith("--- staging/"):
                current = line.split()[1].removeprefix("staging/")
                sections[current] = {"hunks": 0, "added": 0, "removed": 0}
            elif current and line.startswith("@@"):
                sections[current]["hunks"] += 1
            elif current and line.startswith("+") and not line.startswith("+++"):
                sections[current]["added"] += 1
            elif current and line.startswith("-") and not line.startswith("---"):
                sections[current]["removed"] += 1
        # Files that are renames only have no delta at all.
        for untouched in ("release-step.sh", "backup.sh", "php-fpm/vasey-staging.conf"):
            self.assertNotIn(untouched, sections, untouched)  # an empty diff prints nothing
        self.assertEqual(sections["bin/vasey-staging-ctl"]["hunks"], 2)  # the required-variable list, and the probe with its comment
        self.assertLessEqual(sections["bin/vasey-staging-ctl"]["added"] + sections["bin/vasey-staging-ctl"]["removed"], 12)
        self.assertEqual(sections["workers/vasey-staging-workers.conf"]["hunks"], 5)
        self.assertLess(len(delta.splitlines()), 400)

    def test_the_committed_semantic_delta_snapshot_is_current(self):
        # The review surface is the snapshot: it must be exactly what `--delta` prints now, or a kit change went unrecorded.
        snapshot = REPO / "docs/verification/production-ops-kit-20261017/semantic-delta.diff"
        run = subprocess.run([sys.executable, "-I", str(BUILDER), "--delta"], capture_output=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        committed = snapshot.read_bytes()
        if run.stdout != committed:
            drift = "".join(list(difflib.unified_diff(committed.decode(errors="replace").splitlines(True),
                                                      run.stdout.decode(errors="replace").splitlines(True),
                                                      "committed semantic-delta.diff", "build-kit.py --delta", n=1))[:40])
            self.fail(f"{snapshot.relative_to(REPO)} is stale; regenerate it with "
                      f"`python3 -I ops/production/build-kit.py --delta > {snapshot.relative_to(REPO)}` and review the change:\n{drift}")

    def test_check_mode_builds_and_discards(self):
        run = subprocess.run([sys.executable, "-I", str(BUILDER), "--check"], capture_output=True, text=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("build-kit: ok, 13 files", run.stdout)


class AnchorsFailClosed(unittest.TestCase):
    """A staging change that touches a patch anchor must stop the build, not silently produce a different script."""

    def staging_copy(self):
        directory = tempfile.TemporaryDirectory(prefix="va-staging-copy-")
        self.addCleanup(directory.cleanup)
        copy = Path(directory.name) / "staging"
        shutil.copytree(STAGING, copy)
        return copy

    def build_fails(self, staging, expected):
        with tempfile.TemporaryDirectory() as out:
            with self.assertRaises(build_kit.BuildError) as raised:
                build_into(out, staging=staging)
            self.assertIn(expected, str(raised.exception))
            self.assertFalse((Path(out) / "kit").exists(), "a refused build must leave nothing behind")

    def test_every_replace_anchor_must_match_exactly_the_expected_number_of_times(self):
        for entry in build_kit.DERIVED:
            for patch in entry.patches:
                if not isinstance(patch, build_kit.Replace):
                    continue
                with self.subTest(file=entry.src, patch=patch.why):
                    staging = self.staging_copy()
                    path = staging / entry.src
                    original = path.read_text()
                    path.write_text(original.replace(patch.old, patch.old.replace(patch.old[:6], "XXXXXX", 1), 1))
                    self.build_fails(staging, "matched 0 time(s)" if patch.count == 1 else "matched")
                    path.write_text(original + "\n" + patch.old)
                    self.build_fails(staging, f"matched {patch.count + 1} time(s)")

    def test_markers_and_regexes_are_counted_too(self):
        for entry in build_kit.DERIVED:
            for patch in entry.patches:
                if isinstance(patch, build_kit.ReplaceBetween):
                    with self.subTest(file=entry.src, patch=patch.why):
                        staging = self.staging_copy()
                        path = staging / entry.src
                        original = path.read_text()
                        path.write_text(original.replace(patch.start, "# gone\n", 1))
                        self.build_fails(staging, "start marker matched 0")
                        path.write_text(original + patch.end)
                        self.build_fails(staging, "end marker matched 2")
                elif isinstance(patch, build_kit.Regex):
                    with self.subTest(file=entry.src, patch=patch.why):
                        staging = self.staging_copy()
                        path = staging / entry.src
                        path.write_text(path.read_text().replace("stdout_syslog", "x").replace("stdout_logfile=/var/log/vasey-staging/worker-media.log", "stdout_logfile=/dev/null", 1))
                        self.build_fails(staging, "matched 4 time(s), expected 5")

    def test_a_new_staging_only_token_stops_the_build(self):
        staging = self.staging_copy()
        path = staging / "backup.sh"
        path.write_text(path.read_text() + "\nVASEY_NEW=/etc/vasey-staging/htpasswd\n")
        self.build_fails(staging, "remains after the renames and patches")

    def test_the_output_directory_must_be_new_or_empty_and_not_a_symlink(self):
        with tempfile.TemporaryDirectory() as out:
            (Path(out) / "kit").mkdir()
            (Path(out) / "kit/keep").write_text("x")
            with self.assertRaises(build_kit.BuildError):
                build_into(out)
            (Path(out) / "link").symlink_to(Path(out) / "kit")
            with self.assertRaises(build_kit.BuildError):
                build_kit.build(Path(out) / "link")

    def test_a_missing_staging_file_is_a_refusal_not_a_traceback(self):
        staging = self.staging_copy()
        (staging / "seal-release.py").unlink()
        self.build_fails(staging, "cannot read")


class CrossFileConsistency(unittest.TestCase):
    """The helper names, paths and program names must agree across every derived file and template."""

    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix="va-prod-kit-")
        cls.kit = Path(cls.tmp.name) / "kit"
        build_kit.build(cls.kit)
        cls.t = {name: (cls.kit / name).read_text() for name in EXPECTED_FILES if not name.endswith((".py", ".sha256"))}

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def test_one_config_file_everywhere(self):
        self.assertIn("CONF=/etc/vasey-production/production.conf", self.t["bin/vasey-production-ctl"])
        self.assertIn("CONF=/etc/vasey-production/production.conf", self.t["release-step.sh"])
        self.assertIn("${VASEY_PRODUCTION_CONF:-/etc/vasey-production/production.conf}", self.t["forge-deploy.sh"])
        self.assertIn("${VASEY_PRODUCTION_CONF:-/etc/vasey-production/production.conf}", self.t["backup.sh"])
        self.assertIn("/etc/vasey-production/production.conf.tmp", self.t["provision.sh"])

    def test_helper_paths_in_ctl_and_backup_are_where_provision_installs_them(self):
        provision, ctl, backup = self.t["provision.sh"], self.t["bin/vasey-production-ctl"], self.t["backup.sh"]
        installed = set(re.findall(r'install -m 0[0-9]{3} -o root -g root "\$KIT/[^"]+" (/usr/local/[a-z/-]+[^ \n]*)', provision))
        self.assertEqual(installed, {"/usr/local/sbin/vasey-production-ctl", "/usr/local/sbin/vasey-production-backup",
                                     "/usr/local/libexec/vasey-production/normalize-mysql-dump.py",
                                     "/usr/local/libexec/vasey-production/seal-release.py",
                                     "/usr/local/libexec/vasey-production/release-step.sh",
                                     "/usr/local/libexec/vasey-production/forge-deploy.sh"})
        for path in ("BACKUP_BIN=/usr/local/sbin/vasey-production-backup", "SEALER=/usr/local/libexec/vasey-production/seal-release.py",
                     "RELEASE_STEP=/usr/local/libexec/vasey-production/release-step.sh"):
            self.assertIn(path, ctl)
        self.assertIn("CTL=/usr/local/sbin/vasey-production-ctl", backup)
        self.assertIn("NORMALIZER=/usr/local/libexec/vasey-production/normalize-mysql-dump.py", backup)
        self.assertIn("/usr/local/sbin/vasey-production-ctl", provision)  # the sudoers entry

    def test_supervisor_programs_match_the_control_helper(self):
        ctl_programs = re.search(r"^PROGRAMS=\((.*)\)$", self.t["bin/vasey-production-ctl"], re.M).group(1).split()
        workers = self.t["workers/vasey-production-workers.conf"]
        programs = re.findall(r"^\[program:([a-z-]+)\]", workers, re.M)
        self.assertEqual(ctl_programs, programs)
        self.assertEqual(programs, ["vasey-production-media", "vasey-production-payments", "vasey-production-contracts",
                                    "vasey-production-default", "vasey-production-scheduler"])
        self.assertIn("[group:vasey-production]", workers)
        self.assertIn("programs=" + ",".join(programs), workers)
        self.assertIn("vasey-production:*", self.t["bin/vasey-production-ctl"])
        self.assertEqual(self.t["provision.sh"].count("vasey-production:*"), 1)

    def test_sockets_agree_between_pools_nginx_and_provision(self):
        for socket_path in ("/run/php/vasey-production.sock", "/run/php/vasey-paid-delivery.sock"):
            self.assertIn(socket_path, self.t["nginx/vasey-production.conf"])
            self.assertIn(socket_path, self.t["provision.sh"])
        self.assertIn("listen = /run/php/vasey-production.sock", self.t["php-fpm/vasey-production.conf"])
        self.assertIn("listen = /run/php/vasey-paid-delivery.sock", self.t["php-fpm/vasey-paid-delivery.conf"])
        self.assertIn("[vasey-production]", self.t["php-fpm/vasey-production.conf"])
        self.assertIn('"vasey-production-fastcgi"', self.t["provision.sh"])  # created under /var/lib/nginx, 0700, for the nginx user
        self.assertIn("/var/lib/nginx/vasey-production-fastcgi", self.t["nginx/vasey-production.conf"])

    def test_the_validator_path_and_arguments_agree_between_deploy_ctl_and_release_step(self):
        for name in ("forge-deploy.sh", "release-step.sh", "bin/vasey-production-ctl"):
            self.assertIn("ops/production/validate-runtime.php", self.t[name], name)
            self.assertNotIn("ops/staging/validate-runtime.php", self.t[name], name)
        self.assertIn('VASEY_EXPECTED_APP_ENV=$APP_ENV_EXPECTED', self.t["provision.sh"])
        self.assertIn('APP_ENV_EXPECTED=production', self.t["provision.sh"])

    def test_production_does_not_force_mail_or_stripe_mode_but_keeps_the_holds_in_the_validator(self):
        deploy = self.t["forge-deploy.sh"]
        self.assertNotIn("need MAIL_MAILER", deploy)
        self.assertNotIn("need STRIPE_MODE", deploy)
        self.assertIn("need APP_ENV", deploy)
        self.assertIn("need LOG_CHANNEL stderr", deploy)
        self.assertIn("private-server-preflight.php", self.t["validate-runtime.php"])
        self.assertIn("--profile=production", self.t["validate-runtime.php"])
        self.assertNotIn("config('mail.default')", self.t["validate-runtime.php"])
        self.assertNotIn("payments.stripe.mode", self.t["validate-runtime.php"])

    def test_the_probe_sends_no_credentials(self):
        ctl = self.t["bin/vasey-production-ctl"]
        probe = ctl[ctl.index("probe() {"):ctl.index("as_app()")]
        self.assertNotIn("--netrc", probe)
        self.assertNotIn("-u ", probe)
        self.assertNotIn("PROBE", ctl)

    def test_provisioning_is_production_only_and_installs_no_commerce_units(self):
        provision = self.t["provision.sh"]
        self.assertIn('[ "$APP_ENV_EXPECTED" = production ]', provision)
        self.assertNotIn("--basic-auth-user", provision)
        self.assertNotIn("systemctl enable --now vasey", provision)
        self.assertNotIn("stripe-listen", provision)
        self.assertNotIn("test-commerce", provision)
        self.assertIn("[ \"$(head -n 1 /etc/clamav/clamd.conf 2>/dev/null)\" != '# VASEY.AUDIO clamd profile' ]", provision)

    def test_logging_is_journal_for_workers_and_files_have_logrotate(self):
        workers = self.t["workers/vasey-production-workers.conf"]
        self.assertEqual(workers.count("stdout_syslog=true"), 5)
        self.assertEqual(workers.count("stdout_logfile=NONE"), 5)
        self.assertNotIn("stdout_logfile=/", workers)
        self.assertIn("error_log  stderr error;", self.t["nginx/vasey-production.conf"])
        rotated = re.search(r"cat > /etc/logrotate.d/vasey-production <<'EOF'\n(.*?)EOF", self.t["provision.sh"], re.S).group(1)
        for log in ("/var/log/php8.4-fpm-vasey-*.log", "/var/log/vasey-production/backup.log", "/var/log/nginx/vasey-production-*.log"):
            self.assertIn(log, rotated)
        self.assertIn("vasey-production-access.log", self.t["nginx/vasey-production.conf"])
        self.assertIn("php_admin_value[error_log] = /var/log/php8.4-fpm-vasey-production-error.log", self.t["php-fpm/vasey-production.conf"])


class WorkerAndPoolContract(unittest.TestCase):
    """The same four supervised workers, per-minute scheduler and paid-delivery pool as staging."""

    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix="va-prod-kit-")
        cls.kit = Path(cls.tmp.name) / "kit"
        build_kit.build(cls.kit)

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def commands(self, text):
        return [re.sub(r"vasey-(staging|production)", "vasey-X", line) for line in text.splitlines() if line.startswith(("command=", "stopwaitsecs=", "numprocs=", "user=", "directory=", "autorestart=", "stopsignal=", "stopasgroup=", "killasgroup="))]

    def test_programs_commands_and_timeouts_equal_staging(self):
        staging = (STAGING / "workers/vasey-staging-workers.conf").read_text()
        production = (self.kit / "workers/vasey-production-workers.conf").read_text()
        self.assertEqual(self.commands(production), self.commands(staging))
        self.assertEqual(len(re.findall(r"^command=.*queue:work", production, re.M)), 4)
        self.assertEqual(len(re.findall(r"^command=.*artisan schedule:work$", production, re.M)), 1)
        for queue, flags in (("media", "--timeout=900 --tries=3"), ("payments", "--timeout=90 --tries=1"),
                             ("contracts", "--timeout=90 --tries=1"), ("default,inquiry-alerts", "--timeout=120 --tries=1")):
            self.assertRegex(production, rf"--queue={re.escape(queue)} {re.escape(flags)}")

    @unittest.skipUnless(shutil.which("supervisord"), "supervisor is not installed")
    def test_supervisor_parses_the_rendered_programs_without_warnings(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            rendered = (self.kit / "workers/vasey-production-workers.conf").read_text()
            for old, new in (("__APP_USER__", "nobody"), ("__PHP_BIN__", "/usr/bin/php8.4"), ("__VASEY_ROOT__", str(root / "srv"))):
                rendered = rendered.replace(old, new)
            (root / "workers.conf").write_text(rendered)
            (root / "supervisord.conf").write_text(f"[supervisord]\nlogfile={root}/s.log\npidfile={root}/s.pid\n[unix_http_server]\nfile={root}/s.sock\n"
                                                   f"[rpcinterface:supervisor]\nsupervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface\n"
                                                   f"[supervisorctl]\nserverurl=unix://{root}/s.sock\n[include]\nfiles={root}/workers.conf\n")
            script = ("import json,sys\nfrom supervisor.options import ServerOptions\no=ServerOptions()\n"
                      "o.realize(args=['-c', sys.argv[1]], doc='')\no.process_config(do_usage=False)\n"
                      "print(json.dumps({'warnings': o.parse_warnings, 'groups': [[g.name, [[p.name, p.stdout_logfile, p.stdout_syslog, p.redirect_stderr, p.stopwaitsecs] for p in g.process_configs]] for g in o.process_group_configs]}))\n")
            run = subprocess.run([sys.executable, "-c", script, str(root / "supervisord.conf")], capture_output=True, text=True,
                                 env={"PATH": "/usr/bin:/bin", "PYTHONPATH": "/usr/lib/python3/dist-packages"})
            self.assertEqual(run.returncode, 0, run.stderr)
            parsed = json.loads(run.stdout)
            self.assertEqual(parsed["warnings"], [])
            self.assertEqual([g[0] for g in parsed["groups"]], ["vasey-production"])
            programs = parsed["groups"][0][1]
            self.assertEqual([p[0] for p in programs], ["vasey-production-media", "vasey-production-payments", "vasey-production-contracts",
                                                         "vasey-production-default", "vasey-production-scheduler"])
            for _, logfile, syslog, redirect, _wait in programs:
                self.assertIsNone(logfile)
                self.assertTrue(syslog)
                self.assertTrue(redirect)
            self.assertEqual([p[4] for p in programs], [960, 120, 120, 960, 120])

    def test_the_paid_delivery_pool_is_the_staging_pool(self):
        production = (self.kit / "php-fpm/vasey-paid-delivery.conf").read_text()
        self.assertEqual(production, (STAGING / "php-fpm/vasey-paid-delivery.conf").read_text())
        for line in ("request_terminate_timeout = 7800s", "listen.backlog = 8", "php_admin_value[max_execution_time] = 0",
                     "pm.max_children = 8", "listen = /run/php/vasey-paid-delivery.sock"):
            self.assertIn(line, production)

    def test_the_default_pool_keeps_the_staging_limits(self):
        production = (self.kit / "php-fpm/vasey-production.conf").read_text()
        for line in ("request_terminate_timeout = 300s", "php_admin_value[upload_max_filesize] = 201M",
                     "php_admin_value[post_max_size] = 210M", "pm.max_children = 8", "php_admin_value[max_execution_time] = 120"):
            self.assertIn(line, production)

    @unittest.skipUnless(shutil.which("php-fpm8.4"), "php-fpm8.4 is not installed")
    def test_php_fpm_accepts_both_rendered_pools(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "pool.d").mkdir()
            (root / "srv/tmp").mkdir(parents=True)
            for name in ("vasey-production.conf", "vasey-paid-delivery.conf"):
                text = (self.kit / "php-fpm" / name).read_text()
                for old, new in (("__APP_USER__", "nobody"), ("__APP_GROUP__", "nogroup"), ("__NGINX_USER__", "www-data"),
                                 ("__NGINX_GROUP__", "www-data"), ("__VASEY_ROOT__", str(root / "srv"))):
                    text = text.replace(old, new)
                (root / "pool.d" / name).write_text(text)
            (root / "php-fpm.conf").write_text(f"[global]\npid = {root}/fpm.pid\nerror_log = {root}/fpm.log\ninclude={root}/pool.d/*.conf\n")
            run = subprocess.run(["php-fpm8.4", "-t", "-y", str(root / "php-fpm.conf")], capture_output=True, text=True)
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertIn("test is successful", run.stderr)


class NginxTemplate(unittest.TestCase):
    CONF = (PROD / "nginx/vasey-production.conf").read_text()
    STAGING_CONF = (STAGING / "nginx/vasey-staging.conf").read_text()

    @staticmethod
    def blocks(text, pattern):
        """The directive lines of every `location ... {` block whose opening line matches pattern (quoted regexes may hold braces)."""
        masked = re.sub(r'"[^"\n]*"', lambda m: "_" * len(m.group(0)), text)
        found = []
        for match in re.finditer(r"^\s*location[^\n{]*\{[^\n]*$", masked, re.M):
            if not re.search(pattern, text[match.start():match.end()]):
                continue
            depth, end = 0, match.end()
            for position in range(match.start(), len(masked)):
                depth += masked[position] == "{"
                depth -= masked[position] == "}"
                if depth == 0 and masked[position] == "}":
                    end = position
                    break
            body = text[match.start():end + 1].splitlines()[1:-1]
            found.append([l.split("#")[0].strip() for l in body if l.split("#")[0].strip()])
        return found

    def test_no_basic_auth_no_noindex_and_no_webhook_exemption(self):
        directives = "\n".join(l.split("#")[0] for l in self.CONF.splitlines())
        for forbidden in ("auth_basic", "htpasswd", "X-Robots-Tag", "/webhooks/stripe", "limit_except", "auth_basic_user_file"):
            self.assertNotIn(forbidden, directives)

    def test_paid_delivery_contract_is_identical_to_staging(self):
        for pattern in (r"paid-grants/authorizations", r"paid-grants/origins", r"livewire-"):
            ours, theirs = self.blocks(self.CONF, pattern), self.blocks(self.STAGING_CONF, pattern)
            self.assertEqual(len(ours), 1, pattern)
            normalize = lambda block: [l.replace("vasey-staging", "vasey-X").replace("vasey-production", "vasey-X") for l in block]
            self.assertEqual(normalize(ours[0]), normalize(theirs[0]), pattern)

    def test_the_documented_timeouts_buffering_and_temp_cap(self):
        redeem = " ".join(self.blocks(self.CONF, r"authorizations")[0])
        document = " ".join(self.blocks(self.CONF, r"origins")[0])
        for need in ("fastcgi_read_timeout 480s;", "fastcgi_buffering on;", "fastcgi_max_temp_file_size 1024m;",
                     "fastcgi_connect_timeout 5s;", "fastcgi_pass unix:/run/php/vasey-paid-delivery.sock;", "client_max_body_size 1m;"):
            self.assertIn(need, redeem)
        for need in ("fastcgi_read_timeout 3900s;", "fastcgi_connect_timeout 5s;", "fastcgi_pass unix:/run/php/vasey-paid-delivery.sock;"):
            self.assertIn(need, document)
        self.assertIn("client_max_body_size 210m;", " ".join(self.blocks(self.CONF, r"livewire-")[0]))
        self.assertRegex(self.CONF, r"(?m)^    client_max_body_size 16m;")
        self.assertRegex(self.CONF, r"(?m)^    fastcgi_max_temp_file_size 1024m;")
        self.assertRegex(self.CONF, r"(?m)^    fastcgi_temp_path /var/lib/nginx/vasey-production-fastcgi 1 2;")

    def test_private_storage_is_never_public(self):
        directives = "\n".join(l.split("#")[0] for l in self.CONF.splitlines())
        self.assertRegex(directives, r"root __VASEY_ROOT__/current/public;")
        for forbidden in ("alias ", "autoindex on", "/storage", "storage/app", "private"):
            self.assertNotIn(forbidden, directives)
        self.assertIn("location ~ /\\.(?!well-known/) { return 404; }", self.CONF)
        self.assertIn("location ~ \\.php$ { return 404; }", self.CONF)

    def test_placeholders_are_exactly_the_documented_set(self):
        self.assertEqual(set(re.findall(r"__[A-Z_]+__", self.CONF)),
                         {"__PRODUCTION_HOST__", "__VASEY_ROOT__", "__SSL_CERT__", "__SSL_KEY__", "__FORGE_CONF__"})

    def test_every_fastcgi_socket_is_a_pool_the_kit_installs(self):
        self.assertEqual(set(re.findall(r"fastcgi_pass unix:(\S+);", self.CONF)),
                         {"/run/php/vasey-production.sock", "/run/php/vasey-paid-delivery.sock"})

    def test_proxy_trust_is_off_and_only_illustrated(self):
        live = [l for l in self.CONF.splitlines() if re.match(r"\s*(set_real_ip_from|real_ip_header|real_ip_recursive)\b", l)]
        self.assertEqual(live, [])
        self.assertIn("trustProxies is not configured", self.CONF)

    @unittest.skipUnless(shutil.which("nginx"), "nginx is not installed")
    def test_nginx_accepts_the_rendered_template(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for sub in ("before", "server", "after"):
                (root / "forge-conf/site" / sub).mkdir(parents=True)
            subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(root / "k.pem"), "-out", str(root / "c.pem"),
                            "-days", "1", "-subj", "/CN=shop.example.invalid"], check=True, capture_output=True)
            shutil.copy("/etc/nginx/fastcgi_params", root / "fastcgi_params")
            text = self.CONF
            for old, new in (("__PRODUCTION_HOST__", "shop.example.invalid"), ("__VASEY_ROOT__", "/srv/vasey-production"),
                             ("__SSL_CERT__", str(root / "c.pem")), ("__SSL_KEY__", str(root / "k.pem")), ("__FORGE_CONF__", "site")):
                text = text.replace(old, new)
            try:
                socket.socket(socket.AF_INET6).close()
            except OSError:  # this sandbox has no IPv6; the syntax check below still covers the whole file
                text = "\n".join(l for l in text.splitlines() if "listen [::]" not in l)
            (root / "site.conf").write_text(text)
            (root / "nginx.conf").write_text(f"pid {root}/nginx.pid;\nerror_log {root}/error.log;\nevents {{}}\nhttp {{\n  access_log off;\n  include {root}/site.conf;\n}}\n")
            run = subprocess.run(["nginx", "-t", "-p", str(root), "-c", str(root / "nginx.conf")], capture_output=True, text=True)
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertIn("syntax is ok", run.stderr)
            self.assertIn("test is successful", run.stderr)


class EnvironmentComposition(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location("compose_env", PROD / "compose-env.py")
        cls.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.module)

    def pairs(self, text):
        return [tuple(line.split("=", 1)) for line in text.splitlines() if line and not line.startswith("#")]

    def test_composition_is_unique_complete_and_secret_free(self):
        text = self.module.compose("shop.example.invalid")
        keys = [k for k, _ in self.pairs(text)]
        self.assertEqual(len(keys), len(set(keys)))
        values = dict(self.pairs(text))
        self.assertEqual(values["APP_URL"], "https://shop.example.invalid")
        self.assertEqual(values["APP_ENV"], "production")
        self.assertEqual(values["DB_HOST"], "127.0.0.1")
        self.assertEqual(values["LOG_CHANNEL"], "stderr")
        for secret in ("APP_KEY", "DB_PASSWORD", "MEDIA_TAG_PATH", "MEDIA_TAG_SHA256", "STRIPE_TEST_SECRET_KEY", "STRIPE_WEBHOOK_SECRET",
                       "PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY"):
            self.assertEqual(values.get(secret, ""), "", secret)
        every = set(re.findall(r"^([A-Z][A-Z0-9_]*)=", (REPO / "ops/private-server/env.example").read_text(), re.M)) | \
            set(re.findall(r"^([A-Z][A-Z0-9_]*)=", (PROD / "env.production.example").read_text(), re.M))
        self.assertTrue(every <= set(keys), sorted(every - set(keys)))

    def test_every_family_switch_is_off_in_the_composition(self):
        values = dict(self.pairs(self.module.compose("shop.example.invalid")))
        enabled = {k: v for k, v in values.items() if k.endswith("_ENABLED") and v not in ("false",)}
        self.assertEqual(enabled, {}, enabled)
        self.assertIn(values["STRIPE_MODE"], ("test",))
        self.assertEqual(values["PRODUCTION_CHECKOUT_FUNDS_MODE"], "")

    def test_a_conflicting_layer_is_refused_and_a_blank_is_filled(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "a").write_text("APP_URL=\nX=1\nY=\nSAME=z\n")
            (root / "b").write_text("Y=2\nSAME=z\nNEW=3\n")
            (root / "c").write_text("X=9\n")
            original = self.module.LAYERS
            try:
                self.module.LAYERS = [("a", root / "a"), ("b", root / "b")]
                values = dict(self.pairs(self.module.compose("shop.example.invalid")))
                self.assertEqual((values["X"], values["Y"], values["NEW"], values["SAME"]), ("1", "2", "3", "z"))
                self.module.LAYERS = [("a", root / "a"), ("c", root / "c")]
                with self.assertRaises(self.module.ComposeError) as raised:
                    self.module.compose("shop.example.invalid")
                self.assertIn("never overridden", str(raised.exception))
                (root / "b").write_text("Y=2\nY=3\n")
                self.module.LAYERS = [("a", root / "a"), ("b", root / "b")]
                with self.assertRaises(self.module.ComposeError):
                    self.module.compose("shop.example.invalid")
                (root / "a").write_text("APP_URL=https://preset.example\n")
                self.module.LAYERS = [("a", root / "a")]
                with self.assertRaises(self.module.ComposeError):
                    self.module.compose("shop.example.invalid")
            finally:
                self.module.LAYERS = original

    def test_a_bad_host_is_refused(self):
        for host in ("Shop.Example", "shop", "shop.example.com/x", "-a.example.com", ""):
            with self.assertRaises(self.module.ComposeError):
                self.module.compose(host)

    def test_output_file_is_created_new_and_private(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "env"
            run = subprocess.run([sys.executable, "-I", str(PROD / "compose-env.py"), "--host", "shop.example.invalid", "--output", str(target)],
                                 capture_output=True, text=True)
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertEqual(oct(target.stat().st_mode & 0o777), "0o600")
            again = subprocess.run([sys.executable, "-I", str(PROD / "compose-env.py"), "--host", "shop.example.invalid", "--output", str(target)],
                                   capture_output=True, text=True)
            self.assertEqual(again.returncode, 3)
            self.assertIn("must not exist", again.stderr)
            link = Path(directory) / "link"
            link.symlink_to(Path(directory) / "elsewhere")
            self.assertEqual(subprocess.run([sys.executable, "-I", str(PROD / "compose-env.py"), "--host", "shop.example.invalid", "--output", str(link)],
                                            capture_output=True, text=True).returncode, 3)

    @unittest.skipUnless(PHP and (REPO / "vendor/autoload.php").exists(), "PHP and Composer dependencies are required")
    def test_the_composition_is_blocked_until_filled_and_then_passes_preflight_and_the_validator(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "env"
            subprocess.run([sys.executable, "-I", str(PROD / "compose-env.py"), "--host", "shop.example.invalid", "--output", str(target)], check=True)
            preflight = [PHP, str(REPO / "scripts/ops/private-server-preflight.php"), "--env-file", str(target), "--profile=production"]
            blank = json.loads(subprocess.run(preflight, capture_output=True, text=True).stdout)
            self.assertEqual(blank["result"], "BLOCKED")
            blocked = {c["id"] for c in blank["checks"] if c["status"] == "blocked"}
            self.assertEqual(blocked, {"configured_app_key", "configured_db_password", "configured_media_tag_path", "configured_media_tag_sha256",
                                       "application_key_shape_only", "media_tag_relative_path", "media_tag_hash_shape_only"})
            filled = target.read_text()
            for key, value in (("APP_KEY", "base64:" + "A" * 43 + "="), ("DB_PASSWORD", "SyntheticOnlyPassword1234567890"),
                               ("MEDIA_TAG_PATH", "branding/tag.wav"), ("MEDIA_TAG_SHA256", "a" * 64)):
                filled = re.sub(rf"(?m)^{key}=.*$", f"{key}={value}", filled)
            target.write_text(filled)
            passed = json.loads(subprocess.run(preflight, capture_output=True, text=True).stdout)
            self.assertEqual(passed["result"], "FILE_CHECKS_PASSED", passed)
            admitted = subprocess.run([PHP, str(PROD / "validate-runtime.php"), str(target), "production", "shop.example.invalid", "vasey_production"],
                                      cwd=REPO, env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")}, capture_output=True, text=True)
            self.assertEqual(admitted.returncode, 0, admitted.stderr)
            self.assertIn("PASS runtime.profile (production", admitted.stdout)


@unittest.skipUnless(IS_ROOT, "the provisioning wrapper insists on root even to refuse")
class ProvisioningWrapper(unittest.TestCase):
    def run_wrapper(self, *args):
        before = set(Path("/root").glob("vasey-production-kit.*"))
        run = subprocess.run(["bash", str(PROD / "provision.sh"), *args], capture_output=True, text=True, timeout=120)
        self.assertEqual(set(Path("/root").glob("vasey-production-kit.*")), before, "the assembled kit must be removed")
        return run

    def test_only_production_is_accepted_as_the_environment(self):
        for env in ("staging", "local", "testing"):
            run = self.run_wrapper("--host", "shop.example.invalid", "--app-user", "nobody", "--root", "/srv/vasey-never", "--app-env", env)
            self.assertEqual(run.returncode, 1)
            self.assertIn("--app-env must be production", run.stderr)
        self.assertFalse(Path("/srv/vasey-never").exists())

    def test_the_basic_auth_option_is_gone(self):
        run = self.run_wrapper("--host", "shop.example.invalid", "--basic-auth-user", "x")
        self.assertEqual(run.returncode, 1)
        self.assertIn("unknown option --basic-auth-user", run.stderr)

    def test_unsafe_roots_and_hosts_are_refused_before_any_change(self):
        for args, message in ((["--host", "shop.example.invalid", "--app-user", "nobody", "--root", "/tmp/x"], "ancestry must not be group/world writable"),
                              (["--host", "Shop.Example", "--app-user", "nobody"], "--host must be a lowercase DNS name"),
                              (["--host", "shop.example.invalid", "--app-user", "root"], "application account must be nonroot")):
            run = self.run_wrapper(*args)
            self.assertEqual(run.returncode, 1, args)
            self.assertIn(message, run.stderr)
        self.assertFalse(Path("/etc/vasey-production").exists())

    def test_keep_build_retains_the_assembled_kit(self):
        before = set(Path("/root").glob("vasey-production-kit.*"))
        subprocess.run(["bash", str(PROD / "provision.sh"), "--keep-build", "--host", "shop.example.invalid", "--basic-auth-user", "x"],
                       capture_output=True, text=True, timeout=120)
        kept = set(Path("/root").glob("vasey-production-kit.*")) - before
        try:
            self.assertEqual(len(kept), 1)
            self.assertTrue((next(iter(kept)) / "kit/provision.sh").exists())
            self.assertEqual(oct(next(iter(kept)).stat().st_mode & 0o777), "0o700")
        finally:
            for path in kept:
                shutil.rmtree(path)


class Docs(unittest.TestCase):
    def test_readme_links_and_named_files_exist(self):
        readme = (PROD / "README.md").read_text()
        for target in re.findall(r"\]\((\.\./[^)#]+|[A-Za-z0-9_./-]+\.(?:md|sh|py|php|conf|example))(?:#[^)]*)?\)", readme):
            self.assertTrue((PROD / target).exists(), target)
        for name in ("build-kit.py", "compose-env.py", "validate-runtime.php", "env.host-overlay.example", "provision.sh", "nginx/vasey-production.conf"):
            self.assertIn(name, readme)
            self.assertTrue((PROD / name).exists(), name)


if __name__ == "__main__":
    unittest.main()
