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
- **Applied in PR #141 (2026-10-18):** Dependabot `groups:` per ecosystem, minor and patch together, majors separate, with the exact-pinned test tools excluded from the npm group. The first grouped run opened #142 to #145.

## Lanes from 2026-10-17

Each lane has one owner and its own worktree and branch from `origin/main`, and pushes once per verified candidate. A PR is opened only for a final candidate, so each candidate costs one preflight; the lead marks it ready straight away, which cancels the opening run within seconds. A lane that needs native MySQL gets its own private server; no lane shares another lane's datadir. Payment, licensing, authorization, migration and irreversible-data batches get a separate independent reviewer before merge, as `AGENTS.md` requires.

| Lane | Scope | State |
| --- | --- | --- |
| R0 | This reconciliation | Merged as PR #137 |
| DR | Allowlisted-docs refresh: README current state, dated historical markers, refreshed task rows and a source-read parity status column | Merged as PR #138 (docs-only preflight) |
| F2c | Inventory the 25 reviewed SQLite-only methods in `scripts/ci/database-mysql-skips.json` against native counterparts. Add meaningful native counterparts and regenerate the exact selection and skip census without altering the pinned prefix assertions. The lane owns the census files | Merged as PR #146: 14 counterparts (16 cases), census 266 to 280, native selection 2,448 cases in 233 files. Remaining F2 work: row 18 `reviewed`, row 23 and review P3-1 to P3-6 |
| M1 | Dependency maintenance, patch and minor only: `laravel/framework` 13.36.0, `vite` 8.3.4, `@types/node` 24.19.2, `@inertiajs/react` 3.9.1, `jsdom` 30.1.2 | Merged as PR #141, with grouped Dependabot minor/patch updates |
| B4f | The two deferred PR #127 P2s, r4287886519 (hook-list numeric holes) and r4287886535 (playlist create enabled when invalid or at capacity), plus two latent full-suite failures found on the way: the account-feature guard rejected the encrypted session store that `.env.example` enables (every account-feature request would 503), and two stale `ProductionFeatureSourceClosureTest` cases | Merged as PR #139 (independently approved at `89f0340d`) |
| P1 | Read-only work-package proposals for C4 member HTTP, the Tax UI/history index and the D1 mount/observer/first-byte composition | Done. Recommended order: Tax history index (M), then D1 mount (L), then C4 member HTTP (L). C4 waits on Sean's `explicit_owner_confirmation_v1` decision and an unresolved frozen-renderer launch path under PHP-FPM; C4 and D1 share `routes/web.php`, `bootstrap/app.php` and the census, so they run serially |
| T1 | Paid Tax history index: a bounded owner-closed `PaidTaxReads::index`, a default-off JSON route under the existing Tax mount, and a page shell that carries no private props, so the Tax privacy rules need no exception. Native coverage goes into the existing `PaidTaxGrantJourneyTest` without census edits | Merged as PR #147 (approved at `e947cdb3`; Codex P1 on client tax arithmetic fixed in `6066e772` and re-review approved). Deferred Tax P3s are in the deferred ledger |
| O1 | Production operations kit (default-off templates, ClamAV config, opt-in encrypted off-host backups, monitoring), then K1 (staging kit re-baseline on current main) and K2 (missing staging capability flags) | Approved after three reviews (`b8d5c59d` with conditions, `94c70fd6` and `28a3c8af` approve). The main merge exposed the C1 synthetic flag to two drift guards; fixed in `70573daf`. PR carries the PR #147 ledger update |
| C1 | Labelled synthetic staging rehearsal catalog, approved by Sean on 2026-10-17: a default-off, production-refusing command that builds `[SYNTHETIC]` tracks through the real domain services | Merged as PR #140 (approved at `cfc4d6a4` after its review conditions were fixed). On staging it is blocked until the S-6 seller tag is installed or Sean approves a staging-only synthetic tag |
| H1, R1 | Read-only hosting packet (U-02) and parity/release-readiness audit | Done; staging stays Forge + DigitalOcean (decided 2026-10-08), production recommended as a second identical Forge VPS; findings fed DR, O1 and C1 |

**Concurrency note (2026-10-18):** running seven or eight agents at once exhausted the account's usage window and paused every lane together, and the container then rebooted. Interrupted runs are recorded as interrupted. Keep at most about four heavy lanes at a time.

**Held for separate reviewed changes (as of PR #146):** Stripe 22 (#71, against the pinned 21.3.2 and API contract), PHPUnit 13 (#134, major), Playwright 1.64.1 (#145, which needs the pinned WebKit backport rechecked) and the major action bumps (#100 to #102). The grouped Composer PR #142 bundles `tc-lib-pdf` 8.80.1 and `tc-lib-pdf-font` 4.6.2 with Filament 5.10.2 and Laravel 13.37.0; the PDF libraries need frozen-resource compatibility evidence, so they should leave the `composer-minor-patch` group in the next `.github/dependabot.yml` change (with the deferred `@testing-library/*` npm exclusions) and #142 waits until then. #143 (React 19.3.1 group) and #144 (vitest 5.0.4) are ordinary candidates for the next dependency lane. Dependabot closed the superseded #103, #135 and #136.

**Not ready without Sean:** C5 continuity (rollover, cancellation and dunning policy), D2 (exports), A5 provider acceptance (merchant account and refund, partial-tax and dispute rules), and every activation, host, DNS or live-payment step.
