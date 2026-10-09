# Paid252 Codex round 18: per-line preparation budgets, plus review addendum 10 (A10-L1, A10-L2, A10-I2)

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. The work is
uncommitted on `harness/paid252-composition`:

- Part 1 was built on `afd9cd94`.
- Parts 2–4 were added after review addendum 10 was committed as `2c3f8cb8`, which changed only docs and a frontend probe.
- Results say "… + working tree".

What did not change:

- No schema, migration, guard, receipt, fence, raw proof, post-commit proof or authorization lifetime.
- `PaidGrantDeadline`, `PaidGrantFiles` and `PaidGrantRendererProcess` (pinned).
- No file in `resources/contracts/*/profile-assets.json` (`evidence/pinned-check.txt`).
- The 320 s document client timeout.

## Part 1: Codex P2 4223207353 (`PaidGrantDocuments.php:71`), per-line preparation budget

### Finding

`prepare()` passed one call-wide `PaidGrantDeadline::start(LEASE_SECONDS)` to every claimed line: its render checks, its
`PaidGrantAssets::verify()` and its record frame. A line's database lease is `now + 300 s` from its own claim, but it got
only what was left of the call's 300 s. With native renders of about 65–76 s per line, line 4 is claimed at about 228 s
and cut off at 300 s, with about 228 s of its lease left. That claim's attempt (1 of 5) is spent, and the same happens to
one line on every call.

### Fix (`prepare()` only)

- **Line budget:** right after a successful claim frame, `$budget = PaidGrantDeadline::start(self::LEASE_SECONDS)` starts
  a budget that matches that claim's lease.
- **What uses it:** the line's two `proveCurrent()` checks, `PaidGrantAssets::verify($assets, $budget->value())`, the
  record frame and the failure frame.
- **The call budget is kept** for the loop-top `proveCurrent()` (unchanged 410) and the claim frame. So a new line is
  claimed only while the call budget is current.
- **The record frame still enforces the lease itself:** it requires `CarbonImmutable::now() < expires_at`, and 409
  otherwise. The line budget starts just after the claim frame commits, so it ends no earlier than the lease (within the
  frame's tail and the lease's whole-second truncation). The database lease stays the authority.
- **Failure frame: moved to the line budget, deliberately.** On the call budget, a late line that failed for another
  reason (a render or asset error) after the call budget lapsed could not be marked `failed`. It stayed `claimed` and
  blocked a retry for up to the rest of its 300 s lease. On the line budget it is marked failed while its own budget is
  current. Marking it is still restricted to this still-owned claim (`claim_id` and `state = 'claimed'`), and once the
  line's budget has lapsed the claim still stays until its lease ends.
- **Unchanged:** the claim rules, the attempt accounting (an attempt is spent when a line is claimed), `MAX_ATTEMPTS`,
  and every check inside the frames.

As instructed, there is **no size-derived lease**. A single line whose render plus three 1 GiB assets exceeds 300 s
(storage below about 10–14 MiB/s) is still refused, consumes its attempt and fulfils nothing. That is a storage-speed
floor for Sean.

### Consequences

- **A request now runs longer.** The last line can be claimed just before 300 s and run its own 300 s, so the render loop
  alone can take about 600 s. With round 16's completion that is up to about 600 + 10 × 300 + 60 s; completion runs only
  if the loop ends while the call budget is current.
- **A late last line ends the request with 410.** When the last line finishes after the call budget, the loop-top check
  (unchanged) refuses with 410 even though every line is prepared. The next request claims nothing and completes. The
  three-line test shows this, and Part 3 makes that next request reachable from the page.
- **Client timeout (320 s) is now shorter than the server can take.** The page shows "could not be confirmed" while PHP
  carries on. Before this round's Part 3, the page then reached a dead end:
  - **One line still running:** reopening the order shows it `claimed`. The prepare button stays disabled until the
    status read reports `renderRetryAllowed` (after the lease). A click on the stale view is harmless: the server
    answers with the busy projection and claims nothing.
  - **Every line prepared but not fulfilled:** the page offered no control at all (A10-L2). Part 3 fixes this.

## Part 2: A10-L1, no re-verification of a fulfilled order

### Fix (`complete()`)

When the bundle read shows `$bundle['complete'] !== null`, the per-line physical re-verification loop is skipped. The
fulfillment frame still runs: it requires `$graph === $bundle`, inserts nothing, and returns the projection under its own
receipts and projection read.

Nothing is lost, because every download re-verifies its exact bytes:

- assets through `DeliveryAssetFiles::copyVerified` while copying;
- the contract through `PaidGrantFiles::verify` in `copyTarget`;
- the sealed spool copy through `readBack()`.

### Changed existing test (`PaidGrantDocumentJourneyTest`, "missing original is restore-only")

The test removed the original after fulfilment and expected `prepare()` to throw `original_unavailable`. That expectation
is exactly the re-verification A10-L1 removes. The test now asserts:

- `prepare()` returns the identical projection;
- no row changes, so there is still no replacement claim;
- authorizing and redeeming the `contract` is refused with `DeliveryException('target_unavailable')` and no redemption;
- after the original is restored, everything is as before (apart from the one authorization row).

The project-level effect is that `prepare()` on a fulfilled order no longer reports a missing original; only a download
does. The page already says "Exact files are checked again for each download".

### Remaining C12 work for Sean

Single-flight for two concurrent first completions is not built. Both requests still hash everything, and the loser gets
409 from `$graph === $bundle`, with `batch_once` as the database backstop (review probe P2). Bounding concurrent first
completions per batch or per account is still C12.

## Part 3: A10-L2, finishing an all-prepared order from the page

### Fix (`resources/js/components/PaidGrantJourney.tsx`)

- **New control:** when `!origin.fulfilled` and every line is `complete`, the page shows "Finish preparing this order".
  It calls the same `prepare()` request: `POST /paid-grants/origins/{id}/document`, which ends in `complete()`.
- **Gating:** only `busy`. Completion claims nothing and spends no attempt.
- **Unchanged:** "Prepare original licenses and files" for unfinished lines and its gates (attempts < 5, claimed-lease
  check).
- **Timeout:** on a timeout or lost answer the page shows "could not be confirmed" and keeps the control, so the order
  stays recoverable.

The reviewer's probe P3 in `tests/frontend/paid-grant-review-addendum10.test.tsx` still passes, because it only asserts
that the old "Prepare original licenses and files" button is absent. Its test title ("offers no prepare control") now
describes the pre-fix state.

### Remaining C13 owner item

The 320 s document timeout is unchanged by instruction. Making completion asynchronous with status polling, or realigning
the timeout with the server's worst case, remains C13.

## Part 4: A10-I2, the asset hash now runs on the paid clock in tests

`tests/Support/PaidGrantClockedAssetFiles.php` is a test-only subclass of `DeliveryAssetFiles`, bound in `setUp` of both
`PaidGrantCompletionBudgetTest` and `PaidGrantPreparationBudgetTest`.

- It checks each deadline paid code hands to `verify()` against `App\Domain\Grants\Paid\hrtime(true)`, the virtual paid
  clock.
- A deadline that has already lapsed is refused the way the real adapter refuses on a slow clock
  (`DeliveryException('asset_unavailable')`), and counted.
- A missing deadline, or one more than 300 s away, is recorded as a violation.
- Otherwise the real verification runs unchanged.
- Each test asserts no violations, the exact number of lapsed refusals, and a minimum number of hashes.

Because the asset hash now also enforces the line bound, the "one line exceeds its own bound" completion cases changed:
when the 301 s original check exhausts the line bound, the line's asset hash refuses first (`DeliveryException`, as on a
real slow disk) rather than the later `proveCurrent()` (410). Nothing is fulfilled in either case.

## Tests

| File | Case | What it shows |
| --- | --- | --- |
| `PaidGrantPreparationBudgetTest` (new) | three lines, 120 s render each | First call: 410 at the loop top after line 3, states `complete ×3`, attempts exactly `[1, 1, 1]`, 3 originals, 0 fulfillments. Retry renders nothing, fulfils, attempts still `[1, 1, 1]`. Asset hashes all within their line's bound. |
| | late line 3 fails (render error at about 360 s) | Line 3 is marked `failed` under its own budget, attempts `[1, 1, 1]`. Retry fulfils with `[1, 1, 2]`. |
| | single line, 301 s render | 410, attempt spent (`[1]`), stays `claimed`, 0 originals and 0 fulfillments. A refresh inside the lease returns the busy projection and claims nothing; no asset was hashed. |
| `PaidGrantCompletionBudgetTest` (changed) | existing three cases | Now also asserts every completion asset hash under the line bound. The two overrun cases expect `DeliveryException` (see Part 4). The third case no longer claims a fulfilled order is re-verified. |
| | new: fulfilled order | `prepare()` calls `PaidGrantFiles::verify` 0 times and hashes no asset, returns the identical projection, and leaves the preparation, download and fulfillment rows unchanged. |
| `PaidGrantDocumentJourneyTest` (changed) | missing original on a fulfilled order | See Part 2. |
| `tests/frontend/paid-grant-finish-completion.test.tsx` (new) | three vitest cases | The control appears and POSTs `{}` to the document route, then the fulfilled order shows authorize buttons. It is kept after a lost answer. It is absent for a fulfilled order and for an order with an unprepared line, where the old prepare button shows instead. |

All tests use the paid-namespace clock seam; none sleeps. No SQLite skips were added.

## Results (`evidence/`)

PHP commands run from the worktree root with `public/build` absent. PHPUnit is
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`.
Frontend runs use Node 24.21.0 (`. /opt/nvm/nvm.sh && nvm use 24`), with a temporary `node_modules` symlink to
`/home/user/VA-Studio/node_modules` that was removed afterwards.

| Run | Source | Command | Result |
| --- | --- | --- | --- |
| Part 1 red | `afd9cd94`, product unmodified, + new test (before the Part 4 double) | `--filter PaidGrantPreparationBudgetTest tests/Feature` | 3 tests, 55 assertions, 2 failures (line 3 `claimed`, not `complete`/`failed`), rc 1 (`red-new-test-on-afd9cd94.txt`) |
| Part 1 green | `afd9cd94` + Part 1 | same | 3 tests, 68 assertions, rc 0 (`green-new-test.txt`) |
| Part 1 family | `afd9cd94` + Part 1 | `--filter PaidGrant tests/Feature` | 108 tests, 4,259 assertions, 1 skipped, rc 0 (`family-green-prepare-budget.txt`) |
| Part 1 M1: render checks and asset hash back on the call budget | Part 1 + mutation (scratch worktree, removed) | new test | three-line case fails (line 3 `failed`), rc 1 (`mutation-M1-render-checks-on-call-budget.txt`) |
| Part 1 M2: failure frame back on the call budget | Part 1 + mutation | new test | late-failure case fails (line 3 stays `claimed`), rc 1 (`mutation-M2-failure-frame-on-call-budget.txt`) |
| Part 2 red, with Part 4 green | `2c3f8cb8` + Parts 1 and 4 + new A10-L1 test, without the A10-L1 change | `--filter 'PaidGrantCompletionBudgetTest\|PaidGrantPreparationBudgetTest' tests/Feature` | 7 tests, 1 failure (the fulfilled order re-verified 2 originals), rc 1 (`a10-l1-red-and-a10-i2-green-before-l1-fix.txt`) |
| Parts 2 and 4 green | `2c3f8cb8` + final working tree | `--filter 'PaidGrantCompletionBudgetTest\|PaidGrantPreparationBudgetTest\|PaidGrantDocumentJourneyTest' tests/Feature` | 11 tests, 276 assertions, rc 0 (`a10-green-focused.txt`) |
| Part 2 mutation: fulfilled order re-verified again | final tree + mutation (scratch worktree, removed) | same three classes | the new L1 test and the changed journey test fail, rc 2 (`mutation-L1-fulfilled-order-re-verified.txt`) |
| Part 4 mutation I2-M1: completion's per-line asset hash on `prepare()`'s deadline | final tree + mutation | same | two completion cases fail with `DeliveryException` from the paid-clock double, rc 2 (`mutation-I2-M1-completion-asset-hash-on-prepare-deadline.txt`) |
| Part 4 mutation I2-M2: render-loop asset hash on the call budget (line checks unchanged) | final tree + mutation | same | three-line case fails (`DeliveryException` instead of 410), rc 1 (`mutation-I2-M2-render-asset-hash-on-call-budget.txt`) |
| Final family | `2c3f8cb8` + final working tree | `--filter PaidGrant tests/Feature` | 109 tests, 4,272 assertions, 1 skipped, rc 0 (`family-green-final.txt`) |
| Part 3 red | `2c3f8cb8` + new vitest file, client unmodified | `npx vitest run tests/frontend/paid-grant-finish-completion.test.tsx` | 2 failed, 1 passed, rc 1 (`a10-l2-frontend-red.txt`) |
| Part 3 green | final working tree | same | 3 passed, rc 0 (`a10-l2-frontend-green.txt`) |
| Paid frontend files (8 existing + 1 new) | final working tree | `npx vitest run tests/frontend/paid-grant-*.test.tsx` | 9 files, 51 tests passed, rc 0 (`frontend-paid-all.txt`) |
| `tsc` | final working tree | `npx tsc --noEmit` | rc 0 (`tsc.txt`) |
| Pint `--test`, 5 PHP files | final working tree | `/home/user/VA-Studio/vendor/bin/pint --test ...` | passed, rc 0 (`pint.txt`) |
| Census self-test | final working tree | `python3 -I scripts/ci/test-database-receipts.py` | OK, rc 0 (`census-self-test.txt`) |

Notes on these runs:

- The Part 1 red, green and mutation runs used the first version of the new test, before the Part 4 double was added.
  The final versions of both test classes are covered by the focused green run and the Part 2 and Part 4 mutations.
- The Part 2 red run used an earlier form of the new test's row comparison (`stdClass` rows). It failed at the
  verification count, before that comparison ran. The final form goes red on the same mutation
  (`mutation-L1-fulfilled-order-re-verified.txt`).

## Not tested

- Native MySQL. Timing is simulated, and no changed path is MySQL-only.
- Real renders of about 70 s and real slow storage.
- A browser run of the new control. Vitest covers the component.
- PHP's behaviour on client disconnect during a long document request (A10 §5, C11 d/e). That is a deployment item.

## For Sean

- **Preparation attempts:** a line claimed late in a request no longer loses its attempt. Each line gets the full five
  minutes its lease promises. A line that needs more than five minutes on its own is still refused; that is the storage
  speed floor (about 10–14 MiB/s for three 1 GiB files).
- **Finishing an order:** the page can now finish an order whose files are all prepared, using "Finish preparing this
  order", even if the earlier request timed out.
- **Fulfilled orders:** preparing an already fulfilled order no longer re-reads every file.
- **Still open:** C12 (limit concurrent first completions) and C13 (asynchronous completion or a longer page wait).
