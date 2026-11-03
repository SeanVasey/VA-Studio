#!/usr/bin/env python3
"""Independent root-owned runtime qualification, never native ledger admission.

This first provisioning phase has no credential inputs and runs no candidate PHP.
The producer builds the runtime from a reviewed recipe and signed base APT sources.
The required supervised route must not consume this runtime-only descriptor.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import time

from capture import Capture

HASH = re.compile(r"[0-9a-f]{64}\Z")
SHA = re.compile(r"[0-9a-f]{40}\Z")
OCI = re.compile(r"[a-z0-9][a-z0-9./:_-]*@sha256:[0-9a-f]{64}\Z")
HERE = Path(__file__).resolve().parent


class Refusal(RuntimeError):
    pass


def require(condition, reason):
    if not condition:
        raise Refusal(reason)


def closed(value, keys):
    require(type(value) is dict and set(value) == set(keys), "closed-schema")


def unique(pairs):
    value = {}
    for key, item in pairs:
        require(key not in value, "duplicate-field")
        value[key] = item
    return value


def load(raw):
    require(type(raw) is bytes and len(raw) <= 65536, "json-bound")
    return json.loads(raw, object_pairs_hook=unique)


def policy(value):
    closed(value, ("schema", "factory_manifest_sha256", "platform", "mysql", "runtime"))
    require(type(value["schema"]) is int and value["schema"] == 1, "policy-version")
    require(value["platform"] == "linux/amd64" and HASH.fullmatch(value["factory_manifest_sha256"]), "factory-platform")
    closed(value["mysql"], ("image_id", "repo_digest", "version"))
    runtime = value["runtime"]
    closed(runtime, ("base_repo_digest", "recipe", "php_binary", "php_binary_sha256",
                     "php_version", "php_modules", "python_binary", "python_binary_sha256", "python_version"))
    for image in (value["mysql"],):
        require(type(image["repo_digest"]) is str and OCI.fullmatch(image["repo_digest"]), "missing-reviewed-oci-provenance")
        require(type(image["image_id"]) is str and re.fullmatch(r"sha256:[0-9a-f]{64}", image["image_id"]), "image-id")
    require(runtime["base_repo_digest"] == 'debian@sha256:f6b08b09a05725f9431502d3b5714f5c4576e82c1a84fa1debe0c24a7e0c3ec4'
            and runtime["recipe"] == 'Runtime.Dockerfile', "reviewed-runtime-recipe")
    require(value["mysql"]["version"] == "8.4.11" and runtime["php_version"] == "8.4.27"
            and runtime["python_version"] == "3.13.5", "runtime-version")
    require(runtime["php_binary"] == "/usr/bin/php8.4" and runtime["python_binary"] == "/usr/bin/python3.13", "runtime-entry")
    for key in ("php_binary_sha256", "python_binary_sha256"):
        require(type(runtime[key]) is str and HASH.fullmatch(runtime[key]), "binary-pin")
    closed(runtime["php_modules"], ("pdo", "mysqlnd", "pdo_mysql", "mbstring", "ctype", "tokenizer", "iconv"))
    require(all(type(v) is str and HASH.fullmatch(v) for v in runtime["php_modules"].values()), "module-pin")
    return value


def trusted_path(path, directory=False):
    """Every ancestor is root-owned and unwritable by the acceptance UID/groups."""
    path = Path(path)
    require(path.is_absolute() and path == path.resolve(), "noncanonical-root-path")
    for node in (*reversed(path.parents), path):
        info = node.lstat()
        require(not stat.S_ISLNK(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                "mutable-producer-path")
        if node != path or directory:
            require(stat.S_ISDIR(info.st_mode), "producer-directory")
        else:
            require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, "producer-file")
    return path


def image_matches(row, expected):
    closed(row, ("Id", "RepoDigests", "Os", "Architecture"))
    require(row["Id"] == expected["image_id"] and type(row["RepoDigests"]) is list
            and expected["repo_digest"] in row["RepoDigests"]
            and row["Os"] == "linux" and row["Architecture"] == "amd64", "image-provenance-mismatch")


def create_command(image, name, probes):
    require(re.fullmatch(r"sha256:[0-9a-f]{64}", image) and re.fullmatch(r"va-ledger-runtime-[0-9a-f]{32}", name), "create-identity")
    # No Docker socket, host PID namespace, host network, writable source or ambient environment.
    return ["docker", "create", "--pull=never", "--platform=linux/amd64", "--name", name,
            "--label", "va.ledger.runtime=" + name, "--network=none", "--read-only",
            "--user=1000:1000", "--cap-drop=ALL", "--security-opt=no-new-privileges:true",
            "--memory=512m", "--memory-swap=512m", "--cpus=1", "--pids-limit=64",
            "--log-driver=none", "--restart=no", "--init=false",
            "--mount", "type=bind,src=" + str(probes) + ",dst=/qualification,readonly",
            "--entrypoint=/bin/sleep", image, "120"]


class Producer(Capture):
    def __init__(self, output=None):
        self.end = time.monotonic() + 500
        self.limit = self.end - 20
        self.receipt = {"commands": [], "transport_uncertain": False, "native_admitted": False,
                        "runtime_qualified": False, "intended_name": None, "created_id": None}
        self.output = output
        if output is not None:
            trusted_path(output, directory=True)
            require(not any(output.iterdir()), "fresh-owned-output-required")
            self.persist()

    def record(self, stage, facts):
        # Capture stores closed command outcomes. No raw Docker output or secrets.
        self.receipt['last_stage'] = stage
        self.persist()

    def persist(self):
        if self.output is None:
            return
        raw = (json.dumps(self.receipt, sort_keys=True, separators=(",", ":")) + "\n").encode()
        require(len(raw) <= 65536 and len(self.receipt['commands']) <= 128, "progress-bound")
        temporary = self.output / 'runtime-progress.partial'
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o400)
        try:
            with os.fdopen(fd, 'wb', closefd=False) as stream:
                stream.write(raw)
                stream.flush()
                os.fsync(fd)
        finally:
            os.close(fd)
        os.replace(temporary, self.output / 'runtime-progress.json')
        fd = os.open(self.output, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def command(self, args, *arguments, **keywords):
        require(args[0] == "docker", "fixed-controller-entry")
        return super().command(["/usr/bin/docker", *args[1:]], *arguments, **keywords)

    def image(self, expected):
        self.command(["docker", "pull", "--platform=linux/amd64", expected["repo_digest"]],
                     "pinned-image-pull", seconds=60)
        projection = '{"Id":{{json .Id}},"RepoDigests":{{json .RepoDigests}},"Os":{{json .Os}},"Architecture":{{json .Architecture}}}'
        row = load(self.command(["docker", "image", "inspect", "--format", projection,
                                 expected["repo_digest"]], "image-provenance").encode())
        image_matches(row, expected)

    def build(self, probes):
        # The sealed directory is a closed build context; .dockerignore excludes
        # candidate code, credentials, descriptor and unrelated checkout files.
        image = self.command(["docker", "build", "--quiet", "--pull", "--no-cache",
                              "--network=default", "--platform=linux/amd64", "--file",
                              str(probes / "Runtime.Dockerfile"), str(probes)], "trusted-build", seconds=240).strip()
        require(re.fullmatch(r"sha256:[0-9a-f]{64}", image), "built-image-id")
        row = load(self.command(["docker", "image", "inspect", "--format",
            '{"Id":{{json .Id}},"Os":{{json .Os}},"Architecture":{{json .Architecture}}}', image], "built-image-profile").encode())
        require(row == {"Id": image, "Os": "linux", "Architecture": "amd64"}, "built-image-profile")
        return image

    def qualify(self, value, probes, nonce):
        runtime = value["runtime"]
        trusted_path(Path("/usr/bin/docker"))
        version = load(self.command(["docker", "version", "--format",
            '{"Version":{{json .Server.Version}},"ApiVersion":{{json .Server.APIVersion}},"GitCommit":{{json .Server.GitCommit}},"Os":{{json .Server.Os}},"Arch":{{json .Server.Arch}}}'], "controller-profile").encode())
        require(version == {"Version": "28.4.0", "ApiVersion": "1.51", "GitCommit": "249d679",
                            "Os": "linux", "Arch": "amd64"}, "controller-profile")
        cgroup = load(self.command(["docker", "info", "--format",
            '{"CgroupVersion":{{json .CgroupVersion}},"CgroupDriver":{{json .CgroupDriver}},"OomKillDisable":{{json .OomKillDisable}}}'], "controller-cgroup").encode())
        require(cgroup == {"CgroupVersion": "2", "CgroupDriver": "cgroupfs", "OomKillDisable": False}, "controller-cgroup")
        self.image(value["mysql"])
        built_image = self.build(probes)
        name = "va-ledger-runtime-" + nonce
        self.receipt['intended_name'] = name
        self.receipt['built_image_id'] = built_image
        self.persist()
        container = None
        cleanup = False
        try:
            # Save creation identity even if subsequent probing refuses. Never adopt a named ambient container.
            container = self.command(create_command(built_image, name, probes), "create").strip()
            require(re.fullmatch(r"[0-9a-f]{64}", container), "created-id")
            self.receipt['created_id'] = container
            self.persist()
            require(self.command(["docker", "start", container], "start").strip() == container, "started-id")
            for binary, probe in ((runtime["php_binary"], "runtime-probe.php"),
                                  (runtime["python_binary"], "runtime-probe.py")):
                options = ["-d", "memory_limit=512M", "-d", "zend.exception_ignore_args=1"] if probe.endswith('.php') else ["-I"]
                result = load(self.command(["docker", "exec", "--user=1000:1000", container,
                                            binary, *options, "/qualification/" + probe], "profile").encode())
                closed(result, ("profile_match",))
                require(result["profile_match"] is True, "runtime-profile")
            closure = self.command(["docker", "exec", "--user=1000:1000", container, "/bin/cat",
                                    "/usr/share/va-ledger/package-closure.txt"], "signed-package-closure")
            require(closure.endswith("\n") and len(closure.splitlines()) > 10, "package-closure")
            closure_hash = hashlib.sha256(closure.encode()).hexdigest()
            downloads = self.command(["docker", "exec", "--user=1000:1000", container, "/bin/cat",
                                      "/usr/share/va-ledger/download-closure.sha256"], "signed-download-closure")
            require(downloads.endswith("\n") and all(re.fullmatch(r"[0-9a-f]{64}  /usr/share/va-ledger/debs/[A-Za-z0-9.+%_:~=-]+\.deb", line)
                    for line in downloads.splitlines()), "download-closure")
        finally:
            # CLI reaping is provided by the unchanged accepted bounded Capture transport.
            # Backend cleanup is separate and requires the actual wait result and observed removal.
            self.limit = min(self.end, time.monotonic() + 20)
            if container and re.fullmatch(r"[0-9a-f]{64}", container):
                self.command(["docker", "kill", "--signal=TERM", container], "stop")
                require(self.command(["docker", "wait", container], "wait") == "143\n", "runtime-wait")
                self.command(["docker", "rm", container], "remove")
                require(self.command(["docker", "ps", "-a", "--filter", "id=" + container,
                                      "--format", "{{.ID}}"], "removed") == "", "runtime-removal")
                cleanup = True
        require(cleanup and not self.receipt["transport_uncertain"], "runtime-cleanup")
        self.receipt['runtime_qualified'] = True
        self.persist()
        return {"container_removed": True, "bounded_transport": True, "runtime_profile": True,
                "built_image_id": built_image, "package_closure_sha256": closure_hash,
                "package_closure": closure, "download_closure": downloads}


def publish(directory, descriptor):
    trusted_path(directory, directory=True)
    target = directory / "runtime-qualified.json"
    fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o444)
    try:
        os.fchmod(fd, 0o444)
        raw = (json.dumps(descriptor, sort_keys=True, separators=(",", ":")) + "\n").encode()
        require(len(raw) <= 65536, "descriptor-bound")
        with os.fdopen(fd, "wb", closefd=False) as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(fd)
    finally:
        os.close(fd)
    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--policy-sha256", required=True)
    parser.add_argument("--source-manifest-sha256", required=True)
    parser.add_argument("--expected-sha", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    require(os.geteuid() == 0 and SHA.fullmatch(args.expected_sha)
            and HASH.fullmatch(args.policy_sha256) and HASH.fullmatch(args.source_manifest_sha256), "independent-root-producer")
    # Root must seal this exact reviewed source directory before invocation.
    sources = ("runtime-producer.py", "capture.py", "runtime-policy.json", "runtime-probe.php", "runtime-probe.py", "Runtime.Dockerfile", ".dockerignore")
    for name in sources:
        trusted_path(HERE / name)
    manifest_path = trusted_path(HERE / "source-manifest.json")
    require(manifest_path.stat().st_size <= 65536, "manifest-bound")
    manifest_raw = manifest_path.read_bytes()
    require(hashlib.sha256(manifest_raw).hexdigest() == args.source_manifest_sha256, "reviewed-producer-source")
    manifest = load(manifest_raw)
    closed(manifest, sources)
    for name, digest in manifest.items():
        require(type(digest) is str and HASH.fullmatch(digest)
                and hashlib.sha256((HERE / name).read_bytes()).hexdigest() == digest, "producer-source-pin")
    raw = (HERE / "runtime-policy.json").read_bytes()
    require(hashlib.sha256(raw).hexdigest() == args.policy_sha256, "reviewed-policy-pin")
    value = policy(load(raw))
    trusted_path(args.output, directory=True)
    import secrets
    facts = Producer(args.output).qualify(value, HERE, secrets.token_hex(16))
    publish(args.output, {"schema": 1, "kind": "runtime-qualification-only", "native_admitted": False,
                          "requested_candidate_sha": args.expected_sha, "policy_sha256": args.policy_sha256,
                          "source_manifest_sha256": args.source_manifest_sha256,
                          "runtime_image": facts["built_image_id"], "facts": facts})


if __name__ == "__main__":
    try:
        main()
    except Exception:
        # Never expose engine output, paths, arguments or exception chains.
        print("Ledger runtime qualification refused.", file=sys.stderr)
        sys.exit(2)
