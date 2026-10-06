#!/usr/bin/env python3
"""Refuse the retired repository-creation publisher without running git or gh.

VA-Studio development continues through reviewed branches and pull requests in
SeanVasey/VA-Studio. Repository creation and direct-main bootstrap are retired.
"""
import sys

CURRENT_REPOSITORY = "https://github.com/SeanVasey/VA-Studio"


def main():
    print(
        "This legacy publication helper is retired; no changes were made.\n"
        f"Current repository: {CURRENT_REPOSITORY}\n"
        "Use the existing checkout's normal feature-branch and pull-request workflow. "
        "Do not recreate the repository or publish directly to main with this helper.",
        file=sys.stderr,
    )
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
