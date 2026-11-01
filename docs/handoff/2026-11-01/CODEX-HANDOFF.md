# Handoff to Codex — 2026-11-01

Sean asked the Claude Code harness to finish the lanes in flight, document what remains and hand development to Codex
because of usage limits. This note is the entry point. Root `AGENTS.md` governs, including the October 6 CI cost policy.
Sean's current instructions override older documents.

Read in this order:

1. `AGENTS.md`.
2. This note.
3. `docs/handoff/2026-10-24/COMPLETION-PLAN.md`: the lanes, waves and critical path to launch.
4. `docs/handoff/2026-10-17/RESUMPTION.md`: one row per lane, with state and the evidence pointer.
5. `docs/handoff/2026-10-07/codex-deferred-20261011.md`: every deferred review finding, and which gate it blocks.

The prompt Sean gives the Codex session is in [`CODEX-PROMPT.md`](CODEX-PROMPT.md). The October 26 handoff
(`docs/handoff/2026-10-26/CODEX-HANDOFF.md`) is history; this note supersedes it.

`docs/handoff/2026-10-07/CLAUDE-PLAN.md` is the dated merge ledger. Append to it, and never rewrite earlier rows.

## Checkpoint

- **Main:** `1c2d55a2` (PR #185 merge, 2026-11-01), plus this handoff PR.
- **Merged since the October 26 handoff:**

  | PR | Lane | Content | Merge |
  | --- | --- | --- | --- |
  | #178 | REP | Staff ops dashboard, sales report, consent-aware segments (default-off) | `76fca3d7` |
  | #179 | SEC2 | `symfony/mime` 8.1.10 for CVE-2026-45070 | `76fb9338` |
  | #181 | OPS-FIX | `REPORTING_*` flags registered in both ops kits | `a926f98b` |
  | #182 | F9 | Member index proves schema guards once per owned frame | `c203f779` |
  | #183 | CX | Legal pages, order-ready mail, customer data rights (default-off) | `8bb1ab24` |
  | #184 | FUB-1 | REP and CX follow-up fixes | `3e8a1fd7` |
  | #185 | CON | Contract work transitions stored at exactly their decision second | `1c2d55a2` |

  Every receipt is in `CLAUDE-PLAN.md`.
- **Nothing is live.** Every production capability is default-off. No live key, payment, deploy, DNS change or customer
  import has happened. No Foundation CI run has been made since `0f23e63f`.
- **Open PRs:** only Dependabot (#180 composer and #176 npm minor/patch groups; #161, #162, #164, #145, #100–#102, #71 pins and majors).
  Treat them as a dependency batch under `AGENTS.md` and CLAUDE §6: patch/minor ride along when the gate stays green;
  each major gets its own commit with the changelog reviewed.

## Lane state

- **PCC, production checkout composition: built, not reviewed, no PR.** Branch `harness/production-checkout-pcc-20261029`,
  pushed at `cacc9fb0` (its packet says "not pushed"; that line predates the push). Packet:
  `docs/verification/production-checkout-pcc-20261029/README.md`; start at "Status at handoff (2026-11-01)".
  - What it is: the production checkout mount gate (`production_checkout.mounted`, a literal `false` held by the production
    validator), provider registration and route mount behind it, the privacy seam's runtime re-check, the exclusivity
    authority gate, and the operator command `vasey:production-checkout-reconcile`. `ExplicitTrustProxies` is the one
    default behaviour change: no proxy is trusted by default, including on `.on-forge.com` hosts.
  - It splits into PCC-a (operations, up to `ab9e6336`) and PCC-b (composition, `1382f151` to `0515b15f` plus docs). One PR
    or two is your call; PCC-a does not depend on PCC-b.
  - Evidence so far: mutations M1 to M10 killed; 22 targeted suites OK (372 tests); native MySQL 16 tests / 103 assertions
    OK; ops tests and safeguards OK. The full SQLite suite never finished (container restarts); every failure seen so far
    is classified in the packet as a load timeout or pre-existing on main, and none is in PCC code.
  - Both pre-existing failures it found are fixed by this handoff: the contract second-boundary defect (CON, merged as PR #185) and
    the `TransactionalMailPolicy.php` census entry (`10a6281a`, in this PR).
  - **Next:** merge the new main into the branch; finish the full SQLite suite (packet "What remains", step 1); have an
    independent payment/auth review of the exact head, using the focus list in step 2 (the production admission in the
    mount gate is a named conflict for the reviewer to decide); then PR and merge per the routine below.
  - Not in PCC, pushed back to their own lanes: A5 `FinancialReadPlan` production wiring, and the Paid252 and Tax
    production mounts.
- **CON, contract second-boundary fix: merged as PR #185 (`1c2d55a2`).** Review APPROVE at `0fb1c93e`, no P0 to P2;
  P3-1 to P3-3 are in the deferred ledger. The new test is in the native MySQL selection. Packet:
  `docs/verification/contract-second-boundary-20261101/README.md`.
- **Census fix on main:** `StagingEnvironmentAdmissionTest`'s source census failed on main since `8bb1ab24` because CX's
  `TransactionalMailPolicy.php` has a local/testing gate and was not listed. `10a6281a` (this PR) adds it to
  `LOCAL_ONLY_FILES` with a reason: it is a refusal, not an admission. The test is OK (15 tests, 233 assertions).
- **No other lane is in flight.** The member mount gates (F9-R1, N7) were never started as lanes; they are item 2 below.
- **Security alerts:** GitHub reports 4 Dependabot alerts on the default branch (2 high, 2 moderate). `npm audit
  --audit-level=high` and `composer audit --locked` were both clean at `3e8a1fd7` in this container, so read the alerts in
  the GitHub security tab and triage them with the Dependabot batch (item 6).

## How a lane reaches main (the routine that worked)

1. **Work package first.** State the files owned, assumptions, acceptance criteria and tests in a packet (`docs/verification/<lane>-<date>/README.md`) before coding.
2. **Focused local checks only.**
   - Direct PHPUnit on SQLite with the CI environment. Keep `public/build` absent; with a Vite manifest, seven HTTP tests get 409.
   - Copy `.env.example` to `.env`. It sets `SESSION_ENCRYPT=true`, as CI does.
   - Command:

     ```
     env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:$(head -c32 /dev/zero | base64) DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning <files>
     ```
3. **Native MySQL for every locking, race or latency claim.**
   - Use a private Oracle MySQL 8.4.11 on its own datadir, short socket path and free port (check with `ss -ltnp`).
   - Never stop, signal or share another session's server.
   - SQLite never proves MySQL concurrency.
   - **MySQL `ONLY_FULL_GROUP_BY`:** Filament adds a default key sort, which breaks grouped tables (error 1055). Sort by aggregates only, or use `defaultKeySort(false)`.
4. **Census and selection.**
   - `scripts/ci/database-sqlite-skips.json` is append-only. Its first 150 entries are hash-pinned (`d93da4e7…`).
   - When two branches both append, the second to merge unions the lists: main's entries first, then its own.
   - Regenerate the native selection:

     ```
     python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection
     ```

     Main prints 2678 of 9950 cases in 265 files.
   - Update the count comments in `.github/workflows/final-verification.yml` (about line 151) and `.gitlab-ci.yml` (about line 113), then `rm -f phpunit-ci-mysql-*`.
   - Run the safeguards: `scripts/ci/test-phpunit-shards.py`, `test-ci-scope.py`, `test-workflow-cadence.py`, `test-focused-tests.py`, `test-database-receipts.py`.
5. **Mutation checks** on every security-relevant guard. Record killed and surviving mutants in the packet.
6. **Independent review** for payment, licensing, authorization, migration and irreversible-data changes (`AGENTS.md`).
   - A separate reviewer reviews the exact tested SHA, writes a decision record, and the record is copied into the packet as `independent-<lane>-review-<sha>-DECISION.md`.
   - Fix the P0/P1/P2 findings found before the PR opens.
7. **PR.**
   - Follow `.github/PULL_REQUEST_TEMPLATE.md`. Open it as a draft, then mark it ready.
   - Marking it ready cancels the first "Development preflight" run within seconds. That shows as a failure; the replacement run is authoritative.
   - Wait for: the replacement preflight, Codex code review, Codex security review and Cursor Bugbot.
8. **Freeze-and-batch (Sean, 2026-10-11).**
   - Merge at the exact SHA with `expectedHeadSha` once the preflight is green, the independent review approves and no P0/P1 is open.
   - Defer each P2 or P3 bot finding with one thread reply naming the follow-up lane, resolve the thread, and record it in the deferred ledger.
   - No single-finding pushes.
9. **After merge.** Add a dated row to `CLAUDE-PLAN.md`, update the lane row in `RESUMPTION.md`, and merge the new main into every open lane branch.
10. **Lanes that add environment keys must run `tests/ops`** (`python3 -m unittest discover -s tests/ops -p 'test_*.py'`; 326 tests OK at `1c2d55a2`). The development preflight
    does not run it, which is how PR #178 merged with two red ops checks (fixed by #181).
11. **Concurrency.** Three or four lanes at once saturate the 4-core host (load 8 to 13); native timing claims made under
    that load are not idle-host numbers, and fixtures with 60 s budgets start failing in their own setup. Run native
    tests one process per method or file, and say what the load was.

## Next work, in order

1. **Finish anything left in "Lane state" above** that is not yet merged, through steps 6 to 9 of the routine.
2. **Member HTTP mount gates** (both must land before any non-local mount; `MembershipHttpMount` stays literal-false
   until then):
   - **F9-R1, 100-period headroom.** The index plus pre-bytes took 53.9 to 60.4 s at host load 5 to 9 for 100
     periods (27 to 30 s at load 5). Options: a smaller page with paging; fewer of G1's identity dependents scans (58 %
     of the remaining time, needs its own review because it changes G1/C4d guard density); a measurement on the target
     host. Packet: `docs/verification/member-index-f9-20261024/`.
   - **N7, a per-customer rate limiter** for member HTTP (today's limits are per client address).
   - Then F9-R2 (committed-frame read-only test), F9-R3 (one committed identity proof per distinct binding, unmeasured
     for many recoveries), F9-R4 (commit the reviewer's recovery probe as a test).
3. **Deferred-finding batches** (`docs/handoff/2026-10-07/codex-deferred-20261011.md`):
   - **MIG `verified_grant` gates** (hard gates before that mode is activated): R-2, V3, Codex P2 r4368837479
     (zero-value exclusive), Codex P2 r4368837476 (missing-contract declaration), Bugbot r4368848623.
   - **A5 display:** Codex P2 r4368889957 and Bugbot r4368906963 (staff order detail sums every retained allocation;
     use the status-aware `refundedByLine()` totals).
   - **DEF-OPS:** P3-1 to P3-3.
   - **C4e:** P3-2 (R3 census does not cover `definitionByHash` call sites), N4, N5.
   - **REP:** P3-6 (indexes: `finalized_at`, `quotes.created_at`, `orders.created_at`, `customer_inquiries.state`,
     `refund_line_allocations(order_id)`; needs a migration) and R-1 (mark test accounts positively; needs a migration
     and the U-13 decision).
   - **CX:** N1 (export assembled under READ COMMITTED), N2 (retry budget, up to about 150 s; Sean's call), F4
     (schedule `vasey:customer-data-exports:prune` once a host exists), FUB-1 F3 (cross-run scan cursor for
     `vasey:transactional-mail-queue`; needs new storage), FUB-1 re-review P3-1 to P3-4.
   - **G1 and T36 deferred P3s**, and the PR #169 legal P2 if `PRIVACY.md` still over-claims anywhere.
4. **Remaining completion-plan lanes** (`docs/handoff/2026-10-24/COMPLETION-PLAN.md` §3 and §5), in wave order:
   - **PF1** collections and sound kits storefront, licensing, cart lines, checkout and delivery (needs PCC's public
     mounts and Sean's scope decision #5);
   - **CAT** track scheduling, private and unlisted publishing, media library;
   - **UXA** automated accessibility audit;
   - **DEF-ID** identity deferred findings;
   - **FND** final-candidate F2 and census normalization.
5. **Before the single final Foundation run:**
   - fix `scripts/ci/test-related-browser-stage.py` (fails 4 of 12 on main for environment reasons: no `public/build`
     and blocked ClamAV signatures; DEF-OPS added diagnostics; it runs only in Foundation and GitLab);
   - `tests/ops/test_staging_protected_configuration.py` failed in the cloud container after `a8f17e5c` (its
     "probe-proven CLI PHP" admission refused); it passes again at `1c2d55a2` (3 tests OK), so the failure was
     environmental. Rerun it on the staging host before relying on it;
   - then one manual Foundation dispatch with the exact reviewed 40-character `expected_sha`.
6. **Dependabot:** the open PRs listed in the checkpoint, as one batch per the rules above.

## Waiting on Sean (don't guess these)

Full list and defaults: the decision list in the completion plan §2.

- Is the staging Forge server up, and what is its hostname?
- Stripe test keys and webhook secret, entered in the Forge environment only (never in chat or Git).
- BeatStars exports and the read-only Studio walkthrough (the biggest scope lever).
- Currency and tax strategy.
- License tier caps and prices. `docs/legal/LICENSE-TIERS.md` is a draft with no prices.
- Refund policy, exclusive hold and re-download window.
- City, ZIP and Iowa venue county for the legal pages.
  - Governing law is Iowa, USA. The merchant is Vasey Multimedia dba VASEY.AUDIO, and the seller is Sean Vasey.
  - The street address is known, but it must not be committed. The repository is public; use the placeholder "[registered postal address — inserted at publication]".
- Minimum age.
- Email sender and provider.
- Storage (R2 or another store).
- Membership plans.
- Member activation wording and hashes.
- Approval to delete branch `codex/stems-preview-native-proof-20261003`.
- Approval of the one final Foundation run's cost.

## Hard limits (unchanged)

- Without Sean's separate authorization, nothing touches:
  - live credentials, live payments or real money;
  - production deploy or DNS;
  - importing customers into active entitlements;
  - retiring BeatStars.
- Never commit secrets, customer exports, masters, stems or contracts.
- Don't invent prices, license terms or tax rules. Policy values stay unset and refuse when unset.
- Don't recreate the logo. Don't edit `resources/contracts/*/profile-assets.json`.
- VASEY.AUDIO only, never VASEY/AI. The contact is `support@vasey.audio`.
- No routine full MySQL, SQLite or browser matrices. Foundation CI is manual, with an exact SHA; GitLab dispatch only with Sean's cost go-ahead.
- Never weaken, skip or quarantine a test or audit to get green.
