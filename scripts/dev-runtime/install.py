#!/usr/bin/env python3
"""Verify and unpack the disposable runtime, optionally proving native PHP_BINARY children locally."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tarfile
import tempfile


MIB = 1024 * 1024
MAX_ARCHIVE = 128 * MIB
MAX_EXPANDED = 1024 * MIB


def digest(path: Path) -> str:
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def safe_path(name: str) -> PurePosixPath:
    path = PurePosixPath(name)
    if path.is_absolute() or str(path) != name or not path.parts or any(p in ("", ".", "..") for p in path.parts):
        raise ValueError("Unsafe or noncanonical archive path")
    if path.parts[0] not in {"native", "vendor", "runtime.json", "files.json"}:
        raise ValueError("Unexpected archive root")
    return path


def read_json(path: Path, limit: int):
    if path.is_symlink() or not path.is_file() or path.stat().st_size > limit:
        raise ValueError("Invalid bounded metadata file")
    return json.loads(path.read_bytes())


def assemble(parts: Path, archive: Path, manifest: dict) -> None:
    if manifest.get("schema_version") != 1 or not re.fullmatch(r"[0-9a-f]{40}", manifest.get("candidate", "")):
        raise ValueError("Invalid bundle manifest")
    expected = manifest.get("parts", [])
    if not isinstance(expected, list) or not 1 <= len(expected) <= 6:
        raise ValueError("Invalid part count")
    total = 0
    with archive.open("xb") as output:
        for index, part in enumerate(expected, 1):
            if part.get("name") != f"part-{index:02d}.bin" or not isinstance(part.get("size"), int) or not 1 <= part["size"] <= 22 * MIB:
                raise ValueError("Invalid part identity or size")
            source = parts / part["name"]
            if source.is_symlink() or not source.is_file() or source.stat().st_size != part["size"] or digest(source) != part["sha256"]:
                raise ValueError("Part checksum mismatch")
            total += part["size"]
            if total > MAX_ARCHIVE:
                raise ValueError("Archive size exceeds cap")
            with source.open("rb") as stream:
                shutil.copyfileobj(stream, output, MIB)
    if total != manifest["archive"]["size"] or digest(archive) != manifest["archive"]["sha256"]:
        raise ValueError("Archive checksum mismatch")


def extract(archive: Path, destination: Path, manifest: dict) -> None:
    seen = set()
    expanded = 0
    declared_tar_size = manifest["archive"].get("uncompressed_tar_size")
    if not isinstance(declared_tar_size, int) or not 0 < declared_tar_size <= MAX_EXPANDED + 64 * MIB:
        raise ValueError("Expanded archive metadata exceeds cap")
    # Stream through a bounded temporary tar, never allowing zstd to inflate without a byte limit.
    with tempfile.TemporaryFile() as temporary:
        process = subprocess.Popen(["zstd", "--decompress", "--stdout", str(archive)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            received = 0
            while chunk := process.stdout.read(MIB):
                received += len(chunk)
                if received > declared_tar_size:
                    raise ValueError("Decompressed archive exceeds its recorded size")
                temporary.write(chunk)
            stderr = process.stderr.read(4096)
            if process.wait(timeout=30) != 0 or stderr or received != declared_tar_size:
                raise ValueError("Decompression failed or size changed")
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
            process.stdout.close()
            process.stderr.close()
        temporary.seek(0)
        with tarfile.open(fileobj=temporary, mode="r:") as entries:
            for item in entries:
                path = safe_path(item.name)
                if item.name in seen or len(seen) >= 100000 or not (item.isdir() or item.isfile()):
                    raise ValueError("Duplicate, excessive or special archive entry")
                seen.add(item.name)
                target = destination.joinpath(*path.parts)
                if item.isdir():
                    target.mkdir(mode=0o700, parents=True, exist_ok=True)
                    continue
                if item.mode not in (0o644, 0o755) or item.size < 0:
                    raise ValueError("Unsafe archive file mode")
                expanded += item.size
                if expanded > MAX_EXPANDED:
                    raise ValueError("Expanded file total exceeds cap")
                target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                with entries.extractfile(item) as source, target.open("xb") as output:
                    shutil.copyfileobj(source, output, MIB)
                target.chmod(item.mode)
    files_path = destination / "files.json"
    if digest(files_path) != manifest["files_manifest_sha256"]:
        raise ValueError("File inventory hash mismatch")
    inventory = read_json(files_path, 24 * MIB)
    if inventory.get("schema_version") != 1 or not isinstance(inventory.get("files"), list):
        raise ValueError("Invalid file inventory")
    expected = set()
    for item in inventory["files"]:
        name = str(safe_path(item["path"]))
        if name in expected or name == "files.json":
            raise ValueError("Duplicate file identity")
        expected.add(name)
        target = destination / name
        if not target.is_file() or target.is_symlink() or target.stat().st_size != item["size"] or digest(target) != item["sha256"]:
            raise ValueError("Extracted file checksum mismatch")
        if target.stat().st_mode & 0o777 != item["mode"]:
            raise ValueError("Extracted file mode mismatch")
    actual = {path.relative_to(destination).as_posix() for path in destination.rglob("*") if path.is_file()}
    if actual != expected | {"files.json"}:
        raise ValueError("Unlisted or missing extracted files")


def install_configuration(destination: Path, runtime: dict, project: Path | None) -> None:
    if os.geteuid() != 0 or os.uname().machine != "x86_64" or 'VERSION_ID="24.04"' not in Path("/etc/os-release").read_text():
        raise ValueError("Local install requires this disposable Ubuntu 24.04 x86_64 root workspace")
    if runtime.get("default_config_dir") != "/etc/php/8.4/cli" or runtime.get("architecture") != "x86_64" or runtime.get("ubuntu") != "24.04":
        raise ValueError("Unsupported bundle ABI or configuration path")
    if "/" not in str(destination) or any(character in str(destination) for character in '\n\r";'):
        raise ValueError("Unsafe configuration path")
    # Refuse ALL existing PHP config. No existing host file or shared library is replaced.
    config_root = Path("/etc/php")
    if config_root.exists() or config_root.is_symlink():
        raise ValueError("Existing /etc/php configuration; refusing any overwrite")
    modules = runtime["extension_files"]
    if not isinstance(modules, list) or any(not re.fullmatch(r"[a-z0-9_]+\.so", name) for name in modules):
        raise ValueError("Invalid extension filenames")
    for name in modules:
        if not (destination / "native/extensions" / name).is_file():
            raise ValueError("Missing extension")
    ini = '\n'.join(['; Disposable VASEY.AUDIO developer runtime only.', 'date.timezone=UTC', 'memory_limit=512M',
                     'display_errors=stderr', 'log_errors=Off', 'error_reporting=E_ALL', 'opcache.enable_cli=0',
                     'extension_dir="' + str(destination / 'native/extensions') + '"',
                     *('extension=' + name for name in modules), ''])
    config_dir = config_root / "8.4/cli"
    created_root = False
    try:
        config_root.mkdir(mode=0o755)
        created_root = True
        config_dir.mkdir(mode=0o755, parents=True)
        (config_dir / "php.ini").write_text(ini)
        php = str(destination / "native/bin/php")
        code = r'''$child = <<<'PHP'
$required = json_decode($argv[1], true, 8, JSON_THROW_ON_ERROR);
foreach ($required as $module) { if (!extension_loaded($module)) { exit(21); } }
$pdo = new PDO('sqlite::memory:');
if ($pdo->query('select 1')->fetchColumn() != 1) { exit(22); }
echo json_encode(['binary'=>PHP_BINARY,'ini'=>php_ini_loaded_file(),'extensions'=>ini_get('extension_dir'),'php'=>PHP_VERSION]);
PHP;
$process = proc_open([PHP_BINARY, '-r', $child, $argv[1]], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, []);
if (!is_resource($process)) { exit(23); }
fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
if (proc_close($process)!==0 || $err!=='') { fwrite(STDERR,'Clean child failed. '.$err); exit(24); }
echo $out;'''
        result = subprocess.run([php, "-r", code, json.dumps(runtime["required_modules"])], env={"LANG": "C", "LC_ALL": "C", "TZ": "UTC"},
                                text=True, capture_output=True, timeout=30)
        if result.returncode != 0 or result.stderr:
            raise ValueError("Clean PHP_BINARY child startup failed: " + result.stderr[:2000])
        child = json.loads(result.stdout)
        if child != {"binary": php, "ini": str(config_dir / "php.ini"), "extensions": str(destination / "native/extensions"), "php": runtime["php_version"]}:
            raise ValueError("Clean child did not use the exact bundled PHP and extensions")
        # Composer's relative autoload paths are checked against the intended local source tree below.
        if project is not None:
            vendor = project / "vendor"
            if vendor.exists() or vendor.is_symlink():
                raise ValueError("Project already has vendor; refusing replacement")
            if digest(project / "composer.lock") != runtime["composer_lock_sha256"]:
                raise ValueError("Project lock differs from the runtime artifact")
            # Move vendor, rather than linking: Composer calculates the root as dirname(__DIR__, 2).
            shutil.move(str(destination / "vendor"), str(vendor))
            try:
                loaded = subprocess.run([php, "-r", "require $argv[1]; echo class_exists('Illuminate\\Foundation\\Application') ? 'autoload-ok' : 'bad';", str(vendor / "autoload.php")],
                                        env={"LANG": "C", "LC_ALL": "C", "TZ": "UTC"}, text=True, capture_output=True, timeout=30)
                if loaded.returncode or loaded.stdout != "autoload-ok" or loaded.stderr:
                    raise ValueError("Candidate vendor autoload failed: " + loaded.stderr[:2000])
            except BaseException:
                shutil.move(str(vendor), str(destination / "vendor"))
                raise
        print(json.dumps({"clean_php_binary_child": "passed", "php": child["php"], "php_binary": php, "config": child["ini"],
                          "vendor_project": str(project) if project else None}, sort_keys=True))
    except BaseException:
        if created_root:
            shutil.rmtree(config_root)
        raise


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--parts", required=True, type=Path)
    parser.add_argument("--destination", required=True, type=Path)
    parser.add_argument("--project", type=Path)
    choice = parser.add_mutually_exclusive_group(required=True)
    choice.add_argument("--verify-only", action="store_true")
    choice.add_argument("--install", action="store_true")
    args = parser.parse_args()
    parts = args.parts.resolve(strict=True)
    destination = args.destination.absolute()
    if destination.exists() or destination.is_symlink() or destination.parent.resolve() != destination.parent:
        raise ValueError("Destination must be new and have a canonical existing parent")
    manifest = read_json(parts / "manifest.json", MIB)
    destination.mkdir(mode=0o700)
    try:
        with tempfile.TemporaryDirectory(prefix="developer-php-extract-") as temporary:
            archive = Path(temporary) / "bundle.tar.zst"
            assemble(parts, archive, manifest)
            extract(archive, destination, manifest)
        runtime = read_json(destination / "runtime.json", MIB)
        if runtime.get("candidate") != manifest["candidate"] or runtime.get("composer_lock_sha256") != manifest["composer_lock_sha256"]:
            raise ValueError("Runtime identity does not match archive manifest")
        print(json.dumps({"verified_candidate": runtime["candidate"], "archive_sha256": manifest["archive"]["sha256"], "destination": str(destination)}, sort_keys=True))
        if args.install:
            install_configuration(destination, runtime, args.project.resolve(strict=True) if args.project else None)
    except BaseException:
        # Only the newly created extraction directory is removed; no caller directory is replaced.
        shutil.rmtree(destination)
        raise


if __name__ == "__main__":
    main()
