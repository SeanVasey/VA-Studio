# Independent review: Paid252 composition, Addendum 10 (round 16 per-line completion budgets, round 17 per-authorization retry)

**Range:** `093f9081..afd9cd944f80f9179d4243890b93c00fe1c09571`, fetched from `origin/harness/paid252-composition`.
- The coordinator extended the range from `d6ae43cf` to `afd9cd94` during the review.
- `git diff --quiet d6ae43cf afd9cd94 -- app config routes database tests/Feature tests/Support scripts bootstrap` returns
  0, so the PHP results at `d6ae43cf` also hold at `afd9cd94`.

| Commit | Content |
| --- | --- |
| `5e35e424` | Commits my Addendum 9 record (byte-identical to my copies), the Codex-14 README corrections (A9-I6) and the demo parent-script fix |
| `3ed91065` | Codex P2 4222959609. `PaidGrantDocuments::complete()` gives each line a fresh `PaidGrantDeadline::start(LEASE_SECONDS)` and the fulfillment frame a fresh `start()` (60 s). Adds `PaidGrantCompletionBudgetTest` (3 tests). |
| `d6ae43cf` | Docs: `hardening/codex-16/` |
| `4dcaf4ab` | Codex P2 4223149873, client-only. `kept` becomes a list of per-authorization marks, with one retry button per refused, still-unused authorization. |
| `afd9cd94` | Docs: `hardening/codex-17/` |

I wrote none of the code under review. Nothing was committed or pushed, and no product code was modified. **Reviewer:**
Claude (independent review lane). **Date:** 2026-10-08.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.**

- **Round 16** weakens no guarantee. Nothing relies on the physical observation fitting one 300 s window (§1). The
  fulfillment frame's fresh 60 s is safe (§2). The test double is faithful (§3), but the new tests cannot observe the
  per-line bound on the costly asset hash (A10-I2).
- **Round 17** offers no authorization twice and never submits the wrong token. A4-L1 holds per authorization (§6).
- Two new Low findings are pre-mount product conditions, not merge blockers:
  - **A10-L1:** one document request can now run for about 56 minutes. With the route's limits, one buyer can keep
    hundreds of workers hashing at once.
  - **A10-L2:** the page has no way to finish an order whose lines are all prepared but which is not fulfilled. The
    320 s client timeout no longer covers the server.
- **Conditions:** C11 is widened. C12 and C13 are new. C2 and C4–C8 are unchanged.

## 1. Does any guarantee rely on one 300 s observation window?

**No.**

**Reader of the label.** The only reader of the fulfillment manifest is `PaidGrants::graph()` (`PaidGrants.php:175-184`).
- It checks only that `physical_observation === 'exact_private_bytes_all_original_lines'`.
- It also checks the batch, origin, original and asset-manifest hashes, and that every line is `complete`.
- No code reads `observed_at`. A grep over `app/`, `resources/` and `routes/` finds only unrelated commerce
  `observed_at` fields.

**Insert guard.** The `paid_fulfillments` guard is the unique `batch_once` index plus the line, work and original
conditions. No time is involved.

**Downloads re-verify independently.** Each redemption re-checks the exact bytes, so a fulfillment never stands in for a
present-tense check:
- assets go through `DeliveryAssetFiles::copyVerified`, which hashes while copying;
- the contract goes through `PaidGrantFiles::verify` inside `copyTarget`;
- then `readBack()` re-hashes the sealed spool copy against the authorization's pinned SHA-256.

If bytes vanish after the line-1 check, the fulfillment still commits. A later redeem is then refused with
`target_unavailable` before any attempt is recorded. That was already possible inside the old 300 s window, and at any
time after fulfillment.

**A10-I1 (Info): the label now spans a wider window.**
- `exact_private_bytes_all_original_lines` can now describe observations up to about `10 × 300 + 60` s before
  `observed_at`, instead of at most 300 s.
- `observed_at` is the fulfillment commit time, not the observation time. That was true before, over a narrower span.
- Nothing enforces the label, so this is wording only.

## 2. The fulfillment frame's fresh 60 s

**Safe.**

- **The verified bundle is still what commits.**
  - `$bundle` comes from the bundle-read frame, which still uses `prepare()`'s budget.
  - The loop verifies exactly `$bundle`'s `manifest.artifact` and `body.assets`.
  - The fulfillment operation requires `$graph === $bundle` (`PaidGrantDocuments.php:134`). That compares every line's
    origin, body (and so the assets manifest), work state, original row and decoded manifest, plus `complete`.
  - Any change in between returns 409.
- **The frame itself is unchanged.**
  - `$commit` is passed to `$commands->run(...)` and from there to `PaidGrantConsumerCommitAdmission`, both producer
    receipts and `$projectionRead`. The operation also calls `$commit->proveCurrent()` itself.
  - Receipts, fence, `retained()`, `provePure` and the post-commit raw proofs are untouched.
  - `PaidGrantDeadline` still cannot be extended.
- **An overrun after commit costs nothing.** If the fulfillment frame commits and then fails its post-commit proof, the
  fulfillment stays recorded. The order is simply fulfilled, and the next call returns the projection. No attempt is
  consumed, unlike redeem's frame 2 (C2).

## 3. The `PaidGrantFiles` test double

**It hides nothing that could make a test pass where the real class would fail.**

- **Nothing in production can tell it apart.** `PaidGrantFiles` is `final` and stateless: no constructor, no properties
  other than a constant. Production resolves it through `app(PaidGrantFiles::class)` with no type hints or `instanceof`
  checks (`PaidGrantDocuments.php:69,123`, `PaidGrantPrepareStream.php:162`). So a plain object stands in, and any typed
  boundary would raise an error rather than pass.
- **It forwards faithfully.**
  - `verify()` runs the real `verify()` first and then advances the clock. Real refusals still propagate, and the bytes
    returned are the real ones.
  - `__call` forwards `store()` to the real method, which still enforces `RenderedContract $rendered`.
  - The pinned file is unchanged: all five `profile-assets.json` manifests match the tree at `d6ae43cf`.

**A10-I2 (Info, test gap): the costly part of the per-line bound is invisible to the virtual clock.**
- The double advances time only around the contract PDF check (at most 16 MiB).
- The per-line bound on the asset hash (up to 3 × 1 GiB) is passed through `PaidGrantAssets::verify` into
  `DeliveryAssetFiles` (`App\Domain\Delivery`), which reads the real `hrtime`.
- Probe P1: a deadline that the paid namespace considers 400 s expired is accepted by `PaidGrantAssets::verify`.
- So a regression that handed the asset verifier `prepare()`'s deadline again would pass all three new tests, and would
  only show in production. I argue this from the probe; I did not mutate product code.
- **Suggestion:** bind a `DeliveryAssetFiles` container double in the test. It is resolved through `app()` in
  `PaidGrantAssets::verify`, so the double can record the deadline it receives and assert that it equals the line's
  budget.

## 4. Retry and idempotency

- **A refused line.** Nothing is written, since completion writes only in the final frame. The next call re-verifies
  every line from line 1, each under a fresh bound. It does not need to resume, because no line's bound depends on the
  others. Covered by the new tests (b) and (c).
- **Two overlapping completions (probe P2).**
  - Setup: a second `prepare()` runs to completion inside the first one's line-1 verification.
  - Result: the inner call fulfils. The outer call verifies its line 2 and is refused with **409** by `$graph ===
    $bundle`. There is exactly one `paid_fulfillments` row and two originals.
  - A later call returns the identical projection. `batch_once` is the database backstop.
  - Both requests did the full I/O; see A10-L1.
- **A10-I3 (Info, unchanged by this range).** `prepare()`'s render loop still renders and verifies the assets of every
  claimed line under one 300 s budget (`:25`, `:71`).
  - A line claimed late in that budget can fail and be marked `failed`, which consumes one of `MAX_ATTEMPTS = 5`.
  - The next call claims that line first, with a full budget. So a normal order wastes at most one attempt per line.
  - A line that cannot render and verify within 300 s on its own fails permanently after 5 attempts. That is the same
    per-line ceiling that completion now uses.

## 5. Timings and the A9 conditions

**Worst case for one document request.** About `300` (render loop and bundle read) `+ 10 × 300 + 60` = 3,360 s, plus
post-commit proofs. On a retry where every line is already prepared, it is about 3,060 s. Render leases (300 s) apply only
to claims; completion holds no claim, so leases do not interact.

**A10-L1 (Low): per-request work and duration amplification.**
- `prepare()` on a fulfilled order still re-verifies every line. The new test (c) asserts this.
- The route allows `throttle:6,1,paid-render` per user, and `block(20, 5)` releases its session lock after 20 s, so
  requests do not serialize.
- One buyer with a large order can therefore start 6 requests a minute, each holding a worker for up to 56 minutes while
  hashing up to 30 GiB. That is roughly 300 workers in steady state. Before this range, each request ended within about
  300 s, giving roughly 30.
- Nothing leaks and nothing is consumed; this is availability only.
- It is reachable only by POSTing directly, because the page hides the prepare button once lines are complete.
- **Condition C12** below.

**A10-L2 (Low): the page cannot finish or retry completion, and the client timeout is shorter than the server's work.**
- **Pre-existing.** `validPaidOrigin` accepts a projection whose lines are all `complete` with `files: []` and `fulfilled:
  false`, which is the real server state after a refused or interrupted completion. But the page shows the prepare
  button only for `unfinished` lines. Probe P3: such an order shows "waiting for complete preparation" with no control.
  The code comment "a retry finds every line prepared and gets fresh bounds" is reachable only through a direct POST.
- **New.** The document client timeout is 320 s (`paidRequestTimeouts.document`), which round 2 set past the server
  budget. A first preparation of a large order now legitimately exceeds it. The client then aborts and shows "could not
  be confirmed" while PHP continues: no output is written until the end, so PHP does not notice the abort.
- The customer then sees the dead end above until the server finishes, or indefinitely if it refused.
- **Condition C13** below.

**C2 (unchanged).** The fulfillment frame's own 60 s matters less than redeem's frame 2, because an overrun after commit
costs nothing (§2). The native measurement C2 requires may include it, but nothing new is needed.

**C8 (unchanged).** Completion uses no spool slot.

**C11, widened (deployment).** Two points the A9 note missed:
- **(d) Long requests on the document route.** If the document route's PHP wall-clock kill is below about 3,400 s, a large
  order can never be fulfilled: it is killed before the final frame every time.
- **(e) Proxy read timeout.** nginx's default `fastcgi_read_timeout` is 60 s of upstream silence, and neither request
  writes output before its end, so the proxy returns 504 while PHP carries on.
  - Redeem now spends up to 420 s by default before its first byte (A9 §1). With a 60 s proxy read timeout, the proxy
    returns 504 to the iframe while PHP goes on to commit the redemption, then aborts on its first write. The attempt is
    consumed and no file arrives.
  - The read timeout on both routes must therefore exceed their worst case before the first byte: `60 + snapshot_seconds
    + 60` s for redeem, and the completion worst case for document.

## 6. Round 17 (`4dcaf4ab`): per-authorization retry state

**Can one authorization be offered twice, or the wrong token submitted? No.**
- `submit(a)` sets `kept = [...kept.filter(m => m.auth.id !== a.id), mark]`, so there is at most one mark per id.
- `retryable` is derived from those marks, so ids are unique, and the buttons are keyed by id.
- Each button's handler passes its own mark's authorization object `r`. `retryDownload(r)` submits it only if a mark with
  that id is currently refused, and `submit` sends `r.token`.
- A mark replaced between render and click (by a resubmission) is no longer refused, so the click does nothing.
- Probe P4:
  - two refused authorizations give two buttons, and each submits its own token: contract, master, master, contract, in
    the order clicked;
  - after the master is resubmitted and a read lists it `attempted`, only the contract button remains, once;
  - no token appears in the DOM;
  - `pagehide` empties the list.

**Does A4-L1 hold per authorization? Yes.**
- A mark becomes refused only when its own submission's frame reports a refusal. That frame is in `frames.current`, and the
  refusal sets `refused` on that mark object.
- A resubmission replaces the mark with `refused: false`, so a retry in flight is not offered again until it refuses in
  turn. Addendum 5 case (a) still passes.
- Probe P4: with the master still in flight, only the refused contract is offered.
- Even if an authorization did reach two submissions, which needs an idempotent authorize replay to re-add an id already
  in flight, the Addendum 4 harm is gone: a refusal removes only its own frame, and the server allows only one redemption
  per authorization.

**Can the list grow without bound?**

**A10-I5 (Info).** Within one page lifetime, it grows by one mark per distinct authorization submitted.
- It is pruned only by a denial, `pagehide` or unmount. A hidden tab keeps it.
- Marks for completed downloads hold spent tokens in memory. They are never rendered and are useless to the server.
- The size is bounded by issuance: at most 3 per line per 60 s, and at most 10 lines.
- This is the same class as A6-I1 and A8-I1. Optional fix: drop marks that a read lists as `attempted` or `expired`.

A9-I4 and A9-I5 still apply per mark.

## Runs

PHP 8.4.26 through the worktree runner `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require
"vendor/phpunit/phpunit/phpunit";' -- <args>`, with SQLite defaults and `public/build` absent. Each `.txt` file records the
command, head, rc and wall time. Paths are relative to `review-evidence/addendum10/`.

| # | Head | Command | Result | rc | Wall | Raw output |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `d6ae43cf` | `tests/Feature/PaidGrantCompletionBudgetTest.php` | 3/3, 69 assertions | 0 | 31 s | `d6ae43cf/sqlite-completion-budget.{txt,xml}` |
| 2 | `d6ae43cf` | `--filter PaidGrant tests/Feature` (20 files) | **105 tests, 104 passed, 1 skipped** (the native-only `PaidGrantSchemaRecoveryTest` case, the only `PaidGrant` entry in `scripts/ci/database-sqlite-skips.json`), 4,173 assertions; `PaidGrantCompletionBudgetTest` 3/3 | 0 | 479 s | `d6ae43cf/sqlite-paid-family.{txt,xml}` |
| 3 | `d6ae43cf` + untracked probe | `tests/Feature/ReviewProbeAddendum10Test.php`: P1, asset verification accepts a paid-expired deadline (A10-I2); P2, overlapping completions give one fulfillment and 409 for the later finisher | 2/2, 38 assertions | 0 | 20 s | `d6ae43cf/sqlite-review-probe.{txt,xml}`; source `d6ae43cf/ReviewProbeAddendum10Test.php.txt` |
| 4 | `d6ae43cf` | `/home/user/VA-Studio/vendor/bin/pint --test` on `PaidGrantDocuments.php` and `PaidGrantCompletionBudgetTest.php` | passed | 0 | | `d6ae43cf/pint.txt` |
| 5 | `d6ae43cf` | `python3 -I scripts/ci/test-database-receipts.py` | 34 tests OK | 0 | | `d6ae43cf/census-self-test.txt` |
| 6 | `afd9cd94` | `npx vitest run` on `paid-grant-journey` and `paid-grant-review-addendum{1,4,5,6,7,9}` (vitest 5.0.1, Node 24.21.0) | 7 files, 46/46 | 0 | | `afd9cd94/frontend-vitest.txt` |
| 7 | `afd9cd94` + untracked probe | `npx vitest run tests/frontend/paid-grant-review-addendum10.test.tsx`: P3, no completion control (A10-L2); P4, per-authorization retries | 2/2 | 0 | | `afd9cd94/frontend-review-addendum10.txt` |
| 8 | `afd9cd94` | `npx tsc --noEmit`, without and with the probe | clean | 0, 0 | | `afd9cd94/tsc.txt`, `afd9cd94/tsc-with-probe.txt` |

**Not recorded as runs.** These are my own test errors, fixed before rows 3 and 7, and not product failures:
- **PHP probe:** the first draft compared against `hrtime()` from the test namespace, which is the real clock, and
  expected 3 verifications instead of 4.
- **Frontend probe:**
  - the unfulfilled fixture kept `files`, which the server never sends and the client rightly rejects;
  - the master filename did not match the client's `-master_wav.wav` check;
  - the contract's current refusal cleared the page before the read.

`node_modules` was symlinked to `/home/user/VA-Studio/node_modules` for rows 6–8 only and then removed (`unlink`). No
MySQL daemon was started.

## Not tested

- **Real time:** a real 10-line, 30 GiB completion, slow storage, and the real-clock asset bound (A10-I2).
- **Real servers:** the PHP-FPM and nginx timeouts in C11 (d) and (e) are argued from server defaults.
- **Concurrency:** concurrent completions in separate processes (probe P2 nests them in one process), and native MySQL.
- **A real browser.**

## Conditions

| ID | Status after this addendum |
| --- | --- |
| C1, C3 | Resolved (unchanged). |
| C2 | Open, as extended in Addendum 9 (redeem frame 2). |
| C4–C7 | Open, unchanged. |
| C8 | Open, as extended in Addendum 9. Completion uses no spool slot. |
| C9, C10 | Met. |
| **C11 (widened)** | (a)–(c) as in Addendum 9, plus two more. **(d)** The document route's PHP wall-clock bound must be at least about `300 + 10 × LEASE_SECONDS + 60` s plus a margin; otherwise a large order can never be fulfilled. **(e)** The proxy read timeout (nginx `fastcgi_read_timeout`, default 60 s) on both routes must exceed their worst case before the first byte: `60 + snapshot_seconds + 60` for redeem, and the completion worst case for document. Otherwise the proxy returns 504 while a redemption still commits and consumes the attempt. |
| **C12 (new, before mount; A10-L1)** | Bound completion's per-request work for one buyer and batch. Options: single-flight per batch (a non-blocking lock or a completion claim), skip physical re-verification once `complete !== null` (downloads re-verify anyway), or a per-account concurrency limit on the document route. |
| **C13 (new, before mount; A10-L2)** | The page must be able to finish an order whose lines are all prepared but which is not fulfilled, by offering completion when `!fulfilled` and every line is `complete`. The document client timeout must be realigned with the server worst case, or completion made asynchronous with status polling. |

## Files added by this review (untracked; nothing committed)

- This file.
- `review-evidence/addendum10/`: the `d6ae43cf/` folder holds the PHP evidence and the PHP probe source as `.txt`; the
  `afd9cd94/` folder holds the frontend evidence.
- `tests/frontend/paid-grant-review-addendum10.test.tsx`: two documenting cases, both passing against current behaviour.
- The PHP probe was moved out of `tests/` after recording, so `tests/Feature/ReviewProbe*` does not exist.

**Worktree housekeeping.** My untracked Addendum 9 copies were byte-identical to `5e35e424` (18 files) and blocked
checkout. I moved them, not deleted them, to
`/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/rv56a10-parked-addendum9/`.
