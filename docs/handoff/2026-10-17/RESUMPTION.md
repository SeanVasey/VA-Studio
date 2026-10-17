# Resumption after the stopped Codex session — 2026-10-17

Sean reported that the last Codex session appeared frozen or unfinished and asked the Claude harness to reconcile interrupted work and resume development. Root `AGENTS.md` still governs, including the October 6 CI cost policy. This note records what was found, what was reconciled, and who owns each lane now.

## What was found

- **Main** is the PR #132 merge, `efa6eea5b007f1f2623c4009c1e9bef737f06bee` (2026-10-16T01:52:40Z). The merged head `a6b143ce` carries the separate final review at `a21658ad` and a documentation-only delta after it.
- **Nothing from the stopped session reached GitHub after that merge.** There is no newer branch, commit or PR, and no document describes a batch in progress. Any local work the session did afterwards was never published and cannot be recovered from the repository.
- **Interrupted step:** the post-merge receipt and ledger update that every earlier batch recorded in its successor (for example `89655870` for PR #131). The packet README, `CLAUDE-PLAN.md` and `codex-deferred-20261011.md` still said "expected-SHA merge pending", and the agenda still named PR #131 as current main.
- **Open PRs:** 13, all Dependabot (#71, #100–#107, #133–#136). No product PR is in flight.

## Reconciled in this change

- [PR #132 merge receipt](../../verification/fixture-lifecycle-20261016/merge-receipt.md), reconstructed from the GitHub API and the local clone. Merge parents, tree equality, the reviewed-to-merged delta and the six preflight contexts were verified; nothing was re-executed.
- Current-pointer corrections in `CLAUDE-PLAN.md` (header, A5 row, dated ledger row), `DEVELOPMENT-AGENDA.md` (header, Fixtures/CI row, dependency item 2, Dependabot count) and `codex-deferred-20261011.md` (dated row). The packet README gains a merged pointer; its pre-merge text is unchanged.

## CI cost posture (verified 2026-10-17)

- **No automatic heavy runs.** `preflight.yml` triggers only on pull requests and runs no PHPUnit or MySQL. Foundation CI (`final-verification.yml`) and `focused-feedback.yml` are `workflow_dispatch` only, and Foundation requires an exact `expected_sha`. `.gitlab-ci.yml` is manual with the same SHA guard. No schedule exists.
- **Cost per preflight:** about 4 minutes of wall time, roughly 8 billed minutes. Only the 12 allowlisted documents in `scripts/ci/ci-scope.py` get the docs-only path; handoff and verification files take the full preflight.
- **PHP and MySQL evidence comes from local runs.** This session runs Oracle MySQL 8.4.11 (the CI version, checksum verified) from a private datadir under `/var/lib/va-mysql84`, loopback port 33584 and its own socket. No shared server is used. PHP is 8.4.26 and Node is 24.22.
- **Leftover trigger:** branch `codex/stems-preview-native-proof-20261003` carries `preview-regression-proof.yml`, which starts a MySQL 8.4 service and Playwright on any push to that branch. It last ran 2026-10-03. Deleting the branch needs Sean's approval.
- **Suggestion only, not applied:** Dependabot `groups:` (minor and patch together, majors separate) would cut 13 PRs to about 4 and avoid the rebase-and-rerun cascade. The setting belongs to Sean.

## Lanes from 2026-10-17

Each lane has one owner and its own worktree and branch from `origin/main`, and pushes once per verified candidate. A lane that needs native MySQL gets its own private server; no lane shares another lane's datadir. Payment, licensing, authorization, migration and irreversible-data batches get a separate independent reviewer before merge, as `AGENTS.md` requires.

| Lane | Scope | State |
| --- | --- | --- |
| R0 | This reconciliation | Draft PR, docs only |
| F2c | Inventory the 25 reviewed SQLite-only methods in `scripts/ci/database-mysql-skips.json` against native counterparts. Add meaningful native counterparts and regenerate the exact selection and skip census without altering the pinned prefix assertions. The lane owns the census files | Started |
| M1 | Dependency maintenance, patch and minor only: `laravel/framework` 13.36.0, `vite` 8.3.4, `@types/node` 24.19.2, `@inertiajs/react` 3.9.1, `jsdom` 30.1.2 | Started |
| B4f | The two deferred PR #127 P2s, r4287886519 (hook-list numeric holes) and r4287886535 (playlist create enabled when invalid or at capacity) | Started |
| P1 | Read-only work-package proposals for C4 member HTTP, the Tax UI/history index and the D1 mount/observer/first-byte composition, for review before implementation | Started |

**Held for separate reviewed changes:** Stripe 22 (#71, against the pinned 21.3.2 and API contract), PHPUnit 13 (#134, major), `tc-lib-pdf` and `tc-lib-pdf-font` (#135, #136, which need frozen-resource compatibility evidence), Playwright 1.64 (#103, which needs the pinned WebKit backport rechecked), and the major action bumps (#100–#102).

**Not ready without Sean:** C5 continuity (rollover, cancellation and dunning policy), D2 (exports), A5 provider acceptance (merchant account and refund, partial-tax and dispute rules), and every activation, host, DNS or live-payment step.
