"""Actual build helper with native local Composer hooks; npm/runtime/host authority bounded."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import shlex
import subprocess
import tempfile
import unittest


REPO = Path(__file__).resolve().parents[4]
PHP = Path("/workspace/.va-studio-toolchain/standalone/bin/php8.4")
COMPOSER = Path("/workspace/.va-studio-toolchain/root/usr/bin/composer")
PARSER = argparse.ArgumentParser()
PARSER.add_argument("--source-sha", required=True)
ARGUMENTS, TEST_ARGUMENTS = PARSER.parse_known_args()
SOURCE_SHA = ARGUMENTS.source_sha


class ComposerOrderingProbe(unittest.TestCase):
    def test_native_package_discovery_hook_reads_frozen_candidate_before_npm(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mirror = root / "mirror"
            mirror.mkdir()
            environment = {"PATH": "/usr/bin:/bin", "LC_ALL": "C", "COMPOSER_HOME": str(root / "composer-home"),
                           "COMPOSER_CACHE_DIR": str(root / "composer-cache"), "COMPOSER_DISABLE_NETWORK": "1"}
            (root / "composer-home").mkdir()
            (root / "composer-cache").mkdir()
            candidate = root / "frozen.env"
            profile = b"APP_ENV=staging\nAPP_DEBUG=false\nSTRIPE_MODE=test\nPROFILE_MARKER=frozen-candidate\n"
            candidate.write_bytes(profile)
            candidate.chmod(0o440)
            profile_hash = hashlib.sha256(profile).hexdigest()
            (mirror / ".gitignore").write_text("/vendor/\n/public/build/\n.env\n")
            (mirror / "package-lock.json").write_text("{}\n")
            package = mirror / "local-package"
            package.mkdir()
            (package / "composer.json").write_text(json.dumps({"name": "independent/local-hook-fixture", "version": "1.0.0", "type": "library", "license": "proprietary"}))
            (package / "fixture.txt").write_text("local dependency; no download\n")
            manifest = {
                "name": "independent/composer-ordering", "type": "project", "license": "proprietary",
                "require": {"php": "^8.4", "independent/local-hook-fixture": "1.0.0"},
                "repositories": [{"type": "path", "url": "local-package", "options": {"symlink": False}},
                                 {"packagist.org": False}],
                "scripts": {"post-autoload-dump": ["@php artisan package:discover --ansi"]},
            }
            (mirror / "composer.json").write_text(json.dumps(manifest))
            artisan = '''<?php
if (($argv[1] ?? '') !== 'package:discover') { exit(92); }
$bytes = @file_get_contents(__DIR__ . '/.env');
$receipt = ['present' => $bytes !== false, 'mode' => $bytes === false ? null : (fileperms(__DIR__ . '/.env') & 0777),
    'profile_matches' => $bytes !== false && hash('sha256', $bytes) === EXPECTED_HASH,
    'inherited_app_env' => getenv('APP_ENV') !== false];
file_put_contents(__DIR__ . '/hook-receipt.json', json_encode($receipt));
if (!$receipt['profile_matches'] || $receipt['mode'] !== 0600 || $receipt['inherited_app_env']) {
    fwrite(STDERR, "frozen environment was not available at package discovery\\n"); exit(23);
}
'''.replace("EXPECTED_HASH", repr(profile_hash))
            (mirror / "artisan").write_text(artisan)
            validator = mirror / "ops/staging/validate-runtime.php"
            validator.parent.mkdir(parents=True)
            validator.write_text("<?php\nexit(hash_file('sha256', $argv[1]) === " + repr(profile_hash) + " ? 0 : 24);\n")
            locked = subprocess.run([str(PHP), str(COMPOSER), "update", "--no-install", "--no-scripts", "--no-plugins", "--no-audit", "--no-interaction", "--quiet"],
                                    cwd=mirror, env=environment, capture_output=True, text=True, timeout=20)
            self.assertEqual(locked.returncode, 0, locked.stdout + locked.stderr)
            subprocess.run(["git", "init", "-q", str(mirror)], check=True, env=environment)
            subprocess.run(["git", "-C", str(mirror), "add", "."], check=True, env=environment)
            subprocess.run(["git", "-C", str(mirror), "-c", "user.name=Independent fixture", "-c", "user.email=fixture@example.invalid", "commit", "-qm", "local Composer hook project"], check=True, env=environment)
            sha = subprocess.check_output(["git", "-C", str(mirror), "rev-parse", "HEAD"], text=True, env=environment).strip()
            # A changing mirror profile is intentionally unrelated to the protected frozen input.
            (mirror / ".env").write_text("APP_ENV=production\nPROFILE_MARKER=mirror-edited-after-freeze\n")
            release = root / "releases" / sha
            release.mkdir(parents=True)
            evidence = root / "evidence"
            evidence.mkdir()
            binary = root / "bin"
            binary.mkdir()
            (binary / "composer").symlink_to(COMPOSER)
            (binary / "npm").write_text("#!/bin/bash\nset -eu\n[ -f hook-receipt.json ] || exit 25\nmkdir -p public/build\nprintf '{}' > public/build/manifest.json\n")
            (binary / "npm").chmod(0o755)
            config = root / "staging.conf"
            settings = {"VASEY_ROOT": str(root), "VASEY_PHP": str(PHP), "VASEY_MIRROR": str(mirror),
                        "VASEY_APP_USER": pwd.getpwuid(os.getuid()).pw_name, "VASEY_EXPECTED_APP_ENV": "staging",
                        "VASEY_STAGING_HOST": "synthetic.example.invalid", "VASEY_DB_NAME": "synthetic_database",
                        "PATH": str(binary) + ":/usr/bin:/bin"}
            config.write_text("".join(f"{key}={shlex.quote(value)}\n" for key, value in settings.items()))
            helper = root / "release-step.sh"
            helper.write_text(subprocess.check_output(["git", "-C", str(REPO), "show", f"{SOURCE_SHA}:ops/staging/release-step.sh"], text=True)
                              .replace("/etc/vasey-staging/staging.conf", str(config)))
            run = subprocess.run(["bash", str(helper), "build", sha, str(evidence), str(candidate)], env=environment,
                                 capture_output=True, text=True, timeout=20)
            self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
            self.assertEqual(json.loads((release / "hook-receipt.json").read_text()),
                             {"present": True, "mode": 0o600, "profile_matches": True, "inherited_app_env": False})
            self.assertEqual((release / ".env").read_bytes(), profile)
            self.assertEqual(candidate.read_bytes(), profile)
            self.assertEqual(candidate.stat().st_mode & 0o777, 0o440)
            self.assertTrue((evidence / "build.sha256").is_file())
            self.assertTrue((release / "public/build/manifest.json").is_file())


if __name__ == "__main__":
    print(f"Exact assessed source: {SOURCE_SHA}", flush=True)
    version = subprocess.check_output([str(PHP), str(COMPOSER), "--version"], text=True, stderr=subprocess.STDOUT)
    print(version.rstrip(), flush=True)
    unittest.main(argv=[__file__] + TEST_ARGUMENTS, verbosity=2)
