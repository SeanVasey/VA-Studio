#!/usr/bin/env python3
"""Exercise archive integrity and path confinement without installing or executing PHP."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("runtime_install", Path(__file__).with_name("install.py"))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class RuntimeArchiveTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.destination = self.root / "extracted"
        self.destination.mkdir()

    def tearDown(self):
        self.temp.cleanup()

    def archive(self, entries=None, change_inventory=False):
        data = b"synthetic bundled binary, never executed\n"
        files = {"schema_version": 1, "files": [{"path": "native/bin/php", "size": len(data),
                  "sha256": hashlib.sha256(data).hexdigest(), "mode": 0o755, "origin": "synthetic"}]}
        if change_inventory:
            files["files"][0]["sha256"] = "0" * 64
        encoded = json.dumps(files, sort_keys=True).encode()
        tar = self.root / "test.tar"
        with tarfile.open(tar, "w") as output:
            for name, content, kind in entries or [("native/bin/php", data, tarfile.REGTYPE), ("files.json", encoded, tarfile.REGTYPE)]:
                item = tarfile.TarInfo(name)
                item.type = kind
                item.mode = 0o755 if name == "native/bin/php" else 0o644
                item.size = len(content) if kind == tarfile.REGTYPE else 0
                if kind == tarfile.SYMTYPE:
                    item.linkname = "/etc/passwd"
                output.addfile(item, io.BytesIO(content) if kind == tarfile.REGTYPE else None)
        archive = self.root / "test.tar.zst"
        subprocess.run(["zstd", "--quiet", "-f", str(tar), "-o", str(archive)], check=True)
        manifest = {"schema_version": 1, "candidate": "a" * 40,
                    "files_manifest_sha256": hashlib.sha256(encoded).hexdigest(),
                    "archive": {"size": archive.stat().st_size, "sha256": module.digest(archive), "uncompressed_tar_size": tar.stat().st_size}}
        return archive, manifest

    def test_valid_archive_has_exact_verified_bytes_and_modes(self):
        archive, manifest = self.archive()
        module.extract(archive, self.destination, manifest)
        self.assertEqual(b"synthetic bundled binary, never executed\n", (self.destination / "native/bin/php").read_bytes())
        self.assertEqual(0o755, (self.destination / "native/bin/php").stat().st_mode & 0o777)

    def test_traversal_absolute_unexpected_and_noncanonical_paths_are_rejected(self):
        for name in ("../outside", "/etc/passwd", "native/../outside", "native//bin/php", "storage/private", "./native/bin/php"):
            with self.subTest(name=name), self.assertRaises(ValueError):
                module.safe_path(name)

    def test_symlink_is_rejected_before_writing_its_target(self):
        archive, manifest = self.archive([("native/bin/php", b"", tarfile.SYMTYPE)])
        with self.assertRaises(ValueError):
            module.extract(archive, self.destination, manifest)
        self.assertFalse((self.destination / "native/bin/php").exists())

    def test_duplicate_entry_is_rejected(self):
        archive, manifest = self.archive([("native/bin/php", b"one", tarfile.REGTYPE), ("native/bin/php", b"two", tarfile.REGTYPE)])
        with self.assertRaises(ValueError):
            module.extract(archive, self.destination, manifest)

    def test_file_hash_mismatch_is_rejected(self):
        archive, manifest = self.archive(change_inventory=True)
        with self.assertRaises(ValueError):
            module.extract(archive, self.destination, manifest)

    def test_decompression_cannot_exceed_recorded_size(self):
        archive, manifest = self.archive()
        manifest["archive"]["uncompressed_tar_size"] = 1
        with self.assertRaises(ValueError):
            module.extract(archive, self.destination, manifest)

    def test_part_identity_and_hash_are_required_before_assembly(self):
        archive, manifest = self.archive()
        data = archive.read_bytes()
        (self.root / "part-01.bin").write_bytes(data)
        manifest["parts"] = [{"name": "part-01.bin", "size": len(data), "sha256": hashlib.sha256(data).hexdigest()}]
        assembled = self.root / "assembled.zst"
        module.assemble(self.root, assembled, manifest)
        self.assertEqual(data, assembled.read_bytes())
        manifest["parts"][0]["sha256"] = "0" * 64
        with self.assertRaises(ValueError):
            module.assemble(self.root, self.root / "bad.zst", manifest)


if __name__ == "__main__":
    unittest.main()
