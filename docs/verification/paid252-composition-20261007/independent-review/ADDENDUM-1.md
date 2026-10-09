# Independent review: Paid252 composition, Addendum 1 (Codex hardening rounds 1–7)

**Reviewed range:** `70a99960..8f751d0d` on `harness/paid252-composition` (PR #56).

- **Base:** `70a9996023eeb6979a295d7ea237d7ccbde66913`, the head [`DECISION.md`](DECISION.md) approved with conditions.
- **Head:** `8f751d0d368222c310e37d623eab1e8d5f0f2956`. The coordinator extended the range from `392afd6a409264b378f59bcacacb1b481aa4b935` to this head during the review.
- **Worktree:** `/home/user/VA-Studio-review-paid252`, detached. I confirmed the head with `git rev-parse HEAD` before each recorded run.
- **No PHP changed after `392afd6a`.** `git diff --quiet 392afd6a 8f751d0d -- app config routes database tests/Feature tests/Support` returns 0. So the paid-family PHP run at `392afd6a` also holds for `8f751d0d`. The frontend runs and `tsc` were repeated at `8f751d0d`.

**Commits in range:**

| Commit | Round | Kind | Files |
| --- | --- | --- | --- |
| `cae5eeae` | 1 | code | Transfer deadline and per-operation client timeouts: `PaidGrantDownloads.php`, `PaidGrantTransfer.php`, `PaidGrantPolicy.php`, `config/paid-grants.php`, `PaidGrantJourney.tsx` |
| `2b437924` | 2 | client | Reads abort at 80 s |
| `e479a8cb` | 3 | client | Set aside a lost authorize |
| `df9fd823` | 4 | client | Status window proof for set-aside |
| `32440644` | 5 | code | Spool reservation in `PaidGrantPrepareStream.php`; release-rule docblock in `PaidGrantRenderProfile.php` |
| `f643e944` | 6 | code, **authorization** | `PaidGrantPolicy::retained()`, called from `PaidGrantCommands::run()` and `PaidGrants::sameSources()` |
| `c0a68b2d` | 7 | client | A hidden tab keeps the exact replay (`clear(true)`) |
| `0397231e`, `7283c339`, `1cd837eb`, `cde97f0a`, `7a16d050`, `392afd6a`, `8f751d0d` | | docs | `hardening/codex-1` … `codex-7` |

`bde8f55c` (the original reviewer's adversarial tests) and `3be1913f` (MySQL pin `/\A8\.(0|4)\./`) are **ancestors of `70a99960`**, not part of this range: `git merge-base --is-ancestor` succeeds for both. They are already covered by `DECISION.md`'s verdict.

I checked both anyway:
- `3be1913f` is exactly the relaxation that `DECISION.md` §5 recommended.
- `PaidGrantReviewAdversarialTest` passes 4/4 in the family run below.

I wrote none of the code under review. Nothing was committed or pushed, and no product code was modified. **Reviewer:** Claude (independent review lane). **Date:** 2026-10-08.

## Verdict

**APPROVE WITH CONDITIONS** for a *development merge* of the still default-off, unmounted family:
- `config/paid-grants.php` is unchanged in its enablement keys (`rehearsal_enabled=false`, `operative_enabled=false`, `delivery_policy=null`);
- the routes are still not required from `routes/web.php`.

There is no High or Medium finding. Round 6, the authorization change, does **not** weaken authorization, provenance separation or the read receipt (§6). Rounds 1 and 5 hold under the adversarial cases I added.

Two Low findings need a decision before mount:
- **A1-L1:** the set-aside gate does not prove that the lost authorize has finished on the server. Round 7 makes this easier to reach.
- **A1-L2:** spool slots are now held for up to the 2 h transfer deadline.

Neither one can grant a download, consume an attempt, move money or expose a token.

**Original conditions:**
- **C1 (M1) is resolved in design:** the transfer now has its own size-derived deadline. Sean still has to confirm the values with the authored policy.
- **C3 is resolved by `3be1913f`.** I made no native re-run in this addendum.
- **C2 and C4–C7 remain open, unchanged.**

## Findings

### High: none. Medium: none.

### Low

**A1-L1: the set-aside gate does not prove that the lost request has finished on the server.**
Where: `resources/js/components/PaidGrantJourney.tsx:143` (`savedStatus` records `statusAfter`), `:150-153` (`pendingLine` / `abandonable`) and `:100` (round 7 `visibility`).

The round 3/4 proof shows that a status read with no `unused` entry of the kind, and either a non-full window or an `expired` entry, means **no authorization committed before that read** is live (§4). It does not cover a pending authorize that commits **after** the read. Two reachable paths:

1. **(i) A failed exact retry.**
   - After a qualifying read, the customer clicks "Retry the exact request" and the retry's answer is lost too.
   - The retry is a new transmission that may itself commit. But `statusAfter === pending` and `reviewedSaved` still hold, so set-aside stays enabled on the stale read.
   - Reproduced by `it.fails` in `tests/frontend/paid-grant-review-addendum1.test.tsx:43`. The probe shows it fails exactly at `expect(setAside()).toBeDisabled()`, line 58.
2. **(ii) The original is still running on the server.**
   - Round 7 aborts an in-flight authorize as soon as the tab is hidden. That can happen seconds into the server's 60 s budget. A proxy 502/504 or a network error has the same effect (`call()` → `unknown`).
   - The customer comes back, refreshes, opens the order and reads status. If that read takes the current-buyer lock before the original authorize has entered its transaction, it shows nothing live, and set-aside is enabled while the original can still commit.
   - Reproduced by `it.fails` at `tests/frontend/paid-grant-review-addendum1.test.tsx:168`. It fails at line 181 with the original fetch still unresolved.
   - The doc comment at `PaidGrantJourney.tsx:80` ("a deliberate retry then cannot race the original") is also no longer true after a hide. For the *exact retry* this is harmless: the replay path plus the unique index `paid_authorizations (account_id, request_key)` (`PaidGrantSchema.php:210`) make a racing retry return the same row.

**Impact.**
- One extra live authorization is created whose token was never shown.
- No download attempt is consumed: attempts are redemptions, bounded by the retained `max_downloads`.
- It spends one of the line's 3-per-60 s issuances (`PaidGrantDownloads.php:84-85`).

This is not a money or entitlement defect, so it is rated Low.

**Suggested fix (not applied).** Stamp each transmission of the pending request with its send time, including retries. Accept a status read for set-aside only if it was issued at least `paidRequestTimeouts.authorize` (80 s) after the last transmission. This closes (i) and (ii) with one rule.

**A1-L2: a held spool slot now lasts as long as the transfer, up to `transfer_max_seconds` (7,200 s).**
Where: `PaidGrantPrepareStream.php:90`, where the lease moves into `PreparedDeliveryStream`, which releases it only on `close()`; and `PaidGrantDownloads.php:145,157-158`.

- Before round 1, the stream, and so the lease, was cut at the authorization expiry (at most 600 s). Now a slot stays held for base + size/256 KiB/s, which is 4,126 s for 1 GiB.
- There are 3 slots globally (`SLOTS = 3`). One buyer with one line and three kinds can run three concurrent redemptions; the session `block(20, 5)` does not serialize streamed bodies. That holds every paid slot for about an hour. Three slow legitimate customers do the same.
- Other customers' redeems then fail closed with `target_unavailable` **before** the attempt is recorded, so nothing is lost. But paid delivery is unavailable site-wide for that time.
- Free256 has the identical model (`config/production-free-grants.php`: `spool_slots` 3, `transfer_max_seconds` 7200) on its own spool.

**Condition C8:** before mount, an operator decision on slot count, per-account concurrent redeems, or a per-chunk minimum-rate check.

### Informational

- **A1-I1 (round 6): no per-version revocation.**
  - Before round 6, changing the configured policy implicitly locked out every older order, which was a crude kill switch.
  - Now a policy authored by mistake (for example `max_downloads=100`) stays in force for the orders finalized under it. The only remaining lever is disabling the whole family.
  - This is consistent with the license document: `PaidGrantRenderInput::fromOrigin` renders `retrieval_policy` from the retained copy (`PaidGrantRenderInput.php:16`), so what is enforced is what the buyer's PDF states.
  - Sean's call on an allow-list or deny-list of retained versions, as `codex-6` already flags.
- **A1-I2 (docs drift).** `hardening/codex-4/README.md:23` says the policy is "equality-checked on every command". After round 6 it is not. The round 4 proof still holds, because the lifetime comes from the immutable, hash-pinned batch payload (§4).
- **A1-I3 (round 7).**
  - The kept `pending` holds the full origin projection (terms, declared name, amounts, hashes) in memory while the tab is hidden. It is never rendered; my test checks the DOM for the request key, nonce, terms, declared name and origin hash.
  - It is re-rendered (`PaidGrantJourney.tsx:137`, `setOrigin(p.origin!)`) only after a server-confirmed authorize under the current session.
  - A 403/404/419, a sealed-body denial or `refuse()` calls `clear()` without `keepPending`, so it is dropped. The banner also requires `!denied`.
  - If the browser account changes while the tab is hidden, the retry is refused by server ownership (404 → `refuse()`).
- **A1-I4 (pre-existing).** `pagehide` drops a pending authorize without any saved read (`tests/frontend/paid-grant-review-addendum1.test.tsx:95`). The server's issuance limit, not the client, bounds the duplicate.
- **A1-I5 (round 5).**
  - The admission `flock($gate, LOCK_EX)` (`PaidGrantPrepareStream.php:204`) blocks and is not bounded by the snapshot deadline. It is held only for slot probing, `disk_free_space` and two 20-byte writes, the same as Free256.
  - During a rolling deploy, a pre-round-5 process holds slots without the gate or a sidecar and is counted as 0 (transitional).
  - A sidecar that is unusable for its own slot (symlink, wrong mode, or wrong size) permanently retires that slot until an operator removes it. This is by the no-repair rule and fails closed.
- **A1-I6 (round 4 assumption).** "Ids follow `created_at`" also assumes the wall clock does not step backwards. `created_at` is `now()->startOfSecond()`, taken inside the locked frame (`PaidGrantDownloads.php:83`). A backward NTP step could invert the order by up to the size of the step. Not tested.

## Per-round assessment

### 1. Round 1 (`cae5eeae`): valid-to-start lifetime, transfer deadline, client timeouts

- **Valid to start.** The first frame captures `$admitted` once (`PaidGrantDownloads.php` `redeem`, `$admitted ??=`). The second frame re-checks that same instant.
- **The 60 s `PaidGrantDeadline` still bounds everything up to the first byte.** It covers locate, both frames, the snapshot and `proveBeforeBytes()`, and it is never extended.
- **Lifetime still checked after the first frame.** `$this->deadline($before['auth'])` still requires remaining lifetime when the first frame closes.
- **The transfer deadline is computed before the commit frame.** `transferSeconds()` validates the config (503) before the attempt row exists, and the deadline counts from the commit. It uses only the server-side snapshot size and config.
- **Coverage:**
  - The original reviewer's four cases, kept in `PaidGrantReviewAddendum1Test`, re-pass at `8f751d0d`:
    - an expired observation budget refuses the first byte, even with a far transfer deadline;
    - the deadline bracket equals `transferSeconds(size)`;
    - a snapshot that crosses the expiry fails, and a fresh redeem after expiry is 410 with nothing recorded;
    - a transfer policy invalidated mid-snapshot is 503 with nothing recorded, and the authorization stays usable.
  - Codex's `PaidGrantTransferDeadlineTest` passes 6/6.
- **Client timeouts (round 2 included):** read, finalize and authorize abort at 80 s, which is at least 60 s + 5 s + 15 s; document aborts at 320 s. Redeem is unchanged (native form into an iframe).

### 2. Round 5 (`32440644`): spool reservation, a faithful port of Free256

- **One admission at a time.** Slot choice, `freeBytes`, the pending sum and the sidecar write all happen under one exclusive gate.
- **Every held slot has its reservation written.** Slot locks are taken only inside `admit()` under the gate, so any slot another admitter sees as held already has its reservation written.
- **Held-slot detection.** `pending()` probes the other slots with `LOCK_NB` on a fresh descriptor. Per-description `flock` semantics make this correct in-process too.
- **Release.** Reservations drop to zero after unlink, when the open, unlinked file is already reflected by the disk. They also drop to zero in `finally` on every failure.
- **Conservative accounting.** Bytes already written count twice while a copy runs: on disk and in the reservation.
- **No repair.** The paid adapter's rule is kept:
  - its own slot's sidecar is checked with `lstat`, so a symlink is never followed for its own slot;
  - other slots' sidecars go through `sameFile`, which compares the `lstat` and `fstat` identity.
- **My cases (`PaidGrantSpoolReviewAddendum1Test`, 3/3):**
  - a stale, well-formed, huge reservation on an *unheld* slot is not subtracted, and the slot's next holder overwrites it;
  - a tampered sidecar of a *held* slot makes the next admission refuse instead of counting zero, and the holder's release restores it;
  - a symlinked sidecar (slot 0) and a 0644 sidecar (slot 1) are skipped and left untouched, the symlink target is never written, and with slot 2 held, admission refuses instead of repairing.
- **Render profile.** The `PaidGrantRenderProfile` docblock change is documentation only. I agree with the stated release rule: drain unfinished documents, or version the profile, before any pinned file changes.

### 3. Round 6 (`f643e944`): the retained delivery policy (authorization change)

**What changed.** `PaidGrantCommands.php:63` and `PaidGrants.php:235` replace byte-equality between the retained and current policy with `PaidGrantPolicy::retained()` (`PaidGrantPolicy.php:59-63`). The retained policy must pass the same shape rules (`wellFormed`, `:65-74`) and have the current provenance.

**Questions assessed:**

- **Can a sealed batch carry a policy the operator never configured? No.**
  - `delivery_policy` is written only by `finalizeOwned` (`PaidGrants.php:75,87`), from `capture()` of the live config.
  - The commit fence re-reads the raw repository and requires byte-equality with that captured policy (`provePure`, `PaidGrantPolicy.php:81`). A config changed mid-frame therefore refuses.
  - After the write, the policy sits in an encrypted, hash-pinned payload behind immutable/retain triggers.
  - `graph()` requires every origin body's policy `===` the batch's (`PaidGrants.php:153`) and re-validates each source line's provenance against it (`:155`).
  - Forging one needs both DB write access and `app.key`.
- **Can a rehearsal and production order cross? No.** The chain is unbroken:
  - line provenance = retained provenance (`source`, in `graph()`);
  - retained provenance = current provenance (`retained`);
  - current provenance matches the environment and identity provenance (`provePure`, in every fence).
  - My journey case switches the current policy to `verified_production` while a rehearsal order and a live authorization exist. Index, show, status, authorize, redeem and re-finalize all refuse with 403 or 409, asserted as a set. By code reading: index 409, show/status/authorize 409, redeem 403 from `provePure` in `locate`, finalize 409 from `source`.
  - Nothing is inserted, and the retained policy is byte-identical. After the rehearsal policy is restored, the untouched authorization streams exactly once.
- **Is the read receipt still coherent? Yes.**
  - `PaidGrantReadReceipt::capture(..., $policy, ...)` and `fence()` bind and prove the **current** configured policy, which is what admission depends on.
  - The **retained** policy is inside the `paid_order_origins` / `paid_grant_origins` rows, which the receipt already snapshots byte for byte (`PaidGrants::snapshots`).
  - Both are bound. Nothing enforces with the current `max_downloads` or `authorization_seconds`: every enforcement site reads `$graph['payload']['delivery_policy']` (`PaidGrantDownloads.php:50,82,94,149`).
  - The transfer settings are deliberately current config, not retained, and are validated before the attempt is recorded.
- **Downward revision (my journey case).**
  - Under v2 (`max_downloads=1`, 30 s), a pre-revision authorize replayed with the same request key returns the identical row, still with a 60 s lifetime.
  - Three downloads succeed under the retained limit of 3. The fourth authorize is refused with 409, and status reports 3/3.
  - Re-finalize returns the same batch with no new row, and the retained policy is unchanged. `license_grants` stays 0.
- **What it gives up:** A1-I1 (no per-version revocation).

### 4. Rounds 3 and 4 (`e479a8cb`, `df9fd823`): properties the proof relies on

| Property | Holds? | Evidence |
| --- | --- | --- |
| `authorization_seconds` is fixed per batch | Yes | `expires_at = created_at + payload.delivery_policy.authorization_seconds` (`PaidGrantDownloads.php:94`). The payload is immutable and hash-pinned, and round 6's `retained()` checks its shape (30–600) on every command. My downward-revision case shows the lifetime stays 60 s under a 30 s current policy. |
| Issuance is serialized per batch, so ids follow `created_at` on a line | Yes, given a monotonic wall clock (A1-I6) | `created_at` is taken inside the operation, after the current-buyer lock and the owned graph `FOR UPDATE` on MySQL (`PaidGrantRows.php:313`). SQLite serializes writers. Not natively tested here (no MySQL-specific path changed in range). |
| `status()` uses one `$at` | Yes | `PaidGrantDownloads.php:30`: one `$at` for every history entry and `renderRetryAllowed`. The window is `ORDER BY id DESC LIMIT 20` per line (`:33`), mixing kinds, which matches the client's any-kind `expired` test. |
| The gate keys on a post-request read for that origin and line | Yes for reads, **no for in-flight originals or retries** | A1-L1. The original reviewer's cases for a different origin and for a status object cleared by open/refresh pass. |

### 5. Round 7 (`c0a68b2d`): the hidden tab keeps the exact replay (coordinator's questions)

- **(a) Can the kept pending leak private data to the DOM or survive a denial? No.**
  - Hidden: everything shown is cleared. The banner is generic and the replay is never rendered. My case asserts that the request key, nonce, terms, declared name and origin hash are absent.
  - `refuse()` calls `clear()` (no `keepPending`), and the banner requires `!denied`. My case shows that a 403 on return removes the retry and set-aside controls.
  - A1-I3 covers the in-memory copy and its later re-render.
- **(b) Can a late answer from the aborted request be committed? No.**
  - The handler bumps `generation` and aborts.
  - `call()` commits only under `owns()` (`active && mine === generation`). Both the early and late return paths, and `catch`/`finally`, check it.
  - The handler resets `inflight` itself, and the stale call's `finally` does not touch it.
  - Codex's case covers an immediate late answer. Mine resolves it *during a later read* that the customer started after returning: no download control, no token in the DOM, and the retry is still offered.
- **(c) Does it interact badly with the set-aside gate or `statusAfter`?**
  - Mechanically no: `clear(true)` resets `status`, `statusAfter` and `reviewedSaved`, so a new post-return status read is required.
  - In substance, it widens A1-L1 (ii). Before round 7, a client abort happened only at 80 s, past the server budget. Now a hide abandons the authorize at any moment, and the customer can return and qualify a read while the original may still commit.
  - The exact retry stays safe under that race (unique request key, replay path).

## Test runs

All PHP runs used PHP 8.4.26 with the worktree autoload, through `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`. SQLite used the `phpunit.xml` defaults, with `public/build` absent.

Each `.txt` file records the command, head, `rc` and wall time. The `.xml` files are JUnit logs. Paths below are relative to `review-evidence/addendum1/`.

| # | Head | Command (args) | Result | rc | Wall | Raw output |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `392afd6a` (PHP-identical to `8f751d0d`) | `--log-junit … --filter PaidGrant tests/Feature` (13 committed files; reviewer file moved out for the run) | **80 tests, 79 passed, 1 skipped** (native-only SchemaRecovery case), 3,858 assertions | 0 | 359 s | `392afd6a/sqlite-paid-family.{txt,xml}` |
| 2 | `8f751d0d` + untracked | `--log-junit … tests/Feature/PaidGrantReviewAddendum1Test.php` | **6/6**, 126 assertions | 0 | 58 s | `8f751d0d/sqlite-PaidGrantReviewAddendum1Test.{txt,xml}` |
| 2a | same | same, first attempt | 4 passed, **2 failed on my own assertion**: I compared the retained policy in PHP key order, not canonical order. Every product assertion before that line passed. Fixed to compare `CanonicalJson::encode`, then re-run as row 2. | 1 | 56 s | `8f751d0d/sqlite-PaidGrantReviewAddendum1Test-run1-key-order-test-bug.{txt,xml}` |
| 3 | `8f751d0d` + untracked | `--log-junit … tests/Feature/PaidGrantSpoolReviewAddendum1Test.php` | **3/3**, 13 assertions | 0 | 1 s | `8f751d0d/sqlite-PaidGrantSpoolReviewAddendum1Test.{txt,xml}` |
| 4 | `8f751d0d` | `npx vitest run tests/frontend/paid-grant-journey.test.tsx` (vitest 5.0.1, Node 24.21.0) | **16/16** | 0 | 4 s | `8f751d0d/frontend-paid-grant-journey.txt` |
| 5 | `8f751d0d` + untracked | `npx vitest run tests/frontend/paid-grant-review-addendum1.test.tsx` | **5 passed, 2 expected fail** (A1-L1 (i), (ii)) | 0 | 2 s | `8f751d0d/frontend-paid-grant-review-addendum1.txt` |
| 5a | same | probe copy with `it.fails` changed to `it`, deleted afterwards | 2 failed at the final `toBeDisabled()` (lines 58 and 181), 5 passed: confirms that the expected fails fail for the stated reason | 1 | | `8f751d0d/frontend-review-addendum1-probe.txt` |
| 6 | `8f751d0d` + untracked | `npx tsc --noEmit` | clean | 0 | 4 s | `8f751d0d/tsc.txt` |
| 7 | `8f751d0d` | `/home/user/VA-Studio/vendor/bin/pint --test` on both reviewer PHP files | passed | 0 | | `8f751d0d/pint.txt` |

**Per-file breakdown of run 1:**

| File | Tests | Assertions |
| --- | --- | --- |
| CommitCapsule | 11 | 241 |
| CommittingAdmission | 2 | 36 |
| DocumentJourney | 4 | 87 |
| DownloadJourney | 5 | 112 |
| HttpJourney | 18 | 534 |
| PolicyRevision | 2 | 36 |
| RedeemFrameConjunct | 1 | 958 |
| RefusedFrameCallback | 2 | 42 |
| ReviewAdversarial | 4 | 99 |
| SchemaRecovery | 10, including 1 skip | 1,373 |
| SourceJourney | 12 | 196 |
| SpoolReservation | 3 | 11 |
| TransferDeadline | 6 | 133 |

That is the Codex round 6 count of 80, matched exactly.

**Vitest setup.** `node_modules` was symlinked to `/home/user/VA-Studio/node_modules` for these runs only and removed afterwards (`symlink removed: yes`).

**Earlier evidence.** The files directly under `review-evidence/addendum1/` (not in a SHA folder) come from the stopped reviewer's partial run at `0397231e` and `1cd837eb`. They are superseded by the SHA folders above and kept only as history.

## Untested conditions

- **Native MySQL.** No MySQL run in this addendum, because no path changed in the range is MySQL-specific. That leaves two things without native evidence:
  - the round 4 ordering argument, which relies on the pre-existing `FOR UPDATE` serialization;
  - any race between concurrent authorizes.
- **Spool concurrency.** It was exercised in one process, by nesting through the real `flock`s and sidecars. There was no multi-process run and no real filesystem-full condition. `freeBytes` is stubbed.
- **No browser run.** All client cases are jsdom with mocked `fetch`. The A1-L1 (ii) server-side race is argued from the lock order, not reproduced against a server.
- **Production provenance.** A `verified_production` current policy was exercised only as a refusal in the testing environment. Operative enablement is still Sean's authorization (C7).
- **Transfer and spool limits.** No end-to-end slow-client transfer near `transfer_max_seconds`, and no run against 3 occupied slots across processes (A1-L2).
- **Not run:** `vite build`, hosted CI and Foundation CI (cost policy).

## Files added by this review (untracked; nothing committed)

- `docs/verification/paid252-composition-20261007/independent-review/ADDENDUM-1.md` (this file).
- `docs/verification/paid252-composition-20261007/independent-review/review-evidence/addendum1/` (raw evidence; the SHA subfolders are this review's).
- `tests/Feature/PaidGrantReviewAddendum1Test.php`: 4 round 1 cases taken over from the stopped reviewer and re-run, plus 2 round 6 journey cases.
- `tests/Feature/PaidGrantSpoolReviewAddendum1Test.php`: 3 round 5 cases.
- `tests/frontend/paid-grant-review-addendum1.test.tsx`: 4 set-aside cases taken over, plus 3 round 7 cases. Two of them are `it.fails` reproductions of A1-L1, to be flipped to `it` when it is fixed.
