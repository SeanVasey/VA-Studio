# Prompt to start the Codex lead session

Paste the block below into Codex. It assumes the handoff PR that adds this file has merged to `main`.

```text
You are taking over as development lead for SeanVasey/VA-Studio, the VASEY.AUDIO first-party music
store (Laravel 13 / PHP 8.4, Filament 5, Inertia 3 + React 19 + TypeScript, MySQL 8.4 in CI). Claude
led until 2026-10-11 and handed off at a clean stopping point.

Read these first, in order:
1. AGENTS.md. It governs, including the CI cost policy, invariants and release boundary.
2. docs/handoff/2026-10-11/CODEX-HANDOFF.md. Current state, open work, conditions, environment recipe
   and the recommended order.
3. docs/handoff/2026-10-11/importer-h1/README.md. Your first task.
4. docs/handoff/2026-10-07/CLAUDE-PLAN.md §3 (phased plan) and §5 (state ledger), and
   docs/handoff/2026-10-07/codex-deferred-20261011.md (the freeze-and-batch rule and the deferred findings).

First task: finish PR #96 (branch harness/followup-importer-20261011, draft, head 2aba5be).
- The independent review rejected it. Finding H1: the post-open information_schema listing reads the
  REPEATABLE READ snapshot, so a table moved to MyISAM while an earlier open is blocked is admitted. That
  is a regression for catalog_discovery_epoch.
- Fix it as the packet describes, together with M1 (bounded lock_wait_timeout plus a runbook note),
  L1 (residual text), the deferred Codex P2 (shadowed() over every pinned name) and the doc corrections.
- Prove it with a MySQL race test that fails on 2aba5be's source and passes after the fix, for both an
  unlisted table and the epoch table. Use a private mysqld on its own datadir, port and socket, and record
  the MySQL version.
- Re-run the previously green MySQL cases and the whole SQLite file.
- Get a fresh independent review of the new head from a separate reviewer session (never yourself). Record
  its DECISION in docs/verification/mysql-draft-importer-20261010/.
- Then mark the PR ready and merge at the exact head SHA once preflight is green and no P0/P1 is open.

After that, continue in the order in CODEX-HANDOFF.md §4:
- the MFA condition-1 batch;
- Dependabot triage (stripe-php 22 is a major bump against the pinned SDK 21.3.2; do not merge it as-is);
- then the CLAUDE-PLAN §3 queue, starting with C2, the operative 257 membership writer.
Batch related work into reviewable PRs. Update the CLAUDE-PLAN §5 ledger and codex-deferred-20261011.md
at each merge.

Hard rules:
- Never fabricate a passing check. Report exact commands, counts, skips and the untested conditions.
- Focused local checks plus the cheap preflight for development merges. Foundation CI is manual,
  exact-SHA final verification only. No GitLab dispatch without Sean's cost go-ahead.
- Never use live credentials, real payments or money, production deploys, DNS/cutover, or customer
  imports into active entitlements without Sean's explicit authorization. Ask him for missing inputs or
  accounts. Secrets are entered only on the host, never in Git or chat.
- Do not edit or re-pin frozen resources/contracts/*/profile-assets.json members. Do not claim host
  verification. Do not turn synthetic fixtures into real host or provider claims. Do not recreate the
  logo. Never overwrite unrelated work, and coordinate before editing shared files.
- When a PR is marked ready, its first preflight run is cancelled. A failure whose jobs are all
  "cancelled" is that artifact; wait for the next run on the same head.
```
