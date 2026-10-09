# Paid252 round 20: heavy-lock TTL, authorization window, and review addendum 11 (A11-L1, A11-L2, A11-I1, A11-I5, A11-I6)

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. The work is
uncommitted on `harness/paid252-composition`:

- It was started on `a5354220`.
- The coordinator then committed review addendum 11 as `9605e95c`, which changed only docs and the reviewer's
  frontend probe.
- Results say "9605e95c + working tree". The paid product files are identical at `a5354220` and `9605e95c`.

What did not change:

- No schema, migration, guard, receipt, fence, raw proof or post-commit proof.
- No pinned file (`evidence/pinned-check.txt`).
- The 320 s document client timeout.
- `docs/ops/paid-delivery-runtime.md`. It states no lock TTL; the coordinator edits it in the same tree for C11 (f)–(h).

## A. Heavy-lock TTL (Codex P2 4223825205, A11-L1)

**Finding.** The buyer lock was taken before the claim frame, which runs on the call budget (up to 300 s left), and the
claimed line then ran its own 300 s budget. A step could therefore hold the lock for about 600 s. The TTL was
`LEASE_SECONDS + 60` = 360 s, so it could expire mid-render or mid-hash, and the same buyer's next request could start a
second heavy step.

**Fix.** `PaidGrantDocuments::HEAVY_LOCK_SECONDS = 2 * LEASE_SECONDS + 60` (660 s). The comment explains the arithmetic:
the claim frame on the call budget, plus the line's 300 s budget, plus 60 s for post-commit tails. `complete()` holds the
lock for one line's re-verification (a 300 s budget plus its tail), which fits with room to spare.

**Client wait (A11-L1 follow-up).** `paidContinuation.wait` goes from 360 s to 660 s, the same as the TTL.

- A live holder can legitimately keep the lock for up to about 600 s. A crashed holder now blocks the buyer's preparation
  for up to 11 minutes.
- At 360 s the page would stop while a legitimate holder still worked, which only meant one more click. At 660 s it
  keeps polling through any lock a live holder can hold.
- The extra cost is at most 44 status reads (one every 15 s), well inside the read throttle. The 20-POST cap still
  applies, and the page still stops on no progress.
- The comment in `PaidGrantJourney.tsx` now states the TTL and the 11-minute crashed-holder case.

## B. Authorization window (Codex P2 4223825193, A11-L2)

**Finding.** `expires_at = created_at + authorization_seconds` was set at insert, but the token reached the customer only
after the authorize frame committed and passed its post-commit proofs, up to its 60 s budget later. Redeem then took its
admitted instant inside frame 1, after `locate()`. On native MySQL a redeem was refused 0.4 s after expiry.

**Fix (`PaidGrantDownloads`), following Free256's `$requested`:**

1. `public const AUTHORIZE_BUDGET_SECONDS = 60`. `authorize()` now starts its frame budget from this constant
   (`PaidGrantDeadline::start(self::AUTHORIZE_BUDGET_SECONDS)`), so the constant is the budget itself, not a copy of it.
2. At insert, `expires_at = created_at + authorization_seconds + AUTHORIZE_BUDGET_SECONDS`. The policy lifetime then
   counts from the latest moment the token can reach the customer.
3. `redeem()` takes `$admitted = now()` at request start, before `locate()`. Both frames' `live()` checks compare it with
   `expires_at`.
4. **A11-L2: the post-frame check also uses `$admitted`.** It is now `deadline($before['auth'], $admitted)`, and its
   maximum is `600 + AUTHORIZE_BUDGET_SECONDS`.
   - Before, it compared `now()` and refused a redeem begun in time whose `locate()` ended after expiry (reviewer probe
     P4).
   - With the 60 s added, a 600 s policy would also exceed the old 600 s maximum on every fresh redeem.
5. **Unchanged:**
   - the authorize idempotency replay (same row, id, token and expiry);
   - `status()`;
   - the paid-authorization insert guard, which only requires `expires_at > created_at`, so no guard pins the lifetime;
   - the client.

**Lifetime-relationship check.**
- *Client set-aside rule.* It relies on every authorization of a line sharing one fixed lifetime and being issued in id
  order. Every row gets the same added constant, so that still holds; the A1 round-4 window proof is unchanged.
- *Client display.* It shows the server's `expiresAt`.
- *80 s A1-L1 rule.* Unaffected.
- *Database guard.* `PaidGrantSchema` only requires `NEW.expires_at > NEW.created_at`.
- *Valid-to-start widening.* It widens by at most the 60 s observation budget, since request start precedes admission by
  at most that.

**Existing tests updated, with reasons:**
- `PaidGrantPolicyRevisionTest` and `PaidGrantReviewAddendum1Test` asserted `expires_at - created_at = 60`. It is now
  `60 + AUTHORIZE_BUDGET_SECONDS`: the retained 60 s lifetime, still not the revised policy's value.
- `PaidGrantReviewAdversarialTest` travelled `lifetime + 2` s to reach expiry. It now travels
  `lifetime + AUTHORIZE_BUDGET_SECONDS + 2` s.

## A11-I1: the line budget ends with the database lease

The lease's `$at` is computed inside the claim frame, and the line budget started only after that frame's commit and
post-commit proofs. So the budget could outlive the lease, and a line finishing in that gap reached a record frame that
the lease check refused with 409.

The claim frame now records its own instant on both clocks: `CarbonImmutable::now()` and `hrtime(true)`. It returns
`lease_ends_ns = tick - micro × 1000 + LEASE_SECONDS × 1e9`, where `micro` is the sub-second part that the lease's
`startOfSecond()` dropped. The line budget is shortened to that value, so it never ends after the lease.

The attempt is spent either way, because an attempt counts at claim. The difference is that no render, hash or record
frame now runs past the lease. The test shows no asset hashed and a 410 at the line's budget instead of a 409 from the
record frame.

## A11-I5: progress outside the busy subtree, and a Stop control

- **Live region.** The progress line (`role="status" aria-live="polite"`) now sits in a wrapper above the
  `<section aria-label="Paid license journey" aria-busy={busy}>`, outside the busy subtree.
- **"Stop preparing".** It sits next to the progress line and appears only while a continuation runs, so it is enabled
  even though the other controls are busy. It ends the continuation the way a hidden tab does:
  - the run number is bumped and every pending continuation timer cleared (tracked in a set);
  - the generation is bumped and the in-flight request aborted;
  - `inflight` is cleared and the page is no longer busy.
- **Unlike a hidden tab**, the page stays shown. The progress line then reads "Preparation stopped; a request already
  sent may still finish. Choose prepare to continue."
- Not verified with a screen reader.

## A11-I6: monotonic spacing

`lastDocument`, the spacing delay and `waitingSince` now use `performance.now()`. A backward wall-clock step no longer
stalls the next POST (reviewer probe P3), and a forward step no longer rushes one.

## Reviewer probe file

The committed probe `tests/frontend/paid-grant-review-addendum11.test.tsx` asserted the pre-fix behaviour of P1 (live
region inside `aria-busy`) and P3 (stall after a backward step). Both now assert the fixed behaviour, with renamed titles
that say "fixed in round 20". Its fake timers now also fake `performance`, because the page measures with it. P2
(A11-I4) passes unchanged in substance.

## Tests

| File | Case |
| --- | --- |
| `PaidGrantHeavyWorkTest` (+2) | **slow claim then long line:** the claim frame spends 280 s before claiming, the render 290 s (570 s after the lock was taken). The lock is still held at the end of the render and at the line's record-frame commit: the same non-blocking acquisition a concurrent `prepare()` would try, which therefore gets `busy`. Released afterwards; the TTL is 660. |
| | **budget ends with the lease (A11-I1):** a 30 s claim-frame tail, then a 275 s render. Refused 410, no asset hashed, no original, attempts `[1]`, lock free. |
| `PaidGrantAuthorizationWindowTest` (new, 5) | **slow authorize:** commits 55 s after insert, then the customer waits 50 s; the redeem is admitted. |
| | **locate crosses expiry:** started 2 s before expiry, `locate()` ends 3 s after it; admitted. This is the reviewer's P4. |
| | **600 s policy (A11-L2 maximum):** lifetime 660 s, fresh redeem admitted. |
| | **after expiry:** refused 410, also exactly at expiry, with nothing recorded. |
| | **idempotent replay:** same row and expiry; the added constant is 60. |
| `PaidGrantPolicyRevisionTest`, `PaidGrantReviewAddendum1Test`, `PaidGrantReviewAdversarialTest` (changed) | See B. |
| `tests/frontend/paid-grant-continuation.test.tsx` (+4) | live region outside `aria-busy`, and Stop ends the continuation (no further POST, page kept, prepare enabled) |
| | Stop aborts an in-flight request and sends nothing more |
| | a backward wall-clock step does not stall the next POST |
| | the page keeps polling at 600 s while the lock may be held, then stops by 720 s with at most ⌈660/15⌉ reads |
| `paid-grant-finish-completion.test.tsx`, `paid-grant-review-addendum11.test.tsx` (changed) | Fake `performance`; probes P1 and P3 updated as above. |

Time is spent on both clocks (the virtual paid monotonic clock and Carbon travel) where the lock or lease reads the wall
clock. No test sleeps, and no SQLite skips were added.

## Results (`evidence/`)

PHPUnit is run from the worktree root with `public/build` absent:
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`.
Frontend runs use Node 24.21.0 with a temporary `node_modules` symlink, since removed.

| Run | Source | Result |
| --- | --- | --- |
| Server red, first test versions (A, B) | `a5354220` product + new and changed tests | 17 tests, 5 errors, 2 failures, rc 2 (`red-on-a5354220.txt`) |
| Server red, final tests | `9605e95c` product (scratch worktree, removed) + final tests | 26 tests, 6 errors, 3 failures, rc 2 (`server-red-final-tests.txt`) |
| Paid family `--filter PaidGrant tests/Feature` | `9605e95c` + working tree | 123 tests (116 + 7), 4,461 assertions, 1 skipped, rc 0 (`family-green.txt`) |
| A: TTL back to 360 | final tree + mutation | slow-claim test fails, rc 1 |
| B1: admitted instant back inside frame 1 | final tree + mutation | locate-crosses-expiry test errors (410), rc 2 |
| B2: no authorize budget on expiry | final tree + mutation | slow-authorize, 600 s, constant and both updated lifetime tests fail, rc 2 |
| A11-L2a: post-frame check uses `now()` | final tree + mutation | locate-crosses-expiry test errors (410), rc 2 |
| A11-L2b: post-frame maximum back to 600 | final tree + mutation | 600 s policy test errors (410), rc 2 |
| A11-I1: budget not bounded by the lease | final tree + mutation | lease test fails (409 instead of 410), rc 1 |
| Client red | `9605e95c` client (swapped in temporarily) + final frontend tests | 6 failed, 11 passed, rc 1 (`client-red-on-9605e95c.txt`) |
| Client CM-I6: wall-clock spacing | final tree + mutation (restored) | 2 failed, rc 1 |
| Client CM-I5: live region inside `aria-busy` | final tree + mutation (restored) | 2 failed, rc 1 |
| Client CM-I5: Stop leaves the request running | final tree + mutation (restored) | 1 failed, rc 1 |
| Client CM-L1: wait back to 360 s | final tree + mutation (restored) | 1 failed, rc 1 |
| All paid frontend files (11) | final working tree | 65 tests passed, rc 0 (`frontend-paid-all.txt`) |
| `tsc --noEmit` | final working tree | rc 0 (`tsc.txt`) |
| Pint `--test`, 7 PHP files | final working tree | passed (`pint.txt`) |
| Census self-test | final working tree | OK, rc 0 (`census-self-test.txt`) |

The red runs fail for the intended reasons, with three exceptions:

- the 600 s policy test errors first on the missing constant;
- the two updated lifetime tests error first on the missing constant;
- the idempotent-replay test fails on the old 60 s lifetime.

The behavioural reds for the 600 s policy test come from mutations L2b and B2. Mutations ran in a scratch worktree
(removed). Client mutations were restored and the file checked identical with `cmp`.

## Not tested

- Native MySQL and real timings, including the claim-frame tail (C2) that sets the 60 s lock margin and the lease gap.
- A screen reader (A11-I5).

## For Sean

- **Downloads after a slow authorize.** A download authorization now lasts its full lifetime from the moment the customer
  can actually have it. A download started in time is no longer refused because the server was slow.
- **Large-order preparation.** It no longer risks two heavy steps for one customer at once. The page:
  - waits up to 11 minutes for other work before asking for a click;
  - announces progress where screen readers will read it;
  - has a "Stop preparing" button.
