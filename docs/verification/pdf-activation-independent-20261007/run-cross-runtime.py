#!/usr/bin/env python3
"""Reproduce the executed two-phase genuine retained-original canary.

This consolidated harness is retained for reproduction; the existing receipts
record the original two-phase run. Each run has fresh synthetic fixture IDs, so
its PDF hash is specific to that run, not the fixed renderer fixture hash.
"""

import argparse
import base64
import json
import os
from pathlib import Path
import subprocess
import tempfile


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--old-root", type=Path, required=True)
    parser.add_argument("--new-root", type=Path, required=True)
    parser.add_argument("--php", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    outcomes = []
    with tempfile.TemporaryDirectory(prefix="va-pdf-original-review-") as private:
        private = Path(private)
        private.chmod(0o700)
        db = private / "review.sqlite"
        db.touch(mode=0o600)
        storage = private / "storage"
        storage.mkdir(mode=0o700)
        env = os.environ.copy()
        for name in ["VASEY_TEST_CONTRACT_ISSUANCE_ENABLED", "VASEY_TEST_CONTRACT_ISSUANCE_POLICY"]:
            env.pop(name, None)
        env.update({
            "APP_ENV": "testing",
            "APP_KEY": "base64:" + base64.b64encode(os.urandom(32)).decode(),
            "DB_CONNECTION": "sqlite", "DB_DATABASE": str(db), "DB_URL": "",
            "CACHE_STORE": "array", "SESSION_DRIVER": "array", "MAIL_MAILER": "array",
            "QUEUE_CONNECTION": "sync", "BROADCAST_CONNECTION": "null",
            "VA_REVIEW_STORAGE": str(storage), "VA_REVIEW_SNAPSHOT": str(private / "snapshot"),
        })
        for phase, root, filename in [
            ("old-create", args.old_root, "old-create.txt"),
            ("new-read", args.new_root, "new-read.txt"),
        ]:
            script = root / "docs/verification/pdf-activation-independent-20261007/cross-runtime-original.php"
            result = subprocess.run(
                [str(args.php), "-d", "memory_limit=512M", str(script), phase],
                cwd=root, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                text=True, timeout=180,
            )
            (args.output / filename).write_text(result.stdout)
            outcomes.append({"phase": phase, "exit_code": result.returncode})
            if result.returncode:
                break
        env.pop("APP_KEY", None)
    (args.output / "cross-runtime-exit.json").write_text(json.dumps(outcomes, indent=2) + "\n")
    if len(outcomes) != 2 or any(item["exit_code"] for item in outcomes):
        raise SystemExit(1)


if __name__ == "__main__":
    main()
