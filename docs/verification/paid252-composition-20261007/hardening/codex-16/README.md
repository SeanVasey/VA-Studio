# Paid252 Codex round 16: order completion gets per-line verification bounds

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. The change is
uncommitted on `harness/paid252-composition` at `093f9081` (results below say "093f9081 + working tree").

What did not change:

- No schema, migration, guard, receipt, fence, raw proof, post-commit proof or authorization lifetime.
- `PaidGrantDeadline` and `PaidGrantFiles` (pinned).
- No other file pinned in `resources/contracts/*/profile-assets.json` (`evidence/pinned-check.txt`).

## Finding (Codex P2, comment 4222959609, `PaidGrantDocuments.php:120`)

`PaidGrantDocuments::complete()` re-verifies every line in turn: `PaidGrantFiles::verify` for the original and
`PaidGrantAssets::verify` for the assets. All of it ran under the single 300 s `PaidGrantDeadline::start(LEASE_SECONDS)`
that `prepare()` creates at its top, and the final frame that inserts `paid_fulfillments` used the same budget.

An order may have 10 lines with up to three 1 GiB assets each. Below about 100 MiB/s the loop always expired. Every
retry started a new 300 s budget and began again at line 1, so a fully prepared large order could never become
fulfilled. `prepare()`'s render loop was already resumable (finished lines are skipped); completion was not.

## Fix (`app/Domain/Grants/Paid/PaidGrantDocuments.php`, `complete()` only)

- **Per-line bound:** each line's verification of its original and assets gets its own
  `PaidGrantDeadline::start(self::LEASE_SECONDS)` (300 s, the per-line render lease). The bound is passed to
  `PaidGrantAssets::verify()` and proved after the line.
- **Fulfillment frame:** it gets a fresh `PaidGrantDeadline::start()` (60 s). That budget is passed to
  `$commands->run(...)` and so to the consumer commit admission, the producer receipts and `$projectionRead`, and it is
  proved at the start of the operation as before.
- **Bundle read:** the first frame keeps `prepare()`'s budget.
- **Unchanged:**
  - The final frame still requires `$graph === $bundle`.
  - The fulfillment manifest and audit are the same.
  - `PaidGrantCommands::run` keeps every receipt, fence and post-commit proof.
  - A missing original is still restore-only (`ContractIssuanceException original_unavailable`).
  - A refused line records nothing.
- **Retry:** after a refusal, the render loop finds every line `complete` and claims nothing. Completion then starts
  with fresh bounds.
- **Docs:** comments updated. CHANGELOG has one more sentence in the Paid252 entry; the script asserted the anchor
  (the end of the round 13 sentence) matched exactly once.

### Guarantee check (as asked)

I looked for anything that relies on the whole physical observation fitting within 300 s:

- The `paid_fulfillments` insert guard (`PaidGrantSchema`) checks only line count, work state and the original claim,
  with no time.
- No paid code reads the manifest's `observed_at` or `physical_observation`.
- A fulfilled order still never claims the files will stay available. Every download re-verifies the exact bytes and
  hash in the private snapshot (`PaidGrantPrepareStream`).

What does change: the observation of line 1 can now be up to 10 × 300 s + 60 s before the fulfillment commit instead of
at most 300 s. The bytes are write-once originals and hash-checked assets, and the old 300 s window was not locked
either. I found nothing that weakens a guarantee, but `physical_observation: exact_private_bytes_all_original_lines` now
covers a longer window. Reviewers should know that.

## Budgets before and after

| Phase | Before (`093f9081`) | After |
| --- | --- | --- |
| Claims, renders and per-line commit frames in `prepare()` | One 300 s budget from `prepare()` entry | Unchanged |
| Bundle read frame in `complete()` | Same budget | Same budget (unchanged) |
| Re-verification of line n (original + assets) | Leftover of the same budget | Fresh 300 s for that line |
| `paid_fulfillments` frame + body receipt | Leftover of the same budget | Fresh 60 s |
| Worst case for completion, 10 lines | 300 s, restarting from line 1 on every retry | 10 × 300 s + 60 s |

## Tests (`tests/Feature/PaidGrantCompletionBudgetTest.php`)

- **Seam:** the round 13 paid-namespace clock (`Tests\Support\PaidGrantMonotonicClock`); no test sleeps.
- **How time is spent:** a container double of `PaidGrantFiles` advances that clock in `verify()`. `PaidGrantFiles` is
  final and pinned, so the double is not a subclass: it is a small wrapper that calls the real `verify()` and forwards
  every other call (the render path's `store()`) to a real `PaidGrantFiles` through `__call`.
- **Why only completion is timed:** during `prepare()`, only completion calls `PaidGrantFiles::verify`, once per line.
  So the render loop runs at real speed and completion is timed per line.
- **Order:** a two-line order, built the same way as `PaidGrantDocumentJourneyTest::retained(true)`.

| Case | Scenario | Expected |
| --- | --- | --- |
| `test_a_two_line_order_whose_verification_outlasts_one_lease_is_fulfilled_and_still_downloads` | 200 s per line (400 s total) | Fulfilled on the first call, 2 verifications, attempts `[1, 1]`, 1 fulfillment; then each line authorizes and redeems `master_wav` (size and sha256 match), 2 redemptions, 0 license grants |
| `test_a_line_exceeding_its_own_bound_is_refused_with_nothing_fulfilled_and_a_retry_resumes` | 301 s on line 1 | 410 after 1 verification, both work rows `complete`, 2 originals, 0 fulfillments; the retry renders nothing and fulfils |
| `test_a_retry_after_a_slow_completion_fulfils_without_restarting_from_an_expired_budget` | Call 1: 290 s then 301 s; call 2: 290 s per line (580 s total); call 3 on the fulfilled order | Call 1 gives 410 with nothing fulfilled; call 2 fulfils; call 3 returns the same projection with still 1 fulfillment |

A `proveClockSeam()` check asserts the virtual clock reaches `PaidGrantDeadline`. No SQLite skips were added.

## Results (`evidence/`)

Commands run from the worktree root with `public/build` absent. PHPUnit is
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`.

| Run | Source | Command | Result |
| --- | --- | --- | --- |
| Red: new test, product unmodified | `093f9081` + new test file only | `--filter PaidGrantCompletionBudgetTest tests/Feature` | 3 tests, 52 assertions, 2 errors, rc 2 (`red-new-test-on-093f9081.txt`) |
| Green: new test | `093f9081` + working tree | same | 3 tests, 69 assertions, rc 0 (`green-new-test.txt`) |
| Green: paid family | `093f9081` + working tree | `--filter PaidGrant tests/Feature` | 105 tests (102 + 3), 4,158 assertions, 1 skipped, rc 0 (`family-green.txt`) |
| Mutation M1: final frame back on `prepare()`'s deadline | working tree + M1 (scratch worktree, since removed) | new test | Both long-completion cases refused at the final frame (`PaidGrantDocuments.php:132`), 2 errors, rc 2 (`mutation-M1-final-frame-old-deadline.txt`) |
| Mutation M2: per-line bound removed | working tree + M2 (scratch worktree, since removed) | new test | Both refused at the line proof (`:125`), 2 errors, rc 2 (`mutation-M2-per-line-bound-removed.txt`) |
| Pint `--test`, 2 PHP files | working tree | `/home/user/VA-Studio/vendor/bin/pint --test ...` | passed, rc 0 (`pint.txt`) |
| Census self-test | working tree | `python3 -I scripts/ci/test-database-receipts.py` | 34 tests OK, rc 0 (`census-self-test.txt`) |

The red run fails for the intended reason. On the old code, completion of the two-line order is refused by
`proveCurrent` at `PaidGrantDocuments.php:121`, both on the first call and on the retry, so the order can never be
fulfilled. The "one line exceeds its own bound" case passes before and after; it guards that the per-line bound is real.
No family baseline was run at `093f9081` in this round. The round 13 family was 102 tests, and the only code since then
is the client-only round 15.

## Not tested

- Native MySQL. The timing is simulated, and no changed path is MySQL-only.
- Real slow storage and real 1 GiB assets. The asset verification itself (`DeliveryAssetFiles`, real clock) was given
  only the per-line deadline; the time was spent in the artifact double.
- A single line whose real verification takes more than 300 s (three 1 GiB assets below about 10 MiB/s) is still
  refused, by design, and would need a larger per-line bound.

## For Sean

A large paid order can now finish preparation: each line's re-check gets its own five minutes, and a retry no longer
starts from an expired budget. Completing a 10-line order can take up to about 50 minutes in the worst case.
