# Paid252 round 19: conditions C12 and C13 (one heavy step per buyer, progress instead of dead ends)

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. The work is
uncommitted on `harness/paid252-composition` at `a7892c9b`; results say "a7892c9b + working tree".

What did not change:

- No schema, migration, guard, receipt, fence, raw proof, post-commit proof or authorization lifetime.
- No pinned file (`evidence/pinned-check.txt`).
- The 320 s document client timeout.

## Decision (as given by the coordinator, to whom Sean delegated C12 and C13)

**C12, availability only.**
- Paid preparation runs at most one heavy step per buyer account at a time.
- A heavy step is one line's claim through its render, asset hash and record or failure frame, or one line's
  re-verification during completion.
- The mechanism is a non-blocking atomic lock on the default cache store:
  - key `paid-grant-heavy:` + `sha256('paid-heavy-v1:' . accountId)`;
  - TTL `LEASE_SECONDS + 60` = 360 s;
  - released in `finally` by its owner token.
- A request that finds the lock held does not claim or hash. It answers with the current projection through a fresh read
  frame and spends no attempt.
- Database claims, leases and `batch_once` remain the correctness guarantees. A crashed worker's lock frees itself within
  the TTL.

**C13, progress instead of dead ends.**
- When the call budget is spent at the loop top, the server returns the current projection instead of 410. Real refusals
  still refuse: attempts exhausted, access changed, a failed line, a lapsed line budget, and so on.
- The page continues by itself after one click:
  - another document POST while each one makes progress, at most 20 per click, never two within 10 s (the route throttle
    is `6,1`);
  - saved-status polling every 15 s while other work holds the order, or after a timed-out document request;
  - a stop on no progress, a denial, a refusal, the cap, or a hidden or left page;
  - progress in a polite `aria-live` region.

**Alternatives considered**
- *Background job for completion.* Rejected: every paid frame re-proves the current buyer principal and session-bound
  identity (`ProductionCustomerAccess::current`), and a queued job would need a buyer principal outside a session.
- *Single-flight per batch instead of per account.* A per-account lock also bounds one buyer's parallel orders, which is
  the A10-L1 amplification, at the same cost.
- *A blocking lock (wait).* Rejected: it would hold a worker idle and lengthen requests. The non-blocking lock plus client
  polling keeps workers free.
- *Raising the client timeout.* Rejected by the decision. Continuation makes it unnecessary (C11 requirement 5).

## Server change

**`app/Domain/Grants/Paid/PaidGrantDocuments.php`.** The diff looks large mostly because the existing per-line body moved
unchanged into `prepareLine()`.

- **`prepare()`, per iteration:**
  1. If the call budget is spent, return `current()`: a fresh `PaidGrantDeadline::start()` read frame, minting its own
     receipt and projection read.
  2. Take the heavy lock. If it is held, set `$busy = true` and return `current()`.
  3. Otherwise call `prepareLine()` (the old loop body: claim frame, render, store, asset hash, record frame, failure
     frame) and release the lock in `finally`.
  4. On `busy`, meaning another request's live claim, set `$busy = true` and return `current()`. This read frame now uses a
     fresh 60 s budget instead of the call budget, so a late busy answer is not refused.
  5. After the loop, if the call budget is spent, return `current()` instead of reaching `complete()`.
- **`complete()`:** each line's re-verification takes the same lock. If it is held, set `$busy = true` and return
  `current()`. The bundle and fulfillment frames are unchanged.
- **`prepare()` signature:** gains an optional by-reference `?bool &$busy`. Existing callers are unaffected.

**`app/Http/Controllers/PaidGrantController.php`.** The document answer is now `{ origin, busy }`.

**Why a new field.** Progress (more lines `complete`, or `fulfilled`) and another request's live claim (a `claimed`
line) can be read from the projection. A buyer lock held by other work cannot be, for example during another request's
completion re-verification, or another order of the same buyer. So the document answer, and only it, gains a boolean
`busy`. It holds no token or identifier. It sits beside `origin`, not inside it, so `validPaidOrigin`, the projection
receipts and the other routes are unchanged. The client accepts `{ origin }` or `{ origin, busy: boolean }` exactly
(`documentAnswer`).

**Cache store check.**
- The production default is `database` (`config/cache.php`, `.env.example`). Its lock table `cache_locks` is created by
  `database/migrations/0001_01_01_000001_create_cache_table.php`.
- Tests use `array` (`phpunit.xml`), and `ArrayLock` supports atomic locks.
- `test_the_database_cache_store_provides_the_lock_from_the_migrated_table` runs the lock-held case on the `database`
  store against the migrated table. It shows one `cache_locks` row while held and none after every step.
- The lock statements run outside every paid frame, on the default connection.

## Client change (`resources/js/components/PaidGrantJourney.tsx`)

- **Continuation:** `prepare` and "Finish preparing this order" now start `continuePreparing()`, configured by
  `paidContinuation = { posts: 20, spacing: 10_000, poll: 15_000, wait: 360_000 }`.
- **`call()`** now returns its outcome: `ok`, `lost`, `refused`, `denied`, `invalid` or `skipped`. Its messages, denial
  handling and clearing are unchanged. While a continuation runs, the page stays `busy` between requests, so the buttons
  stay disabled.
- **After each document answer:**
  - `fulfilled` → stop ("Your files are ready.");
  - `busy` → wait;
  - more `complete` lines than before → post again (at least 10 s after the last POST);
  - otherwise → stop ("Preparation stopped; no further progress was possible."), leaving the manual buttons.
  - A refusal, denial or invalid answer stops, with the existing alert.
  - A lost answer (timeout or network) shows the existing "could not be confirmed" and then waits.
- **Waiting:** every 15 s the page reads saved status (80 s timeout).
  - If the status shows `fulfilled`, the page reads the order once and stops.
  - A live claim (`renderRetryAfter` set and `renderRetryAllowed` false) keeps it waiting.
  - Otherwise it posts again, and the server says whether the work is still held.
  - Waiting stops after 360 s without progress (one lease plus a margin), or when a saved read fails.
- **Stops:** a hidden tab, leaving the page, unmounting or a denial bumps the run number, so nothing resumes by itself.
- **Live region:** `<p role="status" aria-live="polite">` with text such as "Preparing your files: 1 of 3 lines ready."
  It never takes focus, renders no token and has no animation.
- **`validStatus`** gains an `anyFulfilled` option, used only by the continuation's poll, because the order can become
  fulfilled while the page waits.

## Tests

| File | Case |
| --- | --- |
| `tests/Feature/PaidGrantHeavyWorkTest.php` (new, 7) | lock held → busy projection, no claim, render, original check or asset hash, attempts `[0, 0]`; released → fulfils |
| | completion while the lock is held → no re-verification, same projection, busy; released → fulfils |
| | the lock is held during every render and every completion check, and free afterwards |
| | the lock is released after an exception inside a step (the line is marked `failed`) |
| | a crashed worker's lock (never released) expires after 361 s and is reacquired |
| | a live claim by another request → busy, unchanged, lock free |
| | `database` cache store against the migrated `cache_locks` table |
| `PaidGrantPreparationBudgetTest` (changed) | three lines × 120 s: the first request now returns the projection (all `complete`, not fulfilled, `busy` false) instead of 410; the retry fulfils with attempts `[1, 1, 1]` |
| `PaidGrantHttpJourneyTest` (changed) | the document answer has exactly `origin` and `busy` (false) |
| `tests/frontend/paid-grant-continuation.test.tsx` (new, 7, fake timers) | progress → auto-continue until fulfilled, POSTs ≥ 10 s apart, `aria-live` polite text, focus unchanged, no CSRF value in the DOM; claimed → 15 s polling then continue; timeout → polling recovery to fulfilled; no progress → stop with the buttons; error refusal → stop; cap of 20 POSTs (≥ 10 s apart); hidden tab stops and nothing resumes |
| `tests/frontend/paid-grant-finish-completion.test.tsx` (changed) | the round 18 "lost answer" case now expects the C13 recovery: poll, post again, fulfilled |

## Results (`evidence/`)

PHPUnit is run from the worktree root with `public/build` absent:
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`.
Frontend runs use Node 24.21.0 with a temporary `node_modules` symlink, since removed.

| Run | Source | Result |
| --- | --- | --- |
| Server red, first test versions | `a7892c9b` product + new/changed tests | 27 tests, 2 errors, 6 failures, rc 2 (`server-red-on-a7892c9b.txt`) |
| Server red, final test versions | `a7892c9b` product (scratch worktree, removed) + final tests | 28 tests, 2 errors, 7 failures, rc 2 (`server-red-final-tests-on-a7892c9b.txt`) |
| Server green (HeavyWork, PreparationBudget, HttpJourney) | `a7892c9b` + working tree | 28 tests, 769 assertions, rc 0 (`server-green.txt`) |
| Paid family `--filter PaidGrant tests/Feature` | `a7892c9b` + working tree | 116 tests (109 + 7), 4,465 assertions, 1 skipped, rc 0 (`family-green.txt`) |
| C12-M1: lock acquisition ignored | final tree + mutation (scratch worktree) | 4 failures, rc 1 |
| C12-M2: no release after a line | final tree + mutation | 10 failures, rc 1 |
| C13-M1: budget exhaustion back to 410 | final tree + mutation | 2 errors (three-line case, completion-while-locked setup), rc 2 |
| Client red | `a7892c9b` client (swapped in temporarily) + final frontend tests | 6 failed, 4 passed, rc 1 (`client-red-on-a7892c9b.txt`) |
| Client CM1: no 10 s POST spacing | final tree + mutation (restored) | 2 failed, rc 1 |
| Client CM2: a lost answer stops instead of polling | final tree + mutation (restored) | 2 failed, rc 1 |
| All paid frontend files (10) | final working tree | 58 tests passed, rc 0 (`frontend-paid-all.txt`) |
| `tsc --noEmit` | final working tree | rc 0 (`tsc.txt`) |
| Pint `--test`, 5 PHP files | final working tree | passed (`pint.txt`) |
| Census self-test | final working tree | OK, rc 0 (`census-self-test.txt`) |

Mutation outputs are in `mutation-*.txt`.

**Red runs that pass by design:**
- the hidden-tab and error-refusal client cases (guards);
- the round 18 finish-control cases other than the lost-answer case.

**Test changes after the first red run:**
- The first server red ran a combined lock and exception test. It was then split in two, because a second order in one
  test conflicts with the SMTP enrollment fixture. The final-tests red covers the split versions.
- The CSRF sentinel in the continuation test was changed after the first client red, because `'c' × 40` occurs inside a
  fixture SHA-256. The client red above uses the final file.

## Bounds and `docs/ops/paid-delivery-runtime.md`

No bound increases:

- The lock is non-blocking, so it adds no waiting.
- Completion still runs only when the claim loop ends inside the 300 s call budget.

The document-route row (3,660 s) stays a safe upper bound. The tighter figure is about 3,360 s (A10 §5), because a line
claimed late ends the request at the loop top before completion. I did not edit the coordinator's file. Its C12 and C13
statements match this implementation: "Paid preparation runs at most one heavy step per buyer account at a time", and
requirement 5's "the page polls saved status and continues".

## What remains open

- **Native MySQL and real timings.** Not run. The database lock store is tested only on SQLite.
- **No cross-tab coordination.** Two tabs of the same buyer each run their own continuation. The lock and claims make that
  safe: one gets `busy` and waits. But it doubles the polling.
- **The page stops waiting after 360 s without visible progress.** Another request's completion re-verification of a
  large order can legitimately take longer without a visible change (completion has no per-line progress in the
  projection). The customer then sees "Still waiting for other work; refresh later." and can click again.
- **A lock lost to its TTL.** If a step runs past 360 s (a claim frame slower than 60 s), the lock may expire and a second
  heavy step may start. That costs availability only; claims and leases still decide.
- **Browser and assistive-technology check** of the live region. Vitest covers the DOM only.

## For Sean

- **Large orders finish from one click.** Preparing a large order no longer needs repeated clicks. The page keeps working
  after one click, shows "N of M lines ready", waits politely when another tab or request is already working, and
  recovers if a request times out.
- **Bounded server load.** The server now does heavy file work for at most one step per customer account at a time, so
  one customer cannot keep many workers hashing files.
