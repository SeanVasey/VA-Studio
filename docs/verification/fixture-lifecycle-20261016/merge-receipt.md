# Actual F2b development merge

Recorded 2026-10-17 by the Claude harness, after the fact. The session that merged PR #132 stopped before writing this receipt, so every fact below comes from the GitHub API and the local clone on that date. Nothing was re-executed.

## Merge

| Fact | Value | Source |
| --- | --- | --- |
| PR | [#132](https://github.com/SeanVasey/VA-Studio/pull/132) "Complete bounded native fixture lifecycle supervision" | GitHub |
| Merged | 2026-10-16T01:52:40Z | GitHub `merged_at` |
| Merge commit | `efa6eea5b007f1f2623c4009c1e9bef737f06bee` | `git cat-file -p efa6eea5` |
| First parent (prior main) | `3b90ce476d4480de850e849d19dfd0a5b40d6835` (PR #131 merge) | same |
| Second parent (merged head) | `a6b143ce862f9da6c315dbbbb5884f8c01657653` | same |
| Merge tree | `35734f478cc80eef94a454cfb9f22d91510b7ac1` | `git rev-parse efa6eea5^{tree}` |
| Head tree | `35734f478cc80eef94a454cfb9f22d91510b7ac1` (equal) | `git rev-parse a6b143ce^{tree}` |
| Head ancestry | `a6b143ce` descends from `3b90ce47`; five commits `89655870`..`a6b143ce` | `git log 3b90ce47..a6b143ce` |

The merge tree equals the head tree, so main received exactly the approved head's content.

## Review and checks at the merged head

- **Independent review:** separate final review at `a21658ad2d179eec0605c006b7fe479af0190c85`, **APPROVE BOUNDED NATIVE LIFECYCLE SOURCE AND SEALED FOCUSED EVIDENCE**, no source P0/P1 or required correction ([report](independent-final-review-a21658ad.md), [decision](independent-final-review-a21658ad.json)).
- **Reviewed head to merged head:** `git diff --stat a21658ad a6b143ce` touches only five documentation/evidence files (`CLAUDE-PLAN.md`, `codex-deferred-20261011.md`, this packet's `README.md` and the two imported review files). No executable source changed after the review.
- **GitHub reviews / review threads:** 0 / 0 (`pull_request_read get_reviews`, `get_review_comments`).
- **Cheap preflight:** run [38837046611](https://github.com/SeanVasey/VA-Studio/actions/runs/38837046611) on `a6b143ce`, conclusion success. Contexts: scope success, frontend success, backend-quality success, documentation skipped (full mode), Development preflight result success. Cursor Bugbot success. Six contexts in total.

## What this merge closed, and what stays open

Closed (development scope only): bounded native fixture lifecycle supervision at tested native source `572f078afe5ec7815e80fe052f3dd0b1975d9776` with a fixed MySQL 420-second / SQLite 90-second child allowance. Recorded results: whole native file 4 tests / 70 assertions and separate calibration 1/17 on private MySQL 8.4.11, SQLite 12/181 at `c96da8e29999838ca14477e7dc8f236d6f00ad4a` carried through a MySQL-literal-only change; each with zero failures, errors and skips. Evidence seal SHA256 `19d25ee33151ae67bd7a9b9a471372931fe65fef7a4fef0600df69adcc4dff60` (139 members, 798,115 bytes). The original failed runs (the interrupted 15-case selection, the 300-second 1/2/E1 run and the V4 recorder failure) remain recorded failures, not passes.

Still open, as the packet states: full F2 acceptance, the 25 reviewed SQLite-only methods without native counterparts (`scripts/ci/database-mysql-skips.json`), raw-worker and later-hook normalization, whole-native Guard and concurrency coverage, browser, performance, provider, host, activation and release. No Foundation CI, GitLab dispatch, live credential, payment, deploy, DNS or frozen-resource change occurred.

## Gap recorded on 2026-10-17

The packet README, `CLAUDE-PLAN.md` and `codex-deferred-20261011.md` at the merged head still say "expected-SHA merge pending", and `DEVELOPMENT-AGENDA.md` still names PR #131 as current main. Those were written before the merge and are now superseded by this receipt. No branch or commit from the merging session exists on GitHub after 2026-10-16T01:52Z; any later local work in that session was not published and is not recoverable from the repository.
