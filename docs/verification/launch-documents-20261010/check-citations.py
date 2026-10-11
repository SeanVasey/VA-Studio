#!/usr/bin/env python3
"""Focused documents check for the launch-documents lane.

Reads the three legal drafts, extracts every backticked repository path and fails when one does
not exist in the worktree, or when a draft lacks the required owner-review header. Run from the
repository root: `python3 docs/verification/launch-documents-20261010/check-citations.py`.
This is a documents check, not a test-suite run.
"""
from __future__ import annotations

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[3]
DOCS = [ROOT / 'docs/legal/README.md', ROOT / 'docs/legal/PRIVACY.md', ROOT / 'docs/legal/TERMS.md']
HEADER = 'DRAFT FOR OWNER REVIEW. Not published. Not legal advice.'
PREFIXES = ('app/', 'bootstrap/', 'config/', 'database/', 'docs/', 'ops/', 'resources/', 'routes/', 'scripts/')
ROOT_FILES = ('AGENTS.md', 'CHANGELOG.md', 'CLAUDE.md', 'README.md', 'SECURITY.md')
TOKEN = re.compile(r'`([^`\n]+)`')


def paths_in(text: str) -> set[str]:
    found: set[str] = set()
    for token in TOKEN.findall(text):
        candidate = token.strip().rstrip('/')
        if candidate.startswith(PREFIXES) or candidate in ROOT_FILES:
            if ' ' in candidate or '{' in candidate:
                continue
            found.add(candidate)
    return found


def main() -> int:
    failures: list[str] = []
    checked = 0
    for doc in DOCS:
        if not doc.is_file():
            failures.append(f'missing document: {doc.relative_to(ROOT)}')
            continue
        text = doc.read_text(encoding='utf-8')
        # The header may carry Markdown emphasis; require the sentence itself within the first four lines.
        if not any(HEADER in line for line in text.splitlines()[0:4]):
            failures.append(f'header missing in {doc.relative_to(ROOT)}')
        cited = sorted(paths_in(text))
        for cited_path in cited:
            checked += 1
            if not (ROOT / cited_path).exists():
                failures.append(f'{doc.relative_to(ROOT)} cites missing path: {cited_path}')
        print(f'{doc.relative_to(ROOT)}: {len(cited)} distinct cited paths')
    print(f'checked {checked} citations across {len(DOCS)} documents')
    for failure in failures:
        print('FAIL ' + failure)
    print('RESULT ' + ('FAIL' if failures else 'PASS'))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
