"""Actual configure action and Laravel admission; ownership/services/cache build are bounded fixtures."""
import importlib.util
import json
import os
import grp
from pathlib import Path
import pwd
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[2]
PHP = os.environ.get("PHP_BIN") or shutil.which("php8.4") or shutil.which("php")


class ProtectedConfigurationTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        self.sha = "a" * 40
        self.release = self.root / "releases" / self.sha
        for name in ("bootstrap/cache", "storage/framework", "storage/logs", "storage/app/private", "ops/staging"):
            (self.release / name).mkdir(parents=True, exist_ok=True)
        (self.root / "evidence").mkdir(mode=0o750)
        self.previous = "\n".join([
            "APP_ENV=local", "APP_DEBUG=false", "APP_URL=https://staging.synthetic.invalid",
            "APP_KEY=base64:" + "A" * 43 + "=", "APP_MAINTENANCE_DRIVER=file",
            "SESSION_DRIVER=database", "SESSION_SECURE_COOKIE=true", "SESSION_HTTP_ONLY=true",
            "CACHE_STORE=database", "QUEUE_CONNECTION=database", "DB_QUEUE_RETRY_AFTER=1200",
            "DB_CONNECTION=mysql", "DB_HOST=127.0.0.1", "DB_DATABASE=vasey_staging",
            "DB_USERNAME=vasey_app", "DB_PASSWORD=SyntheticPrivateRuntimeMarker",
            "FILESYSTEM_DISK=local", "MAIL_MAILER=log", "STRIPE_MODE=test",
            # The validator requires a probe-proven CLI PHP for FPM renderer children (M-16, runtime.php_cli_binary).
            "VASEY_PHP_CLI_BINARY=" + os.path.realpath(PHP), "",
        ])
        (self.release / ".env").write_text(self.previous)
        (self.release / ".env").chmod(0o600)
        (self.release / "storage/framework/maintenance.php").write_text("synthetic maintenance")
        # Wrapper invokes the genuine repository validator with the candidate/previous arguments.
        validator = "<?php require " + json.dumps(str(REPO / "ops/staging/validate-runtime.php")) + ";"
        (self.release / "ops/staging/validate-runtime.php").write_text(validator)
        (self.release / "artisan").write_text("""<?php
file_put_contents(__DIR__.'/storage/framework/cache-command', $argv[1].PHP_EOL, FILE_APPEND);
""")
        spec = importlib.util.spec_from_file_location("configuration_sealer", REPO / "ops/staging/seal-release.py")
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)
        with patch.object(self.module, "ROOT_UID", os.getuid()), patch.object(self.module, "ROOT_GID", os.getgid()):
            self.module.seal_release(self.release, os.getuid(), os.getgid())
        self.driver = self.root / "authority-sealer.py"
        self.driver.write_text("""import importlib.util,os,sys
spec=importlib.util.spec_from_file_location('actual_sealer',sys.argv[1])
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
module.ROOT_UID=os.getuid();module.ROOT_GID=os.getgid()
if sys.argv[2]=='stage-env':
    module.stage_environment(sys.argv[3],sys.argv[4],sys.argv[5],os.getuid(),os.getgid())
elif sys.argv[2]=='verify':
    module.run(sys.argv[3],os.getuid(),os.getgid(),False)
else: raise ValueError('unexpected fixture operation')
""")
        source = (REPO / "ops/staging/bin/vasey-staging-ctl").read_text()
        self.definitions = source[source.index("valid_sha()"):source.index("# The sudo entry point")]
        self.environment = {
            "PATH": "/usr/bin:/bin", "ROOT": str(self.root), "RELEASES": str(self.root / "releases"),
            "CURRENT": str(self.root / "current"), "VASEY_PHP": PHP,
            "VASEY_APP_USER": pwd.getpwuid(os.getuid()).pw_name,
            "VASEY_APP_GROUP": grp.getgrgid(os.getgid()).gr_name,
            "VASEY_EXPECTED_APP_ENV": "local", "VASEY_STAGING_HOST": "staging.synthetic.invalid",
            "VASEY_DB_NAME": "vasey_staging", "SHA": self.sha, "CUR": str(self.release),
            "SEALER_FIXTURE": str(self.driver), "SEALER_SOURCE": str(REPO / "ops/staging/seal-release.py"),
            "FIXTURE_OPEN": "0", "FIXTURE_WRITER": "0",
        }

    def configure(self, body, **overrides):
        candidate = self.root / "evidence/candidate.env"
        candidate.write_text(body)
        candidate.chmod(0o600)
        fixtures = """
die() { echo "$*" >&2; exit 1; }
writer_barrier() { [ "$FIXTURE_OPEN" = 0 ]; }
workers_stopped() { [ "$FIXTURE_WRITER" = 0 ]; }
web_stopped() { return 0; }
protected_dir() { :; }  # Root ancestry is an authority fixture; actual sealer metadata/modes still run.
served_release() { printf '%s\\n' "$CUR"; }
seal_tool() { python3 "$SEALER_FIXTURE" "$SEALER_SOURCE" "$@"; }
as_app() { "$@"; }
"""
        run = subprocess.run(["bash", "-eu", "-c", self.definitions + fixtures +
                              '\ncmd_configure "$SHA" "$CANDIDATE"'],
                             env={**self.environment, "CANDIDATE": str(candidate), **overrides},
                             capture_output=True, text=True, timeout=30)
        self.assertNotIn("SyntheticPrivateRuntimeMarker", run.stdout + run.stderr)
        self.assertFalse(list(self.release.glob(".env-candidate.*")), "private staging file was not cleaned")
        return run

    def test_valid_configuration_installs_fresh_read_only_environment_after_real_admission(self):
        candidate = self.previous + "CONTACT_FROM_NAME=synthetic-new-value\n"
        original_inode = (self.release / ".env").stat().st_ino
        run = self.configure(candidate)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual((self.release / ".env").read_text(), candidate)
        self.assertEqual((self.release / ".env").stat().st_mode & 0o777, 0o440)
        self.assertNotEqual((self.release / ".env").stat().st_ino, original_inode)
        # Routes are cached too: the panel's MFA page middleware is compiled into the route cache, so a refresh that
        # changes APP_ENV (local -> staging) must not serve routes built under the old environment (B2 review C1).
        self.assertEqual((self.release / "storage/framework/cache-command").read_text(), "config:cache\nroute:cache\n")

    def test_unsafe_flags_or_key_rotation_keep_original_environment_and_do_not_build_cache(self):
        for body in (self.previous + "APP_DEBUG=true\n",
                     self.previous + 'PRODUCTION_CHECKOUT_ENABLED="true"\n',
                     self.previous.replace("A" * 43, "B" * 43)):
            with self.subTest(case=body.splitlines()[-1].split("=")[0]):
                run = self.configure(body)
                self.assertNotEqual(run.returncode, 0)
                self.assertEqual((self.release / ".env").read_text(), self.previous)
                self.assertFalse((self.release / "storage/framework/cache-command").exists())

    def test_open_admission_or_active_writer_refuses_before_environment_changes(self):
        for refusal in ({"FIXTURE_OPEN": "1"}, {"FIXTURE_WRITER": "1"}):
            run = self.configure(self.previous + "CONTACT_FROM_NAME=new\n", **refusal)
            self.assertNotEqual(run.returncode, 0)
            self.assertEqual((self.release / ".env").read_text(), self.previous)
            self.assertFalse((self.release / "storage/framework/cache-command").exists())


if __name__ == "__main__":
    unittest.main(verbosity=2)
