# Prompt for the Codex session (2026-10-26)

Paste the block below into Codex, with the repository `SeanVasey/VA-Studio` attached.

```text
You are taking over development of the VASEY.AUDIO store (SeanVasey/VA-Studio) from the Claude Code harness, which
stopped at a clean checkpoint because of usage limits. Main is dd587603 (PR #174). Nothing is live.

Read first, in order, and follow them:
1. AGENTS.md — it governs. That includes the October 6 CI cost policy: no routine full MySQL/SQLite/browser
   matrices, Foundation CI is manual only with an exact 40-character expected_sha, and a PR is opened only for a
   final, reviewed candidate.
2. docs/handoff/2026-10-26/CODEX-HANDOFF.md — the checkpoint, the lanes in flight, the merge routine that worked,
   the next work in order, what waits on Sean, and the hard limits.
3. docs/handoff/2026-10-24/COMPLETION-PLAN.md, docs/handoff/2026-10-17/RESUMPTION.md and
   docs/handoff/2026-10-07/codex-deferred-20261011.md.

Then continue in this order:
A. Finish the three pushed work-in-progress lanes. Each packet README has a "Status at handoff (2026-10-26)"
   section with the exact next steps and commands:
   - harness/member-index-f9-20261024 (a5675ddc)
     - Merge main, then add the Codex P2 key-order fix in MembershipController::reserve().
     - Rerun the native and SQLite membership suites, run the mutations and re-measure latency.
     - F9 must land before any member HTTP mount, together with N7 (a per-customer rate limiter, not built yet).
   - harness/customer-comms-cx-20261024 (21b41c3e)
     - Fix the three SQLite migration roundtrip failures and the one MySQL trigger-order assertion.
     - Merge main, union the census and rerun.
   - harness/reporting-rep-20261024 (0692b3c0)
     - Main is already merged. Rerun the six reporting files on native MySQL at the merged head.
   Each of the three needs an independent review of its exact tested SHA before its PR (payment, authorization,
   migration and personal data are involved). Then open the PR, mark it ready and wait for the replacement
   preflight, the Codex code and security reviews, and Bugbot. Merge with expectedHeadSha. Record the receipt in
   CLAUDE-PLAN.md and RESUMPTION.md.
B. Lane PCC, production commerce composition, default-off (handoff "Next work" item 2).
C. The deferred-finding batches. The MIG verified_grant gates come first, because they block activating that mode.
D. The remaining completion-plan lanes (PF1, CAT, UXA, DEF-ID, FND).
E. Before the single final Foundation run, fix scripts/ci/test-related-browser-stage.py.

Rules that held this project together:
- Native MySQL claims need a private Oracle MySQL 8.4.11 on its own datadir and port. SQLite never proves
  concurrency.
- The census scripts/ci/database-sqlite-skips.json is append-only (the first 150 entries are hash-pinned). When
  merging, take main's entries first, then the branch's. Regenerate the native selection and both workflow count
  comments.
- Freeze-and-batch: merge once preflight is green, the independent review approves and no P0/P1 is open. Defer each
  P2/P3 bot finding with one thread reply and a deferred-ledger entry. No single-finding pushes.
- Never weaken, skip or quarantine tests or audits. Never claim a result you did not run. Record untested
  conditions.

Hard limits:
- Without Sean's separate authorization: no live keys, payments, deploy, DNS, customer import or BeatStars
  retirement.
- Never commit secrets or customer data.
- No invented prices, license terms or tax rules; policy values stay unset and refuse when unset.
- Don't recreate the logo.
- The repository is public, so the seller's street address must never be committed.
- Brand is VASEY.AUDIO only (never VASEY/AI); the contact is support@vasey.audio.

Report progress at meaningful checkpoints with exact commands, outcomes and SHAs. Ask Sean only for the items in the
handoff's "Waiting on Sean" list.
```
