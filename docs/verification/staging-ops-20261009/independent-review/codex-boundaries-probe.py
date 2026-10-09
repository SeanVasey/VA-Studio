#!/usr/bin/env python3
"""Independent synthetic Codex-repair boundary probes; no real root, service or database I/O."""
import argparse
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[4]
PHP = "/workspace/.va-studio-toolchain/standalone/bin/php8.4"
SOURCE_SHA = None


def read_source(path):
    return subprocess.check_output(["git", "show", SOURCE_SHA + ":" + path], cwd=REPO, text=True)


def sealer_module():
    module = types.ModuleType("independent_actual_sealer")
    module.__file__ = str(REPO / "ops/staging/seal-release.py")
    exec(compile(read_source("ops/staging/seal-release.py"), module.__file__, "exec"), module.__dict__)
    return module


def key_definitions():
    source = read_source("ops/staging/backup.sh")
    return source[source.index("valid_env_key() {"):source.index("\n# ---- snapshot:")]


class CodexBoundaryProbes(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="vasey-codex-independent.")
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)

    def shell(self, text, **environment):
        setup = 'set -eu\ndie() { printf "%s\\n" "$*" >&2; exit 1; }\n'
        return subprocess.run(["bash", "-c", setup + text],
                              env={"PATH": "/usr/bin:/bin", "VASEY_PHP": PHP} | environment,
                              capture_output=True, text=True, timeout=5)

    def test_multiline_value_cannot_supply_a_fake_effective_key(self):
        for quote in ('"',):
            with self.subTest(quote=quote):
                candidate = self.base / "candidate.env"
                candidate.write_text("CONTACT_FROM_NAME=" + quote + "multiline\nAPP_KEY=base64:" + "A" * 43 + "=\n" + quote + "\n")
                candidate.chmod(0o600)
                dotenv = subprocess.run([PHP, "-r", 'require "vendor/autoload.php"; '
                                         '$v=Dotenv\\Dotenv::createArrayBacked($argv[1],$argv[2])->load(); '
                                         'exit(array_key_exists("APP_KEY",$v)?1:0);', str(self.base), candidate.name],
                                        cwd=REPO, capture_output=True, text=True, timeout=5)
                self.assertEqual(dotenv.returncode, 0, "real Dotenv must confirm this is only another value")
                run = self.shell(key_definitions() + '\nvalid_env_key "$CANDIDATE"', CANDIDATE=str(candidate))
                self.assertNotEqual(run.returncode, 0, "text inside another value was admitted as recoverable APP_KEY")
                self.assertEqual(run.stdout, "")

    def test_environment_hash_must_cover_environment_not_another_valid_file(self):
        backup = self.base / "backup"
        backup.mkdir()
        for name in ("database.sql", "private.tar"):
            (backup / name).write_bytes(b"synthetic baseline")
            checksum = subprocess.check_output(["sha256sum", name], cwd=backup, text=True)
            (backup / (name + ".sha256")).write_text(checksum)
        (backup / "MANIFEST").write_text("release_sha=" + "a" * 40 + "\n")
        (backup / "env.backup").write_text("APP_KEY=base64:" + "A" * 43 + "=\n")
        (backup / "env.backup.sha256").write_text((backup / "database.sql.sha256").read_text())
        (backup / "RESTORE_CHECK").write_text("stale success")
        source = read_source("ops/staging/backup.sh")
        prefix = source[source.index("restore_check() {"):source.index("  install -d -m 0711")]
        run = self.shell(key_definitions() + "\n" + prefix + '\n}\nrestore_check "$BACKUP"', BACKUP=str(backup))
        self.assertNotEqual(run.returncode, 0, "env.backup.sha256 verified database.sql and skipped the environment")
        self.assertFalse((backup / "RESTORE_CHECK").exists())

    def test_special_candidate_is_refused_without_blocking_on_fifo_open(self):
        evidence = self.base / "evidence"
        evidence.mkdir(mode=0o700)
        candidate = evidence / "candidate"
        os.mkfifo(candidate, 0o600)
        output = self.base / "protected-output"
        output.write_text("previous protected bytes")
        output.chmod(0o600)
        frozen = self.base / "actual-sealer.py"
        frozen.write_text(read_source("ops/staging/seal-release.py"))
        script = ('import importlib.util,os,sys; s=importlib.util.spec_from_file_location("actual",sys.argv[1]); '
                  'm=importlib.util.module_from_spec(s); s.loader.exec_module(m); '
                  'm.ROOT_UID=os.getuid();m.ROOT_GID=os.getgid(); '
                  'm.stage_environment(sys.argv[2],sys.argv[3],sys.argv[4],os.getuid(),os.getgid())')
        process = subprocess.Popen([sys.executable, "-c", script, str(frozen), str(evidence), str(candidate), str(output)],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            _, error = process.communicate(timeout=0.5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.communicate(timeout=2)
            self.fail("root capture blocked before its regular-file refusal; owned process killed")
        self.assertNotEqual(process.returncode, 0, "FIFO was accepted")
        self.assertIn("candidate", error)
        self.assertEqual(output.read_text(), "previous protected bytes")

    def test_privileged_cli_never_delegates_to_a_root_application_account(self):
        module = sealer_module()
        cases = (["seal", str(self.base), "root", "root"],
                 ["verify", str(self.base), "root", "root"],
                 ["stage-env", str(self.base), str(self.base / "candidate"), str(self.base / "output"), "root", "root"])
        for arguments in cases:
            with self.subTest(action=arguments[0]), patch.object(module.os, "geteuid", return_value=0), \
                    patch.object(module.sys, "argv", ["actual-sealer"] + arguments), \
                    patch.object(module, "run") as run, patch.object(module, "stage_environment") as capture:
                try:
                    module.main()
                except (ValueError, OSError):
                    pass
                self.assertFalse(run.called or capture.called, "root was accepted as the application identity")

    def release_fixture(self):
        root = self.base / ("a" * 40)
        for path in ("app", "vendor", "public/build", "bootstrap/cache", "storage/framework", "storage/logs", "storage/app/private"):
            (root / path).mkdir(parents=True, exist_ok=True)
        (root / "app/code.php").write_text("synthetic approved source")
        (root / ".env").write_text("synthetic private environment")
        (root / ".env").chmod(0o600)
        return root

    def test_old_source_and_environment_descriptors_cannot_change_fresh_inodes(self):
        root = self.release_fixture()
        module = sealer_module()
        paths = (root / "app/code.php", root / ".env")
        handles = [path.open("r+b") for path in paths]
        try:
            before = [path.read_bytes() for path in paths]
            with patch.object(module, "ROOT_UID", os.getuid()), patch.object(module, "ROOT_GID", os.getgid()):
                module.seal_release(root, os.getuid(), os.getgid())
            for path, handle, expected in zip(paths, handles, before):
                handle.seek(0)
                handle.write(b"old open descriptor mutation")
                handle.flush()
                self.assertEqual(path.read_bytes(), expected)
                self.assertNotEqual(path.stat().st_ino, os.fstat(handle.fileno()).st_ino)
                self.assertEqual(path.stat().st_mode & 0o222, 0)
        finally:
            for handle in handles:
                handle.close()

    def test_code_changed_during_capture_refuses_before_installing_a_staged_file(self):
        root = self.release_fixture()
        path = root / "app/code.php"
        inode = path.stat().st_ino
        module = sealer_module()
        actual_read = module.os.read
        changed = False
        with path.open("r+b") as writer:
            def race(descriptor, limit):
                nonlocal changed
                data = actual_read(descriptor, limit)
                if not changed and os.fstat(descriptor).st_ino == inode:
                    changed = True
                    writer.seek(0)
                    writer.write(b"mutated during capture")
                    writer.flush()
                    os.fsync(writer.fileno())
                return data
            with patch.object(module, "ROOT_UID", os.getuid()), patch.object(module, "ROOT_GID", os.getgid()), \
                    patch.object(module.os, "read", race):
                with self.assertRaises((ValueError, OSError)):
                    module.seal_release(root, os.getuid(), os.getgid())
        self.assertTrue(changed)
        self.assertEqual(path.stat().st_ino, inode)
        self.assertFalse(list(root.rglob(".sealed-*")))

    def test_environment_changed_during_capture_keeps_the_protected_target(self):
        evidence = self.base / "evidence"
        evidence.mkdir(mode=0o700)
        candidate = evidence / "candidate"
        candidate.write_text("synthetic exact candidate")
        candidate.chmod(0o600)
        output = self.base / "output"
        output.write_text("previous protected target")
        output.chmod(0o600)
        inode = candidate.stat().st_ino
        module = sealer_module()
        actual_read = module.os.read
        changed = False
        with candidate.open("r+b") as writer:
            def race(descriptor, limit):
                nonlocal changed
                data = actual_read(descriptor, limit)
                if not changed and os.fstat(descriptor).st_ino == inode:
                    changed = True
                    writer.write(b"changed private candidate")
                    writer.flush()
                    os.fsync(writer.fileno())
                return data
            with patch.object(module, "ROOT_UID", os.getuid()), patch.object(module, "ROOT_GID", os.getgid()), \
                    patch.object(module.os, "read", race):
                with self.assertRaises((ValueError, OSError)):
                    module.stage_environment(evidence, candidate, output, os.getuid(), os.getgid())
        self.assertTrue(changed)
        self.assertEqual(output.read_text(), "previous protected target")

    def test_runtime_file_bytes_inodes_and_modes_are_not_traversed_by_sealing(self):
        root = self.release_fixture()
        retained = root / "storage/app/private/retained"
        retained.write_bytes(b"synthetic retained bytes")
        retained.chmod(0o600)
        os.link(retained, root / "storage/app/private/retained-alias")
        before = retained.stat()
        module = sealer_module()
        with patch.object(module, "ROOT_UID", os.getuid()), patch.object(module, "ROOT_GID", os.getgid()):
            module.seal_release(root, os.getuid(), os.getgid())
        after = retained.stat()
        self.assertEqual((after.st_ino, after.st_mode, after.st_uid, after.st_gid),
                         (before.st_ino, before.st_mode, before.st_uid, before.st_gid))
        self.assertEqual(retained.read_bytes(), b"synthetic retained bytes")

    def test_configuration_cache_failure_retains_protected_new_env_for_snapshot_recovery(self):
        # Reuse only the authority fixture; execute the actual configure body and genuine validator.
        if SOURCE_SHA != subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip():
            self.skipTest("configuration fixture requires the selected current source")
        spec = importlib.util.spec_from_file_location("independent_configuration_fixture", REPO / "tests/ops/test_staging_protected_configuration.py")
        fixture = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fixture)
        fixture.PHP = PHP
        case = fixture.ProtectedConfigurationTest()
        case.setUp()
        try:
            artisan = case.release / "artisan"
            artisan.chmod(0o600)
            artisan.write_text("<?php exit(73);\n")
            with patch.object(case.module, "ROOT_UID", os.getuid()), patch.object(case.module, "ROOT_GID", os.getgid()):
                case.module.seal_release(case.release, os.getuid(), os.getgid())
            new = case.previous + "CONTACT_FROM_NAME=synthetic-replacement\n"
            original_inode = (case.release / ".env").stat().st_ino
            run = case.configure(new)
            self.assertNotEqual(run.returncode, 0)
            self.assertIn("configuration cache failed", run.stderr)
            self.assertEqual((case.release / ".env").read_text(), new)
            self.assertNotEqual((case.release / ".env").stat().st_ino, original_inode)
            self.assertEqual((case.release / ".env").stat().st_mode & 0o777, 0o440)
            self.assertNotIn("SyntheticPrivateRuntimeMarker", run.stdout + run.stderr)
        finally:
            case.doCleanups()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-sha")
    options, remaining = parser.parse_known_args()
    SOURCE_SHA = options.source_sha or subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO, text=True).strip()
    print("Exact assessed source: " + SOURCE_SHA, flush=True)
    unittest.main(argv=[__file__] + remaining, verbosity=2)
