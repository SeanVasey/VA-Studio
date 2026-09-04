#!/usr/bin/env python3
"""Create/push the private repository using the operator's authenticated gh CLI.

Explicitly invoked by the operator; never runs during installation or CI.
Does not force-push, change visibility, add collaborators, or publish a site.
"""
import argparse
import json
import pathlib
import shutil
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
REPO = "VASEYDEV/VASEYAUDIO"


def run(*args, check=True):
    return subprocess.run(args, cwd=ROOT, text=True, capture_output=True, check=check)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--issues", action="store_true", help="Also create missing work-package issues after pushing")
    args = parser.parse_args()
    if not shutil.which("gh"):
        sys.exit("Install the official GitHub CLI and run gh auth login, then invoke this script again.")
    profile = json.loads(run("gh", "api", "user").stdout)
    if profile["login"].casefold() != "vaseydev":
        sys.exit("Expected authenticated GitHub account VASEYDEV; no changes made.")
    if run("git", "status", "--porcelain").stdout.strip():
        sys.exit("Commit or isolate local changes before publishing; no changes made.")
    existing = run("gh", "repo", "view", REPO, "--json", "nameWithOwner,isPrivate", check=False)
    if existing.returncode == 0:
        if not json.loads(existing.stdout)["isPrivate"]:
            sys.exit("Existing repository is public. This publisher only targets a private repository.")
    else:
        # gh refuses an existing/inaccessible name; it will not overwrite a repository.
        run("gh", "repo", "create", REPO, "--private", "--description",
            "VASEY.AUDIO first-party music storefront, licensing, administration, and BeatStars migration")
    remotes = run("git", "remote").stdout.splitlines()
    expected = "https://github.com/" + REPO + ".git"
    if "origin" in remotes:
        actual = run("git", "remote", "get-url", "origin").stdout.strip()
        if actual not in (expected, "git@github.com:" + REPO + ".git"):
            sys.exit("Origin points elsewhere; refusing to change it.")
    else:
        run("git", "remote", "add", "origin", expected)
    # Authentication stays with gh; credentials are never copied into project files.
    run("gh", "auth", "setup-git")
    pushed = run("git", "push", "--set-upstream", "origin", "main", check=False)
    if pushed.returncode:
        sys.exit("Push failed. Inspect existing history/access before retrying; no force push was attempted.\n" + pushed.stderr)
    print("Pushed: https://github.com/" + REPO)
    if args.issues:
        rows = json.loads(run("gh", "issue", "list", "--repo", REPO, "--state", "all", "--limit", "1000", "--json", "title").stdout)
        titles = {row["title"] for row in rows}
        for path in sorted((ROOT / "docs/work-packages").glob("WP-*.md")):
            heading = next((line[2:].strip() for line in path.read_text().splitlines() if line.startswith("# ")), path.stem)
            if heading in titles:
                continue
            result = run("gh", "issue", "create", "--repo", REPO, "--title", heading, "--body-file", str(path))
            print(result.stdout.strip())
            titles.add(heading)


if __name__ == "__main__":
    try:
        main()
    except subprocess.CalledProcessError as exc:
        sys.exit(exc.stderr or str(exc))
