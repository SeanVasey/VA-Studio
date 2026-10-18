#!/usr/bin/env python3
"""Compose the production environment template from the three reviewed pieces.

    python3 -I ops/production/compose-env.py --host shop.example.com [--output FILE]

Layers, in order: ops/private-server/env.example (host baseline), ops/production/env.production.example (feature-family
switches, every one off) and ops/production/env.host-overlay.example (the kit's own non-secret values). The reviewed
templates already carry the keys that both must hold identically (STRIPE_MODE, the test switches); this tool checks that
they agree, keeps one copy, and refuses a conflict. A later layer may fill a key an earlier one leaves blank or add a key
it lacks; it may not change a value an earlier layer set. Comments are dropped (read the annotated templates for what each
key means). Nothing secret is written: APP_KEY, DB_PASSWORD, MEDIA_TAG_PATH and MEDIA_TAG_SHA256 stay blank for the operator
to fill in Forge or on the host. With --output the file is created new (mode 0600, never overwritten); without it the
result goes to standard output.

The result is a template, not an installation: the private-server preflight reports it BLOCKED until the blanks are filled.
"""
import argparse
import os
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
LAYERS = [
    ("host baseline (ops/private-server/env.example)", HERE.parent / "private-server/env.example"),
    ("feature families (ops/production/env.production.example)", HERE / "env.production.example"),
    ("kit values (ops/production/env.host-overlay.example)", HERE / "env.host-overlay.example"),
]
ASSIGNMENT = re.compile(r"^([A-Z][A-Z0-9_]*)=(.*)$")
HOST = re.compile(r"^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$")


class ComposeError(Exception):
    pass


def parse(path):
    pairs = []
    for line in path.read_text().splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        match = ASSIGNMENT.match(line)
        if not match:
            raise ComposeError(f"{path.name}: not a single-line assignment: {line[:40]!r}")
        pairs.append((match.group(1), match.group(2)))
    return pairs


def compose(host):
    if not HOST.match(host):
        raise ComposeError("--host must be a lowercase DNS name")
    values, order, source = {}, [], {}
    sections = []
    for title, path in LAYERS:
        keys = []
        seen = set()
        for key, value in parse(path):
            if key in seen:
                raise ComposeError(f"{path.name}: {key} is assigned twice")
            seen.add(key)
            if key in values:
                if values[key] == value:
                    continue
                if values[key] == "":
                    values[key] = value
                    source[key] = path.name
                    continue
                raise ComposeError(f"{key}: {source[key]} sets {values[key]!r} and {path.name} sets a different value; "
                                   "reviewed values are never overridden")
            values[key] = value
            source[key] = path.name
            order.append(key)
            keys.append(key)
        sections.append((title, keys))
    if values.get("APP_URL", "") != "":
        raise ComposeError("APP_URL must be blank in the reviewed templates; it is filled from --host")
    values["APP_URL"] = f"https://{host}"
    lines = ["# VASEY.AUDIO production environment TEMPLATE composed by ops/production/compose-env.py. No secrets.",
             "# Fill the blanks only in Forge > Site > Environment or on the host: APP_KEY, DB_PASSWORD, MEDIA_TAG_PATH,",
             "# MEDIA_TAG_SHA256. Never commit a filled copy. Check it with:",
             "#   php scripts/ops/private-server-preflight.php --env-file <file> --profile=production"]
    for title, keys in sections:
        if not keys:
            continue
        lines.append("")
        lines.append(f"# ---- {title}")
        lines.extend(f"{key}={values[key]}" for key in keys)
    return "\n".join(lines) + "\n"


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--host", required=True)
    parser.add_argument("--output")
    args = parser.parse_args(argv)
    try:
        text = compose(args.host)
    except (ComposeError, OSError) as error:
        print(f"compose-env: refused: {error}", file=sys.stderr)
        return 3
    if args.output:
        try:
            descriptor = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        except OSError as error:
            print(f"compose-env: refused: cannot create {args.output} (it must not exist): {error.strerror}", file=sys.stderr)
            return 3
        with os.fdopen(descriptor, "w") as handle:
            handle.write(text)
    else:
        sys.stdout.write(text)
    return 0


if __name__ == "__main__":
    sys.exit(main())
