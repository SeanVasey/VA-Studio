# Independent review: MySQL native selection (harness/mysql-native-selection)

- Reviewed: `git diff 0f9b39ce5d59e2d579d11a8ca15153ced1f565b4..23ff88e71c70eb3a09b96d93751f2d04f2b1b53c` (13 files, +753/-60)
- Reviewer: independent subagent, 2026-10-09. `/home/user/wt-mysel` was only read: HEAD is still `23ff88e`, `git status` is clean, and `public/build` is absent.
- Copies used: `git archive 23ff88e` went to `../mysel-review-src` and `git archive 0f9b39c` to `../mysel-review-base`. Each has hard-linked vendor, real `vendor/composer` and `vendor/autoload.php`, and `composer dump-autoload` (11,603 classes). Later each got a local scratch `git init` snapshot so git- and node-dependent self-tests could run. Every generated `phpunit-ci-*` file was deleted.
- Load: PHPUnit discovery only (`--list-tests-xml`, run through the sharder). No PHPUnit suite and no database ran.

## Decision: APPROVE WITH CONDITIONS

The code is correct: the sharder flag, the recomputing verifiers, the cross-engine invariant and the workflow wiring. I found no way for a case to go unexecuted on both engines while every gate passes. Real discovery reproduces every number the change claims, and the unflagged sharder output is byte-identical to main's.

The conditions concern how much the selection rule covers and how honestly that is stated. The rule is "census owners plus `*Migration*Test.php`". It drops some MySQL-only proofs that SQLite does not skip but replaces with a fallback branch, including a billing race test. It also hides existing MySQL-skip failures. Both effects need an owner decision and explicit disclosure before merge (C1, C2).

## Findings

### F1 · High (policy scope / disclosure). Some native-only proofs now run on no engine, though SQLite reports a pass

The selection only sees MySQL-only behaviour that SQLite *skips*. Tests that branch on the driver instead of skipping pass on SQLite through a weaker fallback, and they are no longer run on MySQL. Of the 409 unselected files, 61 (936 cases) branch explicitly on `getDriverName()`, `ATTR_DRIVER_NAME` or `['driver']` (evidence/unselected-explicit-driver-branch-files.txt). Concrete examples:

- `tests/Feature/ProductionMembershipBilling/BillingNativeDedupRaceTest.php` (1 case). On MySQL it races two independent processes against the billing ledger dedup. On SQLite it runs the two retrievals sequentially; the file's own docblock says this "does not prove concurrency". AGENTS.md says SQLite tests do not prove MySQL concurrency and that payment-replay races need adversarial cases. This race now has no MySQL proof in Foundation.
- Row-lock assertions guarded by `if (DB::getDriverName() === 'mysql') assertStringContainsString('for update', ...)`, in licensing and offer authoring:
  - `BulkReplaceLicenseDraftSourceTest` (85 cases), line 553
  - `ReviewedOfferDraftTest` (64), line 548
  - `ReviewedLicenseDraftTest` (30), line 312
  - `LicenseTemplateAuthoringTest` (39), line 269
- `ProductionCheckoutExemptionAuthorityTest`, around line 70: the strict-numeric-storage refusal path is reached only on MySQL.
- `FreeGrantSchemaRecoveryTest`: MySQL trigger recovery steps, plus the seven `*Schema*Test.php` files that the evidence note already lists.

The CHANGELOG and README say in general terms that MySQL-specific behaviour outside the census and migration files is no longer proven. That is accurate. But the stated rationale, "run on MySQL only what SQLite cannot prove", is not achieved by this rule. "SQLite still runs everything" also reads as coverage for these cases when SQLite actually executes only the fallback.

Cost of a targeted fix: the six race/lock/strict files above plus the seven Schema files come to 277 cases (260 using FinalizationDatabaseMigrations). That is about 152 MySQL minutes at 35 s per case, or roughly 19 minutes per shard across 8 shards.

### F2 · Medium (disclosure). The selection removes an existing MySQL red signal

On main, the complete MySQL partition requires zero skips (`junit`: "MySQL did not execute every listed case"). Four unselected files call `markTestSkipped` on MySQL:

- `SupportAttachmentsTest` (28 cases) and `ServiceSupportAttachmentsTest` (8): setUp skips all of them unless `ATTACHMENT_NATIVE_ISOLATED=1`, which neither workflow sets (grep of `.github`, `.gitlab-ci.yml`, `scripts`, `phpunit.xml` found nothing).
- `CustomerListeningFreshnessTest::test_framework_reads_cannot_use_a_temporary_catalog_shadow_while_proof_reads_main` (SQLite-only).
- `FreeGrantSchemaRecoveryTest::test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target` (SQLite-only).

The repository code shows that a complete MySQL run on main would have been refused for these skips. No hosted run reached them: run 37921309772 timed out first. After this change the files are unselected, so the gate can go green while the native attachment-consumer paths stay unexecuted on MySQL. The evidence note does not mention this. This is not a hole in the "unexecuted on both engines" invariant, because SQLite runs these cases, but it turns a release-blocker signal into a pass.

### F3 · Medium (operational). The GitLab MySQL timeout is unchanged and likely too short

`.gitlab-ci.yml` passes the flag with 4 shards and keeps `backend-mysql` at `timeout: 90m`. Measured from real 4-shard discovery, the FinalizationDatabaseMigrations cases per shard are 271, 282, 244 and 298. At the change's own 35 s/test figure that is about 158, 164, 142 and 174 minutes. This is not a regression (the complete suite was longer), and GitLab runner speed may differ. The evidence note presents the 4-shard proof without this caveat.

### F4 · Low (test gap, redundant layer). Collector-level recomputation is not independently tested

Mutation M5 made the GitHub collector's MySQL expectation trust the executed set instead of the recomputed selection. M11 did the same in the GitLab collector. M12 dropped the GitHub MySQL `executed_cases` sum check. All three survived (rc 0). As far as I can tell they are redundant: per-shard `database_evidence` already recomputes the selection from each artifact's complete source list and the committed policies, requires all 8 (or 4) listings to cover it exactly, and requires the manifest hash to be identical across jobs. Removing them would therefore not open a hole, but the extra layer is untested. M12's check also predates this change.

### F5 · Info. The 150-minute GitHub timeout is reasonable

From real 8-shard discovery, FinalizationDatabaseMigrations cases per shard are 136, 154, 126, 170, 131, 98, 135 and 145, about 57 to 99 minutes at 35 s. That matches the workflow comment. 150 minutes leaves about 50% headroom on the worst shard and still bounds a hung test. Partition weights come from stale timings (63 of 146 selected files are untimed), so balance is approximate. The README and CHANGELOG claims (146/555 files, 1,434/7,633 cases, 409 files and 6,199 cases unselected, "not yet run on hosted MySQL") are accurate.

## Answers to the five questions

**1. Can a case go unexecuted on both engines while the gates pass? No path found.**

- **SQLite skips:** each shard's skipped identities must *equal* the census-method identities in that shard (`junit` `expected_skipped`, unchanged). So every SQLite skip is a census case.
- **Selection:** every census-owning file is selected (`native_selection` / `select_native`), and a census pair that is not discovered is refused by both the sharder and the verifier. MySQL must run exactly the selection with zero skips.
- **Data providers:** pairs are (class, method), so every dataset of a census method and every case of a selected file is included.
- **Moved census class:** ownership comes from discovery, so the move is followed. A renamed method is refused.
- **Forged or omitted selection block:** the key set requires `selection` for MySQL and forbids it for SQLite. The block must equal the recomputed one (the 9 field mutations are refused). Proof text is engine-specific.
- **Recomputation:** it is independent of artifacts and uses the collector checkout's committed policies, which are in `POLICY_FILES` (GitLab's `POLICY` includes them).
- **Engines agree on the census:** source censuses must be equal across engines, so engine-dependent providers fail closed.
- **Collectors:** both GitHub and GitLab add `sqlite_skipped ⊆ mysql_executed` (M1 and M4 kill it).
- **Policy changes:** `database-mysql-selection.json` is not on `ci-scope.py`'s docs-only allowlist, so changing it triggers full mode.

The remaining exposure is F1: a case that "executes" on SQLite only through a fallback branch.

**2. Is the SQLite path unchanged?** Yes. With real discovery at 23ff88e:

| Command (head script vs base script, same copy) | rc | Result |
| --- | --- | --- |
| `--shards=8 --prefix=phpunit-ci-mysql --timings=…mysql.json` | 0 / 0 | 18 files byte-identical (`diff -r`); 7,633 tests, 555 files |
| `--shards=2 --prefix=phpunit-ci-sqlite --timings=…sqlite.json` | 0 / 0 | 6 files byte-identical |
| head with `--mysql-native-selection`, 8 shards | 0 | 1,434 tests, 146 files; shards 183/162/190/170/207/206/170/146 |
| head with `--mysql-native-selection`, 4 shards | 0 | shards 392/320/360/362 |
| base 0f9b39c with `--mysql-native-selection` | 2 | argparse: unrecognized argument (flag did not exist) |
| head, `--prefix=phpunit-ci-sqlite --mysql-native-selection` | 1 | "only valid with --prefix=phpunit-ci-mysql" |

The flagged manifest's `source_*` fields and source listing are identical to the unflagged ones. Selection block: policy `232f4d29…6e32`, skip policy `9080acda…69b0`, 146 files and 1,434 cases, identity `b5efb9b3…c5ef`. An independent recomputation (`tools/indep_select.py`, which does not use repo code) gives the same numbers: 217/217 census pairs discovered, 101 census files with 932 cases (623 census cases), 49 pattern files with 589 cases, 4 overlapping, and the same identity hash.

In the verifier, the SQLite branch of `database_evidence`, its manifest keys and proof text, `junit`, and the SQLite collector expectation (now checked against the full census) are semantically unchanged or stricter.

**3. Self-tests and mutations.** Results are in evidence/selftests/summary.txt:

- Head: ci-scope 27, database-receipts 43, focused-tests 50, gitlab-database-receipts 26, gitlab-setup 12, gitlab-writer-feedback 7, php-test-runtime 5, phpunit-shards 45, workflow-cadence 14. All rc 0.
- `test-related-browser-stage.py`: rc 1 with the same 4 failures on head and base (identical failure-set hash `f5086709…`), so this is environmental.
- In a plain archive copy without git, workflow-cadence errors and related-browser-stage fails 9 on both sides. These are copy artifacts; the git-snapshot copies above are the valid runs.
- Base: database-receipts 34, gitlab 24, phpunit-shards 36 (all rc 0).

Mutations (each applied to the copy, run, then reverted):

| Mutation | Result |
| --- | --- |
| M1 drop GitHub skip⊆MySQL | killed |
| M2 drop manifest selection equality | killed (7 subtests) |
| M3 MySQL target = full census | killed |
| M4 drop GitLab skip⊆MySQL | killed |
| M6 sharder drops census files | killed |
| M7 sharder drops undiscovered-pair check | killed |
| M8 verifier drops census files | killed |
| M9 flag allowed for any prefix | killed |
| M10 old partition step name | killed |
| M5, M11, M12 | survived (F4) |

**4. Is the pattern honest?** The pattern accepts alphanumeric path segments only. No test filenames have other characters, and all tests are under `tests/Feature` or `tests/Unit`. It excludes `*Schema*Test.php`: 7 such files are unselected, matching the note's list. It also excludes `PublicDiscoveryIndexTest`. Native-only behaviour outside census and pattern is unproven: see F1 (driver-branch files) and F2 (MySQL-skip files). The 150-minute limit is reasonable (F5); GitLab's is not (F3). The README and CHANGELOG are accurate as far as they go; F1 and F2 need adding.

**5. Other protections.** None weakened. The only workflow change is the step rename, the flag and `timeout-minutes` 90 → 150; no other workflow is touched. Exact-SHA dispatch, audits, secret scans, required steps (the collector requires the renamed step; M10 is killed) and the matrix counts are intact. `POLICY_FILES` gains the selection policy. The aggregate collector still requires all ten jobs and artifacts.

## Conditions

- **C1 (owner decision, before merge):** either add the native race/lock/strict-storage proofs from F1 to the selection, or record them as an explicit owner-accepted risk in the evidence note and CHANGELOG. At minimum this means `BillingNativeDedupRaceTest` (1 case) and the four licensing/offer `for update` files. One way to add them is a reviewed explicit `files` list in `database-mysql-selection.json`. If they are omitted, name `BillingNativeDedupRaceTest` explicitly as a payment-race test with no MySQL proof.
- **C2 (disclosure):** record F2 in the evidence note: the 4 files and 38 cases that skip on MySQL, and that the selection removes the zero-skip refusal those cases would have triggered on main. Preferably open or track a blocker for the native attachment-consumer coverage.
- **C3:** raise or caveat GitLab `backend-mysql` `timeout: 90m` for the 4-shard selection (F3).
- **C4 (optional):** add a collector-only test for the recomputed MySQL expectation (F4).

## Evidence (beside this file)

- `evidence/<run>/{stdout,stderr,rc}.txt`: the six sharder runs.
- `evidence/noflag-byte-compare.txt`, `evidence/generated-manifest-sha256.txt`, `evidence/head-mysql8-flag-manifest.json`.
- `evidence/unselected-files.json`, `evidence/pattern-files.json`.
- `evidence/unselected-explicit-driver-branch-files.txt`, `evidence/unselected-driver-branches.txt`, `evidence/unselected-native-concurrency-candidates.txt`, `evidence/unselected-driver-sensitive-files.txt`.
- `evidence/selftests/{head,base}/*.txt` and `evidence/selftests/summary.txt`.
- `evidence/mutations/M*.txt`.
- The independent recomputation script is at `../tools/indep_select.py`, outside this directory.

## Not verified

- No MySQL or SQLite test executions. No hosted run.
- The F2 behaviour on main is inferred from code (the `junit` zero-skip rule plus unconditional `markTestSkipped` without the env var), not observed.
- The 35 s/test figure is the change's own and was not re-measured.
