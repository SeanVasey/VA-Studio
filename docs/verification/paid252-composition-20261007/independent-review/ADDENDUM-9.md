# Independent review: Paid252 composition, Addendum 9 (round 13 split budgets, round 14 ruling, round 15 frame answers)

**Range:** `9004245f..093f90819aa4905babfdad26923cd82b4547903d`, fetched from `origin/harness/paid252-composition`. The
coordinator extended the range from `d00e0fa0` to `093f9081` during the review. `git diff --quiet d00e0fa0 093f9081 -- app
config routes database tests/Feature tests/Support scripts bootstrap` returns 0, so every PHP result below holds for both heads.

| Commit | Content |
| --- | --- |
| `a6c02498` | Codex P2 4222562053: separate snapshot and commit-frame budgets in `PaidGrantDownloads::redeem()`, `paid-grants.snapshot_seconds`, the stream ceiling (60 → 1800 s for caller deadlines), the paid-namespace `hrtime` test seam and `PaidGrantSnapshotDeadlineTest` (6 tests) |
| `d00e0fa0` | Docs: `hardening/codex-13/` and `hardening/codex-14/` (Codex P2 4222614269, routed to Sean, no code) |
| `1b500ac6` | Codex P2 4222901206, client-only: a download frame's answer no longer depends on the API-request generation |
| `093f9081` | Docs: `hardening/codex-15/` |

I wrote none of the code under review. Nothing was committed or pushed, and no product code was modified. **Reviewer:** Claude
(independent review lane). **Date:** 2026-10-08.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.**

- Splitting the budget weakens no authorization, one-use, limit, owner/identity, receipt or fence guarantee (§1). It
  removes a path that consumed attempts without delivery (the shared 60 s budget).
- The stream ceiling change is sound and reaches only redemption (§2).
- The test seam cannot load in production and cannot change any other test's clock (§3). One fragility is recorded (A9-I3).
- The frame-2 residual is acceptable for a development merge and is folded into C2 (§4).
- The Codex-14 ruling (deployment condition, no code) is correct. I add **C11** with corrected arithmetic and two
  operational requirements (§5).
- Round 15 records no refusal it should not, and the retry gate holds. A read taken before the refusal can now enable the
  retry, but the retry cannot cost an attempt or race the original (§6, A9-I4, A9-I5).

There is no finding at Low or above. C2 and C4–C8 stay open; C11 is new.

## 1. Security: does the split weaken any guarantee?

`redeem()` at `a6c02498` (`PaidGrantDownloads.php:108-174`):

| Phase | Budget | Where |
| --- | --- | --- |
| Locate + frame 1 | `$deadline = PaidGrantDeadline::start()` (60 s) | `:112`, passed to `$commands->run(...)` at `:138` |
| Snapshot | `hrtime(true) + snapshotSeconds()` from when frame 1 has returned and `deadline($before['auth'])` has passed | `:147-148` |
| Frame 2, post-commit proof, before-first-byte proof | `$commit = PaidGrantDeadline::start()` (60 s) | `:156`; `run()` hands the same object to `PaidGrantConsumerCommitAdmission::capture`, both producer `committedReadReceipt`s and `PaidGrantProjectionRead::capture` (`PaidGrantCommands.php:70-86`), so `proveBeforeBytes()` uses it (`PaidGrantProjectionRead.php:44`) |
| Transfer | size-derived, from the commit | unchanged (`:169`) |

Each guarantee, re-checked in frame 2 under its own locks:

- **Valid-to-start.** `$admitted ??=` (`:127`) is set once, in frame 1. Frame 2 re-runs `$inspect`, so `live($auth, $rows,
  $admitted)` re-checks the same instant against `expires_at` and requires no redemption row. Unchanged.
- **One use and `max_downloads`.** Frame 2 still requires `count(attempts) < max_downloads` from the retained policy and
  inserts `paid_redemptions` inside the owned graph frame. `authorization_once` remains the database backstop. Probe P3
  inserts a redemption row for the same authorization during a 1,000 s virtual snapshot: frame 2 refuses with 409 and no
  second row exists.
- **Unchanged state.** `$current === $before` compares the full graph, line, authorization row and decoded payload.
  Unchanged.
- **Owner and identity.** Frame 2 is a complete `PaidGrantCommands::run`: current-buyer lock, durable binding,
  historical binding, producer `lockedRead` (payment `on_time`), `retained()` against the freshly captured policy,
  `provePure` in the fence. It is just as fresh as before; only its start time is later. Probe P2 disables the family, and
  separately switches the configured provenance to `verified_production`, during a 1,000 s virtual snapshot. Both refuse
  before anything is recorded, and the same authorization redeems once the configuration is restored, so the slot and
  reservation were released.
- **Receipts, fences and post-commit raw proofs.** These are untouched. Only the deadline object differs, and it is fresh
  rather than extendable: `PaidGrantDeadline` has no extend path and `start()` is capped at 300 s.
- **Can the snapshot go stale or be swapped?** No. The snapshot is bound to the hash, not to time.
  - `copyVerified` hashes the protected source descriptor while copying (`DeliveryAssetFiles.php:56-75`).
  - `readBack()` re-hashes the sealed 0400 spool file through a fresh read-only descriptor and requires
    `$target['sha256']` and the exact size (`PaidGrantPrepareStream.php:190-209`).
  - The file is then unlinked, and the inode, size and mode are re-checked on the open descriptor (`:102-106`).
  - `$target` is the authorization payload's target. Frame 2 requires it to equal `target($graph, $line, kind)` from the
    current retained graph (`:133`), and the retained originals are immutable.
  - So whatever bytes are streamed hash to the target that frame 2 proved current. The 60 s budget never protected this;
    the hash does. A longer window changes nothing.

**A9-I1 (Info): valid-to-start now spans the snapshot budget.**
- Before, a redemption admitted just before expiry had to commit within the 60 s total budget.
- Now it may commit up to about `60 + snapshot_seconds + 60` s after admission, which is 420 s by default and 1,920 s at
  the maximum.
- Probe P1: with `snapshot_seconds = 1800`, a redemption admitted inside the 60 s lifetime commits after the wall clock
  has passed expiry by 1,700 s, and delivers the exact master. A new redeem of the same authorization is then 410.
- This is the intended valid-to-start semantics (round 1). Every authority except the admitted instant is re-proved at
  commit. No condition is needed. Sean should know that "authorization lifetime" bounds the start, not the commit.

**A9-I2 (Info): `capture()` now validates `snapshot_seconds`.** An out-of-range value therefore returns 503 for every
paid command, not only redeem. That includes finalize, document, reads, status and authorize. It is fail-closed and
matches the transfer keys since round 1. The new test (d) covers authorize and redeem.

## 2. The stream ceiling (60 → 1800 s for caller deadlines)

- **Callers.** The only production caller is `redeem()` (`grep` over `app routes config`). Tests pass `null` (spool tests)
  or reach it through `redeem()`. A `null` deadline still gets `MAX_SECONDS` (60 s). Nothing else gains a longer bound.
- **Effect of the ceiling.** `redeem()` computes the deadline from `snapshotSeconds()` (≤ 1800) before `handle()`
  reads its own `$now`. So `min($snapshotDeadline, $now + 1800 s)` always resolves to the caller's value in production.
  The ceiling only guards future callers. Codex mutation M1 shows that the old 60 s cap would have made `snapshot_seconds`
  above 60 inert.
- **Admission lock.** It is not held during the copy. `admit()` takes `admission.lock` with `LOCK_EX` and releases it in
  its own `finally` (`PaidGrantPrepareStream.php:217-259`), before `fopen(x+b)` and `copyTarget()`. A slow snapshot
  therefore never blocks other admissions. They see its slot as held, with its reservation.
- **Slot and reservation.**
  - During the snapshot, the buyer's slot (lock + holder) and its `.reserve` of `size_bytes` are held for up to
    `snapshot_seconds`, instead of up to 60 s.
  - The reservation is released once the snapshot is sealed and unlinked (`:101`). The slot stays held through frame 2
    and the transfer, as before.
  - Worst-case slot hold: before, about 60 + 60 + `transfer_max_seconds`. Now `60 + snapshot_seconds + 60 +
    transfer_max_seconds` (7,620 s by default).
  - Snapshot duration depends on server storage speed, not the client. One slot per buyer still applies throughout.
  - This only lengthens C8's existing capacity question; it adds no new exposure. Recorded under C8.

## 3. Test seam safety (`tests/Support/PaidGrantMonotonicClock.php`)

- **Production.** It cannot load there.
  - `Tests\` is mapped only in `autoload-dev` (`composer.json`), and there is no `files` autoload entry.
  - Nothing under `app config routes bootstrap database public` references `Tests\Support` or `tests/Support`.
  - The shadow function is defined only when the support file is included. That happens only through
    `class_exists(PaidGrantMonotonicClock::class)` at the top of the test file.
  - A `--no-dev` install has no autoload path to it.
- **Other tests.**
  - Once the test file is loaded, every paid call site in that PHPUnit process runs the shadow. At offset 0 it returns
    `\hrtime(true)` exactly, and the array form is passed through. The probe shows a difference below 50 ms and an array
    for the no-argument form.
  - `setUp`/`tearDown` reset the offset. `tearDown` resets before `parent::tearDown()`, so it runs even after a failing
    test, and `proveClockSeam` resets in `finally`.
  - The commit listener is registered per application instance, so it does not outlive the test.
  - No other test defines `App\Domain\Grants\Paid\hrtime`; the `function_exists` guard would only matter if one did.
- **Shards.**
  - `scripts/ci/phpunit-shards.py` partitions whole files. A shard either loads this file while building its suite, or
    never defines the shadow.
  - Every paid data provider returns literal arrays: I read the bodies of `capsuleCases`, `committingWithdrawals`,
    `lateBodyWithdrawals`, `refusals`, `refusedFrames`, `drifts` and `withdrawals`. So no paid code runs before the file
    is loaded.
- **Census.**
  - The new file has no skip markers.
  - In my family run, the only skip is the pre-existing native-only `PaidGrantSchemaRecoveryTest` case, which is the one
    `PaidGrant` entry in `scripts/ci/database-sqlite-skips.json`.
  - `python3 -I scripts/ci/test-database-receipts.py` passes 34/34. That self-test covers the receipt machinery; actual
    census exactness is enforced against JUnit when receipts are collected, and nothing in this range changes the skip
    set.

**A9-I3 (Info): call-site caching can leave the seam half live.** PHP caches each call site's resolution the first time it
runs. My standalone probe (`seam-probe.txt`) shows this:
- **"early" mode** (support file loaded first, as the test does): the seam is live.
- **"late" mode** (paid code ran first): `PaidGrantDeadline::start()` stayed on the global clock, while
  `proveCurrent()`, first executed afterwards, used the shadow.
- **Effect:** a half-live seam can make a test that expects success pass without its budget being exercised. For example,
  if the stream's `within()` stayed global, case (a) would pass even under mutation M1.
- **Why it is Info:** it is unreachable under PHPUnit's load-then-run order with literal data providers.
  `proveClockSeam()` runs only in case (a) and checks only the `start()` site.
- **Suggestion:** call `proveClockSeam()` in `setUp`, and keep the mutation runs (M1–M3) as the real liveness evidence.

## 4. Residual: frame 2 alone over 60 s

`test_a_commit_frame_overrunning_its_own_budget_still_fails_the_unchanged_post_commit_proof` pins the residual. If frame
2 commits inside its budget but the post-commit proofs finish after it, the attempt is consumed and nothing is delivered.

- An overrun **before** the commit is refused with nothing recorded: `PaidGrantConsumerCommitAdmission::proveCurrent`
  checks the deadline on both sides of the commit admission.
- So the exposure is only the time from commit to the end of the post-commit proofs (`proveClosed`, the producer
  `proveClosed`, `proveRawClosed`). Those include the identity closed re-reads behind C2.
- This window is strictly smaller than before. Previously it was whatever remained of one shared 60 s budget; now it is a
  fresh 60 s for frame 2 alone.

**Acceptable for a development merge.** I fold it into **C2**: the native margin measurement C2 requires must report
frame 2, from start through the post-commit proof, against its own 60 s, not only the total redeem time.

## 5. Codex-14 ruling (blocked SAPI write holds a spool slot)

**The routing is correct, and no in-code mitigation is proportionate.**

- **The mechanism is real.** `PaidGrantTransfer::writeTo` checks the deadline only between chunks (`:31-36`), so a
  consumer write blocked on backpressure never returns to the check or to the `finally` that closes the lease.
  - I re-ran the Codex demonstration from scratch copies, with the child named `child.php`. The parent cannot take the
    lock while the child is blocked in `poll_schedule_timeout`, and it can after `SIGKILL` (`codex14-flock-repro.txt`).
  - `max_execution_time` is CPU time on Linux, so it does not fire during a blocked write.
- **No process inheritance on this path.** The only process spawners near the paid family are the document renderers
  (`PaidGrantRendererProcess`, plus the Free/ProductionFree ones), and none of them runs during redeem or transfer. I
  grepped `app/Domain/Grants`, `Delivery`, `Customers/ProductionIdentity`, `Commerce/ProductionCheckout` and
  `ProductionCustomerAccess`. So the descriptor-inheritance caveat does not apply today.
- **Code options.** Releasing the slot before streaming breaks the disk bound. Stealing a held `flock` slot is not
  possible and would corrupt the reservation accounting. PHP has no preemptible SAPI write. Code can only add observability
  (for example, writing the admission time into the holder sidecar), which is optional.

**A9-I6 (Info): corrections to the Codex-14 deployment note.** These are carried into **C11**.

1. **Wall-clock bound arithmetic.** The longest legitimate request is `60 + snapshot_seconds + 60 +
   transfer_max_seconds` plus header and proxy latency. That is 7,620 s at the defaults and 16,320 s at the validated
   maxima (1,800 / 14,400). The note says "7200 s plus the snapshot budget plus a margin", which leaves out both 60 s
   frames.
   - A kill set below this does not just free the slot. It also cuts legitimate slow transfers after the commit, which
     consumes the attempt and leaves a partial file.
   - `request_terminate_timeout` applies to the whole pool, so a two-hour cap on every route is weak protection elsewhere.
     Use a dedicated FPM pool, or equivalent, for the paid download route.
2. **Buffering is the effective mitigation.** With nginx `fastcgi_buffering on` and `fastcgi_max_temp_file_size` at least
   the largest deliverable (`DeliveryAssetFiles::MAX_BYTES` = 1 GiB; the default `1024m` is exactly that), PHP writes the
   whole file at disk speed.
   - The slot is then released in seconds, whatever the client's speed. That also eases C8 substantially.
   - Two consequences:
     - the size-derived transfer deadline no longer bounds delivery to the client; nginx `send_timeout` does;
     - the proxy's temporary file is a second on-disk copy of a private master, outside the 0700 spool and outside the
       spool's reservation accounting.
   - The proxy temp path must therefore be private and non-public, on storage sized for the concurrent maximum, and
     covered by the same no-public-path invariant as the spool.
3. **Evidence nit.** `flock-blocked-writer-parent.php` starts `__DIR__.'/child.php'`, but the committed child is
   `flock-blocked-writer-child.php`. The scripts do not re-run as committed. The recorded output is genuine: I reproduced
   it after renaming.

## 6. Round 15 (`1b500ac6`): frame answers independent of the generation

The `load` handler now returns early only if the page is unmounted or the frame is no longer in `frames.current`. Frames
leave `frames.current` only through `refuse()` (a CSRF token is missing, or a read returns 401/403/404/419), `pagehide`,
unmount, or the frame's own refusal. No other `clear()` call drops frames. There are four calls: `refuse()`, `pagehide`, the hidden tab (`clear(true, true)`) and the frame's own current refusal (`clear(true, true)`).

**Does any path record a refusal it should not? No.**
- `mark` is the submission's own object. A refusal sets `refused` on that object only. If a newer submission has replaced
  `kept.current`, the older mark is detached, so it cannot enable a retry (the Addendum 5 newer-mark case still passes).
- After a denial or departure, the frame is gone and its answer is ignored. The new Addendum 5 case shows this after
  `pagehide`, and `refuse()`/`leave` also null `kept`.
- A non-refusal body (an HTML 502 or another code) records nothing. Outside the current generation it also shows no
  message (my probe P6).
- Recording is client-local: it only feeds the gate below.

**Does the retry gate hold? Yes, unchanged.** `keptUnused` (`PaidGrantJourney.tsx:200-202`) requires all of the
following: the kept mark refused, no denial, an open order, a status for that order, the authorization not among the
shown issued entries, and a history entry with that exact id and kind marked `unused`. The retry cannot race its original,
because `refused` is only set by that submission's own frame reporting, which proves the server finished it (A4-L1 stays
closed). My probe P4 confirms that no retry is offered while the original's frame is silent.

**Can a read issued before the refusal arrived enable a harmful retry? It can enable a retry, but not a harmful one.**
**A9-I4 (Info).**
- A non-current refusal no longer calls `clear()`, so the status from a read taken between submission and refusal
  survives.
- Probe P4: a read that completed before the refusal enables the retry with no further fetch.
- Probe P5: a read still in flight when the refusal arrived enables it once it lands with `unused`, and does not if it
  lists the authorization `attempted`.
- This relaxes Addendum 5 (a), which required a read taken after the refusal.
- **Why it is harmless:**
  - A pre-commit refusal leaves the authorization genuinely unused.
  - A refusal sent after commit happens only on the frame-2 post-commit failure in §4. For that one, a pre-commit read
    wrongly shows `unused`. The retry then gets 409 from `live()` in frame 1, before any snapshot, slot or attempt. The
    attempt was already consumed by the original, and nothing more is consumed.
  - `submit()` clears `status`, so the next offer needs a new read. That read shows `attempted`.

**A9-I5 (Info, UX).** A non-current refusal changes only refs (`mark.refused`, `frames.current`) and calls no state setter,
so nothing re-renders. The retry button appears only at the next render for any reason. Probe P4 shows it absent right
after the refusal and present after a plain re-render with no fetch. In practice the customer refreshes, so the cost is
only a delay. A `setState` tick in that branch would show it at once.

## Runs

PHP 8.4.26 through the worktree runner `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require
"vendor/phpunit/phpunit/phpunit";' -- <args>`. The worktree has its own Composer autoload, non-authoritative, with
`Tests\` mapped to the worktree; packages are symlinked to `/home/user/VA-Studio/vendor`. SQLite defaults, `public/build`
absent. Each `.txt` records the command, head, rc and wall time. Paths are relative to `review-evidence/addendum9/`.

The PHP evidence is in `d00e0fa0/`. The PHP tree is identical at both heads, and each file's header names the head it
actually ran at. The new-test run was at `d00e0fa0`. Every other PHP run, Pint and the census were at `093f9081`. The
frontend evidence is in `093f9081/`.

| # | Head | Command | Result | rc | Wall | Raw output |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `d00e0fa0` | `tests/Feature/PaidGrantSnapshotDeadlineTest.php` | 6/6, 144 assertions | 0 | 51 s | `d00e0fa0/sqlite-snapshot-deadline.{txt,xml}` |
| 2 | `093f9081` | `--filter PaidGrant tests/Feature` (19 files) | **102 tests, 101 passed, 1 skipped** (the native-only `PaidGrantSchemaRecoveryTest` case, the only `PaidGrant` entry in the census), 4,071 assertions. Codex's run had 4,080; the difference is the load-dependent `RedeemFrameConjunct` sampling. | 0 | 462 s | `d00e0fa0/sqlite-paid-family.{txt,xml}` |
| 3 | `093f9081` + untracked probe | `tests/Feature/ReviewProbeAddendum9Test.php`: P1 commit 1,700 s past expiry (A9-I1); P2 disable (404) and switch provenance (409) during a 1,000 s snapshot, nothing recorded, then redeems; P3 a redemption row committed during the snapshot gives 409 and one row | 3/3, 52 assertions | 0 | 29 s | `d00e0fa0/sqlite-review-probe.{txt,xml}`; source `d00e0fa0/ReviewProbeAddendum9Test.php.txt` |
| 4 | `093f9081` | Standalone seam probe, `early` and `late` modes | early: offset 0 matches the global clock within 50 ms and the seam is live; late: the seam is half live (A9-I3) | 0, 0 | <1 s | `d00e0fa0/seam-probe.{php.txt,txt}` |
| 5 | `093f9081` | `/home/user/VA-Studio/vendor/bin/pint --test` on the 6 changed PHP files | passed | 0 | | `d00e0fa0/pint.txt` |
| 6 | `093f9081` | `python3 -I scripts/ci/test-database-receipts.py` | 34 tests OK | 0 | 7 s | `d00e0fa0/census-self-test.txt` |
| 7 | n/a | Codex-14 `flock` demonstration, scratch copies with the child named `child.php` | held while blocked in write: `false`; after `SIGKILL`: `true` | 0 | 3 s | `d00e0fa0/codex14-flock-repro.txt` |
| 8 | `093f9081` | `npx vitest run` on `paid-grant-journey` and `paid-grant-review-addendum{1,4,5,6,7}` (vitest 5.0.1, Node 24.21.0) | 6 files, 42/42 | 0 | 8 s | `093f9081/frontend-vitest.txt` |
| 9 | `093f9081` + untracked probe | `npx vitest run tests/frontend/paid-grant-review-addendum9.test.tsx`: P4 a pre-refusal completed read and the re-render (A9-I4, A9-I5); P5 an in-flight read, `unused` versus `attempted`; P6 a non-refusal or non-current answer | 3/3 | 0 | 2 s | `093f9081/frontend-review-addendum9.txt` |
| 10 | `093f9081` | `npx tsc --noEmit`, without and with the probe | clean | 0, 0 | | `093f9081/tsc.txt`, `093f9081/tsc-with-probe.txt` |

**Notes on the runs:**
- The first version of P4 asserted that the retry appears right after the refusal, and failed. That failure is A9-I5, and
  the committed probe asserts it. It was not recorded separately.
- `node_modules` was symlinked to `/home/user/VA-Studio/node_modules` for runs 8–10 only and then removed (`unlink`).

## Not tested

- **Native MySQL.** No changed path is MySQL-specific. The frame-2 duration on a production-shaped database, which the
  §4 residual depends on, is unmeasured (C2).
- **Real time.** Real slow storage, a 1 GiB snapshot at 300 s, and a real FPM/nginx stack for Codex-14. Buffering,
  `send_timeout` and `request_terminate_timeout` behaviour is argued from server semantics; only the `flock` mechanism was
  reproduced.
- **A real browser** for round 15 (jsdom with mocked frames).
- **Multi-process spool contention** during a long snapshot.

## Conditions

| ID | Status after this addendum |
| --- | --- |
| C1 | Resolved in design (Addendum 1). |
| C2 | **Open, extended:** the native measurement must report frame 2 (start to post-commit proof) against its own 60 s, since an overrun after commit consumes an attempt (§4). |
| C3 | Resolved (`3be1913f`). |
| C4–C7 | Open, unchanged. |
| C8 | **Open, extended:** the slot hold now includes up to `snapshot_seconds` (§2). Proxy buffering (C11) largely decouples the hold from client speed. |
| C9, C10 | Met (Addenda 5 and 8). |
| **C11 (new, before mount; deployment, Sean/operator)** | (a) Bound the PHP worker's wall clock for the paid download route at no less than `60 + snapshot_seconds + 60 + transfer_max_seconds` plus a margin, in a dedicated pool. (b) Keep proxy response buffering on for that route, with a temp-file cap of at least the largest deliverable. (c) Put the proxy's temp path on private, non-public storage sized for the concurrent maximum (A9-I6). |

## Files added by this review (untracked; nothing committed)

- This file.
- `review-evidence/addendum9/`.
- `tests/frontend/paid-grant-review-addendum9.test.tsx`: 3 documenting cases, all passing against current behaviour.
- The PHP probe was moved out of `tests/` after recording. It is kept only as evidence text
  (`review-evidence/addendum9/d00e0fa0/ReviewProbeAddendum9Test.php.txt`), and `tests/Feature/ReviewProbe*` does not exist.

**Worktree housekeeping.** The untracked Addendum 8 files were byte-identical to the copies committed at `d00e0fa0`, but
they blocked the checkout. I moved them, not deleted them, to
`/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/rv56a9-parked-addendum8/`. No MySQL
daemon was started.
