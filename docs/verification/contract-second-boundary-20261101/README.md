# Contract work transitions across a second boundary (lane CON, 2026-11-01)

This is development evidence from the Claude Code harness. It is not final or Foundation acceptance. Lane PCC found the root
cause on `main` `8bb1ab24`. This lane fixed it on branch `harness/contract-second-boundary-20261101` and merged `main`
`3e8a1fd7` (PR #184) before the final checks.

## Defect

`RenderTestContract` and `ContractWork` stamp each `contract_render_work` transition with
`$at = now()->toImmutable()->utc()->startOfSecond()`, then call `$work->forceFill([..., 'updated_at' => $at])->save()`.
If `$at` equals the stored `updated_at`, the attribute is not dirty, so Eloquent's `HasTimestamps::updateTimestamps()`
sets `updated_at` to `freshTimestamp()` at save time. If the clock has entered the next second by then, the row stores
`$at + 1 s`.

| Save | When the stamp is not dirty | Effect on `8bb1ab24` |
| --- | --- | --- |
| Completion (`RenderTestContract`) | claim and completion in the same second | `work.updated_at = issued_at + 1`; `ReadGrantContract` (and `ActivationEvidence`) need equality, so the issuance throws `evidence_changed`, the work is quarantined and `handle()` returns `changed` |
| Claim from `pending` (`ContractWork::claim`) | request and claim in the same second | the guard `contract_render_work_valid_update` needs `lease_expires_at = updated_at + 300`, rejects the update, and `handle()` returns `changed` with the work still `pending` |
| Transient failure (`ContractWork::fail`, `retry`) | claim and failure in the same second | the guard needs `next_attempt_at >= updated_at + 60` and rejects the update. `RenderTestContract::fail()` returns `retry`, but the work stays `processing` until its lease expires |
| Permanent failure (`ContractWork::fail`, `quarantined`) | claim and failure in the same second | the guard accepts it, but the stored stamp is a second after the decision |
| Exhausted claim (`ContractWork::claim`, `retry_exhausted`) | cannot happen: `$at` is at least the stored stamp plus 60 or 300 s | none. It shares the write and is fixed for consistency |

## Fix

All four `contract_render_work` saves now run inside `ContractRenderWork::withoutTimestamps(...)`, so the row stores exactly
`$at`. When the stamp is not dirty, the UPDATE leaves `updated_at` alone, and its stored value already equals `$at`. Nothing
else changed. `ReadGrantContract` still requires `issued_at` to equal `work.updated_at`, `ActivationEvidence` still requires
`work.updated_at` to equal `issued_at`, and the guards in `2026_09_26_000021_test_contract_issuance.php` are untouched. They
accept the write because each guard condition holds when `NEW.updated_at` is exactly the decision second: the claim lease is
`+300`, the retry is at least `+60`, the completion has `issued_at >= OLD.updated_at` and `issued_at <= NEW.updated_at`, and
the lease is still active. The new test passes on both engines with these triggers in place.

Search scope:

- `app/Domain/Contracts`: the only `forceFill([... 'updated_at' ...])->save()` writes are the four above.
  `RequestTestContract` creates the work with explicit `created_at = updated_at = $at`. Both are dirty on insert, so Eloquent
  keeps them. No other code writes `ContractRenderWork`. `ActivateTestFulfillment` only locks and reads it.
- Production contract and grant documents (`app/Domain/Grants/{Free,Member,Paid,PaidTax,ProductionFree}`): there is no
  Eloquent `forceFill`, `save()` or `updated_at` write. They use explicit query writes, so the framework clock never stamps
  them.
- Outside scope, not changed: other domains use the same Eloquent pattern with second-precision stamps. Examples are
  `RefundDisputeLedger`/`RefundDisputeActions`, `CustomerDataRights` and `InquiryNotificationWork`. They are listed for the
  lead to triage, and this lane did not check whether anything compares them for equality.

## Regression test

`tests/Feature/TestContractSecondBoundaryTest.php` freezes the clock 200 ms into a second. In each case a `ContractRenderWork`
`saving` listener moves the clock forward 900 ms, once, while one transition is saved. Saving listeners run before
`updateTimestamps()`, so this reproduces the race exactly.

| Case | `8bb1ab24` (SQLite and MySQL 8.4.11) | fixed |
| --- | --- | --- |
| same-second completion, no crossing (control) | pass | pass |
| completion crossing | fail: `changed` instead of `ready` | pass |
| claim crossing | fail: `changed` instead of `ready` | pass |
| transient-failure crossing | fail: work `processing` instead of `retry` | pass |
| permanent-failure crossing | fail: stored stamp a second late | pass |

Mutation at the final source: reverting the whole fix makes the same four cases fail (`Tests: 5, Failures: 4`). Reverting one
site at a time kills that site's case: completion kills 1, claim kills 1, and `fail()` kills the transient and permanent
cases (2). Reverting the exhausted-claim site leaves every case passing. That mutant survives because its non-dirty case
cannot happen (see the table above).

## Evidence

The host is shared with other lanes. Load average was about 7 to 13 on 4 cores throughout.

- **Load probe** (PCC's `ContractIssueUnderLoadProbeTest`: 12 real issuances per process, untracked scratch copy, not
  committed): 3 rounds of 3 concurrent processes.
  - `8bb1ab24`: **14 of 108 failed**. 13 were `quarantined`/`evidence_changed` (completion) and 1 was `pending`/0 attempts
    (claim rejected by the guard), which confirms the claim path outside the deterministic test.
  - Fixed (`19f7cd3c` source, run while the affected suites and a MySQL run shared the host): **0 of 108 failed**, all 9
    processes OK.
- **SQLite, affected files** (`/home/user/notes/con/pu.sh <file>`, direct PHPUnit, `public/build` absent, one process per file,
  3 parallel chunks): 44 files covering every `tests/` file that uses `ActivationFixtures`, `RenderTestContract`,
  `ContractWork`, `ContractRenderWork` or `GrantContract`, plus `RightsDeclarationWriterTest`,
  `InquiryBrowserCatalogCleanupTest`, `PaidGrantDocumentJourneyTest` and `PaidTaxGrantJourneyTest`. 600 cases ran. 42 files
  passed (native-only cases skipped on SQLite as the census expects). Two files failed under load with unrelated timeouts:
  `InquiryBrowserCatalogCleanupTest` (2 worker processes passed their 60 s `prepare` timeout) and
  `PaidGrantDocumentJourneyTest` (the SMTP sink's `accept()` timed out). Both were rerun at the merged head (below).
- **Merged head `a1a55b90`, SQLite reruns**: `TestContractSecondBoundaryTest` `OK (5 tests, 39 assertions)`, `InquiryBrowserCatalogCleanupTest` `OK (6 tests, 106 assertions)`, `PaidGrantDocumentJourneyTest` `OK (4 tests, 90 assertions)`, `RightsDeclarationWriterTest` `OK (33 tests, 472 assertions)`, `TestContractIssuanceTest` `OK (53 tests, 504 assertions)`.
- **Native MySQL 8.4.11**: a private `mysqld` on its own datadir under `/home/user/notes/con/`, socket `/tmp/con-my/s`, port
  3641 (checked free with `ss -ltnp`), and separate `vaseyaudio_*` schemas for parallel runs.
  - The regression test at `8bb1ab24`: `Tests: 5, Failures: 4`. At the fix (`19f7cd3c` source): `OK (5 tests, 39 assertions)`.
  - Merged head `a1a55b90`: `TestContractSecondBoundaryTest` `OK (5 tests, 39 assertions)`; `TestContractIssuanceConcurrencyTest` (native races) `OK (4 tests, 68 assertions)`; `TestFulfillmentActivationConcurrencyTest` (`ActivationEvidence` consumer) `OK (1 test, 18 assertions)`; `TestContractIssuanceTest --filter 'test_one_original_pdf_and_completed_work|test_transient_render_failure_uses_due_backoff|test_permanent_render_failure_is_quarantined|test_five_transient_failures_exhaust|test_last_attempt_worker_crash|test_a_lease_that_expires_during_render|test_private_storage_failure_retries'` `OK (9 tests, 120 assertions)`. Command: `/home/user/notes/con/native.sh <args>` (direct PHPUnit with `DB_CONNECTION=mysql` and `VA_LOCAL_DISPOSABLE_MYSQL` naming the schema). The server was stopped afterwards by its exact PID.
- **Selection and safeguards at `a1a55b90`**: the SQLite skip census is `main`'s 310 entries (this branch adds no native-only
  test). `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`
  proved 2673 selected cases in 264 files (unchanged) of 9950 discovered (was 9945; the 5 new cases). The workflow comment
  was updated, `.gitlab-ci.yml`'s selected-count comment is unchanged, and the generated files were deleted.
  `test-phpunit-shards.py` (52), `test-ci-scope.py` (27), `test-workflow-cadence.py` (14), `test-focused-tests.py` (53)
  and `test-database-receipts.py` (61) all passed. All 20 `tests/ops/test_*.py` passed. `composer audit` found no advisories.

## Untested conditions

- The new test is not in the reviewed MySQL CI selection (`database-mysql-selection.json`), so hosted MySQL shards will not
  run it. Its MySQL evidence is the local native run above. Adding it to `include_files` is a selection-policy decision for
  the lead.
- MySQL runs of the existing contract suites were limited to the native race tests and to the `TestContractIssuanceTest`
  cases that exercise the changed writes, listed above. The full contract suites ran on SQLite only, because one test takes
  about 7 minutes on this host's MySQL.
- No full-suite or Foundation run. No staging run. The staging test chain uses the same code, so this fix also removes the
  hosted symptom, but that was not observed on a host.
