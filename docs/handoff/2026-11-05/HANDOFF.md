# Claude harness checkpoint — 2026-11-05

Entry point for the next session (Claude's usage resets Saturday). It supersedes the Codex checkpoint of 2026-11-04 for
current state. That checkpoint's archive, bundle and recorded results remain history. `AGENTS.md` governs. Sean's instructions
override older documents.

## Checkpoint 2026-11-12

Added 2026-11-12 by the Claude Code harness. It is the current state; the sections below describe 2026-11-05 and are kept as history.
Sources: `git log origin/main`, the staging host's deploy logs, and the harness's running notes for 2026-11-11. Measurements marked
"measured" were taken on the staging host by the operator; nothing here is acceptance.

**Main** is `9a9f7dc8` (PR #342). **Staging** serves `ead7368d` (Forge deployment 82759032, live since 2026-11-12 00:02 UTC) and so includes PRs #322 to #340. It does not include #342 (the registry classification of the sound-kit purchase columns). Nothing is live outside staging; no live keys, payments, DNS or customer data are involved.

### Merged since 2026-11-10 (squash SHA on main)

| PR | SHA | What |
| --- | --- | --- |
| #322 | `0307dd72` | Per-track and store-default control of the seller tag on public previews |
| #324 | `67b4c020` | Public listing no longer hashes private deliverables (pages were 10 to 50 s) |
| #325 | `23f9680c` | Public renders read each eligibility row once per request (about 1.1 to 1.4 s warm, measured) |
| #326 | `076cbf0f` | Off-host backup shipping after the site resumes |
| #328 | `7b7d1b2f` | Sound-kit Stripe-test purchase, grant, contract and delivery (BL-13) |
| #327, #330 | `089ccdcf`, `9e29270d` | Checkout return page refreshes itself; stale-closure fix |
| #329, #332 | `c7307262`, `6233f462` | Migration roundtrip test fix; CI ops-kit step no longer needs Launchpad |
| #333 | `81e9fa61` | Test unpaid release admitted as an optional staging capability |
| #334 | `b5a4cea4` | Staff view of kit test orders, scheduled kit contract issuance, fair grant sweep |
| #331, #336 | `c9a114cf`, `7dac3969` | Staging alpha test guide; follow-ups (kit order page refresh every 10 s for up to 15 min) |
| #335 | `b18ca08e` | Backup sets share unchanged private files: deploy downtime about 22 min to about 5 min |
| #338 | `101c10e8` | Browser specs collected no tests (bare JSON import, broken since #268); `accessibility-browser` suite |
| #337 | `d61d90de` | Early backup space gate counts only what will be copied |
| #340 | `f45755ab` | Accessibility defects and stale browser helpers; nine `browser-*` suites |
| #339 | `ead7368d` | Staff page to enable and block delivery for track test orders |
| #342 | `9a9f7dc8` | Sound-kit purchase columns classified in the media reference registry |

### Live on staging (verified)

- **Track purchase**, order `84857de0`, 2026-11-12: payment, grant and contract happen by themselves; the order reads "Contract issued, activation pending" for about 2 to 3 minutes, then "Ready, delivery not enabled"; staff enable delivery (admin **Test commerce > Test track orders**, or the console command); the buyer then downloaded the contract PDF and the MP3. Enabling stays a deliberate per-order step (D-19). The Filament page was not driven in a browser with a TOTP sign-in.
- **Kit purchase**, 2026-11-11: grant within seconds; the schedule `vasey:issue-test-kit-contracts` issues the contract within about 10 minutes; delivery is enabled per order from **Test kit orders**. Two kit orders (`776b948f`, `eb82325a`) are waiting with delivery not enabled.
- **Unpaid release** is on: three abandoned orders were released and one of those tracks was bought again (`900b628f`). Limits: a late-paid order cannot be released, and an order never sent to Stripe cannot be released until #341 is merged and deployed.
- **Exclusive**: 32 active offers whose license template and offer snapshot are type exclusive. A test purchase permanently locks that track's other licenses and there is no release path. None has been bought.
- **Stripe drills**: drill 1 (declined card) passed (order `e44f06a0`, later released). Drills 3 and 4 have not been run. The expired-session path was exercised by the three released orders.
- **Deploy downtime**, measured on the 2026-11-12 deploy: 4 min 45 s of maintenance, 8 min 01 s for the whole deploy; the backup part inside the window about 1 minute; about 3 minutes of re-hashing run before the site is paused; the rest is the build, which runs inside the pause because the candidate is built as the application user (Codex finding 4226283468 on PR #63). Before #335 each deploy took about 22 minutes of downtime.
- **Browser specs**: collection was broken from #268 until #338. The first complete results are in [`browser-focused-20261111.md`](../../verification/browser-focused-20261111.md); a second round (`browser-focused-20261112.md`) arrives with PR #343.

### Open

- **#341**: staff release of a test order never sent to Stripe (new migration with triggers; independent review was running at the last record; needs main merged before it can merge). Not live.
- **#343**: admin upload buttons (kit and media upload Finish, Cancel and Pause were swallowed by Filament 5.11's `aria-disabled` guard) and dropdown fixes in browser specs, plus the second browser-results note. Draft; not live.
- **Foundation CI** has not run on main since 2026-10-10 (run 38077166247 on `0f23e63f`). Preflight runs static checks, audits, frontend unit tests, the build and the ops kit tests; it runs no PHPUnit. PHP tests run locally per change and in the manual final verification.
- Forced-colors contrast in the licence dialog (browser run 2026-11-11) is undecided: real defect or axe emulation artefact.
- Reviewer P3 findings deferred under the freeze-and-batch rule (from #322, #324 to #326, #328, #333, #334, #335 and #337) are not yet batched into a PR.

### Next, in order

1. Merge #341 (after review and main merged) and #343; re-provision and deploy staging once for both; re-run the five browser suites that were red.
2. NEW-04: pass the buyer email to Stripe Checkout (`customer_email`) and stop re-reconciling an already-verified order on each status click. NEW-03: a reversible Exclusive rehearsal and an operator-validator that agrees with the deploy gate.
3. BL-27: Stripe drills 3 and 4 (and drill 2 with an evidence packet) on staging; do not run drill 4 during Sean's test window.
4. BL-01: a full local sweep on a quiet machine before the one Foundation CI run; then BL-14 (held paid orders), BL-11 (resumable downloads for the production chains), BL-21 (TypeScript lint gate), NEW-05 (sound-kit production parity), NEW-06 (dependency PRs).
5. Build before the maintenance pause needs a dedicated build account (deferred; the pause is about 5 minutes).

### Waiting on Sean

Regenerate the Backblaze B2 master key that was pasted in chat; an off-host restore drill with his age key; whether a Stripe-test Exclusive may be bought on staging; the official logo SVG and icon source; the postal city and ZIP for the legal pages; counsel review of the Exclusive clauses before any live exclusive sale; mail, tax, payout and BeatStars export inputs. Standing boundaries are unchanged: no live payments, production deploy, DNS or customer imports without his explicit authorization.

## Where things stand

- **Main:** `f766e354` (PR #191 merged 2026-11-05). B is done. Publication order continues **C → D → E → F**.
- **PR #191's security gate.** The Codex security review failed on the payer's usage limit. Sean approved merging on an
  independent Claude security review of the exact head `a58fa2db`: APPROVE WITH CONDITIONS, no P0–P2 findings
  ([record](../../verification/member-mount-gates-20261101/PR191-SECURITY-a58fa2db-INDEPENDENT-20261104.md);
  [PR comment](https://github.com/SeanVasey/VA-Studio/pull/191#issuecomment-6646460004)). Its P3s are mount gates and are in
  the deferred ledger. **Expect the same Codex quota failure on every new PR's security review** until the payer's limit resets.
- **Nothing is live.** Mounts stay false, and no keys, payments, deploys or customer data are involved.

## Lane branches (all pushed; none has a PR yet)

Each branch's packet records its exact commands, results, review record and deferred items. In the "Native" column, "with ACC-IPV6"
means the run used the branch head plus the cherry-picked fix `a2144d4a`, which this harness's kernel needs (see Environment).

| Lane | Branch @ head | Review | SQLite | Native MySQL 8.4.11 |
| --- | --- | --- | --- | --- |
| ACC-IPV6 (fix on main's account guard) | `claude/catalog-ipv6-absent-20261104` @ `8236f570` | APPROVE; P3-2/3 fixed | unit 75/1653 | `CurrentCatalogAdmissionTest` 7/42 on a no-IPv6 host |
| REP indexes | `claude/rep-resume-20261104` @ `ec8a0578` | repair review: conditions fixed | 8/408; affected 56/1222; post-fix 2/113 | 8/293; post-fix 2/113 |
| G1 N-1 | `claude/g1-token-20261104` @ `7ddf2657` | conditions fixed | — | original STOP kept; revised 1/15; token mutant survives (no kill claimed) |
| CX recovery cursor | `claude/cx-recovery-review-20261104` @ `e37caa35` | REQUEST CHANGES, then re-review conditions fixed | 46/499 | 11/162 (real concurrent workers) |
| CAT inventory | `claude/cat-inventory-20261104` @ `2fc74539` | APPROVE; F1/F3/F5 fixed | 11/59 | 11/59 |
| DEF-ID metadata | `claude/def-id-20261104` @ `9278d79c` | conditions met | 53/169 (40/53 fail on unmodified main); affected 98/665 | all 76 selected |
| UXA public a11y | `claude/uxa-20261104` @ `614b9d5d` | — | npm audit 0, typecheck, vitest 1177, build | browser spec blocked here (no ClamAV signatures) |

The Codex prep branches these build on are also on origin under their original names (pushed 2026-11-05):
`codex/mig-final-integration-20261103` (`6f441ac7`), `codex/a5-final-integration-20261103` (`bfbe6a28`),
`codex/defops-final-integration-20261103` (`b5d1467b`), `codex/c4e-integration-census-prep-20261103` (`6d96042f`),
`codex/c4e-test-gaps-prep-20261103` (`abbd8a9b`), `codex/t36-cache-backoff-prep-20261103` (`e0a07604`),
`codex/pf1-a-technical-revision-prep-20261103` (`f4f03a84`), `codex/account-ledger-ci-portability-20261103` (`ebc472d3`) and
`codex/cx-mail-followup-prep-20261103` (`d8984c27`). Codex's mutation-experiment branches were deliberately not pushed; they
remain only in its bundle.

## Next work, in order (smallest first; no rework is known to be needed)

1. **Publish C**: MIG → A5 → DEF-OPS, each merged with the new main (all three trial-merged cleanly onto `a58fa2db`). Then the
   C4 census and the deferred batches: REP, G1, CX, ACC-IPV6. At integration:
   - regenerate the native selection and both count comments (CX on the C4 census proved 2777 of 10237 in 275 files);
   - union the SQLite census main-first;
   - mark FUB-1 F3 addressed (CX);
   - add the lane packets' deferred items to the ledger.

   ACC-IPV6 can also go first as its own small PR: it changes no behaviour where IPv6 exists.
2. **Publish D**: CAT, DEF-ID, UXA, PF1, then FND final census normalization.
3. **E** needs a staging host (protected-configuration rerun, browser, scanner and build evidence). **F** is the Dependabot batch.
4. Still open, design only: C4e N4 idle-PDO, the REP EXPLAIN diagnostics and UI companion (their packets stayed in Codex's
   workspace), the account-ledger CI hosted checks.

## Found while publishing this checkpoint

- Main's `composer audit` turned red on 2026-11-04 (new Symfony advisories), which fails every PR's preflight. This PR
  carries the patch/minor lock bump (see CHANGELOG, Security). Merge main into each lane branch before its PR.
- `PruneExpiredCacheRowsTest::test_expired_locks_go_and_held_locks_stay_held` fails on `main` `f766e354` in this harness
  (lock key order `live-lock`, `held-synthetic`), 3 of 3 runs, with or without the bump. Codex's T36 branch changes that test
  file; check whether it already repairs this before writing a new fix.

## Decisions for Sean (unchanged unless noted)

- **Exact PHP pin (new).** `CatalogSocketBinding::attest` accepts only PHP 8.4.27. CI (`setup-php` '8.4') and Forge install the
  latest 8.4 patch, so identity migrations refuse everywhere once 8.4.28 ships. Choose a policy (an allowlist or a minimum
  version). The code was deliberately not changed. *Decided 2026-11-08: the 8.4 series from 8.4.27 onward is admitted, 8.5+
  still refuses (`docs/architecture/decision-register.md`).*
- **CI collation (new, unverified).** Hosted MySQL shards use `mysql:8.4` with its default `utf8mb4_0900_ai_ci`. The account guard
  checks `utf8mb4_unicode_ci`. The first Foundation run will show whether the shards refuse.
- The handoff's existing "Waiting on Sean" list (staging hostname, Stripe test keys, exports, prices and tax, legal inputs).

## Environment recipe for native runs in a cloud container (from this session)

- PHP **8.4.27 or a later 8.4 patch** (exactly 8.4.27 before the 2026-11-08 decision). The Ondřej PPA offers it: `apt-get update`, then upgrade all `php8.4-*` packages.
- A private Oracle MySQL 8.4.11 on its own datadir and port, started with `--skip-name-resolve`, plus a `root@'127.0.0.1'`
  account. Create the test database with `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`.
- If the kernel has no IPv6 (`/proc/self/net/tcp6` missing), cherry-pick `a2144d4a` onto the source under test until ACC-IPV6
  merges, and say so in the evidence.
- Some identity-heavy native files take more than 60 minutes. Run them alone, with a longer limit.
- A test that creates a MySQL VIEW must drop it: `migrate:fresh` does not drop views by default.
