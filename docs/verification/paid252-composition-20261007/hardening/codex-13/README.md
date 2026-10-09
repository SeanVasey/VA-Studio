# Paid252 Codex round 13: snapshot preparation gets its own deadline

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. The change is
uncommitted on `harness/paid252-composition` at `9004245f` (results below say "9004245f + working tree"). No schema,
migration, guard, receipt, fence, raw proof, post-commit proof or authorization lifetime changed, `PaidGrantDeadline`
is unchanged, and no file pinned in `resources/contracts/*/profile-assets.json` was touched (`evidence/pinned-check.txt`).

## Finding (Codex P2, comment 4222562053, `PaidGrantDownloads.php:144`, reviewed commit `2c1b4f8a`)

`redeem()` started one 60 s `PaidGrantDeadline` and used it for locating, frame 1, the private snapshot (copy plus hash
read-back of up to 1 GiB), frame 2 (which inserts `paid_redemptions`) and the before-first-byte proof. Recorded native
MySQL timings put the redeem path at 38–43 s before delivery, at about 18.7k statements per frame (independent review
M2/C2). The snapshot therefore only got the leftover seconds, so slow storage or a production-sized database could
refuse valid authorizations again and again. Frame 2 could also commit `paid_redemptions` and then fail the post-commit
`$deadline->proveCurrent()` in `PaidGrantCommands::run`, which consumes an attempt and delivers nothing.

## Fix

The fix follows the Free256 design (`ProductionFreeGrantDownloads::redeem`, `$snapshotDeadline`) and the round 1 pattern
for the transfer keys.

- **`paid-grants.snapshot_seconds`** (`config/paid-grants.php`, default 300). It is top-level configuration, like the
  transfer keys. It is not part of the retained `delivery_policy` or the provenance, so no retained-policy hash changes.
- **Validation:** `PaidGrantPolicy::capture()` now also calls the new `snapshotSeconds()`. Both return 503 unless the
  value is an integer from 30 to 1800 (Free256's bounds). The accessor sits next to `transferSeconds()`.
- **Snapshot budget:** after frame 1 closes, `redeem()` computes `hrtime(true) + snapshotSeconds() * 1e9` and passes that
  to `PaidGrantPrepareStream::handle()` instead of `$deadline->value()`.
- **Commit-frame budget:** a fresh `PaidGrantDeadline::start()` (60 s) is created just before frame 2 and passed to its
  `$commands->run(...)`. `run()` hands the same object to `PaidGrantConsumerCommitAdmission`, the producer committed-read
  receipts and `PaidGrantProjectionRead::capture()`, so it also bounds `proveBeforeBytes()`.
- **Unchanged:** frame 2 still re-runs `$inspect` (same token, same authorization row, the same `$admitted` instant
  against `expires_at`, no existing redemption), requires `$current === $before`, enforces the `max_downloads` attempt
  count and inserts under the same locks, receipts and fence. The post-commit proofs in `PaidGrantCommands::run`, the
  `$this->deadline($before['auth'])` check after frame 1 and the transfer deadline are unchanged. Each budget can only
  get shorter; none can be extended.
- **Comments** in `redeem()` now describe the three budgets. CHANGELOG has one more sentence in the Paid252 entry, added
  by a script that asserts the anchor matched exactly once.

### `PaidGrantPrepareStream` check (as asked)

`$originalDeadline` (renamed `$snapshotDeadline`) is used only on line 43 to compute the stream's local `$deadline`.
That value goes only to `copyTarget()` (and on to `DeliveryAssetFiles::copyVerified`), `within()` and `readBack()`.
Slot admission does not wait on it: the `flock`s are either non-blocking or the short global admission lock. Nothing
treats it as the observation deadline.

One thing did conflict with the requested design. The stream also capped every snapshot at `MAX_SECONDS = 60` from the
start of `handle()`, which would make `snapshot_seconds` above 60 have no effect, the 300 s default included. I kept
`MAX_SECONDS` (60 s) for callers that pass no deadline (tests and non-account callers). A caller's deadline is now honoured
up to the new ceiling `MAX_DEADLINE_SECONDS = 1800`, the largest accepted `snapshot_seconds`. Mutation M1 below shows
the old cap breaks scenario (a). **This is a behaviour change in the stream and needs review.**

## Budgets before and after

| Phase | Before (`9004245f`) | After |
| --- | --- | --- |
| Locate + frame 1 | One 60 s observation budget from `redeem()` entry | Same 60 s budget (unchanged) |
| Snapshot (copy + read-back) | `min(leftover of that budget, 60 s from handle())` | `snapshot_seconds` from frame-1 close (default 300 s, 30–1800), ceiling 1800 s in the stream |
| Frame 2 (commit) + post-commit proof | Leftover of the same budget | Fresh 60 s budget started just before frame 2 |
| Before-first-byte proof | Leftover of the same budget | The same fresh frame-2 budget |
| Transfer stream | `min(max, base + size/rate)` from commit | Unchanged |
| Authorization lifetime | Valid-to-start (admitted instant) | Unchanged |
| Worst case, entry to first byte | 60 s | 60 + 300 + 60 = 420 s by default (60 + 1800 + 60 at the maximum) |

## Test seam

`tests/Support/PaidGrantMonotonicClock.php` defines `App\Domain\Grants\Paid\hrtime()`, which returns the global
`\hrtime(true)` plus a test offset. PHP resolves the unqualified `hrtime(true)` calls in paid code to that namespaced
function, so a test can spend observation, snapshot and commit time without sleeping. Product code is unchanged by
this. I chose it because the alternatives were worse:

- A clock parameter on `PaidGrantDeadline` is forbidden by the brief.
- A clock seam only on `PaidGrantPrepareStream` could not show that frame 2 gets a fresh budget.
- The existing reflection approach (as in `PaidGrantReviewAddendum1Test`) cannot reach `redeem()`'s local deadline
  before the snapshot.

Limits:

- Only the paid namespace moves. Other namespaces (Delivery, ProductionCheckout) keep the real clock, so their checks
  against a paid deadline only become more lenient and never refuse falsely. The paid-namespace checks are the ones under
  test.
- PHP caches a call site's resolution the first time it runs. The test file therefore loads the support file at file
  scope (`class_exists(...)`), which happens while PHPUnit builds the suite, before any paid code runs. Every PHPUnit run
  that loads `tests/Feature` defines the function. At offset 0 it returns exactly the global value. `setUp` and
  `tearDown` reset the offset.
- `proveClockSeam()` asserts the seam is live: a 1000 s offset must move `PaidGrantDeadline::start(1)`. The red run and
  mutations M2/M3 also show the seam reaches `PaidGrantDeadline`, `PaidGrantConsumerCommitAdmission` and the stream.

Time is spent at two points:

- When an owned paid frame commits. A `TransactionCommitted` listener counts only outermost commits whose backtrace
  includes `PaidGrantCommands::run` and not `ProductionCustomerAccess`, so 1 = frame 1 and 2 = frame 2. `PaidGrantCommitFrameProbe`
  already listens to transaction events in tests.
- During the snapshot copy, through the existing container-double pattern (`copyTarget` override).

No SQLite skips were added.

## Tests (`tests/Feature/PaidGrantSnapshotDeadlineTest.php`)

| Case | Scenario | Expected |
| --- | --- | --- |
| (a) `test_a_snapshot_started_late_in_the_observation_budget_gets_its_own_bound` | Frame 1 leaves 5 s of the 60 s budget; the snapshot takes 120 s | Transfer delivered, exactly 1 redemption |
| (b) `test_a_snapshot_exceeding_snapshot_seconds_is_refused_...` | Bound 30 s with a 31 s snapshot; bound 300 s with a 301 s snapshot; then bound 30 s with a 29 s snapshot | Each overrun is refused (`DeliveryException target_unavailable`) with no new `paid_redemptions` row, and the same authorization then redeems; 29 s is admitted |
| (c) `test_the_commit_frame_and_first_byte_get_a_fresh_budget_...` | Frame 1 40 s + snapshot 40 s (80 s), frame 2 50 s, first byte 5 s later | Exactly 1 redemption, bytes delivered, a second redeem gets 409 |
| (c) `test_the_fresh_commit_budget_is_still_bounded_before_the_first_byte` | As (c), then 61 s before the first byte (transfer allowance 600 s + size) | 410, no byte, 1 redemption |
| (c) `test_a_commit_frame_overrunning_its_own_budget_still_fails_the_unchanged_post_commit_proof` | Frame 2 itself takes 61 s | 410 after commit, 1 redemption (unchanged behaviour, documented) |
| (d) `test_out_of_range_snapshot_seconds_refuses_with_503_before_anything_is_recorded` | Shipped 300; 30/300/1800 accepted and `capture()` is still the unchanged `delivery_policy`; 29, 1801, `'300'`, null | `capture()`, `snapshotSeconds()`, authorize and redeem each 503, with 1 authorization and 0 redemptions; the restored value redeems |
| (e) paid family | `--filter PaidGrant tests/Feature` | All pass |

## Results (`evidence/`)

Commands run from the worktree root with `public/build` absent. PHPUnit is
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`.

| Run | Source | Command | Result |
| --- | --- | --- | --- |
| Red: new test, product unmodified | `9004245f` + new test files only | `--filter PaidGrantSnapshotDeadlineTest tests/Feature` | 6 tests, 80 assertions, 4 errors, 1 failure, rc 2 (`red-new-test-on-9004245f.txt`) |
| Green: new test | `9004245f` + working tree | same | 6 tests, 144 assertions, rc 0 (`green-new-test.txt`) |
| Baseline: paid family | `9004245f` (scratch worktree, since removed) | `--filter PaidGrant tests/Feature` | 96 tests, 3,747 assertions, 1 skipped, rc 0 (`family-baseline-9004245f.txt`) |
| Green: paid family | `9004245f` + working tree | same | 102 tests (96 + 6), 4,080 assertions, 1 skipped, rc 0 (`family-green.txt`) |
| Final focused (after a comment-only docblock fix) | `9004245f` + final working tree | `--filter 'PaidGrantSnapshotDeadlineTest\|PaidGrantTransferDeadlineTest\|PaidGrantReviewAddendum1Test' tests/Feature` | 18 tests, 403 assertions, rc 0 (`green-final-focused.txt`) |
| Mutation M1: stream ceiling back to 60 s | working tree + M1 | new test | (a) errors; 1 error, rc 2 (`mutation-M1-stream-60s-cap.txt`) |
| Mutation M2: frame 2 on the original budget | working tree + M2 | new test | (a) and both fresh-budget (c) cases get 410; 3 errors, rc 2 (`mutation-M2-commit-frame-old-budget.txt`) |
| Mutation M3: snapshot on the original budget | working tree + M3 | new test | (a), (b) and the (c) cases fail; 3 errors, 1 failure, rc 2 (`mutation-M3-snapshot-old-budget.txt`) |
| Pint `--test`, 6 changed or new PHP files | final working tree | `/home/user/VA-Studio/vendor/bin/pint --test ...` | passed, rc 0 (`pint.txt`) |
| Census self-test | working tree | `python3 -I scripts/ci/test-database-receipts.py` | 34 tests OK, rc 0 (`census-self-test.txt`) |

The red run fails for the intended reasons:

- (a), (c) and the bounded-first-byte case: the snapshot is refused by the leftover observation budget.
- (b): the 30 s bound is not honoured.
- (d): `snapshot_seconds` does not exist.

The "frame 2 overruns its own budget" case passes before and after. It guards that the post-commit proof is unchanged.
The family green run came before a comment-only correction to the `PaidGrantPrepareStream` `@param` text. The final
focused run and Pint cover the final tree.

## Not tested

- Native MySQL. No changed path is MySQL-only, and the timing is simulated with the paid-namespace clock. The 18.7k
  statements per frame (review M2/C2) are unchanged. Each frame now has its own 60 s, but a frame-2 commit over 60 s on
  real MySQL would still consume the attempt and deliver nothing (shown by the overrun case). That was not measured.
- Real slow storage, or a 1 GiB snapshot at the 300 s bound.
- Browser/journey timing. The redeem request is a native form POST with no client abort (round 1), so the longer
  server-side path does not interact with the client timeouts.

## For Sean

A redemption admitted inside the authorization lifetime can now take up to about 7 minutes (60 + 300 + 60 s by default)
before the attempt is recorded and the first byte leaves. Before, it had 60 s, which slow storage could not meet. While
the snapshot runs, it holds one of the three paid spool slots for up to `snapshot_seconds` (each buyer can hold only one).
The stream's former fixed 60 s snapshot cap is raised to 1800 s for callers that pass a deadline. That is a behaviour
change and needs reviewer confirmation. The defaults copy Free256.
