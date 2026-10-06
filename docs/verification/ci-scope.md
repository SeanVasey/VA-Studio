# Foundation CI documentation scope

Status: implementation candidate; runtime acceptance belongs to its integrating PR. This change adds a conservative documentation mode and retains full verification for runtime, tests, dependencies, configuration and CI changes.

The `scope` job checks the actual checkout against the event SHA and compares its complete Git diff with the recorded base/before SHA. Both are full commit IDs, the base must be an ancestor, and every changed path must be a regular non-executable file in the explicit `DOCUMENTS` allowlist in `scripts/ci/ci-scope.py`. Renames are checked as deletion/addition, so moving an application file into an allowed documentation name does not hide the original path. Empty, malformed, unavailable, unrelated or oversized evidence selects the full suite. Manual dispatch always selects full verification. No GitHub changed-file API truncation or top-level workflow path filter is used.

The allowlist contains the README, changelog, ordered record, decision register, remaining-plan/task/parity records, CI strategy and four named CI evidence guides. It does not broadly exempt all Markdown/CSV files. AGENTS.md, code, lockfiles, migrations, test files, workflow/scripts, assets, brand manifests and unknown/new document paths use the full suite.

In documentation mode, the documentation job recomputes the source classification, runs the routing regression tests, checks changed-file whitespace and validates retained allowed files for UTF-8 text, CSV structure and repository-local links. Deleting a document cannot silently leave a dangling link in another retained allowed document. It does not claim application or database tests ran.

The stable `backend` aggregate always runs. It accepts exactly one complete mode:

| Mode | Required successful jobs | Expected nonexecuted jobs |
| --- | --- | --- |
| `full` | scope, backend quality, all eight MySQL shards, both SQLite shards, frontend/build/audits, both operator-browser projects and related-browser | documentation |
| `docs` | scope and documentation | runtime quality, databases, frontend and browser |

Missing, failed, cancelled, unknown or inconsistent results fail the aggregate. Runtime mode now includes frontend/browser success in the aggregate rather than relying solely on unknown repository protection settings. The stable aggregate job name is retained. MySQL has eight whole-file shards; SQLite still has two. Existing test commands, timeouts, audit settings and exact partition proof are retained. Matrix success remains GitHub's aggregate result of all its shards; a cancelled/failed/skipped matrix cannot satisfy full mode.

Full CI still runs for ready or draft runtime PRs, main runtime pushes and manual dispatch. This first efficiency increment does not introduce draft suppression or post-merge runtime proof reuse. Feature branches can use the separate [focused workflow](focused-ci.md) for informative feedback before a ready PR. Do not describe a focused pass as full acceptance.

Local verification: `python3 scripts/ci/test-ci-scope.py` exercises real temporary Git histories for regular docs, mixed/unknown paths, rename/deletion, symlink/executable mode, missing/stale/unrelated identities, dispatch/main routing, bounded evidence, document links/CSV and acceptance failure states. The integrating PR records the executed test total, exact source and cloud results. Proposed behavior is not adopted until the CI implementation itself passes the full suite and independent review.

Rollback: revert this workflow/routing increment to restore unconditional full PR/main/manual checks. Preserve accepted application changes and evidence. Before the eight-shard job-name change on October 6, the successful main-branch metadata read explicitly reported protection disabled and empty required check names; the ruleset listing including parents was empty. The separate administrative protection endpoint returned 403. No protection setting is changed by this commit, and the source-defined full aggregate remains mandatory.
