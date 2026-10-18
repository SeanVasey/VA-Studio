# F4 repairs: six failing test classes from the S1 phase 2 sweep

Lane F4 repairs the 12 failing cases (6 classes) that the local S1 phase 2 sweep found on `main` `4c0854fd`. All six reproduce in isolation on `main` `97e865e9`, so none is test-order pollution. Each was introduced by a recent merge, and in each case the test or its fixture had gone stale against an intended product change. **No production code changed:** every commit touches only `tests/`, this packet and `CHANGELOG.md`. Nothing was pushed, no PR was opened or commented on, and no workflow was dispatched (CI cost policy, AGENTS.md).

- Worktree `/home/user/wt/f4`, branch `harness/s1-phase2-repairs-20261018`, based on `origin/main` at `97e865e985a6a5ec0eb7b35d1d80e0f3b52cd4be` (`97e865e9`).
- Tested source: `b38dd1f9aa8f9f23b65c04ae0e71f23b50d93b59` (the last test commit), with `laravel/framework` 13.36.0. The docs commit that carries this packet and the `CHANGELOG.md` line changes no application or test file. The branch then merged `main` at `5007a906` (PR #154, `laravel/framework` 13.37.0); the independent review re-ran the six files (53 tests) and a 78-file sample (730 tests, 0 failures, 0 errors, skips all in the census) on 13.37.0 at `80452969`.
- Runtime: PHP 8.4.26, PHPUnit 12.5.34, SQLite in memory. Lane command: `APP_ENV=testing`, `APP_DEBUG=false`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `CI=true`, `GITHUB_ACTIONS=true`, `--fail-on-phpunit-warning`, `memory_limit=512M`, `public/build` absent. The batch runs used the S1 runner (`env -i`, `PAO_DISABLE=1`, junit log) with the same variables.
- Sweep logs that found the failures: the lead's scratchpad `s1/` (`p2b1.junit.xml`, `p2b2.junit.xml`, `p2-batch1.txt`, `p2-batch2.txt`).

## Commits

| Commit | Class | Failure | Introduced by |
| --- | --- | --- | --- |
| `99226d77` | `test:` | 6 | `952fb9e3` (#127) |
| `19fca5f6` | `test:` | 5 | `f1d5fb1f` (#129) |
| `b7b23dc9` | `test:` | 4 | `cbe111ec` (#127) |
| `7d6abcee` | `test:` | 3 | `67fa03a7` (#114) |
| `b38dd1f9` | `test:` | 1 and 2 | `11ab36d7` (#130) |
| (this packet) | `docs:` | none | none |

## Summary

| # | Test | Real or order-dependent | Introduced by | Cause | Decision | Change |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `OrderInquiryTest::test_staff_context_rechecks_authority_after_reconstruction_and_audit` (4 data sets) | Real, fails in isolation | `11ab36d7` (#130) | The shared fixture approves its license with an unenrolled reviewer; ReviewLicense now requires staff MFA enrollment when the panel requires MFA | Intended product change; fix the fixture | `QuoteFixtures` enrolls an explicit reviewer |
| 2 | `OrderInquiryStaffContextTest::test_actual_retained_context_entry_rechecks_authority_even_after_filament_cached_its_state` (2 data sets) | Real, fails in isolation | `11ab36d7` (#130) | Same fixture, same cause | Same | Same commit as 1 |
| 3 | `OrderInquiryMigrationTest::test_empty_owned_roundtrip_and_repeat_install_preserve_unrelated_schema` | Real, fails in isolation | `67fa03a7` (#114) | The test compares SQLite `rootpage`; a later migration moved the free pages the recreated objects receive | Storage detail, not schema; normalize only the owned rows | Drop `rootpage` for `inquiry_order_contexts` rows |
| 4 | `ProductionFreeGrantContentionTest::test_a_deadlock_after_the_customer_write_is_a_fixed_refusal_and_nothing_is_written` (3 data sets) | Real, fails in isolation | `cbe111ec` (#127) | The simulated driver error came from a `Repository` subclass; identity metadata admission refuses it first (`identity_refused`) | Intended hardening; move the injection seam | Throw from the first encrypter decrypt after the origin write |
| 5 | `ProductionFreeGrantStalledSourceTest::test_the_deadline_is_checked_before_the_first_read` | Real, fails in isolation | `f1d5fb1f` (#129) | `snapshot()` now reads `$before['auth']['account_id']`; the hand-built `$before` has no `auth` | Intended; complete the fixture | `auth.account_id` added to three `$before` arrays |
| 6 | `ProductionAccountFeaturesDefaultConfigurationTest::test_booted_parents_are_the_exact_closed_default_off_plain_arrays` | Real, fails in isolation | `952fb9e3` (#127) | The shipped config gained `'http_mounted' => false`; the pinned closed default lacks it | Pin the shipped literal | Key added to the constant |

No class is in `scripts/ci/database-sqlite-skips.json` or `scripts/ci/database-mysql-skips.json`, and no census file changed. Only `OrderInquiryMigrationTest` is in `scripts/ci/database-mysql-selection.json` (the `*Migration*Test` pattern); see "Untested conditions".

## 1 and 2: staff MFA enrollment for the license reviewer in the shared fixture

### Reproduction

`logs/red-OrderInquiryTest.txt` and `logs/red-OrderInquiryStaffContextTest.txt`, on `97e865e9` in isolation:

```
Illuminate\Auth\Access\AuthorizationException: Staff multi-factor authentication is required for this write.
app/Domain/Staff/StaffWriteAuthority.php:122
app/Domain/Rights/ReviewLicense.php:59
tests/Support/LicenseFixtures.php:42  (approved)
tests/Support/QuoteFixtures.php:24
```

Both tests turn the admin panel's required MFA on, enroll only the inquiry operator, then call `Fixture::guest()`, which builds a catalog through `QuoteFixtures::selection`. The 4 and 2 data sets all fail while building that fixture, before they reach the withdrawal they assert.

### Root cause

`11ab36d7` (merged in #130) added `StaffWriteAuthority::require($currentActor)` to `ReviewLicense::approve` and `submit` and to the other catalog, license and exclusive writers. With panel MFA required, the acting staff row must now have an enrolled provider. `QuoteFixtures::selection` already enrolls its publisher (its comment says so) but calls `LicenseFixtures::published($actor)`, whose default reviewer is `LicenseFixtures::admin()`, a fresh admin with no enrollment. With panel MFA off, as in nearly every test, the missing enrollment is invisible; only tests that require MFA before building the catalog hit it.

Dating (`logs/dating-parent-vs-introducing-commit.txt`): at `8b2cd56c`, the parent of `11ab36d7`, the two files pass (2 and 5 tests); at `11ab36d7` they error (2 of 2, and 4 of 5).

### Is the product change intended?

Yes. `11ab36d7` was preceded by its own red test, `8b2cd56c` (`CatalogLicenseStaffMfaTest`), and #130 is the "staff MFA leftovers" batch. Requiring current enrollment of the approving reviewer is the point of that change.

### Fix (`b38dd1f9`)

`tests/Support/QuoteFixtures.php`: create an explicit reviewer with `LicenseFixtures::admin()`, enroll it with `saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP')` (the same synthetic secret `FreeGrantFixtures` gives its author and reviewer), and pass it to `LicenseFixtures::published($actor, $reviewer)`. The tests are untouched. The reviewer was already created before, so user counts do not change; with panel MFA off the enrollment has no effect.

### Green

`logs/green-OrderInquiryTest.txt`: 27 tests, 105 assertions. `logs/green-OrderInquiryStaffContextTest.txt`: 3 tests, 14 assertions. The fixture is shared by 347 test files (all files that reach `QuoteFixtures` directly or through another support class, `logs/dependents-of-QuoteFixtures.txt`); all 347 were run, see "Verification".

## 3: SQLite `rootpage` in the migration round-trip test

### Reproduction

`logs/red-OrderInquiryMigrationTest.txt`: after `down()` and `up()`, the `sqlite_master` row of `inquiry_order_contexts_inquiry_id_unique` has `rootpage` 793, the baseline 331, and the `inquiry_order_contexts_order_id_index` row moved from 330 to 331. Nothing else differs. The result is stable across runs.

### Root cause

`67fa03a7` (merged in #114, the membership billing retrieval identity migration) is the first bad commit (`logs/bisect-migration.txt`; `71c4e7a7`, its parent, passes). It adds DDL earlier in the migration chain, which changes the freelist the in-memory database has when `down()` frees and `up()` re-allocates the migration's own table and indexes, so they receive different pages. `rootpage` is where SQLite stores a b-tree, not part of the schema. The migration, its guards and every column definition are unchanged; `sql` is identical in the diff.

### Fix (`7d6abcee`)

`tests/Feature/OrderInquiryMigrationTest.php`: the existing `$structure` normalizer already drops `CREATED` for the migration's own MySQL guards. Its new SQLite branch drops `rootpage` for rows whose `tbl_name` is `inquiry_order_contexts`. Every unrelated row, rootpage included, and every other column of the owned rows still compare with `assertSame`. The MySQL branch is unchanged.

### Green

`logs/green-OrderInquiryMigrationTest.txt`: 8 tests, 33 assertions.

## 4: contention injection behind the identity metadata admission

### Reproduction

`logs/red-ProductionFreeGrantContentionTest.txt`: three data sets (1213, 1205, 40001) fail at the shared `refuses()` with `Failed asserting that two strings are identical. -'contention' +'identity_refused'`.

### Root cause

The test simulates a driver error from inside the transaction after the write by replacing the `config` instance with an anonymous `Illuminate\Config\Repository` subclass whose `get('production-free-grants')` throws once. Every customer command first runs `ProductionCustomerAccess::lock`, whose `read` calls `IdentityMetadataAdmission::admit`; since `cbe111ec` that admission requires `$repository::class === Repository::class` (`IdentityMetadataAdmission.php:90`) and throws `IdentityException`, which `customerCommand` maps to `identity_refused`. The subclass is therefore refused before the hook can run. The staff path (`ProductionFreeGrantDefinitions::staffCommand`) does not reference `IdentityMetadataAdmission`, which is why the staff data sets still pass with the config seam.

`cbe111ec` is the first bad commit (`logs/bisect-contention.txt`; `24f6fd14`, its parent, passes).

### Is the stricter refusal intended?

Yes. The F3 packet (`docs/verification/foundation-repairs-20261018/README.md`, group 1) establishes the design intent from `docs/verification/account-features-http-20261015/design.md:42-44` and confirms by census that no production path registers a container or config hook. This lane's classes are the same family: a test that relied on a hook the admission now refuses.

### Fix (`b7b23dc9`)

`tests/Feature/ProductionFreeGrants/ProductionFreeGrantContentionTest.php`: a new helper, `contendAfterWriteOnDecrypt`, throws the same simulated driver error from the first `Encrypter::decrypt` that follows a write to `production_free_origins`. Every command decrypts a row it has just written, inside its transaction, and the encrypter is outside what the admission inspects. The decorator is built from the booted encrypter's own key, cipher and previous keys. The customer test uses it; the staff test and the shared `contendAfterWrite` are unchanged. Assertions are unchanged: exactly one throw, the fixed `contention` refusal, nothing written, then the same request key succeeds exactly once.

### Sensitivity

`logs/mutation-contention-classification.txt`: with `if (self::contended($error))` in `ProductionFreeGrantTransactions::run` replaced by `if (false && self::contended($error))`, all three data sets error with the raw `PDOException` thrown from `Encrypter::decrypt`, so the seam is live and the test proves the classification. The file was restored with `git checkout`.

### Green

`logs/green-ProductionFreeGrantContentionTest.txt`: 7 tests, 44 assertions.

## 5: stalled-source snapshot fixtures lacked the account the spool keys on

### Reproduction

`logs/red-ProductionFreeGrantStalledSourceTest.txt`: `-'expired' +'artifact_unavailable'`.

### Root cause

`f1d5fb1f` (merged in #129, per-account free spool admission) made `ProductionFreeGrantDownloads::snapshot()` pass `hash('sha256', 'production-free-spool-account-v1:'.$before['auth']['account_id'])` to the spool (`ProductionFreeGrantDownloads.php:225`). Three tests call `snapshot()` by reflection with a hand-built `$before` that has `policy` and `target` but no `auth`. The undefined index is raised while the argument list is evaluated, inside the method's catch-all, so every such call refuses with `artifact_unavailable` before it reaches the deadline or read-timeout code. `test_the_deadline_is_checked_before_the_first_read` expects `expired` and failed. The other two (`test_a_hung_blocking_source_is_cut_off_by_a_read_timeout_derived_from_the_remaining_time` and `test_a_blocking_source_that_rejects_a_read_timeout_is_refused_before_any_read`) also expect `artifact_unavailable`, so they have passed since #129 without reaching what they were written for. That is the one surprising result of this lane: two tests were green for the wrong reason.

Dating: at `ca20e351`, the parent of `f1d5fb1f`, the deadline test passes; at `f1d5fb1f` it fails (`logs/dating-parent-vs-introducing-commit.txt`).

### Fix (`19fca5f6`)

`tests/Feature/ProductionFreeGrants/ProductionFreeGrantStalledSourceTest.php`: the three `$before` arrays now include `'auth' => ['account_id' => $this->owner['principal']->accountId]`. All three tests reach the code they prove; assertions are unchanged (the file goes from 31 to 33 assertions because the deadline test now passes its later assertions).

### Sensitivity

`logs/mutation-deadline-checks.txt`: with both `ProductionFreeGrantException::require(hrtime(true) < $deadline, 'expired')` checks in `snapshot()` removed, `test_the_deadline_is_checked_before_the_first_read` fails (`refuses` gets `artifact_unavailable` after a read). The file was restored with `git checkout`.

### Green

`logs/green-ProductionFreeGrantStalledSourceTest.txt`: 5 tests, 33 assertions.

## 6: the pinned closed default lacks `http_mounted`

### Reproduction

`logs/red-ProductionAccountFeaturesDefaultConfigurationTest.txt`: the file has `'http_mounted' => false` between `enabled` and `provenance`; the pinned constant has not.

### Root cause

`952fb9e3` (merged in #127, "compose default-off production account feature HTTP and UI") added `'http_mounted' => false` to `config/production-account-features.php`. `AccountFeatureMount` mounts the routes only when that key is a literal `true`. The sibling `ProductionAccountFeaturesDefaultMountTest` already asserts the default `false`; the test here pins the whole file and was not updated. This is the same shape as F3's group 3 (Tax `http_mounted`).

### Fix (`99226d77`)

`tests/Feature/ProductionAccountFeatures/ProductionAccountFeaturesDefaultConfigurationTest.php`: add `'http_mounted' => false` to the `SHIPPED` constant in the shipped order. The comparison stays an exact `assertSame` on the file, the booted config and the raw `ProductionFeatureConfiguration` snapshot.

### Green

`logs/green-ProductionAccountFeaturesDefaultConfigurationTest.txt`: 3 tests, 69 assertions.

## Verification

All counts are SQLite, `CI=true`, `public/build` absent. "Before" is the S1 sweep on `4c0854fd`; "after" is this worktree with all five test commits.

| Check | Result |
| --- | --- |
| Each of the six files, whole, in isolation on `97e865e9` (red) | All six fail with the S1 error; `logs/red-*.txt` |
| Each of the six files, whole, in isolation at the tested source (green) | 27, 3, 8, 7, 5 and 3 tests, all `OK`; `logs/green-*.txt` |
| S1 phase 2 batch 1 (257 files, original order) | before: 3673 tests, 4 failures, 6 errors; after: 3673 tests, 35114 assertions, none of the original 10 cases fail |
| S1 phase 2 batch 2 (256 files, original order) | before: 3453 tests, 2 failures; after: 3453 tests, 34031 assertions, none of the original 2 cases fail |
| Fixture dependents outside those batches (88 files that reach `QuoteFixtures`) | 967 tests, 11806 assertions, 70 skipped, 0 failures, rc 0 |
| Fixture dependents in total | 347 files: 259 in the two batches plus the 88 above |
| Skip census in all three runs | No unlisted skips; every census method present was skipped in every case (`logs/batch-analysis.txt`) |
| `scripts/ci/test-phpunit-shards.py` | exit 0, 52 tests |
| `scripts/ci/test-ci-scope.py` | exit 0, 27 tests |
| `scripts/ci/test-workflow-cadence.py` | exit 0, 14 tests |
| `scripts/ci/test-focused-tests.py` | exit 0, 53 tests |
| `git diff --check origin/main...HEAD` | clean |

The two batch runs each report two failing cases that are not in the original list. They are the four contract-renderer tests below, and they are an artifact of this lane's vendor directory, not of the repairs: `IsolatedContractRendererAcceptanceTest`, `TestContractIssuanceTest::test_real_isolated_renderer_can_issue_one_private_pdf_from_an_actual_paid_grant`, `TestContractProfileSuccessorTest::test_real_scrubbed_child_dispatches_only_the_trusted_successor_implementation` and `TestContractSuccessorActivationTest::test_exact_v2_policy_captures_real_request_and_worker_preserves_one_original`. They spawn a scrubbed child PHP with `open_basedir` set to the project root, and this worktree's `vendor/` first held symlinks to another worktree's packages, which the child may not read (`IsolatedContractRenderer.php:71`, `render_failed`). With the symlinks replaced by real copies, the four files pass: 74 tests, 597 assertions (`logs/rerun-contract-renderer-files-real-vendor.txt`). None of the five commits touches a file these four use.

## Independent review

[`independent-review-80452969-DECISION.md`](independent-review-80452969-DECISION.md): **APPROVE WITH CONDITIONS** at
`80452969`, no P0 to P2. Each repair was checked by mutation: the authority re-check, an index recreated on different columns,
a missing rollback after the customer write, and the two stalled-source tests that had passed vacuously all fail as they
should. Repairs 1 and 2 share one root cause, so the lane repairs five root causes in six classes. Both conditions (P3: that
count in the ledger, and this packet's note on the 13.37.0 re-run) are corrected by the commit after the reviewed one.

## Untested conditions

- No native MySQL run. `OrderInquiryMigrationTest` is in the MySQL selection; the change is in the SQLite branch of its normalizer and the MySQL branch is untouched, but MySQL did not execute it here. The contention test is not in the MySQL selection and was run on SQLite only; its injection is engine-independent (an exception thrown from the encrypter), but a native deadlock on a customer command was not exercised.
- Only the 347 files that reach `QuoteFixtures` and the six repaired files were re-run after the fixes. The remaining test files share no edited support class and were not re-run; the five commits change no other file under `tests/` or `app/`.
- The batch runs were made with a vendor directory of symlinks to another worktree for their first pass (see above); only the four contract-renderer files were re-run with real copies.
- The lane needs `APP_KEY` in the environment (or a `.env`): without it `InquiryPolicy::publicSetup` returns null and every `OrderInquiry*` fixture fails at `InquiryConversationFixtures.php:25`, an error that is unrelated to the six failures. Runs here set a generated key through the environment.
- No browser specs, no hosted CI, no lint gate (none is enforced yet, CLAUDE.md "Standard adoption gaps").

## Adjacent findings (not fixed)

- `LicenseFixtures::approved` and `::published` still default to an unenrolled reviewer. Any future test that turns panel MFA on before calling them directly, rather than through `QuoteFixtures`, will hit the failure of groups 1 and 2.
- The phase 1 sweep found `MemberGrantFactsCallbackClosureTest` "committed identity_configuration" failing with `IdentityException`, the same hardening as group 4; F3 repaired it (`docs/verification/foundation-repairs-20261018/`).
