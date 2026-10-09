#!/usr/bin/env python3
"""Bounded independent adversarial probes: never executes privileged mount/service commands."""
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[4]
CTL = REPO / "ops/staging/bin/vasey-staging-ctl"
DEPLOY = REPO / "ops/staging/forge-deploy.sh"
BACKUP = REPO / "ops/staging/backup.sh"
PHP = "/workspace/.va-studio-toolchain/standalone/bin/php8.4"


def bash(source, env=None):
    return subprocess.run(["bash", "-eu", "-c", source], text=True, capture_output=True,
                          env=os.environ | (env or {}))


def ctl_functions():
    source = CTL.read_text()
    return source[source.index("valid_sha()"):source.rindex('\ncase "${1:-}" in')]


def env_validation():
    source = DEPLOY.read_text()
    start = source.index('envget()')
    end = source.index('step "runtime .env passes')
    return source[start:end]


def valid_environment(extra=""):
    # Entirely synthetic credentials, no account or provider I/O.
    return "\n".join([
        "APP_ENV=local", "APP_DEBUG=false", "APP_URL=https://staging.example.invalid",
        "SESSION_SECURE_COOKIE=true", "SESSION_DRIVER=database", "CACHE_STORE=database",
        "QUEUE_CONNECTION=database", "DB_CONNECTION=mysql", "DB_HOST=127.0.0.1",
        "DB_DATABASE=vasey_staging", "FILESYSTEM_DISK=local", "MAIL_MAILER=log",
        "STRIPE_MODE=test", "APP_MAINTENANCE_DRIVER=file", "APP_KEY=base64:" + "A" * 43 + "=",
        "DB_USERNAME=vasey_app", "DB_PASSWORD=synthetic-review-only", "DB_QUEUE_RETRY_AFTER=1200",
        extra, "",
    ])


class IndependentStagingOpsProbes(unittest.TestCase):
    def test_detach_refuses_symlink_to_nonrelease_mount(self):
        with tempfile.TemporaryDirectory(prefix="vasey-ops-review-") as temp:
            root = Path(temp)
            sha = "a" * 40
            target = root / "releases" / sha / "storage/app/private"
            target.parent.mkdir(parents=True)
            target.symlink_to("/proc")
            trace = root / "umount-trace"
            # Actual mountpoint reads /proc; umount is an explicit harmless function substitute.
            run = bash(ctl_functions() + '\numount() { printf "%s\\n" "$*" >> "$TRACE"; return 73; }\n'
                       + 'die() { printf "%s\\n" "$*" >&2; exit 1; }\ncmd_detach "$SHA"',
                       {"RELEASES": str(root / "releases"), "PRIVATE": str(root / "private"),
                        "CURRENT": str(root / "current"), "SHA": sha, "TRACE": str(trace)})
            self.assertNotEqual(run.returncode, 0)
            self.assertFalse(trace.exists(), "helper dispatched umount through an app-writable symlink to /proc")

    def test_application_deploy_can_activate_under_provisioned_parent(self):
        provision = (REPO / "ops/staging/provision.sh").read_text()
        self.assertIn('install -d -m 0755 -o root -g root "$ROOT"', provision)
        source = DEPLOY.read_text()
        # A bounded privileged switch action is a valid repair. Probe the original direct writes otherwise.
        if re.search(r'"\$\{CTL\[@\]\}" switch "\$SHA"', source):
            return
        activation = source[source.index('ln -sfn "$REL" "$CURRENT.next"'):
                            source.index('[ "$(readlink -f "$CURRENT")" = "$REL"')]
        with tempfile.TemporaryDirectory(prefix="vasey-ops-review-") as temp:
            parent = Path(temp) / "root-owned-equivalent"
            parent.mkdir(mode=0o555)
            try:
                run = bash(activation, {"REL": str(Path(temp) / "release"), "CURRENT": str(parent / "current")})
                self.assertEqual(run.returncode, 0,
                                 "nonroot activation denied: provisioned root:root 0755 likewise denies app directory writes")
            finally:
                parent.chmod(0o700)

    def test_validator_refuses_effective_quoted_production_flag(self):
        with tempfile.TemporaryDirectory(prefix="vasey-ops-review-") as temp:
            path = Path(temp) / ".env"
            path.write_text(valid_environment('PRODUCTION_CHECKOUT_ENABLED="true"'))
            runtime = subprocess.run([PHP, "-r",
                'require $argv[1]."/vendor/autoload.php"; '
                'Dotenv\\Dotenv::create(Illuminate\\Support\\Env::getRepository(), $argv[2])->load(); '
                'echo Illuminate\\Support\\Env::get("PRODUCTION_CHECKOUT_ENABLED") === true ? "enabled" : "disabled";',
                str(REPO), temp], text=True, capture_output=True)
            self.assertEqual(runtime.returncode, 0, runtime.stderr)
            self.assertEqual(runtime.stdout, "enabled")
            run = bash('die() { printf "%s\\n" "$*" >&2; exit 1; }\n' + env_validation(),
                       {"RUNTIME_ENV": str(path), "VASEY_EXPECTED_APP_ENV": "local",
                        "VASEY_STAGING_HOST": "staging.example.invalid", "VASEY_DB_NAME": "vasey_staging"})
            self.assertNotEqual(run.returncode, 0, "validator accepted a Laravel-effective enabled production checkout flag")

    def test_charset_normalization_preserves_row_bytes(self):
        source = BACKUP.read_text()
        match = re.search(r"local norm='([^']+)'", source)
        self.assertIsNotNone(match)
        original = "INSERT INTO `terms` VALUES ('A CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');\n"
        altered = "INSERT INTO `terms` VALUES ('A COLLATE utf8mb4_unicode_ci');\n"
        def normalize(data):
            return subprocess.run(["sed", match.group(1)], input=data, text=True, capture_output=True, check=True).stdout
        self.assertNotEqual(normalize(original), normalize(altered),
                            "global charset normalization equates different retained INSERT row data")

    def test_failed_reproof_invalidates_previous_success_marker(self):
        source = BACKUP.read_text()
        functions = source[source.index('RC_WORK=""'):source.index('\nship()')]
        with tempfile.TemporaryDirectory(prefix="vasey-ops-review-") as temp:
            backup = Path(temp)
            (backup / "database.sql").write_text("synthetic-broken-dump")
            (backup / "private.tar").write_text("synthetic-broken-archive")
            for name in ("database.sql", "private.tar"):
                (backup / (name + ".sha256")).write_text("0" * 64 + "  " + name + "\n")
            marker = backup / "RESTORE_CHECK"
            marker.write_text("result=RESTORE_VERIFIED\n")
            run = bash('die() { printf "%s\\n" "$*" >&2; exit 1; }\n' + functions + '\nrestore_check "$BK"',
                       {"BK": str(backup)})
            self.assertNotEqual(run.returncode, 0)
            self.assertFalse(marker.exists() and "RESTORE_VERIFIED" in marker.read_text(),
                             "failed integrity reproof leaves the stale RESTORE_VERIFIED marker intact")

    def test_deploy_does_not_claim_stopped_writers_from_phase_flag(self):
        source = DEPLOY.read_text()
        on_exit = source[source.index('on_exit()'):source.index('trap on_exit EXIT')]
        # This is the exact possible state after quiesce fails on HTTP proof, or partial worker restart.
        run = bash(on_exit + '\nQUIESCED=1\ntrap on_exit EXIT\nexit 73')
        self.assertEqual(run.returncode, 73)
        self.assertNotIn("writers are stopped", run.stderr,
                         "phase flag alone falsely reports proven quiescence after failed quiesce/resume")

    def test_environment_refresh_saves_old_environment_before_replacement(self):
        source = DEPLOY.read_text()
        start = source.index('if [ -e "$REL" ]; then')
        end = source.index('install -d -m 0750 "$ROOT/evidence/')
        branch = source[start:end]
        with tempfile.TemporaryDirectory(prefix="vasey-ops-review-") as temp:
            root = Path(temp)
            rel = root / ("a" * 40)
            rel.mkdir()
            previous = "APP_KEY=synthetic-old-review-key\n"
            (rel / ".env").write_text(previous)
            env = root / "new.env"
            env.write_text("APP_KEY=synthetic-new-review-key\n")
            (root / "current").symlink_to(rel)
            trace = root / "ctl-trace"
            shell = 'step() { :; }\ndie() { printf "%s\\n" "$*" >&2; exit 1; }\n' \
                + 'ctl_stub() { printf "%s:%s\\n" "$1" "$(cat "$REL/.env")" >> "$TRACE"; }\n' \
                + 'php_stub() { :; }\nCTL=(ctl_stub)\nPHP=php_stub\n' + branch
            run = bash(shell, {"ROOT": str(root), "REL": str(rel), "CURRENT": str(root / "current"),
                               "RUNTIME_ENV": str(env), "SHA": "a" * 40, "TRACE": str(trace)})
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertIn("snapshot:" + previous.strip(), trace.read_text(),
                          "environment-only refresh overwrites the served key/configuration without a recoverable pre-change snapshot")


if __name__ == "__main__":
    unittest.main(verbosity=2)
