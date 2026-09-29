#!/usr/bin/env python3
"""Fail when the built client bundle exposes server secret names or key material.

Secret names come from .env.example: every variable whose name contains KEY,
SECRET, PASSWORD or TOKEN, except Vite's deliberately public VITE_ prefix.
Stripe secret, restricted and webhook key prefixes are checked as values.
Usage: scan-client-bundle.py [bundle directory, default public/build]
"""

from __future__ import annotations

from pathlib import Path
import re
import sys


ROOT = Path(__file__).resolve().parents[2]
TEXT_SUFFIXES = {".js", ".mjs", ".cjs", ".css", ".json", ".html", ".map", ".svg", ".txt"}
KEY_PREFIXES = r"(?:sk|rk)_(?:live|test)_[A-Za-z0-9]|whsec_[A-Za-z0-9]"


def secret_names() -> list[str]:
    names = set()
    for line in (ROOT / ".env.example").read_text(encoding="utf-8").splitlines():
        match = re.match(r"#?\s*([A-Z][A-Z0-9_]*)=", line)
        if match and re.search(r"KEY|SECRET|PASSWORD|TOKEN", match.group(1)) and not match.group(1).startswith("VITE_"):
            names.add(match.group(1))
    return sorted(names)


def main() -> int:
    bundle = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else ROOT / "public" / "build"
    if not (bundle / "manifest.json").is_file():
        print(f"No built client bundle at {bundle}; run npm run build first.", file=sys.stderr)
        return 1
    names = secret_names()
    if not names:
        print("No secret names found in .env.example; refusing an empty scan.", file=sys.stderr)
        return 1
    pattern = re.compile(r"\b(?:" + "|".join(map(re.escape, names)) + r")\b|" + KEY_PREFIXES)
    files = sorted(path for path in bundle.rglob("*") if path.is_file() and path.suffix in TEXT_SUFFIXES)
    hits = [
        f"{path.relative_to(bundle)}: {match.group(0)}"
        for path in files
        for match in pattern.finditer(path.read_text(encoding="utf-8", errors="replace"))
    ]
    if hits:
        print("Secret names or key material found in the client bundle:", *hits, sep="\n", file=sys.stderr)
        return 1
    print(f"Scanned {len(files)} bundle files for {len(names)} secret names and Stripe key prefixes: no hits.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
