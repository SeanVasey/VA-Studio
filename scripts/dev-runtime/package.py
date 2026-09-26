#!/usr/bin/env python3
"""Build a candidate-bound development bundle; never package the application tree or runner configuration."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import tempfile


MIB = 1024 * 1024
MAX_ARCHIVE = 128 * MIB
CHUNK = 22 * MIB
MAX_EXPANDED = 1024 * MIB
GLIBC = {"libc.so.6", "libm.so.6", "libpthread.so.0", "libdl.so.2", "librt.so.1",
         "libresolv.so.2", "libutil.so.1", "libanl.so.1", "ld-linux-x86-64.so.2"}
# Dependency order matters for dlopen modules. Everything else is a built-in or intentionally absent.
MODULES = ["pdo", "mysqlnd", "xml", "ctype", "curl", "dom", "fileinfo", "iconv", "intl", "mbstring",
           "bcmath", "gd", "pdo_mysql", "pdo_sqlite", "sqlite3", "phar", "posix", "pcntl",
           "simplexml", "tokenizer", "xmlreader", "xmlwriter", "zip"]


def run(*args: str, **kwargs) -> str:
    return subprocess.check_output(args, text=True, timeout=180, **kwargs).strip()


def digest(path: Path) -> str:
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def canonical(value) -> bytes:
    return (json.dumps(value, sort_keys=True, separators=(",", ":")) + "\n").encode()


def dependencies(path: Path) -> dict[str, Path]:
    result = subprocess.run(["ldd", str(path)], text=True, capture_output=True, check=True, timeout=30)
    if "not found" in result.stdout:
        raise RuntimeError("Unresolved native dependency")
    found = {}
    for line in result.stdout.splitlines():
        match = re.search(r"=>\s+(/[^\s]+)", line) or re.match(r"\s*(/[^\s]+)", line)
        if match:
            name = Path(match[1]).name
            if name not in GLIBC:
                found[name] = Path(match[1]).resolve(strict=True)
    return found


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--project", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--candidate", required=True)
    args = parser.parse_args()
    if not re.fullmatch(r"[0-9a-f]{40}", args.candidate):
        raise RuntimeError("Invalid candidate SHA")
    project = args.project.resolve(strict=True)
    if run("git", "-C", str(project), "rev-parse", "HEAD") != args.candidate:
        raise RuntimeError("Candidate mismatch")
    if run("uname", "-m") != "x86_64" or 'VERSION_ID="24.04"' not in Path("/etc/os-release").read_text():
        raise RuntimeError("This kit only supports Ubuntu 24.04 x86_64")
    php = Path(shutil.which("php") or "").resolve(strict=True)
    info = json.loads(run(str(php), "-r", "echo json_encode(['version'=>PHP_VERSION,'version_id'=>PHP_VERSION_ID,'binary'=>PHP_BINARY,'extension_dir'=>ini_get('extension_dir'),'loaded'=>get_loaded_extensions(),'config_path'=>PHP_CONFIG_FILE_PATH]);"))
    if info["version_id"] // 100 != 804 or info["config_path"] != "/etc/php/8.4/cli":
        raise RuntimeError("Unexpected PHP runtime or compiled default configuration path")
    lock = json.loads((project / "composer.lock").read_text())
    required = {key[4:].lower() for group in ("packages", "packages-dev") for package in lock[group]
                for key in package.get("require", {}) if key.startswith("ext-")}
    required |= {"bcmath", "pdo", "pdo_mysql", "pdo_sqlite", "pcntl", "posix"}
    loaded = {name.lower() for name in info["loaded"]}
    if not required <= loaded:
        raise RuntimeError("Required PHP modules are not installed: " + ", ".join(sorted(required - loaded)))
    output = args.output.resolve()
    output.mkdir(mode=0o700, parents=False, exist_ok=False)
    with tempfile.TemporaryDirectory(prefix="developer-php-bundle-") as temporary:
        stage = Path(temporary) / "payload"
        stage.mkdir()
        origins: dict[str, str] = {}

        def copy(source: Path, relative: str, origin: str) -> Path:
            destination = stage / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            if destination.exists():
                if digest(destination) != digest(source):
                    raise RuntimeError("Native library basename collision")
                return destination
            shutil.copyfile(source, destination)
            executable = relative.startswith("native/bin/") or (relative.startswith("vendor/") and source.stat().st_mode & 0o111)
            destination.chmod(0o755 if executable else 0o644)
            origins[relative] = origin
            return destination

        copied_native = [copy(php, "native/bin/php", str(php))]
        native_sources = [php]
        extension_names = []
        extension_dir = Path(info["extension_dir"]).resolve(strict=True)
        for name in MODULES:
            source = extension_dir / (name + ".so")
            if source.is_file() and name in loaded:
                copied_native.append(copy(source, "native/extensions/" + source.name, str(source)))
                native_sources.append(source)
                extension_names.append(source.name)
        for program in ("qpdf", "pdftotext", "pdfinfo"):
            source = Path(shutil.which(program) or "").resolve(strict=True)
            copied_native.append(copy(source, "native/bin/" + program, str(source)))
            native_sources.append(source)
        libraries: dict[str, Path] = {}
        for source in native_sources:
            for name, path in dependencies(source).items():
                if name in libraries and digest(path) != digest(libraries[name]):
                    raise RuntimeError("Conflicting shared library identities")
                libraries[name] = path
        for name, source in sorted(libraries.items()):
            copied_native.append(copy(source, "native/lib/" + name, str(source)))
        for path in copied_native:
            rpath = "$ORIGIN" if path.parent.name == "lib" else "$ORIGIN/../lib"
            run("patchelf", "--force-rpath", "--set-rpath", rpath, str(path))
        vendor = (project / "vendor").resolve(strict=True)
        for source in sorted(vendor.rglob("*")):
            if source.is_symlink():
                raise RuntimeError("Unexpected vendor symlink; review before packaging")
            if source.is_dir():
                continue
            if not source.is_file():
                raise RuntimeError("Unexpected vendor special file")
            relative = "vendor/" + source.relative_to(vendor).as_posix()
            copy(source, relative, "locked-composer:" + source.relative_to(vendor).as_posix())
        runtime = {"schema_version": 1, "candidate": args.candidate, "composer_lock_sha256": digest(project / "composer.lock"),
                   "php_version": info["version"], "architecture": "x86_64", "ubuntu": "24.04", "glibc": "2.39",
                   "default_config_dir": info["config_path"], "extension_files": extension_names,
                   "required_modules": sorted(required), "packages": sorted([
                       {"name": p["name"], "version": p["version"], "reference": (p.get("dist") or p.get("source") or {}).get("reference")}
                       for group in ("packages", "packages-dev") for p in lock[group]], key=lambda p: p["name"])}
        (stage / "runtime.json").write_bytes(canonical(runtime))
        origins["runtime.json"] = "generated-clean-runtime-metadata"
        native_php = stage / "native/bin/php"
        command = [str(native_php), "-n", "-d", "extension_dir=" + str(stage / "native/extensions")]
        for name in extension_names:
            command += ["-d", "extension=" + name]
        smoke = subprocess.run(command + ["-r", "echo (new PDO('sqlite::memory:'))->query('select 1')->fetchColumn();"],
                               env={"LANG": "C", "LC_ALL": "C", "TZ": "UTC"}, text=True, capture_output=True, timeout=30)
        if smoke.returncode != 0 or smoke.stdout != "1" or smoke.stderr:
            raise RuntimeError("Copied PHP/extension SQLite smoke failed: " + smoke.stderr[:2000])
        files = []
        expanded = 0
        for path in sorted(stage.rglob("*")):
            if path.is_file():
                size = path.stat().st_size
                expanded += size
                files.append({"path": path.relative_to(stage).as_posix(), "size": size, "sha256": digest(path),
                              "mode": path.stat().st_mode & 0o777, "origin": origins[path.relative_to(stage).as_posix()]})
        if expanded > MAX_EXPANDED:
            raise RuntimeError("Expanded bundle exceeds 1 GiB")
        file_manifest = canonical({"schema_version": 1, "files": files})
        (stage / "files.json").write_bytes(file_manifest)
        raw_archive = Path(temporary) / "payload.tar"
        with tarfile.open(raw_archive, "w", format=tarfile.PAX_FORMAT) as archive:
            for path in sorted(stage.rglob("*")):
                entry = archive.gettarinfo(str(path), arcname=path.relative_to(stage).as_posix())
                entry.uid = entry.gid = entry.mtime = 0
                entry.uname = entry.gname = ""
                entry.pax_headers = {}
                with path.open("rb") if path.is_file() else open(os.devnull, "rb") as stream:
                    archive.addfile(entry, stream if path.is_file() else None)
        compressed = Path(temporary) / "payload.tar.zst"
        subprocess.run(["zstd", "-10", "--threads=1", "--no-progress", str(raw_archive), "-o", str(compressed)], check=True, timeout=240)
        if compressed.stat().st_size > MAX_ARCHIVE:
            raise RuntimeError("Compressed bundle exceeds 128 MiB")
        parts = []
        with compressed.open("rb") as stream:
            while data := stream.read(CHUNK):
                name = f"part-{len(parts) + 1:02d}.bin"
                (output / name).write_bytes(data)
                parts.append({"name": name, "size": len(data), "sha256": hashlib.sha256(data).hexdigest()})
        manifest = {"schema_version": 1, "candidate": args.candidate, "composer_lock_sha256": runtime["composer_lock_sha256"],
                    "archive": {"size": compressed.stat().st_size, "sha256": digest(compressed), "uncompressed_tar_size": raw_archive.stat().st_size},
                    "files_manifest_sha256": hashlib.sha256(file_manifest).hexdigest(), "parts": parts}
        (output / "manifest.json").write_bytes(canonical(manifest))
        print(json.dumps({"candidate": args.candidate, "php": info["version"], "packages": len(runtime["packages"]),
                          "files": len(files), "expanded_bytes": expanded, "compressed_bytes": compressed.stat().st_size, "parts": len(parts),
                          "manifest_sha256": digest(output / "manifest.json")}, sort_keys=True))


if __name__ == "__main__":
    main()
