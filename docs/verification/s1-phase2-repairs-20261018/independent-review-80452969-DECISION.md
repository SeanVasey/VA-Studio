# Independent review — F4 S1 phase 2 repairs at 80452969

- **Reviewed commit:** `80452969f936ecc195d2eabd4b46f71a10f354b4`, branch `harness/s1-phase2-repairs-20261018`, worktree `/home/user/wt/f4`
- **Base:** `origin/main` `5007a90651212713ca0f204cefb58558a603e7f3` (PR #154 merge; merged into the branch at `e1aef1fb`)
- **Diff reviewed:** `git diff origin/main...80452969`: 30 files, +1226/−9. Test code is 5 files: `QuoteFixtures`, `OrderInquiryMigrationTest`, `ProductionFreeGrantContentionTest`, `ProductionFreeGrantStalledSourceTest` and `ProductionAccountFeaturesDefaultConfigurationTest`. The rest is the evidence packet, `CHANGELOG.md` and two ledger files.
- **Reviewer:** independent Claude subagent. I did not write this change. Nothing was committed, pushed or commented on GitHub, and no workflow was dispatched.

## Decision: APPROVE WITH CONDITIONS

Each of the five repairs keeps the test's original intent, and none weakens an assertion. Every repaired test that guards a production check failed when I removed that check, and each now fails at the same point as before. The two stalled-source tests that had been passing vacuously were passing vacuously in fact: under the pre-fix test file my mutations survive, and under the fixed file they are killed. I found no production defect behind any repair. The only conditions are two P3 documentation corrections (F4-R1, F4-R2). Neither blocks merging, and both can ride with the PR or be deferred under the freeze-and-batch rule.

## Findings

| id | severity | file:line | finding | evidence | fix |
| --- | --- | --- | --- | --- | --- |
| F4-R1 | P3 | `docs/handoff/2026-10-17/RESUMPTION.md:47` | The F4 ledger row says "Six root causes repaired". The packet itself shows five root causes across six classes: groups 1 and 2 share the `QuoteFixtures` reviewer cause. | Packet summary table: 5 test commits for 6 classes. | Say "Five root causes in six classes repaired test-only". |
| F4-R2 | P3 | `docs/verification/s1-phase2-repairs-20261018/README.md:5-7` | The packet names `b38dd1f9` on base `97e865e9` as its tested source, with a vendor at `laravel/framework` 13.36.0. The PR head now also contains the PR #154 merge (framework 13.37.0), and the packet does not say the repairs were rechecked after that merge or which framework version its runs used. | `grep 13.37\|5007a906\|e1aef1fb README.md` finds nothing. My re-run on 13.37.0 at `80452969` is green (below). | Add one line: the branch merged `5007a906` (framework 13.37.0); the six files and the sample were re-run on 13.37.0 at the head (cite this record). |
| F4-N1 | note (no action for F4) | `tests/Support/LicenseFixtures.php:36-44` | `LicenseFixtures::approved/published` still default to an unenrolled reviewer, so any future test that turns on panel MFA before calling them directly will hit the group 1/2 failure. The packet already lists this as an adjacent finding. | Read of the fixture. | Optional follow-up: enroll the default reviewer, or document the requirement. |
| F4-N2 | note (pre-existing, not introduced by F4) | `tests/Feature/OrderInquiryStaffContextTest.php:53` | Its two data sets survive the removal of any *single* authority layer: the entry's `authorizedRead`, `staffContext` line 106, or the post-reconstruction recheck at line 113. They fail only when all three are removed. This is defence in depth that the test cannot tell apart; F4 did not change it. `OrderInquiryTest`'s four data sets do pin line 113 on its own. | Mutations M1, M1d and M1e below. | None for F4. |

No P0, P1 or P2.

## Assessment by repair

### 1 and 2: `QuoteFixtures` reviewer enrollment (`b38dd1f9`)

- **Intent preserved.** The reviewer the fixture now creates is a separate user from the test's inquiry operator (`$this->fixture['actor']`), whose role and MFA the tests withdraw. Enrolling the license reviewer only lets catalog construction pass `StaffWriteAuthority::require` in `ReviewLicense::approve`, which `11ab36d7` added (diff confirmed: 3 lines in `ReviewLicense.php`). The authority recheck under test is untouched.
- **Mutation M1:** I replaced `OrderInquiry::staffContext`'s post-reconstruction recheck (`app/Domain/Inquiries/OrderInquiry.php:113`) with a comment. All 4 `OrderInquiryTest::test_staff_context_rechecks_authority_after_reconstruction_and_audit` data sets fail with "Late staff withdrawal escaped."
- **Mutations M1d and M1e:** for `OrderInquiryStaffContextTest`, removing only the entry's `authorizedRead` survives (M1d). Removing it together with lines 106 and 113 kills both data sets with "Cached reference escaped current authorization." (M1e). See F4-N2.
- **Side effects of the fixture change.** The user count is unchanged, but the reviewer is now created earlier than before. Among the dependents, only `CustomerIdentityKeyRotationTest` asserts `User::count()`, and it is green in my run. The author also re-ran all 347 dependents.
- **Not a production defect.** Requiring a currently enrolled reviewer is the intended #130 change (`8b2cd56c` added its red test first).

### 3: SQLite `rootpage` normalization (`7d6abcee`)

- The normalizer drops `rootpage` only for rows with `tbl_name === 'inquiry_order_contexts'`. `type`, `name`, `tbl_name` and `sql` still compare exactly, and every unrelated row keeps `rootpage`. The MySQL branch is unchanged.
- **Probes,** each temporary and reverted. I injected these after the final `up()` in the round-trip test:
  - P2a: recreate `inquiry_order_contexts_order_id_index` on `(order_id, id)`. **Fails** (`sql` differs).
  - P2b: drop that index. **Fails** (row missing).
  - P2c: drop it and recreate it byte-identically. **Passes.** Only `rootpage` moves, which is the intended tolerance and is exactly what `down()`/`up()` does.
  - P2d: drop an unrelated index, allocate a pad table, then recreate the index identically. **Fails** with `rootpage` 14 → 794, so unrelated rows stay pinned.
- Index columns are pinned through `sql`. Triggers have `rootpage` 0. An autoindex's columns come from the table's own `CREATE TABLE` `sql`, which is still compared. A dropped, recreated or redefined owned object therefore cannot hide behind the change.

### 4: contention injection through the encrypter (`b7b23dc9`)

- **Same contention point.** `ProductionFreeGrantRows::insert` executes the INSERT and then reads the row back through `one()` → `ProductionFreeGrantRecords::verify/decrypt` → `Crypt::decryptString`. That read-back sits outside the `try` that wraps `execute`, so the simulated `PDOException` propagates out of `$work` inside `DB::transaction`. `ProductionFreeGrantTransactions::run` then rolls back and classifies it. The hook fires only once `production_free_origins` has a row, which is after the customer write and inside the transaction, so the test exercises the same classification as before. The old config seam threw slightly later (at `ProductionFreeGrantPolicy::prove`, after `$work` returned), but both points are inside the transaction and after the write. `decrypt` catches only `DecryptException|JsonException`, so the `PDOException` is not swallowed. Swapping the encrypter is not something the admission inspects (`IdentityMetadataAdmission::inspect` checks the `config`/`db` instances and facades only).
- **Author's mutation reproduced (M3a):** with `false && self::contended($error)`, all 3 customer data sets error with the raw `PDOException`.
- **My distinguishing mutation (M3b):** `run()` swallows contention *inside* the transaction closure, so partial writes commit, and then throws `contention` after the commit. All 6 contention data sets fail with `Failed asserting that 1 is identical to 0`: 3 customer (origins row committed) and 3 staff (definitions row committed).
- **Control (M3c and M3d):** with M3b still in place, I changed the helper's hook to throw on the *first* decrypt regardless of the write. That is a pre-write injection, and the 3 customer data sets **pass**: a pre-write error cannot see a commit-before-refuse defect. The same pre-write variant also passes on unmutated code. So the test's power to detect a missing rollback rests on the post-write gate (`count() > 0`), which the F4 helper keeps.
- **Not a production defect.** The admission's exact-class check (`$repository::class === Repository::class`, added in `cbe111ec`) is intended anti-tamper hardening. The staff path (`ProductionFreeGrantDefinitions`) does not call the admission, which is why its config seam still works. Production always binds exactly `Illuminate\Config\Repository`.

### 5: `auth.account_id` in the stalled-source fixtures (`19fca5f6`)

- `snapshot()` evaluates `$before['auth']['account_id']` as the argument to `prepare()`, inside its catch-all, so a missing key became `artifact_unavailable` before any deadline or read. In production `$before['auth']` is always the authorization row with `account_id` (`ProductionFreeGrantDownloads.php:70-84`), so this is not a production defect.
- **Vacuity confirmed and killed:**
  - **M4a:** read timeout fixed at 8 s instead of `max(1, min(5, remaining))`. With the fixed test, `test_a_hung_blocking_source_…` **fails** (8,010,455,091 ns is not < 4e9). With the pre-fix test from `origin/main`, it **passes** (vacuous).
  - **M4b:** the result of a failed `stream_set_timeout` is ignored, so a FIFO is accepted with no timeout. With the fixed test, `test_a_blocking_source_that_rejects_a_read_timeout_…` **hangs past its 30 s deadline**; I killed it at 90 s with `timeout`, which counts as a failure. With the pre-fix test it **passes** in 4.9 s (vacuous). I removed the FIFO the killed run left in `/tmp`.
  - **M4c (author's):** both `'expired'` checks removed. `test_the_deadline_is_checked_before_the_first_read` fails, `'expired'` vs `'artifact_unavailable'`.

### 6: `'http_mounted' => false` pin (`99226d77`)

- `config/production-account-features.php` ships `'http_mounted' => false` as a literal, not env-driven, first added in `952fb9e3` (`git log -S`). `AccountFeatureMount::…` mounts only on a literal `=== true`. Default off is confirmed, and the pin stays an exact `assertSame` over the whole file.

## Dating spot-checks (b)

Executed in a temporary detached worktree, with per-package vendor symlinks to f4's 13.37.0 vendor and a regenerated autoloader. Since removed, f4's vendor was confirmed intact afterwards. The only lock difference at those commits is framework 13.35.0 vs 13.37.0.

| Repair | Parent | Result | Introducing commit | Result |
| --- | --- | --- | --- | --- |
| 1/2 (`OrderInquiryStaffContextTest`, rechecks filter) | `8b2cd56c` | OK (2 tests, 6 assertions) | `11ab36d7` | 2 errors: "Staff multi-factor authentication is required for this write." |
| 4 (customer contention) | `24f6fd14` | OK (3 tests, 21 assertions) | `cbe111ec` | 3 failures, `'contention'` vs `'identity_refused'` |
| 5 (deadline test) | `ca20e351` | OK (1 test, 5 assertions) | `f1d5fb1f` | failure, `'expired'` vs `'artifact_unavailable'` |

I confirmed 3 and 6 from the diffs. `67fa03a7` adds a 20-line migration earlier in the chain, which is consistent with the freelist explanation. `952fb9e3` is the commit that adds the `http_mounted` literal. The PR mapping (#130, #127 ×2, #129, #114) matches the first `Merge pull request` on each commit's ancestry path.

## Docs and ledger (e)

- **PR #154:** merge `5007a90651212713ca0f204cefb58558a603e7f3` has parents `97e865e9` and `0d3b192ec7fc40808f7b78132269f2f5764d7ccb`. The GitHub REST API confirms it: `merged: true`, `merge_commit_sha` 5007a906, head 0d3b192e.
  - Preflight run 39126656904 succeeded at 0d3b192e. Run 39126649145 was cancelled at the same head.
  - Cursor Bugbot succeeded. The Codex code and security review summaries were completed with no findings.
  - The lock change is `laravel/framework` v13.36.0 → v13.37.0 only. `package.json` has React ^19.3.1, `@types/react-dom` ^19.3.0, user-event ^14.6.8 and Vitest 5.0.4.
  - The review record `independent-review-1464ebfc-DECISION.md` exists with APPROVE WITH CONDITIONS. The ledger row is accurate.
- **CHANGELOG:** a `Tests (lane F4)` line under `[Unreleased] / Fixed`, in the same form and placement as F3's precedent. Its claims match the evidence.
- **Packet claims:** red and green counts, root causes, dating, mutations and the contract-renderer vendor artifact are consistent with what I reproduced. See F4-R1 and F4-R2 for the two inaccuracies.
- **Secrets:** grepping the packet for `base64:` keys, `APP_KEY=<value>`, Stripe or webhook secrets and private-key headers finds no matches. The only `APP_KEY` mention is the prose in the untested-conditions list.

## Scope (f)

`git diff --name-only origin/main...HEAD` touches only `tests/`, `docs/` and `CHANGELOG.md`. There are no changes under `app`, `config`, `database`, `routes`, `resources`, `scripts`, `.github`, or the composer and npm manifests and locks. `git diff --check` is clean.

## Commands and results

Vendor for all PHPUnit runs: `laravel/framework` **13.37.0** from `/home/user/wt/m2` (`Application::VERSION` and `InstalledVersions` both report v13.37.0), with m2's `installed.json` and `installed.php`. The original 13.36.0 framework and installed files were moved to the scratchpad and restored at the end (`Application::VERSION` back to 13.36.0; `git status` clean; HEAD `80452969`). Every run used the instructed environment, a fresh random `APP_KEY`, `public/build` absent, `--fail-on-phpunit-warning` and `PAO_DISABLE=1` (to suppress pao JSON). The runner is `…/scratchpad/f4rev/run.sh`, and logs are in `…/scratchpad/f4rev/`.

| Command | Result |
| --- | --- |
| The six repaired files together (`six.txt`) | OK, 53 tests, 298 assertions (27+3+8+7+5+3 tests) |
| 78-file sample (`sample.txt`, `sample-run.txt`, `sample.junit.xml`): all `OrderInquiry*`, `Quote*`, `FreeGrant*` and `ProductionFreeGrant*` test files not in the six (48), plus 30 seeded-random other `QuoteFixtures` dependents; 50 of the 78 are `QuoteFixtures` dependents | OK, 730 tests, 8,050 assertions, 0 failures, 0 errors, 106 skipped (36 distinct methods, all present in `scripts/ci/database-sqlite-skips.json`; none outside the census); 31m57s |
| `CustomerIdentityKeyRotationTest` (asserts `User::count()`) | OK, 2 tests, 14 assertions |
| `python3 scripts/ci/test-focused-tests.py` | OK, 53 tests |
| `python3 scripts/ci/test-phpunit-shards.py` | OK, 52 tests |
| `python3 scripts/ci/test-ci-scope.py` | OK, 27 tests |
| `python3 scripts/ci/test-workflow-cadence.py` | OK, 14 tests |
| `git diff --check origin/main...HEAD` | clean |
| Independent dependents closure (word-boundary scan of `tests/Support`) | 348 vs the packet's 347; the one extra (`InquiryBrowserCatalogCleanupTest`) is a false positive of my regex, since it uses no support fixture |

## Mutations and probes (all reverted with `git checkout`)

| id | Change | Target | Outcome |
| --- | --- | --- | --- |
| M1 | `OrderInquiry.php:113` recheck removed | `OrderInquiryTest` rechecks (4) and `OrderInquiryStaffContextTest` (2) | 4/4 `OrderInquiryTest` killed; StaffContext survived (see M1e) |
| M1d | `InquiryTextEntry::getState` without `authorizedRead` | `OrderInquiryStaffContextTest` | survived (line 106 still rechecks) |
| M1e | M1d + `OrderInquiry.php:106` and `:113` removed | `OrderInquiryStaffContextTest` | 2/2 killed |
| M3a | `false && self::contended(...)` (author's) | customer contention (3) | 3/3 killed (raw PDOException) |
| M3b | contention swallowed inside the transaction, refused after commit | all contention data sets (6) | 6/6 killed (`1` vs `0` rows) |
| M3c | M3b + hook throws pre-write | customer contention (3) | survived (control: pre-write injection is blind to M3b) |
| M3d | pre-write hook, unmutated production | customer contention (3) | passes (control) |
| M4a | read timeout fixed at 8 s | hung socket test, fixed vs pre-fix file | fixed: killed; pre-fix: survived (vacuous) |
| M4b | `stream_set_timeout` failure ignored | FIFO test, fixed vs pre-fix file | fixed: hung >90 s (killed); pre-fix: survived in 4.9 s (vacuous) |
| M4c | both `'expired'` checks removed (author's) | deadline test | killed |
| P2a–P2d | index redefined / dropped / identical recreate / unrelated identical recreate | migration round-trip | fail / fail / pass / fail, as intended |

## Untested conditions

- No native MySQL. `OrderInquiryMigrationTest` is in the MySQL selection; its MySQL branch is unchanged but was not executed. A real MySQL deadlock on a customer Free256 command was not exercised; the injection is engine-independent.
- I re-ran 78 of the 347 `QuoteFixtures` dependents plus the six files on 13.37.0, not all 347. The author's full 347-file and two-batch runs were on 13.36.0 before the PR #154 merge.
- Dating runs used the 13.37.0 vendor at commits whose lock pinned 13.35.0.
- No browser specs, no hosted CI, no lint gate.
- The four contract-renderer files the packet mentions (vendor-symlink artifact) were not re-run by me.
