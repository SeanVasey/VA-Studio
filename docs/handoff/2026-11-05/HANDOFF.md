# Claude harness checkpoint — 2026-11-05

Entry point for the next session (Claude's usage resets Saturday). It supersedes the Codex checkpoint of 2026-11-04 for
current state. That checkpoint's archive, bundle and recorded results remain history. `AGENTS.md` governs. Sean's instructions
override older documents.

## Checkpoint 2026-11-14, 02:50 UTC

Added by the Claude Code harness; it supersedes the 2026-11-13 20:30 checkpoint below for current state. A Foundation pass
applies only to its recorded commit and is not launch or handoff acceptance.

**Main** is `e09c91a7` (PR #382). **Staging** was last deployed at `d4b0955d` (2026-11-13 20:29 UTC) and has not been
redeployed: #381 and #382 change tests, comments and documents only.

### Foundation CI: first complete pass

**Run 43532215436 on `main` at `e09c91a7f8d082a65fad4bec4da33a893c399ff1` passed in full** (dispatched 2026-11-14 00:09 UTC,
about 2 h 35 min): 37 jobs succeeded, including all 24 MySQL shards, all 6 SQLite shards and the browser jobs; `documentation`
is skipped by design. It is the first Foundation run to pass in full.

The two runs before it, for the record:

- Run 43485590174 at `0885d464`: every database shard passed (the `PaidTaxDownloadJourneyTest` window is confirmed on MySQL
  shard 7); two operator-browser jobs failed on one spec each, fixed in #381.
- Run 43505345035 at `1aa0495e`: all browser jobs passed (confirms #381); SQLite 1/6, SQLite 5/6 and MySQL 8/24 failed on six
  cases in three tests, fixed in #382.

### Merged since 20:30 (squash SHA on main)

| PR | SHA | What | Evidence |
| --- | --- | --- | --- |
| #381 | `1aa0495e` | Two browser timing races (`tests/browser/modal-ready.ts`; `site-images.spec.ts`) and the 2026-11-13 checkpoint | Preflight green; focused browser suites passed; confirmed by Foundation 43505345035 |
| #382 | `e09c91a7` | Three tests after `laravel/framework` 13.42: a refused commit now runs rollback callbacks itself (`ProductionTaxCommittedReadReceiptTest`); the nested lock-error handler now rolls the savepoint back (`MediaUploadCommitRecoveryTest`); a whole-second cache-key race in `TrackSchedulingTest`. Tests and two comments; no production behaviour change | Preflight green; focused `foundation-20261113-framework` on SQLite passed (the MySQL run hit the 900 s cap, no verdict); independent review approved with P3 follow-ups; confirmed by Foundation 43532215436 |

### Open

- **Deferred from #382's review** (P3, one follow-up batch): the `Cache::get` assertion in `TrackSchedulingTest` proves only
  the key formula, and the CHANGELOG repeats the claim; `app/Domain/Grants/PaidTax/PaidTaxOwnedFrame.php:106` carries the same
  stale comment that #382 corrected in `TaxPaidReadFrame`; that corrected comment ("nothing is left to remove") holds only for
  a refusal inside `DB::transaction()`; no tax test now pins callback suppression on a held-frame abort; CHANGELOG wording
  (six cases in three tests; the exact release between 13.39 and 13.42 that changed the behaviour is unverified).
- **Deferred from #372's review**, the Dependabot PRs (#373, #375 to #380, #100 to #102), the items waiting on Sean
  (`/sound-kits`; U+204F) and the branch housekeeping are unchanged from the checkpoint below. The last push to main reported
  six Dependabot alerts (two high, two moderate, two low); the harness still cannot read them (403).
- Merged branches `claude/browser-timing-20261113` and `claude/framework-1342-tests` can be deleted.

## Checkpoint 2026-11-13, 20:30 UTC

Added by the Claude Code harness; it supersedes the 2026-11-12 18:30 checkpoint below for current state. Nothing here is acceptance.

**Main** is `d4b0955d` (PR #374). **Staging** serves `d4b0955d` (Forge deployment 82951839, finished 20:29 UTC; status recipe
202998: php-fpm active, five workers running, `GET /` 200).

### Merged today (squash SHA on main)

| PR | SHA | What | Evidence |
| --- | --- | --- | --- |
| #372 | `1d26fe40` | MySQL tests reuse one migrated schema per process (`MysqlSchemaSnapshot`); MySQL-only and Ubuntu 26.04 test findings; SQLite in six shards. Test/CI only, except `resources/legacy-redirects/map.json` URL-005 (`/sound-kits`) recorded as unresolved | Preflight green; independent review APPROVE at `a542cb9a` (P2/P3 deferred, listed in the PR); merged on the development-merge rule, not on a full Foundation pass |
| #374 | `d4b0955d` | Dependabot: `laravel/framework` 13.38.0 to 13.42.0, `filament/filament` 5.11.2, Tinker, Pail. Clears GHSA-63hm-g96r-2m2q (low; published 2026-11-13 18:54 UTC), which had turned `composer audit` red on main and every PR. The app does not use the `Concurrency` facade | Preflight green; focused `unit` and `browser-operator-uploads` (SQLite) and `test-delivery-races` (MySQL) passed; `customer` (SQLite) ran 189 of 338 with no failure before the 900 s cap, the same as main |

### Foundation CI state

- **All database shards now finish.** Run 43455392026 at `2f7f3b9f`: 24 MySQL and 6 SQLite shards inside their limits; 23 MySQL
  and all SQLite shards passed. Its two failures (MySQL shard 7 `PaidTaxDownloadJourneyTest` late-chunk window; chromium
  `customer-library-browsing.spec.ts` fixture timeout) were fixed in #372's last two commits.
- **Run 43485590174 at `0885d464`** (the content merged as #372) was still running at this checkpoint. Its two operator-browser
  jobs failed on one spec each; the customer-library fix is confirmed there. Read its database shards before relying on the
  `PaidTaxDownloadJourneyTest` change: it is not yet confirmed on MySQL.
- **No Foundation run has passed in full.** Each of the last three runs failed on a different timing-sensitive test on a slow
  runner. Foundation has not run on `d4b0955d` (the framework bump).

### Open

- **PR #381** (`claude/browser-timing-20261113`): the two browser races from run 43485590174. `tests/browser/modal-ready.ts`
  waits for the New track modal's focus trap before the first fill (five specs); `site-images.spec.ts` leaves the polling
  table after a poll settles. Focused `browser-operator-publication`, `browser-operator-tracks` and `browser-customer` passed;
  `site-images.spec.ts` is in no focused suite.
- **Deferred from #372's review** (one follow-up batch): `MembershipSchemaProofFrameTest` MySQL branch lacks a positive control
  (P2); `MysqlSchemaSnapshot` leaves `information_schema_stats_expiry` and `lock_wait_timeout` changed on its failure path;
  stale "Each test rebuilds the schema" comments in `final-verification.yml` and `focused-feedback.yml`; the fingerprint omits
  PARAMETERS and PARTITIONS; no run compares against `VA_TEST_MIGRATE_EACH_TEST=1`.
- **Dependabot PRs still open:** #373, #375 to #380 (majors among them: phpunit 13, stripe-php 22, jsdom 31) and the three
  Actions bumps #100 to #102. GitHub also reports four Dependabot alerts on main (two high, two moderate) that the harness
  cannot read (403); #374 may have cleared some.
- **Waiting on Sean:** `/sound-kits` (enable the storefront or add a fallback as `/services` has); U+204F needs a new contract
  profile version (the profile class is hash-frozen).
- The session branch `claude/sleepy-ramanujan-pjbo1w` holds only merged history; restarting it needs a force-push, which the
  harness was not permitted to do. `claude/scratch-mysql-migration-profile` is a diagnostics branch, not for merge.

## Checkpoint 2026-11-12, 18:30 UTC

Added by the Claude Code harness; it supersedes the 13:00 checkpoint below for current state. Nothing here is acceptance.

**Main** is `daa14cbf` (PR #364). **Staging** still serves `785f47e4` and has not been redeployed: everything merged since is
default-off or CI/test-only. To use #359 or #363 on staging, deploy main, then set each flag and its exact policy line (the
test-commerce overlay shows both) and deploy again. A new optional flag is deployed off first (06:30 lesson below).

### Merged since 13:00 (squash SHA on main)

| PR | SHA | What | Review |
| --- | --- | --- | --- |
| #360 | `08cf6975` | Test fixtures on Ubuntu 26.04 runners (FFmpeg 8 under the 512 MiB cap: `MALLOC_ARENA_MAX=1`) and #322's missed WAV output count | Codex: none |
| #359 | `75b5926a` | Optional automatic enabling of test delivery (D-19 amendment; default off) | Codex: none |
| #361 | `3ddeb956` | Hosted MySQL CI could run no migrating test (see below) | Codex: none |
| #362 | `f5741682` | Deferred ops P3s: `ctl attach` decides a failed isolated slot by the slot directory; EBADMSG/EUCLEAN at open is a content finding; marker symlink | Independent: one P3 reproduced, fixed, re-review APPROVE; Codex: none |
| #363 | `038a8d16` | Buyer-side cancel of a never-sent test order (the `claude/bbc-20261112` lane, finished; default off) | Independent APPROVE; Codex and Bugbot: one P2 (fixed in #364) |
| #364 | `daa14cbf` | #363 follow-ups: Cancel kept after a refused start; customer-account, withdrawn-account and actor tests | Independent APPROVE (P3 fixed); Codex: none |

### CI facts that changed

- **MySQL on hosted CI works again (#361).** The catalog admission (`CatalogSocketBinding`) binds each session to its exact TCP
  4-tuple, and the service's published port `127.0.0.1:3306` is Docker NAT, so every `migrate:fresh` refused ("Current private
  catalog admission required") in focused feedback *and* Foundation CI. Both workflows now reach the service container at its bridge
  address (`database-receipts.py` requires `DB_HOST` to be exactly the inspected container) and set the database to
  `utf8mb4_unicode_ci` (the image default `utf8mb4_0900_ai_ci` fails the account guard). Foundation CI itself has not run since
  2026-10-10; its MySQL step is unexercised with this change.
- **Focused MySQL budget:** about 100 s per migrating test, so a MySQL focused suite fits the 900 s cap only with about eight such
  tests. Use the small `*-races` selections (`test-delivery-races`, `buyer-cancel-races`); `track-concurrency` and `customer` ran
  8/8 and 15/15 before the cap.
- **Runners are Ubuntu 26.04** (`ubuntu-latest`): FFmpeg 8.0.1. The application's media limits (2 GiB) are unaffected.
- New focused suites: `test-delivery`, `test-delivery-races`, `buyer-cancel`, `buyer-cancel-migrations`, `buyer-cancel-races`.

### Evidence for #359 and #363 (focused feedback only)

- #359: SQLite `test-delivery` 192 OK (2 skipped); MySQL `test-delivery-races` 2/2 OK (22 assertions).
- #363/#364: SQLite `buyer-cancel` 144 OK (5 MySQL-only skipped); `buyer-cancel-migrations` 64 OK; MySQL `buyer-cancel-races`
  5/5 OK; frontend 85 files / 1281 tests OK.

### Still deferred

- The re-verification token's age is checked separately by `estimate` and `snapshot` (a token that ages out between them makes the
  snapshot copy everything; fails closed).
- The deploy exceeds Forge's ten-minute watch (runbook section 9); optional speed-ups unchanged from 13:00.
- #359 review P3s (kit hold wording, spoofable "Enabled automatically" label, persistent "needs attention", test gaps) and the
  #363 notes in its PR body (expired-refusal copy beside Cancel; a principal paired with another account's key, covered by
  `CustomerAccess::lock`).

### Next, in order

1. If Sean wants either capability on staging: deploy main (off), then switch on `VASEY_TEST_DELIVERY_AUTO_ENABLED` and/or
   `VASEY_TEST_BUYER_CANCEL_ENABLED` with their exact policy lines and deploy again; rehearse per `docs/ops/staging-test-purchase.md`.
2. Run one Foundation CI (manual, exact SHA) on a chosen main SHA to prove the #361 MySQL path end to end.
3. The remaining deferred items above.

## Checkpoint 2026-11-12, 13:00 UTC

Added by the Claude Code harness; supersedes the 06:30 checkpoint below for current state. Nothing here is acceptance.

**Main** is `785f47e4` (PR #357). **Staging** serves `785f47e4`: re-provisioned at 12:33 UTC (Forge recipe "vasey-staging
reprovision (main)", run 669321: `vasey-staging-build` created, systemd/cgroup v2 and toolchain checks passed), the build
unit smoke test passed all five probes on the host (recipe "vasey-staging smoke build unit", 12:35), then the first isolated
deploy (Forge deployment 82807668) finished healthy at 12:46:24 (`GET /` 200, workers running, nothing to migrate). Forge
shows that deploy as "failed": it outlasted Forge's ten-minute watch (runbook section 9). Measured pause about 4m, not the
1.5m estimated (runbook section 8). The Forge firewall allows only 22, 80 and 443 (provision warns that MySQL binds `*`).

Merged since 06:30: #356 (`09178f10`: deferred #353/#354 findings; `ctl activate` re-runs the runtime admission; `ctl
attach` refuses isolated candidates; held runtime trees refuse symlinks) and #357 (`785f47e4`: `smoke-build-unit.sh`). The
review threads listed below are answered and resolved (#335, #336, #337, #341, #345, #354).

Deferred (in addition to the list below, minus what #356 fixed): #357 re-review P3s (the operation-marker check should also
refuse a symlink; the drift guard misses `--setenv=`); #356 re-review P3s (a symlinked `bootstrap` in a failed unsealed slot
sidesteps the attach owner check and leaves the slot 0755; compare numeric owners); EBADMSG/EUCLEAN outside a byte read; the
three-set `error.inodes` test; the two-moment token age; the deploy now exceeds Forge's ten-minute window (consider
skipping the pre-quiesce re-hash when a fresh token exists, or a shorter restore check). `claude/bae-20261112` still needs a
PHP 8.4 environment (this container could not install one); `claude/bbc-20261112` is unfinished.

## Checkpoint 2026-11-12, 06:30 UTC

Added by the Claude Code harness (session resumed from the stood-down 2026-11-12 session). It supersedes the checkpoint below
for current state. Nothing here is acceptance.

**Main** is `37aabf22` (PR #354). **Staging** serves `3d1782d1` (Forge deploy 82769209, 04:12 UTC) with the test refund/dispute
capability on; the refund rehearsal passed (Stripe test refund on order `84c07059`, staff check `refundState=full`). Lesson from
that deploy: the gate that runs first is the served release's, so a new optional flag is deployed off first, then switched on and
deployed again.

### Merged this session

| PR | SHA | What | Review |
| --- | --- | --- | --- |
| #353 | `1cb85f81` | Backup kit findings from #335/#337 (off-host modes, stale link token, error rule; a finding is never hidden by a later set-level finding or operational error) | Two reviews found the same P2 (reproduced) at `ccd5cc16`; fixed; re-review APPROVE WITH CONDITIONS (P3s); Codex: no findings |
| #354 | `37aabf22` | Build before the pause under `vasey-staging-build`, inside a hardened transient systemd unit; leftover scan; atomic reap | Two reviews rejected `435079b0` (P1 reproduced: set-id helper let the app write into a later candidate); fixed; re-review P2 (logind/linger over unix sockets) fixed; final delta APPROVE WITH CONDITIONS; Codex: no findings |

### Before the next staging deploy (operator, in order)

1. Re-provision staging (`ops/staging/provision.sh` as root). Both PRs change installed helpers; the deploy refuses on helper
   drift until then. Re-provisioning also creates `vasey-staging-build` and checks systemd, cgroup v2 and that composer, npm, node,
   git and PHP resolve outside `/home`.
2. Run the smoke probes in runbook section 8 inside a unit with `build_in_unit`'s exact properties. **No real systemd unit has run
   yet** (the tests use a stand-in). Add a network operation to probe (v), for example `git ls-remote https://github.com/laravel/framework HEAD`
   (review condition). Then deploy and record the first measured pause in runbook section 8. A failed isolated build leaves the site up.

### Deferred findings (freeze-and-batch; next ops follow-up PR)

- #354 (Cursor Bugbot, both fail closed): `ctl attach` reseals an isolated candidate with the legacy sealer and refuses; `verify_held`
  refuses symlinks because it tests mode bits before `S_ISLNK`.
- #354 (reviews): tests for the lingering refusal and the reap guard; a deterministic test that the reap kills before it looks;
  `sleep "0.$attempt"` is not monotonic (0.10 on attempt 10); the comment and runbook say "no local daemon" but loopback TCP stays
  open (wording should say unix sockets; consider `IPAddressDeny=localhost` with the 127.0.0.53 stub allowed); scan errors are
  suppressed (`2>/dev/null || true`), so an unreadable mount passes silently; an apport crash file or `/dev/shm` object left by a
  build blocks later deploys until root removes it (runbook note); a killed root ctl leaves the unit running until
  `RuntimeMaxSec`; Node `os.networkInterfaces()` throws under `RestrictAddressFamilies` (no current dependency calls it).
- #353 (re-review): a message regression appends "(0 file(s))" to an EIO finding with no earlier failure, and calls a mode-only
  failure a hash mismatch; no tests for the Walker `entry.stat` ENOENT guard or the three-set `error.inodes` union; EBADMSG/EUCLEAN
  outside a byte read stay operational; no test that `restore-check` withdraws nothing on engine exit 3 or a nameless exit 1; the
  token age is checked at two moments; a runbook note that the off-host destination must honour POSIX modes.

### Unpublished lanes

- `claude/bae-20261112` @ `11bf6f09`: optional automatic delivery enabling (D-19 amendment), merged with main after #352. Its
  post-merge PHP re-run is still owed. This container has PHP 8.3.6 and adding the PHP 8.4 package source was refused by the
  session's permission classifier, and no focused CI suite selects its tests (`TestTrackOrderResourceTest`,
  `TestKitOrderResourceTest`, `TestDeliveryAutoEnableConcurrencyTest`, `StagingRuntimeValidatorTest`,
  `TestCommerceProfileValidatorTest`). Needs a PHP 8.4 environment (and native MySQL for the race test), then independent review and a PR.
- `claude/bbc-20261112` @ `b6740e1d`: WIP buyer-side cancel for never-sent test orders; not finished, tested or reviewed; will
  conflict with #352 in `scripts/ops/capability-boundary.php`.

### Review-bot threads still to answer and resolve (fixes merged)

#341 PRRT_kwDOU5febs62bGiq (by #350); #336 PRRT_kwDOU5febs62VTwD (by #351); #335 PRRT_kwDOU5febs62XUzG (by #337); #345
PRRT_kwDOU5febs62a4Fk (by #346); #335 62XSn4, 62XSn_, 62XUzL and #337 62Z2Mv, 62Z3Xr (by #353).

### Sean's standing decisions (2026-11-12)

No Exclusive test buy on staging. Refund/dispute on staging: done. B2 master key: regenerated. Buyer cancel, automatic delivery
enabling and the build account: delegated. Stripe drill 3's real "Resend" needs the Dashboard or CLI. The off-host restore drill
needs Sean's age key on his Mac.

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
