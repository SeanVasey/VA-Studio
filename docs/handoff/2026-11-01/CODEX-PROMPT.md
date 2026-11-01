# Prompt for the Codex session (2026-11-01)

Paste the block below into Codex, with the repository `SeanVasey/VA-Studio` attached.

```text
You are taking over development of the VASEY.AUDIO store (SeanVasey/VA-Studio) from the Claude Code harness, which
stopped at a clean checkpoint because of usage limits. Start from the latest main. The code checkpoint is 1c2d55a2
(PR #185); the handoff documents merged on top of it. Nothing is live.

Read first, in order, and follow them:
1. AGENTS.md. It governs. That includes the October 6 CI cost policy: no routine full MySQL/SQLite/browser
   matrices, and Foundation CI is manual only, with an exact 40-character expected_sha.
2. docs/handoff/2026-11-01/CODEX-HANDOFF.md: the checkpoint, the lane still in flight, the merge routine that
   worked, the next work in order, what waits on Sean, and the hard limits.
3. docs/handoff/2026-10-24/COMPLETION-PLAN.md, docs/handoff/2026-10-17/RESUMPTION.md and
   docs/handoff/2026-10-07/codex-deferred-20261011.md.

Then continue in this order (the handoff's "Next work" has the detail):
A. Lane PCC, production checkout composition, default-off. Branch harness/production-checkout-pcc-20261029
   (cacc9fb0); its packet section "Status at handoff (2026-11-01)" has the exact commands.
   - Merge main into the branch. Main now carries the two pre-existing fixes PCC's full suite tripped on.
   - Finish the full SQLite suite at the merged head. Rerun any timeout alone before classifying it.
   - Get an independent payment/auth review of the exact tested SHA, using the packet's focus list. Fix its
     P0-P2 findings before the PR.
   - Open the PR (one, or PCC-a and PCC-b), mark it ready, and wait for the replacement preflight, the Codex code
     and security reviews, and Bugbot. Merge with expectedHeadSha. Record the receipt in CLAUDE-PLAN.md and
     RESUMPTION.md.
B. The member HTTP mount gates: F9-R1 (100-period headroom) and N7 (a per-customer rate limiter), then F9-R2 to
   R4. MembershipHttpMount stays literal-false until both land.
C. The deferred-finding batches. The MIG verified_grant gates come first, because they block activating that mode.
D. The remaining completion-plan lanes: PF1, CAT, UXA, DEF-ID, FND.
E. Before the single final Foundation run: fix scripts/ci/test-related-browser-stage.py, and confirm
   tests/ops/test_staging_protected_configuration.py on a host with real PHP-FPM.
F. Dependabot: the open PRs and the 4 security alerts on main, as one batch (CLAUDE.md section 6).

Rules that held this project together:
- Native MySQL claims need a private Oracle MySQL 8.4.11 on its own datadir and port. SQLite never proves
  concurrency.
- The census scripts/ci/database-sqlite-skips.json is append-only (the first 150 entries are hash-pinned). When
  merging, take main's entries first, then the branch's. Regenerate the native selection and both workflow count
  comments.
- Any lane that adds environment keys runs python3 tests/ops/test_*.py; the preflight does not.
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
