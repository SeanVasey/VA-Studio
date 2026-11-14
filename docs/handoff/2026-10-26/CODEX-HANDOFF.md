# Handoff to Codex — 2026-10-26

Sean asked the Claude Code harness to stop at a checkpoint and hand the remaining development to Codex because of
usage limits. This note is the entry point. Root `AGENTS.md` governs, including the October 6 CI cost policy. Sean's
current instructions override older documents.

Read in this order:

1. `AGENTS.md`.
2. This note.
3. `docs/handoff/2026-10-24/COMPLETION-PLAN.md`: the lanes, waves and critical path to launch.
4. `docs/handoff/2026-10-17/RESUMPTION.md`: one row per lane, with state and the evidence pointer.
5. `docs/handoff/2026-10-07/codex-deferred-20261011.md`: every deferred review finding, and which gate it blocks.

The prompt Sean gives the Codex session is in [`CODEX-PROMPT.md`](CODEX-PROMPT.md).

`docs/handoff/2026-10-07/CLAUDE-PLAN.md` is the dated merge ledger. Append to it, and never rewrite earlier rows.

## Checkpoint

- **Main:** `dd587603af8330c51eac878331c217802c8b720e` (PR #174 merge, 2026-10-25T00:59Z).
- **Merged in the October 24–25 run:**

  | PR | Lane | Content | Merge |
  | --- | --- | --- | --- |
  | #165 | D1 | Production free mount | `a08e79ca` |
  | #166 | O4 | Cache-row prune | `5b0a6606` |
  | #167 | M4 | Laravel 13.38, vitest, jsdom | `786fb7c7` |
  | #168 | G1 | Writer schema proof once per frame | `e5d0d3f2` |
  | #169 | LEG | Legal drafts and completion plan | `692d8951` |
  | #170 | T36 | Legacy redirects and JSON-LD | `707649a6` |
  | #171 | MIG | History archive, delta and exclusivity authority | `62e0296d` |
  | #172 | A5 | Refunds and disputes | `8f166c5a` |
  | #173 | DEF-OPS | Deferred ops batch | `812617be` |
  | #174 | C4e | Default-off member HTTP | `dd587603` |

  Receipts are in `CLAUDE-PLAN.md` (through #173) and below (#174).
- **PR #174 receipt (not yet in `CLAUDE-PLAN.md`; add it in your first ledger change):**
  - Merged from exact head `93b0784a` (merge of main `812617be`).
  - Preflight: 40148483411 was the readiness cancellation; the replacement 40148514540 succeeded. Bugbot clean. Codex security review clean.
  - Codex code P2 r4369151991 (reservation body key order) was deferred to the F9 lane with one reply.
  - Reviews:
    - `b3deef84`: APPROVE WITH CONDITIONS.
    - `9d1e09f6`: APPROVE.
    - `ad1647ff`: APPROVE WITH CONDITIONS. F9 and N7 block any mount. P3-1 and P3-3 were fixed in `3629a135`; P3-2 is deferred.
  - Records: `docs/verification/member-http-c4e-20261024/`.
- **PR #173 note:** its Codex security review hung for more than 75 minutes. A re-request started only a code review, which was clean. It was merged on the independent review, with the stall recorded.
- **Open PRs at the checkpoint:** only Dependabot.
  - Minor and patch groups: #175 (Composer) and #176 (vite 8.3.5).
  - Pins and majors: #164 and #162 (tc-lib-pdf, excluded from the group on purpose by M2/DEF-OPS), #161 (PHPUnit 13, major), #145 (Playwright, exact-pinned), #100–#102 (Actions majors), #71 (stripe-php 22, major).
  - Treat them as a dependency batch under `AGENTS.md` and CLAUDE §6: patch/minor ride along when the gate stays green; each major gets its own commit with its changelog reviewed.
- **Nothing is live.**
  - Every production capability is default-off.
  - No live key, payment, deploy, DNS change or customer import has happened.
  - No Foundation CI run has been made since `0f23e63f`.

## Lanes in flight (pushed branches, no PRs yet)

Each branch was pushed at the checkpoint. No PR is open for any of them: under the CI cost policy a PR is opened only for a final, reviewed candidate, because each push to an open PR runs the preflight. A branch that has a "Status at handoff (2026-10-26)" section in its packet README states its own state. That section is the authority, and this table only summarizes it. None of these branches has had its independent review yet.

| Lane | Branch | Head at checkpoint | State | Next step |
| --- | --- | --- | --- | --- |
| F9: member index latency (blocks any member HTTP mount) | `harness/member-index-f9-20261024` | `a5675ddc` | The index opens G1's owned frame, so schema guards prove once per frame. On MySQL 8.4.11 (loaded host), 100 periods went from refused at the 60 s deadline to 33–35 s; 1 period went from 19.5 s to 9 s. Statements per extra period fell from 4,032 to 276. A new native growth test counts statements, not timing. Verified so far: the availability test 7/180 on both engines, and the growth test | 1. Merge main `dd587603`.<br>2. Apply the Codex P2 key-order fix in `MembershipController::reserve()`.<br>3. Regenerate the selection.<br>4. Re-run the full native and SQLite membership suites, R1 and R4.<br>5. Run the mutations (driver in `evidence/`).<br>6. Re-measure latency, including 100 periods with a concurrent award.<br>7. Independent review.<br>Packet: `docs/verification/member-index-f9-20261024/README.md` |
| CX: legal pages, order-ready mail, data export/deletion | `harness/customer-comms-cx-20261024` | `21b41c3e` | Built, all default-off: site-content schema v5 legal pages (`/legal/{privacy,terms,licenses}` only from a published release; the `PRIVACY.md` §8 encryption wording is fixed); data export/deletion that refuses while retention values are unset; an order-ready mail adapter that refuses without a sender, mailer and passing preflight. Three new migrations (`2026_10_24_268100`, `268200`, `268300`). SQLite `tests/Feature/CustomerComms` 96 OK; MySQL 10/12; mutations M1–M14 all killed. ESP suppression (FP-081/091) not built (waits on U-13) | 1. Fix the known failures: three SQLite migration roundtrip tests (a trigger references the dropped `transactional_notices`; remove that `EXISTS` clause) and one MySQL trigger-order assertion (sort in the test).<br>2. Merge main.<br>3. Union the census and regenerate.<br>4. Re-run the native and SQLite suites.<br>5. Independent review (migrations, privacy).<br>Packet: `docs/verification/customer-comms-cx-20261024/README.md` |
| REP: ops dashboard, sales report, customer segments | `harness/reporting-rep-20261024` | `0692b3c0` | Main `dd587603` merged; selection 2589 cases in 255 files. FP-028 dashboard widgets; FP-089 sales and conversion report with audited CSV (refunds netted through the A5 ledger, status-aware, test and production separate); FP-090 consent-aware segments, default-off, with no contact data. All six reporting files pass on MySQL 8.4.11 (on pre-merge app code, unchanged by the merge). SQLite 23/393 OK. Mutations 22/25 killed (3 explained survivors). FP-069 skipped (U-11) | 1. Re-run the six reporting files natively on the merged head.<br>2. Independent review (authority, refund netting, consent filtering).<br>3. PR.<br>Packet: `docs/verification/reporting-rep-20261024/README.md` |
| PCC: production commerce composition | none yet; create `harness/production-checkout-pcc-<date>` from main | n/a | Not started | See "Next work" item 2 |

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

     Main prints 2579 of 9774 cases in 249 files.
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

## Next work, in order

1. **Finish and merge the four in-flight lanes** above, each through steps 6–9.
   - F9 is a hard gate before any member HTTP mount, together with N7 (a per-customer rate limiter, not yet built).
   - Each branch must merge main `dd587603` first.
2. **PCC: production commerce composition, default-off** (completion plan, row PCC; `docs/ops/production-activation-packet.md` §1). The branch exists with no commits. Do the following:
   - Register `ProductionCheckoutServiceProvider` behind a literal-false mount gate. Copy the reviewed `MembershipHttpMount` / `ProductionFreeHttpMount` pattern, and mount `routes/production-checkout.php` only through it.
   - Add a retrieval-only production reconcile operator command. It refuses without explicit flags and is idempotent.
   - Wire `ExclusivityAuthority::storeMaySellExclusives()` (PR #171), so exclusives are refused unless the authority allows them.
   - Wire `FinancialReadPlan` output into the A5 ledger (PR #172 packet, "Not wired"), with no double-record on replay.
   - Parametrize the preflight and validator: real mail or live mode only with explicit flags. Defaults are unchanged, and the capability hold stays intact.
   - Set `trustProxies` explicitly, and add production identity SMTP config.
   - It may split into PCC-a (ops, preflight, reconcile) and PCC-b (provider, mounts, wiring).
   - It needs an independent payment/auth review.
3. **Follow-up batches** (deferred ledger):
   - **MIG `verified_grant` gates** (must land before that mode is activated): R-2, V3, Codex P2 r4368837479 (zero-value exclusive), Codex P2 r4368837476 (missing-contract declaration), Bugbot r4368848623.
   - **A5 display:** Codex P2 r4368889957 and Bugbot r4368906963.
   - **DEF-OPS:** P3-1 to P3-3.
   - **C4e:** P3-2, N4, N5.
   - **Legal:** Codex P2 on the `PRIVACY.md` encryption wording, if CX hasn't fixed it.
   - **G1 and T36 deferred P3s.**
4. **N7:** a per-customer rate limiter for member HTTP.
5. **Remaining completion-plan lanes:** PF1 (collections and kits storefront), CAT, UXA (automated a11y audit), DEF-ID, FND (final-candidate F2/census normalization). Follow the completion plan's waves and dependencies.
6. **Before the single final Foundation run:**
   - Fix `scripts/ci/test-related-browser-stage.py`. It fails 4 of 12 on main for environment reasons: no `public/build` and blocked ClamAV signatures. DEF-OPS added diagnostics. It runs only in Foundation and GitLab.
   - Then run one manual Foundation dispatch with the exact reviewed 40-character `expected_sha`.

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
