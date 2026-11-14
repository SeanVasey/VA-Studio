# Test-contract issuance quarantines when the clock crosses a second before the completion save

Found by lane PCC on 2026-11-01. Pre-existing on main `8bb1ab24` (reproduced there); PCC does not touch `app/Domain/Contracts`.

## Code location

- `app/Domain/Contracts/RenderTestContract.php`, inside the `ContractWork::locked()` closure:
  line 50 `$at = now()->toImmutable()->utc()->startOfSecond();`, then lines 56-62 create the `GrantContract` with
  `issued_at => $at` and `$work->forceFill([... 'updated_at' => $at])->save();`, then line 65 calls
  `ReadGrantContract::forRequest($locked, $account)`.
- `app/Domain/Contracts/ReadGrantContract.php` line 39: `... || ! $document->issued_at->equalTo($work->updated_at)` raises
  `evidence_changed` (line 40).
- `app/Domain/Contracts/Models/ContractRenderWork.php`: an ordinary Eloquent model with timestamps enabled.
- Framework: `Illuminate\Database\Eloquent\Concerns\HasTimestamps::updateTimestamps()` sets `updated_at = freshTimestamp()`
  whenever `updated_at` is not dirty.

## Failure path

1. `ContractWork::claim()` stores `updated_at = T` (second precision, `startOfSecond`).
2. Rendering and storage finish within the same wall-clock second, so the completion stamp `$at` is also `T`.
3. `forceFill(['updated_at' => $at])` sets the value it already has, so `updated_at` is **not dirty**.
4. If the clock has passed into second `T+1` before `save()`, `updateTimestamps()` overwrites `updated_at` with `T+1`
   (freshTimestamp), while the document keeps `issued_at = T`.
5. `ReadGrantContract::forRequest()` at line 65 sees `issued_at != work.updated_at` and throws `evidence_changed`; the
   exception handler in `RenderTestContract` calls `fail()`, which quarantines the work (`state=quarantined`,
   `reason=evidence_changed`) and `handle()` returns `changed`, not `ready`.
6. Test fixtures then throw `LogicException('Synthetic contract did not issue.')` (`tests/Support/ActivationFixtures.php:38`).

It needs claim and completion in the same second and a second boundary between `$at` and `save()`, so it is timing-dependent:
rare on an idle host, frequent under load (the first issuance in a fresh PHP process is the slowest). It is not
order-dependent: shard 3 at `5110d74c`, run alone in its exact shard order, passed 2474/2474.

## Deterministic probe

`ContractSecondBoundaryProbeTest` (source: `evidence/ContractSecondBoundaryProbeTest.php.txt` in this folder; it was run from
`tests/Feature/PccScratch/` in a scratch main worktree and is not committed anywhere). It freezes Carbon at
`2026-11-01T01:00:00.200000Z`, issues one synthetic contract through the real chain, and in one case registers a
`ContractRenderWork::saving` listener that, when `state === 'completed'`, moves Carbon to `+900 ms` (the next second).
Result on main `8bb1ab24`: without the crossing the contract issues; with it the work is
`{"state":"quarantined","reason":"evidence_changed"}` and the contract does not issue (OK 2 tests, 2 assertions; the
second test asserts the failure). A load probe (`evidence/ContractIssueUnderLoadProbeTest.php.txt`: 12 issuances per process)
failed 1 of 36 on the lane head and 2 of 36 on main while the 4-shard suite ran.

## What it explains

- `Tests\Feature\RightsDeclarationWriterTest::test_new_pending_and_verified_successors_preserve_paid_history_contract_bytes_and_original_verification`:
  `LogicException: Synthetic contract did not issue.` (via `ActivationFixtures::issue`).
- `Tests\Feature\InquiryBrowserCatalogCleanupTest::test_failed_withdrawal_audit_keeps_the_ready_track_site_release_and_private_history_unchanged`:
  "Isolated catalog cleanup regression operation failed: purchase LogicException"; the worker's purchase phase is
  `CustomerFixtures::ready()` -> `ActivationFixtures::issue()`.
- Any issuance quarantined with `evidence_changed` right after a successful render, in tests or on a host (staging uses the
  test chain): a real buyer-facing contract would stay unissued until an operator acts.

## Possible fixes (for the fix lane to choose and review)

Save the completion without the framework touching `updated_at` (for example `$work->timestamps = false` for that save, or
`saveQuietly` with timestamps off), or compare `issued_at` to the stored `updated_at` after an explicit refresh; add the probe
as a regression test.
