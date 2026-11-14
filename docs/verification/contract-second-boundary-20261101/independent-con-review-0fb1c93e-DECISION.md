# Independent review: lane CON (contract issuance second boundary) at `0fb1c93e`

- **Reviewed:** `harness/contract-second-boundary-20261101`, HEAD `0fb1c93e1d3b3ea078fd10e31a7d2698a7d48de9`, worktree `/home/user/wt/con`.
  - Main `3e8a1fd7` is an ancestor.
  - PR diff: `git diff 3e8a1fd7...0fb1c93e`. It touches 8 files, 7 apart from the packet: `ContractWork.php`, `RenderTestContract.php`, the new test, the native include list, two count comments and the CHANGELOG.
- **Root cause:** `/home/user/notes/pcc-ev/contract-second-boundary.md`. **Packet:** `docs/verification/contract-second-boundary-20261101/README.md`.
- **Reviewer:** independent Claude reviewer; I am not the author. Date: 2026-11-01.
- **Rules kept:**
  - No commit, push or dispatch, and the worktree under review was never written.
  - Scratch worktrees lived under `/home/user/notes/con-review/` with vendor hard-linked; all 160 vendor package versions equal `composer.lock`.
  - Native runs used my own `/opt/mysql-8.4.11/bin/mysqld` (pid 4234, port 3477 checked free, socket `/tmp/conr-my/s`).
  - Progress log: `/home/user/notes/con-review/PROGRESS.md`.

## Decision

**APPROVE.**
- No P0, P1 or P2.
- The fix stores exactly the decision second in all five `contract_render_work` transitions. It leaves no other column stale, and it leaves every evidence check and trigger as strict as before.
- None of the three flagged areas has a real second-boundary defect.
- Three P3 notes follow.

## Assessment

### 1. Evidence semantics

- **Root cause confirmed.** `HasTimestamps::updateTimestamps()` stamps `updated_at = freshTimestamp()` whenever `updated_at` is not dirty. An explicit `forceFill(['updated_at' => $at])` equal to the stored second is not dirty, so a save that runs after a second boundary stored `T+1`.
- **The fix.** `ContractRenderWork::withoutTimestamps(fn () => … ->save())` makes every save store exactly `$at`: the explicit value when it differs, and the unchanged stored value when it is equal. It covers:
  - claim to processing, `ContractWork.php:37`;
  - the exhausted-retries quarantine, `:30`;
  - fail to retry or to quarantine, `:56`;
  - completion, `RenderTestContract.php:63`.
- **Storing `$at` is right in all of them.**
  - Each `$at` is `now()->utc()->startOfSecond()`, taken inside the row lock.
  - Monotonicity is guarded in code: `claim()` returns early when `$at < updated_at` (`:25`), and `owns()` requires `! $at->lessThan(updated_at)` (`:68`, used by `fail()` and completion). The trigger repeats this as `NEW.updated_at >= OLD.updated_at`.
  - Completion sets `issued_at = $at` and `updated_at = $at` in the same lock.
- **Nothing else goes stale.**
  - `withoutTimestamps` suppresses only the framework's `created_at`/`updated_at` stamping, and it is scoped to the `ContractRenderWork` class for the duration of one save; Laravel restores it in a `finally`.
  - On an update, `created_at` is never touched anyway, and the trigger requires it unchanged (`unchanged(['id', 'contract_render_request_id', 'created_at'])`).
  - The insert in `RequestTestContract.php:42` already sets `created_at = updated_at = $at` explicitly and is unchanged.
  - The `GrantContract` and `AuditEvent` writes happen outside the closures, so they keep normal timestamps.
- **The evidence checks are unchanged and as strict.**
  - `ReadGrantContract.php:39` (`issued_at` equals `work.updated_at`).
  - `ActivationEvidence.php:56` (`work.updated_at` equals `document.issued_at`).
  - `ContractEvidence.php:60` (ordering).
  - The diff does not touch any of them.

### 2. Triggers

- `database/migrations/2026_09_26_000021_test_contract_issuance.php:82-108` is unchanged.
- **It still refuses drift.** On the reverted code (my mutant), the second-boundary cases hit those trigger rules:
  - a claim that crosses the boundary breaks `lease_expires_at = updated_at + 300` and ends as `changed`;
  - a transient failure that crosses breaks `next_attempt_at >= updated_at + 60`, so the save is refused and the work stays `processing`.

  With the fix both cases pass, so the triggers still enforce what they did before.
- **State machine and the other rules hold.** A fresh claim from pending, retry or expired, active-lease retry, quarantine, exhausted and completed all hold. Completed still needs `issued_at` between `OLD.updated_at` and `NEW.updated_at` and before the lease.
- **Native runs at `0fb1c93e`:**
  - `TestContractSecondBoundaryTest`: OK, 5 tests, 39 assertions.
  - `TestContractIssuanceConcurrencyTest`: OK, 4 tests, 68 assertions.

### 3. Breadth (the author's flagged pattern)

None of these is a real second-boundary defect:

- **`RefundDisputeLedger.php:59,125` and `RefundDisputeActions.php:167`.**
  - `RefundDisputeWork` has `$timestamps = false` (`Models/RefundDisputeWork.php:11`), so Eloquent never rewrites `updated_at`.
  - The `refund_dispute_work_update` trigger has no `updated_at` relation.
  - Result: no defect.
- **`CustomerDataRights.php:365`, `resolve()`.**
  - The trigger requires `NEW.updated_at = NEW.resolved_at` (`2026_10_24_268200_customer_data_requests.php:112`), which would be exposed.
  - But `CustomerDataRequest` has `$timestamps = false` (`Models/CustomerDataRequest.php:12`), so the explicit `$at` is stored as written.
  - The counter update (`:377`) also relies on that.
  - Result: no defect.
- **`CustomerDataRights.php:210`, the account withdrawal.**
  - `CustomerAccount` uses timestamps, so a same-second save can store `T+1`.
  - The `customer_accounts` update trigger only requires `NEW.updated_at >= OLD.updated_at` (`2026_10_06_000040_customer_accounts.php:47`).
  - I found no equality check on `customer_accounts.updated_at` against another stamp.
  - Result: harmless drift of up to one second (P3-3, informational).
- **`InquiryNotificationWork.php:88,288`.**
  - `InquiryNotificationIntent` has `$timestamps = false`.
  - The trigger relations are strict inequalities with 30 to 300 s margins, computed from `now()` calls microseconds apart.
  - Result: no defect.

### 4. Mutation (fix reverted to main's sources)

- SQLite: `TestContractSecondBoundaryTest` gives 4 of 5 failures:
  - completion: `changed` instead of `ready`;
  - claim: `changed`;
  - transient failure: `processing` instead of `retry`;
  - permanent failure: `updated_at` drifted to `T+1`.
- The same-second no-crossing case passes, as designed.
- Native: the same 4 of 5 fail.

### 5. Gates

- **Selection.** `phpunit-shards.py --shards=8 … --mysql-native-selection` printed "Proved 2678 … in 265 files (of 9950 …)". It matches `.github/workflows/final-verification.yml:151` and `.gitlab-ci.yml:113`. Generated files were deleted.
  - The new test is in `include_files`.
  - The SQLite skip census is unchanged (310 = 310). The new test runs on both engines, so no census entry is needed.
- **Safeguards:** 52, 27, 14, 53 and 61 tests, all OK.
- **Composer:** `composer audit --locked`: no advisories.
- **Hygiene:** `git diff --check`: clean.

## Findings

| ID | Sev | Where | Note |
| --- | --- | --- | --- |
| P3-1 | P3 | `tests/Feature/TestContractSecondBoundaryTest.php` | The exhausted-retries quarantine save (`ContractWork.php:30`) has no boundary-crossing case. It is the same pattern and no trigger relation depends on its `updated_at` beyond monotonicity, so the risk is low; a sixth case would pin it |
| P3-2 | P3 | `ContractRenderWork` saves | `withoutTimestamps` is class-wide while the callback runs. Today each callback performs exactly one save, so it is harmless. A future observer or `saving` hook that saves another `ContractRenderWork` inside it would also skip stamping |
| P3-3 | P3 | `CustomerDataRights.php:210` (`CustomerAccount` with timestamps) | `updated_at` can drift one second past the decision `$at` used for the request and events. The trigger requires only monotonicity and nothing compares it for equality, so it is informational |

## Commands and results

Native runs used my private server on port 3477 (REPEATABLE-READ, `performance_schema` on), one database per run. SQLite runs used the direct PHPUnit command (in-memory, `public/build` absent).

| Run | Source | Result |
| --- | --- | --- |
| SQLite `TestContractSecondBoundaryTest` | `0fb1c93e` | OK, 5 tests, 39 assertions |
| SQLite same | fix reverted | 4 of 5 fail (as above) |
| Native `TestContractSecondBoundaryTest` | `0fb1c93e` | OK, 5 tests, 39 assertions (1,481 s) |
| Native same | fix reverted | 4 of 5 fail (completion, claim, transient, permanent), as on SQLite |
| Native `TestContractIssuanceConcurrencyTest` | `0fb1c93e` | OK, 4 tests, 68 assertions |
| Selection | `0fb1c93e` | 2678 / 9950 / 265, matching both comments |
| Safeguards | `0fb1c93e` | 52, 27, 14, 53, 61 OK |
| `composer audit --locked` | `0fb1c93e` | no advisories |

## Not tested

- The full contract and delivery suites. Only the new test and the concurrency test ran natively; the rest of the change is covered by unchanged evidence checks.
- Foundation and hosted CI.

## Cleanup

- My `mysqld` (port 3477) was shut down with `mysqladmin shutdown` and its datadir removed.
- The worktrees `wt` and `mut` under `/home/user/notes/con-review/` were removed with `git worktree remove`.
- `/home/user/wt/con` is unchanged at `0fb1c93e`.
