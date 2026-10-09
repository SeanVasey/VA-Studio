# MySQL native selection for Foundation CI — 2026-10-09

Branch `harness/mysql-native-selection`, based on main `0f9b39ce5d59e2d579d11a8ca15153ced1f565b4`.
This records partition and self-test evidence only. No hosted or local MySQL test run has executed
the selection, so this is not Foundation acceptance.

## Why

Foundation run 37921309772 could not finish the MySQL matrix. 307 test files use
`tests/Support/FinalizationDatabaseMigrations` (a full `migrate:fresh` before every test and
`db:wipe` after), which costs about 35 s per test on MySQL 8.4 in CI. Eight 90-minute shards reached
about 25% of the 7,633 cases. Sean chose to run on MySQL only what SQLite cannot prove, and to keep
the complete suite on SQLite.

## What runs where

| Engine | Scope | Cases at `0f9b39c` |
| --- | --- | --- |
| SQLite (2 shards) | Complete suite; the 623 reviewed census cases skip | 7,633 listed, 7,010 executed |
| MySQL (8 GitHub / 4 GitLab shards) | Native selection, zero skips | 1,434 in 146 files |

The selection lives in `scripts/ci/database-mysql-selection.json`. It takes whole files:

- every file that owns at least one (class, method) pair in `scripts/ci/database-sqlite-skips.json`
  (217 pairs in 101 classes): 101 files, 932 cases, 623 of them the SQLite-skipped census cases;
- every file whose repository path matches
  `^tests/(?:Feature|Unit)/(?:[A-Za-z0-9]+/)*[A-Za-z0-9]*Migration[A-Za-z0-9]*Test\.php$`:
  49 files, 589 cases.

Four files are in both groups, giving 146 files and 1,434 cases. Every case of a selected file
runs, including methods the census does not list.

**No longer exercised on MySQL in Foundation CI:** the other 409 files (6,199 cases). They still
run on SQLite. MySQL-specific behaviour in those files, such as strict-mode casts, collation,
index-length limits or locking that lacks a census entry, is no longer proven on MySQL there. The
pattern does not match files named `*Schema*Test.php`. Of the 16 such files, 9 are selected because
they own census methods. These 7 are not selected:

- `tests/Feature/FreeGrantSchemaRecoveryTest.php`
- `tests/Feature/PrivateProductDraftSchemaTest.php`
- `tests/Feature/ProductionMemberOriginals/MemberActivationCouplingSchemaTest.php`
- `tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php`
- `tests/Feature/ServiceProjectSchemaTest.php`
- `tests/Feature/SupportAttachmentSchemaTest.php`
- `tests/Unit/SchemaQualifierDelimiterTest.php`

Adding a census entry or a pattern change brings a file back, and both go through review.

## How it is enforced

- `scripts/ci/phpunit-shards.py --mysql-native-selection` is accepted only with
  `--prefix=phpunit-ci-mysql`. Discovery, the cross-file dependency refusal and the manifest's
  `source_*` census stay complete. Only the selected files are partitioned and weighted. Each shard
  configuration excludes every file it was not assigned, and re-discovery must cover every selected
  case, file and group exactly once. The manifest's `proof` says so and a `selection` block records the
  policy path, both policy hashes, the selected file and case counts and the case identity hash. An
  empty selection or a census pair that PHPUnit no longer discovers is refused. Without the flag the
  output is byte-for-byte what it was.
- `scripts/ci/database-receipts.py` adds the selection policy to `POLICY_FILES`. For MySQL,
  `database_evidence` recomputes the selection from the complete source inventory and the committed
  policies. It does not trust the manifest. The manifest's selection block must equal the recomputed
  one, the shards must cover exactly the selected cases, files and groups once, and untimed files must
  be selected files. MySQL skips stay at zero. SQLite is unchanged.
- The GitHub collector requires SQLite executions to equal every source case once (unchanged) and
  MySQL executions to equal exactly the selected cases once, with the summed MySQL `executed_cases`
  equal to the selection size. It also requires every SQLite-skipped identity to be in the
  MySQL-executed set, so no case can go unexecuted on both engines. The MySQL partition step is renamed
  `Prove the native-selection MySQL test partition`, and the collector requires that name.
- The GitLab collector (`scripts/ci/gitlab-database-receipts.py`) applies the same invariants. In
  `.gitlab-ci.yml` the shared database template passes the flag only when `DB_CONNECTION` is `mysql`.

## Real discovery (no database)

Commands ran in `/home/user/wt-mysel` with PHP 8.4.26 and PHPUnit discovery only. All generated
`phpunit-ci-*` files were deleted afterwards.

```
python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql \
  --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection        # rc 0
```

```
warning: 63 test file(s) have no entry in scripts/ci/phpunit-timings-mysql.json and use the fallback weight; ...
Proved 1434 selected MySQL-native tests in 146 files (of 7633 discovered tests in 555 files) across 8 nonempty shards.
Shard 1: 183 tests; 17 complete files; estimated 16m 08s.
Shard 2: 162 tests; 17 complete files; estimated 16m 07s.
Shard 3: 190 tests; 18 complete files; estimated 16m 07s.
Shard 4: 170 tests; 18 complete files; estimated 16m 10s.
Shard 5: 207 tests; 22 complete files; estimated 16m 06s.
Shard 6: 206 tests; 18 complete files; estimated 16m 09s.
Shard 7: 170 tests; 18 complete files; estimated 16m 10s.
Shard 8: 146 tests; 18 complete files; estimated 16m 07s.
```

Manifest `selection` block:

| Field | Value |
| --- | --- |
| `policy_sha256` | `232f4d29f2b218f8fe44749860237ecda3a72c43723a4ef5181b7d18468e6e32` |
| `sqlite_skip_policy_sha256` | `9080acdabcfc425ac37dcebee23c9f9be00bcd5a6790404977ba41c6994569b0` |
| `files` / `test_cases` | 146 / 1,434 |
| `case_identity_sha256` | `b5efb9b359662c26adc4d977f89f55af2bad94b08f01f97c6ad821662562c5ef` |

The complete source census in the same manifest is 555 files, 7,633 cases, case identity
`34cb39ad31b40c5f12b5d826bf1837efcfc2fc69183188307fe35194b86aa7b2`.

The same command with `--shards=4` (the GitLab count) proved 1,434 cases across 4 shards of 392, 320,
360 and 362 cases (rc 0).

Cross-check: `database_evidence` from `database-receipts.py` accepted the real selected manifest and
inventories, with a synthesized passing JUnit file for shard 1 (183 cases). Its independent
recomputation gave 146 files and 1,434 cases, equal to the manifest. Given the real unflagged
8-shard evidence, it refused the complete MySQL partition (`Partition loses or duplicates cases,
files or groups`).

### Without the flag, the output is unchanged

The new script and main's script (`git show 0f9b39c:scripts/ci/phpunit-shards.py`, run from a
temporary copy in the same checkout) each ran without the flag:

- `--shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json`: both rc 0,
  7,633 tests in 555 files, all 18 generated files byte-identical (`diff -r`).
- `--shards=2 --prefix=phpunit-ci-sqlite --timings=scripts/ci/phpunit-timings-sqlite.json`: both rc 0,
  all 6 generated files byte-identical.

`--mysql-native-selection` with `--prefix=phpunit-ci-sqlite` was refused (rc 1) before discovery.

### The duration estimate is not reliable

The 16-minute estimates come from `phpunit-timings-mysql.json`, which predates run 37921309772.
It also lacks 63 of the 146 selected files (656 cases), which take the 3,830 ms fallback. The
timings were not regenerated. Of the 1,434 selected cases, 1,095 are in files that use
`FinalizationDatabaseMigrations`, with 136, 154, 126, 170, 131, 98, 135 and 145 such cases in shards
1 to 8. At about 35 s each, those cases alone would take about 57 to 99 minutes per shard. The busiest
shard could therefore exceed a 90-minute job timeout, so the MySQL job limit is raised to 150 minutes
(still far below GitHub's 6-hour default for a hung test; total compute is unchanged). Refreshing MySQL
timings from actual selected-run JUnit after the first hosted run should replace this estimate.

## Self-tests

| Command | Result |
| --- | --- |
| `python3 scripts/ci/test-phpunit-shards.py` | rc 0, 45 tests |
| `python3 scripts/ci/test-database-receipts.py` | rc 0, 43 tests |
| `python3 scripts/ci/test-gitlab-database-receipts.py` | rc 0, 26 tests |
| `python3 scripts/ci/test-ci-scope.py` | rc 0, 27 tests |
| `python3 scripts/ci/test-workflow-cadence.py` | rc 0, 14 tests |
| `python3 scripts/ci/test-focused-tests.py` | rc 0, 50 tests |
| `python3 scripts/ci/test-gitlab-setup.py` | rc 0, 12 tests |
| `python3 scripts/ci/test-gitlab-writer-feedback.py` | rc 0, 7 tests |
| `python3 scripts/ci/test-php-test-runtime.py` | rc 0, 5 tests |
| `python3 scripts/ci/test-related-browser-stage.py` | rc 1, 4 of 12 fail; see below |
| `node --test scripts/ci/apply-playwright-webkit-offline-backport.test.mjs` (Node 24.21.0) | rc 0, 33 pass |

`test-related-browser-stage.py` fails the same 4 cases (isolated PHP bootstrap and runner refusal)
with every change stashed, on unchanged `0f9b39c` in this checkout. The failures come from this
environment, not from this change.

The new adversarial cases cover these refusals:

- a MySQL partition that loses a selected case;
- a MySQL partition that adds an unselected file, or partitions the complete census;
- a manifest selection block that differs from the recomputed one, field by field, or is missing;
- untimed MySQL files outside the selection;
- a SQLite manifest that carries a selection, or a SQLite partition narrowed to the selection;
- a forged archive set whose selection omits a census file, which only the SQLite-skip ⊆
  MySQL-executed invariant can catch (GitHub and GitLab collectors);
- the old MySQL partition step name.

Fixtures use the committed selection pattern.

## Not verified

- No MySQL test executed the selection, locally or hosted. Whether the 1,434 cases pass on MySQL 8.4,
  and how long each shard takes, is unknown until a manual Foundation dispatch on the reviewed exact
  SHA.
- The GitHub and GitLab collectors ran only against synthetic fixtures, not real artifacts.
- The pattern and census reflect `0f9b39c`. A new MySQL-only test that is neither in the census nor
  named `*Migration*Test.php` would run only on SQLite.
