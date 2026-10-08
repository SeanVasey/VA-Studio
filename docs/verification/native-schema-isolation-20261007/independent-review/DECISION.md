# Independent review: native schema isolation and identity inspection cost (PR #50)

- Reviewed SHA: `61b8ee173b6d331610215a99bf65202edc90e042` (`harness/native-schema-isolation`).
- Code head: `c7f9987b`. `61b8ee17` changes docs only: `git diff --stat c7f9987b 61b8ee17 -- app database tests scripts config routes` is empty.
- Base: `e86381bf139b4351bba534f3fe0b26f4f8efbdd0` (merge-base with `origin/main`).
- Change under review:
  - `5dd4e35b` fix(migrations): isolate server-wide dependency scans to the selected schema.
  - `2bbc8f8a`, `58baafbb` and `489369c7` perf(identity).
  - Two tests and the SQLite census entry.
- The 253 commits (`49b64262`, `581ac049`, `c7f9987b`) carry no diff against `origin/main` (`73c898db`) on their paths. See `review-evidence/source-identity.txt`.
- Reviewer: independent reviewer agent. It authored no lane commit and changed no app code, flag or registration. Mutations were temporary and reverted (see below). Nothing was committed.
- Date: 2026-10-07 (UTC).

## Environment

- **Worktree.** A detached review worktree, `/home/user/VA-Studio-review-trigscan`, made with `scripts/dev/mkworktree.sh` at `61b8ee17`. It has the main checkout's locked `vendor/` symlinked and its own Composer autoload.
- **Runtime.** PHP 8.4.26 and PHPUnit 12.5.34. PHPUnit ran as `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`, never `php artisan test`.
- **Database.** A private `mysqld` 8.4.11 started with `--no-defaults` on 127.0.0.1:3497 (`lower_case_table_names=0`). Its datadir and socket were under `$scratchpad/review-trigscan-mysql/` and its schema was `vaseyaudio_review_trigscan`.
  - The shared :3306 server was not touched, and neither were the other agents' instances on :3471 and :3493.
  - It was shut down cleanly and its datadir removed. The lifecycle is in `review-evidence/native/private-instance-lifecycle.txt`.
- **Load.** The host load average was 5 to 10 from other lanes, so wall times are noisy. Statement counts are deterministic.

## Decision

**APPROVE WITH CONDITIONS** for a development merge.

The isolation fix does what it claims. In every case I tried, an object in another schema that can actually reach the selected schema's owned tables is still refused, with the original exact message:

- a qualified name with backticks, comments, newlines, ANSI quotes or upper case;
- a view that MySQL stored already qualified;
- a dynamic-SQL procedure.

Objects whose unqualified names MySQL resolves to their own schema are admitted. I checked this by executing them: they hit the peer's table, not ours.

The three perf commits change no refusal rule:

- Every batched lookup returned exactly the rows of the original single-name statement: 21 statements, 68 requested names, 608 rows, 0 mismatches, including case and accent dictionary aliases.
- The 110-statement bound does not depend on how many schemas or objects the server holds.

No finding is Medium or above, and every finding fails closed. The two Low findings are a residual over-refusal of the kind this PR fixes (R-1) and a gap in what the tests pin (R-2). Neither blocks the merge.

This decision does **not** approve:

- the paid252 60 s journey, which the lane README records as still failing;
- the section 4 per-frame cache proposal;
- any change to deadlines, refusal floors, authorization lifetimes or audit settings;
- Foundation acceptance.

## Findings

| ID | Severity | Area | Finding | Recommendation | Evidence |
| --- | --- | --- | --- | --- | --- |
| R-1 | Low (availability; fails closed) | Qualifier match, all three guards | The selected database name counts as named when the next character is outside `[a-z0-9_]`. A peer schema whose name extends the selected name with `-`, `$` or a non-ASCII character still blocks all three guards, through its own views. MySQL always stores a view definition qualified (`` `<db>-rv4`.`t` ``), and that text contains `<db>` followed by `-`. This is the failure class the PR fixes: a parallel lane named `vaseyaudio_x-2` would again block `migrate:fresh` for `vaseyaudio_x`. Peers named `<db>_x` and `vaseyaudio_review` (a prefix of the selected name) are correctly admitted. | Match the selected name as a whole identifier token followed by optional quote, whitespace or comments and then `.`. Alternatively, record in the parallel-lane runbook that peer schema names must extend the selected name only with `[a-z0-9_]`. Add a hyphenated-peer regression either way. | `probes/probes-head-v2.txt` (P4: `hyphen-extension` and `dollar-extension` refused by capability, inquiry and identity; `longer-underscore` and `shorter-prefix` admitted) |
| R-2 | Low (test gap) | Case of the qualifier | Matching the qualifier case-insensitively is what keeps an upper-case `` `VASEYAUDIO_X`.`t` `` refused on a `lower_case_table_names=1/2` server, where it names the same schema. The production host is undecided (U-02). No lane test pins this. Mutation M3 makes the 243 database-name match case-sensitive while keeping `prepare` case-insensitive. All 25 `NativeSchemaIsolationTest` cases still pass under M3; only reviewer probe P3 fails. The same pattern in the capability and identity helpers is unpinned for the same reason. | Add an upper-case-qualifier case (trigger and routine) per guard to `NativeSchemaIsolationTest`. | `mutations/M3_inquiry_case_sensitive_qualifier--lane-isolation.txt` (OK 25/235), `--probes-p3.txt` (`P3 inquiry upper-case-qualifier: admitted`) |
| I-1 | Info (over-refusal, conservative) | Case-variant peer | On `lower_case_table_names=0`, a peer `VASEYAUDIO_REVIEW_TRIGSCAN` is a different schema. Because the schema comparison uses `strtolower`, its unqualified same-named triggers are classified as local and refused by all three guards. This is the safe direction and consistent with R-2. | None required. Document it with R-1. | `probes-head-v2.txt` (P5) |
| I-2 | Info (pre-existing, unchanged from base) | `EVENTS` not scanned | None of the three scans reads `information_schema.EVENTS`. A peer event `DO DELETE FROM <db>.<owned table>` is admitted by all three guards, at base as at head, since base read only TRIGGERS, VIEWS and ROUTINES. Owned BEFORE guards still fire on event DML, so this is a missed dependency for adoption and rollback, not a write bypass. | In a separate change, add `EVENT_DEFINITION` with the same per-schema classification. | `probes-head-v2.txt` (P6) |
| I-3 | Info (pre-existing) | Literal table name prerequisite | Every rule, the new `PREPARE` rule included, applies only when the owned table name appears literally (`references($sql, $tables)` is unchanged). A procedure that assembles both the qualifier and the table name at run time (`CONCAT('pi_','origins')`) is not refused, at base as at head. Conversely, the lane's "peer dynamic routine" case builds an unqualified name that resolves to the peer, and it is refused conservatively. | None for this PR. State the limitation alongside the `PREPARE` rule. | Code: `CapabilityMigrationOwnership::dependsOn`, `IdentityMigrationOwnership::dependsOn`, 243 `dependsOnTable` |
| I-4 | Info (pre-existing) | Dictionary visibility | `information_schema` lists only objects the connected account can see. With a least-privilege production account, foreign triggers, views and routines are invisible to these scans (before and after this change). All runs here used root. | Keep in mind when choosing the production account (U-02). | MySQL semantics; not probed |
| I-5 | Info | Exception type in tests | The batching helpers throw `LogicException` (`Unbatched identity dictionary name`, `Unsupported identity dictionary lookup`), the same class as the identity refusal. Several existing refusal tests catch bare `LogicException`. Fail-closed. Under mutation M4, the lane's unicode alias test caught the defect only because the guard DDL later failed with `PDOException 1359` at `247000...php:24`. By then the inspection had admitted and `up()` had already executed owned DDL. | Assert the exact refusal message in identity refusal tests, or throw a distinct type from the helpers. | `mutations/M4_identity_binary_prefilter--lane-unicode-alias.txt` |
| I-6 | Info | Cost bound semantics | The bound is a real invariant with respect to the server's schemas and objects. It does grow by one statement per namespace or owned name, through the per-name `SHOW CREATE TABLE` proofs; headroom is 6 at 104. Wall time still scales with server object volume, because the three server-wide scans read every row, and the bound does not measure that. | None required. Do not cite the test as a latency bound; the paid252 budget remains open per the lane README. | `probes-head-v2.txt` (P8: 104 statements with no peers, and 104 with 2 cloned peers: 1,421 server triggers, 200 views, 148 routines, 7 schemata); `native/IdentityInspectionCostTest.txt` (104 statements) |

## Answers to the review questions

### (a) Can a foreign schema get a dependency past the classification?

I found no static route.

- **Views.** A view created in a peer schema while the selected schema was the default, with an unqualified name, is stored by MySQL as `` select `vaseyaudio_review_trigscan`.`<t>`.`id` ... from `vaseyaudio_review_trigscan`.`<t>` ``. It is refused by its own guard only (P1). The cross-check matrix shows that the other two guards admit it.
- **Routines and triggers.** A routine or trigger created in a peer schema the same way keeps its unqualified body. Executing it fails with `1146 Table 'vaseyaudio_review_trigscan_rv2.production_identity_origins' doesn't exist` (P2). MySQL resolves the name to the object's own schema, as the fix assumes, so admitting it is correct.
- **Qualifier spellings.** Each of these is refused in all three guards (P3):
  - `` `<db>` /* note */ . `<t>` ``;
  - `<db>` newline `.` newline `<t>`;
  - `-- comment` then `` `<db>`.`<t>` ``;
  - ANSI `"<db>"."<t>"`;
  - an upper-case qualifier.
- **Dynamic SQL.** A peer dynamic-SQL procedure is refused by the `PREPARE` rule (lane tests). Triggers and stored functions cannot prepare statements.
- **Remaining routes.** All are pre-existing and unchanged from base: events (I-2), names assembled at run time (I-3) and privilege-limited visibility (I-4).

### (b) Does the qualifier match handle quoting, case and prefixes?

- **Quoting.** Backtick-quoted, unquoted and ANSI-quoted forms all match (P3). The boundary class is `[a-z0-9_]` under `/i`, so quotes, dots, spaces and comment markers are boundaries.
- **Case.** Matching is case-insensitive. On `lower_case_table_names=0` this over-refuses (I-1); on 1/2 it is required, and no lane test pins it (R-2).
- **Prefixes.** `vaseyaudio_review_trigscan_rv4` (longer, `_`) and `vaseyaudio_review` (shorter) are correctly not treated as the selected schema (P4). Mutation M2, a raw `stripos` substring match, is caught by the lane isolation test and by P4.
- **Other extensions.** Extensions with `-`, `$` or non-ASCII characters are over-refused (R-1).

### (c) Can batching or prefiltering return fewer rows than the per-name queries?

No, as far as I could find.

- **Name sets.** `$namedIndexes` and `$namedConstraints` are requested over `$reserved`, and `$reserved` is built from exactly the `unique` and `foreign` keys the loop reads. `$namedTables` and `$namedTriggers` request supersets of the names they serve. A name outside a batch throws in `named()` (fails closed). An unrequested key in `lookups()` would raise a PHP warning, which Laravel turns into an exception.
- **Branch predicate.** Each `UNION ALL` branch applies the unchanged predicate to a `NO_MERGE` CTE. The derived column keeps the dictionary column's collation.
- **Prefilter.** It is `<same expression> IN (<same names>)`, which compares with the same collation as `=`.
- **NULL.** The looked-up columns (`TABLE_NAME`, `TRIGGER_NAME`, `INDEX_NAME`, `CONSTRAINT_NAME`, `EVENT_OBJECT_TABLE`, `ROUTINE_NAME`, `EVENT_NAME`) are never NULL. A NULL would match neither `=` nor `IN`.
- **Projected columns.** The narrowed columns cover every key the checks read. I cross-checked each `$rows[0][...]`, `$row[...]`, `$part[...]` and `$key[...]` access.
- **Empirical check (P7, v2).** The probe ran every one of the 21 lookup statements in the file (17 literals plus the 4 expansions of the `$dictionary` template). It compared the head's single-name statement with `lookups()` over the names the inspection requests plus one absent name. Before running it, I created these aliases in the selected schema:
  - a case-alias table `Customer_Accounts`;
  - an upper-case index `PIA_ADDRESS_UNIQUE`;
  - an accent-alias trigger `pia_address_uniqué`, which the single-name `LOWER(TRIGGER_NAME)=?` does return.

  Result: 608 rows, 0 mismatches. A superset run including the alias spellings gave 636 rows, 0 mismatches.
- **Mutation M4.** M4 makes the prefilter `BINARY`. P7 v2 then fails, missing the accent row for `pia_address_unique`. The lane unicode alias test also goes red, as described in I-5.
- **A probe defect of my own (retained).** P7 v1 put the alias spellings into the requested set, which let a binary prefilter keep the row, so v1 passed under M4. See `mutations/M4_identity_binary_prefilter--probes-p7-v1-alias-in-name-set.txt`.

### (d) Is the 110-statement bound a real invariant?

Yes, with respect to schemas on the server. It does not track owned-name growth or wall time (I-6).

- **Measurement.** P8 measured 104 statements before and after adding two full peer clones (every table, all guards, and 50 views and 50 routines each).
- **What can and cannot change the count.**
  - Every server-wide read is a single statement.
  - `DATABASE()` is read once per inspection.
  - No loop issues a statement per dictionary row.

## Verified claims (with evidence)

- **Reproduction premise.** The base scans were unfiltered, and the three owners are the only server-wide TRIGGERS, VIEWS and ROUTINES readers. Checked by grep at `61b8ee17` and at `origin/main`, including dynamically named catalogs. `MemberGrantSchema` and `MembershipSchema`, new on main since base, filter every read to `BINARY ... = BINARY DATABASE()`. See `review-evidence/main-drift.txt`. `main` has not changed any lane file since base, and `git merge-tree --write-tree origin/main 61b8ee17` is clean (exit 0).
- **Refusal rules unchanged for local objects.** In all three diffs, an object in the selected schema still goes through the original `references()` test, and the extra-trigger-on-owned-table rule is kept. NULL definitions are still refused in every schema, because `references()` rejects non-strings before classification. The FK scans are textually unchanged apart from the projected columns.
- **Exact original messages.** These come from the lane tests (24 refusal cases) and from my probes P1, P3, P4 and P5. The probes report the original strings:
  - `Unexpected production capability external view reference; rollback refused before schema changes.`
  - `Unexpected inquiry notification external trigger reference; ...`
  - `Unexpected production identity schema or retained evidence; refused before DDL.`
- **Selected catalog unchanged by every probe.** Each probe compared the selected schema's tables, triggers and routines before and after (`assertSame($before, $this->catalog())`). Before shutdown, the instance listed only `vaseyaudio_review_trigscan` plus the system schemata.
- **Identity cost.** `IdentityInspectionCostTest` reported 104 statements (bound 110) and the admission equal to the ordinary connection's. The lane's 876 → 104 history was not re-measured.
- **Pint.** `vendor/bin/pint --test` over the five changed PHP files: `passed` (`review-evidence/pint.txt`).
- **SQLite census.** `NativeSchemaIsolationTest` and `IdentityInspectionCostTest` both appear in `scripts/ci/database-sqlite-skips.json`. On SQLite: 26 tests, 26 skipped, exit 0 (`review-evidence/sqlite/`).

### Native runs at `61b8ee17` (private 8.4.11, port 3497)

Counts are from each run's JUnit top-level `testsuite`. Exit codes are PHPUnit's own: `run.sh` captures `$?` directly after the `timeout php ...` command.

| Selection | Exit | Tests | Assertions | Failures | Errors | Skipped | Wall (s) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `tests/Feature/NativeSchemaIsolationTest.php` | 0 | 25 | 235 | 0 | 0 | 0 | 312 |
| `tests/Feature/ProductionIdentity/IdentityInspectionCostTest.php` (104 statements) | 0 | 1 | 6 | 0 | 0 | 0 | 73 |
| `tests/Feature/ProductionIdentity/ProductionIdentityMigrationTest.php` (whole file) | 0 | 5 | 19 | 0 | 0 | 0 | 715 |
| `tests/Feature/ProductionIdentity/ProductionIdentityDependencyAdmissionTest.php` (whole file) | 0 | 9 | 48 | 0 | 0 | 0 | 1,009 |
| **Lane total** | all 0 | **40** | **308** | **0** | **0** | **0** | 2,109 |
| Reviewer probes `probes/ReviewerSchemaIsolationProbeTest.php` (final, v2) | 0 | 8 | 3,074 | 0 | 0 | 0 | 105 |

Earlier probe runs, retained:

- attempt 1: a PHP fatal, because the helper was named `count()`; no test ran;
- v1 head run: 8/1,646, exit 0.

All runs show `tree=61b8ee17 dirty_app_db=0` in `native/summary.txt` and `probes/summary.txt`.

### Mutations (each reverted with `git checkout -- app database`, then `git diff --quiet -- app database` exit 0)

| ID | Mutation | Lane test | Reviewer probe |
| --- | --- | --- | --- |
| M1 | Identity `dependsOn` by schema only (drops the qualifier and `PREPARE` rule) | `NativeSchemaIsolationTest` exit 1: **4 failures**: `identity: peer trigger / routine / view naming this schema`, `identity: peer dynamic routine` (25/219) | P1+P3 exit 1: **2 failures** (`P1 identity view vs identity`, `P3 identity backtick+comment: admitted`) |
| M2 | Capability qualifier as a raw `stripos` substring (prefix-unsafe) | `NativeSchemaIsolationTest` exit 2: **1 error**: the isolation case, `Unexpected production capability external view reference` at migrate (25/233) | P4 exit 1: **1 failure** (`longer-underscore capability` refused) |
| M3 | 243 database-name match case-sensitive (`prepare` still case-insensitive) | `NativeSchemaIsolationTest` exit 0: **not caught** (25/235). This is R-2. | P3 exit 1: **1 failure** (`P3 inquiry upper-case-qualifier: admitted`) |
| M4 | Batched prefilter compared with `BINARY` | Unicode alias test exit 2: **1 error** (`PDOException 1359` at 247 `up()` line 24, after the inspection admitted). Cost test exit 0, still 104 statements. | P7 v2 exit 1: **1 failure** (accent row missing for `pia_address_unique`). P7 v1 exit 0 is retained as a probe-design defect. |

Each mutation's diff is in `mutations/<ID>.diff`, and `mutate.py` applies it. After all reverts, the three code files hash to the reviewed values (`mutations/summary.txt`), and `git status --short -- app database` is empty.

## SHA-256 at `61b8ee17`

Each is identical in `git show 61b8ee17:<path>`, in the worktree and at `489369c7` (`review-evidence/sha256-reviewed-files.txt`).

| Path | SHA-256 |
| --- | --- |
| `app/Domain/Commerce/ProductionPolicy/CapabilityMigrationOwnership.php` | `b0002097caa3575d7e76d48a8fd4d39134cfe0bf5c90f38c33374d4c7ab02040` |
| `app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php` | `1715515c80d8333dd930eb0053005779a5fa19d55315a69b2d8b72192198531b` |
| `database/migrations/2026_10_07_243000_inquiry_notification_intents.php` | `ee11f3595d0f9535f59f0e06bf1450f685150b07db015ae360e7eaf597db1387` |
| `tests/Feature/NativeSchemaIsolationTest.php` | `d0d2a8af0904dda719d9b2272d1cfb1b6cac9d043a223776d4b0766dec38da2d` |
| `tests/Feature/ProductionIdentity/IdentityInspectionCostTest.php` | `d745a4066d120342b2821995f8f61e6708bd8033091550a54d23a3cbdeba47d3` |
| `scripts/ci/database-sqlite-skips.json` (census) | `94f0666a4226e2a8988f30028f51453f24e5c4a20ec0b6fa09ba13b6aa09a933` |
| Reviewer probe `review-evidence/probes/ReviewerSchemaIsolationProbeTest.php` (v2, final) | `3fb133e1f69c53c52fe79f1d756558e7324977c0560ff828cf4b3d29633472eb` |

## Conditions

None blocks the development merge. Each attaches to the step named.

1. **R-2, before production host selection (U-02) or any reliance on the guards on a `lower_case_table_names` 1/2 server.** Add upper-case-qualifier regressions for the capability, inquiry and identity guards, so that the case-insensitive match is pinned. M3 must then fail a lane test.
2. **R-1, before the next parallel-lane native batch on a shared daemon.** Do one of:
   - make the qualifier match token-aware and add a hyphenated-peer regression; or
   - record in the native runbook that peer schema names may extend the selected name only with `[a-z0-9_]`.
3. **I-6, carried forward from the lane README.** Do not cite `IdentityInspectionCostTest` as a latency bound. The paid252 60 s journey remains open and needs the section 4 owner decision. This review does not approve that cache.

## Not reviewed

- The 253 commits' content. They carry no diff against `origin/main`, so they are out of scope here.
- Re-measurement of the lane's base and intermediate statement counts (876, 1,463 and 140), the 253 initialize timings and the paid252 profiles.
- The archived membership canary. It was not re-run.
- These lane selections, which I did not run natively: `ProductionIdentityRuntimeTest`, `ProductionIdentityCommittedFrameTest`, `IdentityHistoricalCommittedReceiptTest`, `ProductionBuyerAssentObservationMigrationTest`, `ProductionFeatureNativeAdmissionTest` and the selected `InquiryNotificationMigrationTest` methods.
- SQLite lane directories other than the two native-only files.
- Behaviour on `lower_case_table_names` 1/2 servers and with a least-privilege account (all runs: lctn=0, root).
- Full native directories, the full suite and Foundation CI (cost policy).

## Commands and results

From `/home/user/VA-Studio-review-trigscan`, with the native environment `APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3497 DB_DATABASE=vaseyaudio_review_trigscan DB_USERNAME=root DB_PASSWORD=<ci-only> DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`. `review-evidence/native/run.sh` sourced it from the scratchpad file `review-trigscan-mysql/native.env`, which was removed with the datadir.

```
scripts/dev/mkworktree.sh /home/user/VA-Studio-review-trigscan 61b8ee17
mysqld --no-defaults --initialize-insecure --datadir=$scratchpad/review-trigscan-mysql/data
mysqld --no-defaults --port=3497 --bind-address=127.0.0.1 --socket=$scratchpad/review-trigscan-mysql/s ...   # lifecycle file
review-evidence/native/run.sh NativeSchemaIsolationTest tests/Feature/NativeSchemaIsolationTest.php                       # exit 0, OK 25/235
review-evidence/native/run.sh IdentityInspectionCostTest tests/Feature/ProductionIdentity/IdentityInspectionCostTest.php  # exit 0, OK 1/6, 104 statements
review-evidence/native/run.sh ProductionIdentityMigrationTest tests/Feature/ProductionIdentity/ProductionIdentityMigrationTest.php                     # exit 0, OK 5/19
review-evidence/native/run.sh ProductionIdentityDependencyAdmissionTest tests/Feature/ProductionIdentity/ProductionIdentityDependencyAdmissionTest.php # exit 0, OK 9/48
OUT=.../probes review-evidence/native/run.sh probes-head-v2 review-evidence/probes/ReviewerSchemaIsolationProbeTest.php    # exit 0, OK 8/3074
review-evidence/mutations/run-mutations.sh                                                                                 # M1-M4 as tabled; each reverted
python3 review-evidence/mutations/mutate.py M4_identity_binary_prefilter; run.sh ... --filter test_p7_; git checkout -- app database   # P7 v2: exit 1
vendor/bin/pint --test <5 changed PHP files>                                                                               # passed
$P tests/Feature/NativeSchemaIsolationTest.php tests/Feature/ProductionIdentity/IdentityInspectionCostTest.php (SQLite defaults)  # exit 0, 26 skipped
git merge-tree --write-tree origin/main 61b8ee17                                                                           # exit 0
mysqladmin --no-defaults -h127.0.0.1 -P3497 -uroot shutdown; rm -rf $scratchpad/review-trigscan-mysql
```

## Evidence index (`review-evidence/`)

- `source-identity.txt`: SHAs, merge-base, the 253 no-diff check, the docs-only head check and the lane diff stat.
- `main-drift.txt`: `main` movement since base, the trial merge, and the grep of server-wide and dynamically named dictionary reads at `origin/main` and head.
- `sha256-reviewed-files.txt`: hashes at `61b8ee17`, the worktree and `489369c7`.
- `pint.txt`: the Pint result.
- `native/`:
  - `run.sh` (runner);
  - `summary.txt` (ledger with PHPUnit exit codes);
  - JUnit and text for the four lane selections;
  - `private-instance-lifecycle.txt`.
- `probes/`:
  - `ReviewerSchemaIsolationProbeTest.php` (P1 to P8, final v2);
  - `probes-head-v2.*` (final run);
  - `probes-head-v1.*` (P7 v1);
  - `attempt1-harness-fatal.*`;
  - `summary.txt`.
- `mutations/`:
  - `mutate.py` and `run-mutations.sh`;
  - one `.diff` per mutation;
  - JUnit and text per mutation and selection, including the retained P7 v1 run under M4;
  - `summary.txt` (revert proofs and post-revert hashes);
  - `driver-output.txt`.
- `sqlite/`: the SQLite run of the two native-only files (26 skipped).

## Cleanup

- **Mutations.** All were reverted. `git diff --quiet -- app database` returned exit 0 after each, and the post-revert hashes equal the reviewed values.
- **Worktree.** `git status --short` in the review worktree shows only `?? docs/verification/native-schema-isolation-20261007/independent-review/`.
- **Peer schemas.** Every peer schema the probes created (`_rv1` to `_rv8b`, `_rv4`, `vaseyaudio_review`, `-rv4`, `$rv4`, the upper-case variant) and the lane test's `_other` were dropped in teardown. Before shutdown the instance listed `information_schema, mysql, performance_schema, sys, vaseyaudio_review_trigscan`.
- **Private mysqld.**
  - pid 18644 on :3497 was shut down with `mysqladmin shutdown`; `err.log` ends `MySQL Server - end.`
  - Afterwards `/proc/18644` was absent, there was no LISTEN on 3497 (`0DA9`) in `/proc/net/tcp`, and no `mysqld` process had `port=3497`.
  - `$scratchpad/review-trigscan-mysql` (223M) was removed.
  - The other agents' daemons on :3471 and :3493 and the shared :3306 were not touched.

## Addendum 1: conditions 1 and 2, delta `53f4783f..b47de6b6`

- **Reviewed delta.** `53f4783f..b47de6b6` on `origin/harness/native-schema-isolation`: one commit, `b47de6b6` "fix(migrations): match a schema qualifier only as a complete identifier followed by a dot". `53f4783f` is this review, committed by the integration owner; it has no code change against `61b8ee17`.
  - The code delta touches the three matchers, `NativeSchemaIsolationTest` and the census (+1 entry). `IdentityInspectionCostTest` is unchanged. Scope and hashes are in `review-evidence/addendum1/sha256-and-scope.txt`.
- **Method.** The review worktree was moved to `git checkout --detach b47de6b6`. The `independent-review/` directory, now tracked and byte-identical to my untracked copy (54 files, 0 differences), was kept.
- **Database.** A fresh private `mysqld` 8.4.11 with `--no-defaults` on 127.0.0.1:3613, with `--socket=` empty and schema `vaseyaudio_review_trigscan`. The shared :3306 server and ports 3531, 3555, 3567 and 3589 were not touched.
- **Date.** 2026-10-08 (UTC).

### Decision for the delta

**APPROVE.** Conditions 1 (R-2) and 2 (R-1) are **closed**. No new finding is Low or above. The remaining notes are Info only.

### What changed

- **Matcher.** The three matchers now share one expression, byte-identical in all three files (3 occurrences, 1 distinct):

  ```
  /(?:`NAME`|"NAME"|(?<![A-Za-z0-9_$\x{80}-\x{10FFFF}])NAME)(?:\s|\/\*.*?\*\/|(?:--\s|#)[^\n]*)*\./isu
  ```

  `NAME` is `preg_quote($database, '/')`. A regex failure refuses.
- **Capability and identity guards.** Rule: `references(tables) && (same schema || qualifies || references(['prepare']))`.
- **243.** Rule: same schema or `qualifiesDatabase`, otherwise a standalone `prepare` word check. This is equivalent to the old loop for `prepare`.

### Adversarial judgement of the expression

Two layers of evidence:

- **Matcher matrix** (`review-evidence/addendum1/matcher/matrix.php`, output `matrix-b47de6b6.txt`). It calls the three private matchers by reflection and needs no database:
  - 34 definitions × 13 database names × 3 implementations = **1,326 checks, 0 mismatches**, exit 0.
  - The database names include regex metacharacters and the delimiter: `va+sey(1)`, `va*sey?`, `va$sey`, `va#sey`, `va/sey`, `va|sey`, `va-sey`, `va.sey`, `vasé`, `va[s]ey`, `va\sey`, `va^sey{2}`.
- **Native probe P9** (`ReviewerSchemaIsolationProbeV3Test`). It creates each variant in a peer schema and records the text MySQL actually stores. It then executes the object to show which table MySQL resolves, and runs all three guards.

| Case | Stored by MySQL (P9) | Resolves to | Guards | Judgement |
| --- | --- | --- | --- | --- |
| Qualifier split by a block, `--` or `#` comment (`db/* c */.t`, `db -- c\n . t`, `db # c\n.t`) | stored as written | selected table | refused ×3 | correct |
| `db . table` with spaces or newlines | as written | selected | refused ×3 | correct |
| Dot or qualifier inside a versioned comment (`` `db` /*!.*/ `t` ``, `/*!80000 db */ . t`, `/*!db.t*/`) | **expanded**: `` `db` . `t` ``, `db . t`, `db.t` (markers removed) | selected | refused ×3 | correct. On raw text the matcher would not match (matrix: `0`), but MySQL never stores the raw form. A `/*!99999 …*/` comment above the server version is dropped from the body and resolves to the peer (`storage/escaped-backtick-storage.out`). |
| Double-quoted qualifier, ANSI_QUOTES off (`"db".t`) | not creatable: `1064` syntax error | n/a | n/a | not reachable. Under ANSI_QUOTES it is refused (P3, matrix). |
| Name that is a regex metacharacter | n/a (matrix only) | n/a | matcher as expected for all 13 names | correct. `preg_quote` with the `/` delimiter. |
| `` `db` `` followed by a backtick-escaped dot (`` `db``.t` ``, one identifier) | MySQL stores escaped backticks **mangled**, but locally (`` `vdb`.tt` ``). A later qualifier in the same body stays intact (`storage/`: `` `aa`bb` `` … `` `rvsel`.`owned` ``). | peer (`1146 '<peer>.db`.t'`) | admitted ×3 | correct: it is not a qualifier |
| Name inside a string literal (`'db.t'`) | as written | not executed | refused ×3 | over-refusal (Info, safe direction) |
| Upper-case qualifier (`` `DB`.`t` ``, bare `DB.t`) | as written | selected on lctn 1/2 | refused ×3 (P3, lane) | correct (R-2) |
| Peer named `<db>-rv4`, `<db>$rv4`, `<db>é`, `<db>_rv4`, `x-<db>`, or shorter `vaseyaudio_review` | own objects qualified with the peer's name | peer | admitted ×3 (P4 v3) | correct (R-1) |
| Prefix extension `x<db>.t`, `$<db>.t`, `é<db>.t`, `` `x<db>`.t `` | matrix | n/a | not a qualifier | correct |
| Invalid UTF-8 body | matrix | n/a | matcher throws, so refused | fails closed |
| Long runs of `#` or `"# \n"` after the name with no dot (2,000 lines) | matrix | n/a | 0 to 1.1 ms, no backtracking failure | no ReDoS observed |

### Native runs at `b47de6b6` (private 8.4.11, port 3613; PHPUnit exit codes)

| Selection | Exit | Tests | Assertions | Failures | Errors | Skipped | Wall (s) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `tests/Feature/NativeSchemaIsolationTest.php` (lane) | 0 | 34 | 357 | 0 | 0 | 0 | 695 |
| Reviewer probes v3 `addendum1/probes/ReviewerSchemaIsolationProbeV3Test.php` (P1 to P9; P4 now expects every extension admitted; rerun on a clean tree) | 0 | 9 | 3,148 | 0 | 0 | 0 | 122 |
| Reviewer probes v2 unchanged (`probes/ReviewerSchemaIsolationProbeTest.php`) | 1 | 8 | 3,065 | 1 | 0 | 0 | 94 |

- **The v2 failure is the intended one.** It is P4's over-refusal characterization of R-1: `P4 hyphen-extension (…-rv4) -> capability: admitted`. That is condition 2 observed closed.
- **P1, P2, P3 and P5 to P8 are unchanged.** P7 again compared 608 requested and 636 superset rows with 0 mismatches. P8 again counted 104 statements with and without peers.
- **SQLite.** `NativeSchemaIsolationTest` gives 34 skipped (census), and `pint --test` on the four changed PHP files passed.
- **A superseded run.** The first v3 run (`probes-v3-b47de6b6.*`, also 9/3,148 OK) overlapped a one-second dry run of my mutation script. It is superseded by the rerun and recorded in `addendum1/probes/summary.txt`.

### Mutation (my choice): A1-M5

- **Change.** Identity `qualifies()` drops "followed by a dot" and ends the name at a narrow `(?![A-Za-z0-9_])` boundary.
- **Lane test.** `--filter extends_the_selected_name` exits 2 with **3 errors** (hyphen `-2`, dollar `$x`, non-ASCII `é`), each `Unexpected production identity schema or retained evidence; refused before DDL.` during `migrate:fresh`.
- **Probe.** P4 v3 exits 1 with 1 failure (`P4 hyphen-extension identity` refused).
- **Revert.** `git checkout -- app database`; `git diff --quiet -- app database` exit 0. The identity file then hashes to `fc5e3e80…` again.
- **Evidence.** `addendum1/mutation/A1-M5.diff`, `mutate.py` and `summary.txt`.

I did not repeat the lane's M3 run. The six upper-case lane cases pass natively here, and my P3 and the matrix confirm the refusal independently.

### Conditions

1. **Condition 1 (R-2): closed.**
   - The lane now has six upper-case qualified-reference cases (trigger and routine × three guards), green natively here.
   - The lane reports that its M3 case-sensitive mutation fails 6/6.
2. **Condition 2 (R-1): closed.**
   - The token-aware match was implemented, and the lane added a hyphen, dollar and non-ASCII extended-peer regression, green here (3 cases).
   - My mutation A1-M5 makes all three fail.
   - P4 v3 admits six differently extended or prefixed peers in all three guards.
3. **Condition 3 (I-6): unchanged.** It is carried forward.

### Info notes on the delta (no finding Low or above)

- **A1-I1.** The match is textual, on the stored body. MySQL expands versioned comments and mangles escaped backticks token-locally before storing. I found neither hides a qualifier.
- **A1-I2.** Remaining over-refusals are all in the safe direction:
  - a name inside a string literal;
  - a case-variant peer on lctn=0 (I-1, unchanged).
- **A1-I3.** The prefix lookbehind is not pinned by a lane test. Removing it can only over-refuse, because a qualifier still needs the dot. My matrix and P4 `hyphen-prefix` cover it.
- **Unchanged.** I-2 (events), I-3 (literal table name), I-4 (visibility) and I-5 (exception type).

### SHA-256 at `b47de6b6`

Each is identical in the worktree.

| Path | SHA-256 |
| --- | --- |
| `app/Domain/Commerce/ProductionPolicy/CapabilityMigrationOwnership.php` | `52ddd943c7bc3527c6f5a0369dab88aa7136a3f64b03d504fa4e057cfe0fcfe7` |
| `app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php` | `fc5e3e80cf41eb74a5f30fd51a20c00eaab7d42c4a9d15708bde671e384144a2` |
| `database/migrations/2026_10_07_243000_inquiry_notification_intents.php` | `0fa48390969ad3585b9c65374551499350f677ad342d2e3a2739a9f804d19d6b` |
| `tests/Feature/NativeSchemaIsolationTest.php` | `cfcca4b9e35ba152d123e5b3a633e4da4a5bf8c06c1189baac6ba1d56aa80076` |
| `scripts/ci/database-sqlite-skips.json` | `12f60311d58f0ca81476361f8b0f35dadaf620c33e2292ebffe9fa27cbc7b777` |
| Reviewer probe v3 `addendum1/probes/ReviewerSchemaIsolationProbeV3Test.php` | `aba0dc8fbd8c935c4c4bfa684c4d9c862bb0736c589b94c877c49209cc92a817` |
| Reviewer matrix `addendum1/matcher/matrix.php` | `e31081b104df75d9ee81d4d78d41c13482b7c90f51d50cfb8882ec52ffbe77af` |

### Evidence index (`review-evidence/addendum1/`)

- `sha256-and-scope.txt`.
- `native/`: `run.sh`, the ledger, JUnit and text for the lane test, and `private-instance-lifecycle.txt`.
- `probes/`: the v3 probe, the v3 runs (clean rerun plus the superseded first run), the unchanged v2 run, and `summary.txt`.
- `matcher/`: `matrix.php` and its output.
- `storage/`: the MySQL storage check for versioned comments and escaped backticks.
  - The SQL and its output, plus the first attempt's syntax error, retained.
  - The check ran in throwaway schemas `rvsel` and `rvpeer`, both dropped.
- `mutation/`: `mutate.py`, `A1-M5.diff`, JUnit and text for the lane and probe runs, and `summary.txt`.
- `pint.txt` and the SQLite `NativeSchemaIsolationTest` result.

### Cleanup

- **Mutation.** A1-M5 was reverted. `git diff --quiet -- app database` returned exit 0.
- **Worktree.** It is at `b47de6b6`. `git status --short` shows only `independent-review/`: `DECISION.md` modified and `review-evidence/addendum1/` untracked.
- **Schemas.** Before shutdown, the instance listed `information_schema, mysql, performance_schema, sys, vaseyaudio_review_trigscan`. Every peer and throwaway schema had been dropped.
- **Private mysqld.**
  - pid 31834 on :3613 was shut down with `mysqladmin shutdown`; `err.log` ends `MySQL Server - end.`
  - Afterwards `/proc/31834` was absent, there was no LISTEN on 3613 (`0E1D`), and no `mysqld` process had `port=3613`.
  - `$scratchpad/review-trigscan-a1-mysql` (223M) was removed.
  - Other lanes' daemons were left running: 3531, 3567, and 3641 to 3644, which another agent started during this addendum.

## Addendum 2: merge of main and the delimiter guard, delta `b47de6b6..25a00840`

- **Reviewed delta.** `b47de6b6..25a00840` on `harness/native-schema-isolation`, first parent: `052cef7c` (addendum 1, documentation only), `855610be` (merge of main at `9593fcdf`) and `25a00840` "fix(migrations): refuse a selected database name that carries an identifier delimiter". The range also contains main's own commits through `9593fcdf`; those are outside this review.
  - `25a00840` touches the three matchers (three added lines each, plus the docblock), adds `tests/Unit/SchemaQualifierDelimiterTest.php`, and adds a README section and `conditions/codex-delimited-name/`. The regex line itself is unchanged.
- **Also judged.** The second Codex P2 on `25a00840` (thread `discussion_r4214949277`), at the coordinator's request.
- **Method.** The worktree was detached at `25a00840`, with the vendor tree symlinked and its own Composer autoload. `git status --short` showed nothing before this addendum.
- **Database.** A fresh private `mysqld` 8.4.11 with `--no-defaults` on 127.0.0.1:3709, `--socket=` empty, `--mysqlx=OFF`, `lower_case_table_names=0`, default `sql_mode`, schema `vaseyaudio_review_trigscan`. Port 3306 and the other lanes' daemons were not touched.
- **Date.** 2026-10-08 (UTC).

### Decision for the delta

**APPROVE.** The merge changes none of this branch's code. The delimiter guard is sound and is reached only where it matters. Each of its six arms is pinned by the new test. No finding is Low or above.

The second Codex P2 is real, but it can only over-refuse; it never admits. I rate it **Info**. Leaving it open is acceptable for a development merge, with the owner deciding whether to name it explicitly (A2-I1).

### 1. The merge `855610be` (question 2)

All of this is in `review-evidence/addendum2/sha256-and-scope.txt`.

- **Parents.** `052cef7c` (this branch) and `9593fcdf` (main).
- **Paths that differ from main.** `git diff --name-only 9593fcdf 855610be` lists 183 paths:
  - 177 under `docs/verification/native-schema-isolation-20261007/`;
  - the branch's own six code, test and census paths: the three matchers, `NativeSchemaIsolationTest`, `IdentityInspectionCostTest` and `database-sqlite-skips.json`;
  - **0 other paths.**
- **The reviewed code is unchanged.** `git diff b47de6b6 855610be` on the three matchers and the two lane tests is empty.
- **The five 253 files.** Re-merging (`git merge-tree 052cef7c 9593fcdf`) conflicts on these five files and on the census.
  - `git diff 9593fcdf 855610be` on the five files is **empty**: main's #49 versions were taken.
  - `git diff fad3ab44 052cef7c` on the five files is **empty**: the branch carried main's pre-#49 copies unchanged.
- **Correction to the brief.** `git diff fad3ab44 855610be` on the five files is **not** empty: 177 lines across 66 insertions and 7 deletions. That diff is exactly #49's own change to these files, which the merge correctly brought in. The emptiness the brief expected holds against `9593fcdf`, not against `fad3ab44`.
- **Census.** Compared with main, the merge adds 4 entries (this branch's four) and removes none. Compared with `052cef7c`, it adds 1 (#49's `MysqlConnectionTimezoneTest`) and removes none. That is a union.

### 2. Is the fail-closed check sound and complete? (question 1)

**Where `$database` comes from.**
- Capability and 243 read `DB::getDatabaseName()`, which is the config value `DB_DATABASE` (`config/database.php`, default `laravel`). Identity reads `SELECT DATABASE()`.
- I tested whether the store can even select such a database: `storage/laravel-connect.php` boots the application against the private instance, and the output is in `laravel-connect.out`. Laravel's `MySqlConnector` runs ``use `<name>`;`` without escaping.
  - **A name with a backtick cannot be selected at all.** `rv`bt` and `a`b` fail with `1064` on the `use` statement. `rv``bt` fails with `1049 Unknown database` in the DSN.
  - **Other names connect.** `rv"q`, `rv.dot`, `rv#c` and `rv sp` all connect, and config and `DATABASE()` agree.
- So the backtick arm is defence in depth. The double-quote arm is reachable.

**When the check runs.**
- It runs inside `qualifies()`/`qualifiesDatabase()`, which are reached only for a stored object outside the selected schema whose body names a guarded table. The `references(...)` test comes first, then the same-schema comparison short-circuits.
- So a delimited selected name refuses only when such a candidate exists. Otherwise there is nothing to decide, and migration proceeds.
- This is correct, but it is narrower than two README phrases (A2-I3).

**The same-schema branch.**
- `strtolower($schema) === strtolower($database)` compares the dictionary's raw `*_SCHEMA` value with the name. It reads no body text, so delimiters cannot mislead it. It does not need the guard.
- MySQL stores the schema name unmangled in the dictionary: `SCHEMATA` hex for `a`b` is `616062`.

**Names containing `.`, whitespace or comment openers.**
- I checked these on 8.4.11 (`storage/delimiter-storage.sql` and `.out`, and `matcher/stored-bodies.php`, whose output `stored-bodies-25a00840.txt` shows 22 cases and 0 mismatches).
- **MySQL rejects a trailing space or tab.** `CREATE DATABASE `rvtrail `` and the tab form both fail with `1102 Incorrect database name`. So the `(?:\s|…)*\.` tail can never be fed from inside the name.
- **Accepted names are stored as written and refused by all three guards.** The names `rv.dot`, `rv/*c`, `rv--c`, `rv#c`, `rv sp` (inner space, with spaces around the dot) and `rv\bs` are accepted. Each is stored as written inside a backtick-quoted body, and each `` `<name>`.`owned` `` matches in all three guards (qualifies = 1, so the dependency is refused).
  - The name is `preg_quote`d and the flags are `isu` (no `x`), so a `.`, `#`, `/*` or space inside the name is literal.
  - The name is consumed before the tail, so the tail cannot borrow from it.
- **Interaction with a shorter selected name `rv`.** The only effect is over-refusal (A2-I2):
  - A peer `rv.dot` matches through the bare arm: the opening backtick is not an identifier character, and then the dot follows.
  - A peer `rv#c` matches because `#[^\n]*` runs up to the later dot.
  - Peers `rv/*c` (unterminated comment), `rv--c` (no whitespace after `--`) and `rv sp` do not match.

**Mangling without a delimiter.**
- Every mangled form I have seen comes from a doubled delimiter inside its own quoting:
  - `` `a``b` `` is stored as `` `aa`bb` ``, `` `rv``bt` `` as `` `rrv`btt` ``, and `` `aa``bb` `` as `` `aaa`bbb` ``;
  - under `ANSI_QUOTES`, `"rv""q"` is stored as `"rrv"qq"`.
- A backslash is stored literally (`rv\bs`).
- MySQL identifiers are limited to the BMP, without U+0000.
- Inside backtick quoting the only character that is escaped is the backtick. Under `ANSI_QUOTES` it is the double quote. Both are covered by the guard.
- I found no admission path left. This is not an exhaustive character sweep. Versioned comments are expanded before storage, as addendum 1 showed.

**Pre-fix behaviour, which confirms the defect was real.**
- `matcher/pre-fix-expression.txt` applies the `855610be` expression:
  - it returns **0, meaning admitted**, for the stored `` `rrv`btt` `` (selected `rv`bt`), for the stored ANSI form `"rrv"qq"` (selected `rv"q`), and for the unit test's backtick bodies;
  - it returns **1, meaning refused**, for a backtick-quoted `` `rv"q` `` and for the unit test's two double-quote bodies.
- The guard closes the backtick and ANSI cases.

### 3. The second Codex P2: a peer whose name contains a backtick (coordinator's (a) and (b))

**Claim verified.**
- A peer schema `a`b` holds a routine and a trigger naming its own table `` `a``b`.`owned` ``. MySQL stores both as `` `aa`bb`.`owned` ``.
- Executing them reads the peer's own table (7 rows), not `bb.owned` (2 rows).
- All three guards return 1 for selected `bb`. The longer case behaves the same: `aa`bb` is stored as `` `aaa`bbb` `` and matches selected `bbb`.
- **End to end** (`storage/codex-p2-2-end-to-end.out`, with `DB_DATABASE=bb` and `php artisan migrate:fresh --force`):
  - With a peer routine in `a`b` naming its own `inquiry_notification_intents`, `migrate:fresh` exits 1 at `2026_10_07_243000` with `Unexpected inquiry notification external routine reference`.
  - The control, the same routine in a peer `a`c` (stored `` `aa`cc` ``), exits 0, with all 77 migrations DONE.

**Counterexample verified.**
- MySQL accepts `` SELECT COUNT(*) FROM`bb`.`owned` `` with no separator. This holds in a procedure, inside a derived table (`` FROM(SELECT id FROM`bb`.`owned`)x ``) and in a trigger. Under `ANSI_QUOTES`, `FROM"bb"."owned"` is also accepted.
- All four are stored as written and resolve to `bb.owned` (2 rows; trigger `@t_nosep = 2`). The current guards return 1 for all four, which is correct.
- **The nearest regex fix fails open.** The variant puts the identifier-character lookbehind in front of the quoted arms as well. It returns **0 for all four real dependencies**, while it would remove the Codex over-refusal (`variant=0` on `` `aa`bb` ``). A regex fix in that direction would admit a real cross-schema dependency.

**Severity: Info (availability only; fails closed).**
- The precondition is a schema on the same server whose name contains a backtick and which holds a trigger, view or routine naming one of the guarded tables.
- That schema cannot be one the store itself uses, because Laravel cannot select it (section 2). The operator's remedy is to rename or drop that schema.
- **Leaving it open is acceptable for a development merge.** Because the stored text is ambiguous, refusing is the only sound outcome for objects in such a schema.
- The `SCHEMATA` route the coordinator proposes would also refuse. It improves the message, not availability, and is the owner's choice. It is not a condition.

### 4. Tests and mutations (question 3)

**SQLite** (`sqlite-SchemaQualifierDelimiterTest.txt`, JUnit alongside): `tests/Unit/SchemaQualifierDelimiterTest.php` gives `OK (5 tests, 26 assertions)`, rc=0.

**Native at `25a00840`** (private 8.4.11, port 3709, `native/run.sh`; PHPUnit exit codes):

| Selection | rc | Tests | Assertions | Failures | Errors | Skipped | Wall (s) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `tests/Unit/SchemaQualifierDelimiterTest.php` | 0 | 5 | 26 | 0 | 0 | 0 | 1 |
| `tests/Feature/ProductionIdentity/IdentityInspectionCostTest.php` | 0 | 1 | 6 | 0 | 0 | 0 | 194 |
| `tests/Feature/NativeSchemaIsolationTest.php` (lane) | 0 | 34 | 357 | 0 | 0 | 0 | 955 |

The refusal does not reach plain-named servers: the selected name `vaseyaudio_review_trigscan` gives the same lane results as at `b47de6b6`.

**Mutations (my choice: all six arms).**
- Each run removed one `str_contains` check in one guard, using `mutation/mutate.py <guard> <backtick|dquote>`, and the unit test was run on SQLite. Results are in `mutation/summary.txt`, with the diff, text and JUnit for each mutation.

| Mutation | rc | Result | Failing cases (message names the mutated guard) |
| --- | --- | --- | --- |
| capability, backtick removed | 1 | 5 tests, 2 failures | `backtick`, `doubled backtick`: "capability admitted …" |
| capability, double quote removed | 1 | 5 tests, 2 failures | `double quote`, `leading quote` |
| identity, backtick removed | 1 | 5 tests, 2 failures | `backtick`, `doubled backtick` |
| identity, double quote removed | 1 | 5 tests, 2 failures | `double quote`, `leading quote` |
| inquiry (243), backtick removed | 1 | 5 tests, 2 failures | `backtick`, `doubled backtick` |
| inquiry (243), double quote removed | 1 | 5 tests, 2 failures | `double quote`, `leading quote` |

- Every mutation was reverted with `git checkout -- app database`. `git diff --quiet -- app database` returned exit 0 each time, and `sha256sum -c` against the pre-mutation hashes printed OK ×3.
- All mutation runs finished before the native runs started; the native summary records `dirty_app_db_tests=0`.

### 5. Pint (question 4)

`vendor/bin/pint --test` on the four changed PHP files: `{"tool":"pint","result":"passed"}`, rc=0 (`pint.txt`).

### Findings on the delta (none Low or above)

| ID | Severity | Area | Finding | Recommendation | Evidence |
| --- | --- | --- | --- | --- | --- |
| A2-I1 | Info (availability; fails closed; no admission) | Second Codex P2: peer named with a backtick | A peer `a`b` naming its own guarded table is stored as `` `aa`bb`.`… ``. The quoted arm matches `` `bb` `` for selected `bb`, and `migrate:fresh` refuses (end to end, rc 1 at 243). The control `a`c` passes. The nearest regex fix (identifier lookbehind before the quoted arms) fails open on the valid ``FROM`bb`.`owned` ``, ``FROM(…FROM`bb`…)`` and `ANSI_QUOTES` ``FROM"bb"."owned"``. | Leave as is for development. The operator remedy is to rename or drop such a peer. An explicit `SCHEMATA` refusal is an optional owner choice for a clearer message. Do not adopt the lookbehind fix. | `storage/delimiter-storage.out`, `storage/codex-p2-2-end-to-end.out`, `matcher/stored-bodies-25a00840.txt` |
| A2-I2 | Info (over-refusal, safe direction) | Peers extending the selected name with `.` or `#` | For selected `rv`, a peer named `rv.dot` or `rv#c` that names its own guarded table is refused. The bare arm accepts the opening backtick as a boundary, and `.` or `#…` then reaches a dot. This was the same at `b47de6b6`. | None required. Note it next to I-1. | `matcher/stored-bodies-25a00840.txt` |
| A2-I3 | Info (documentation accuracy) | Lane README and unit-test wording | (1) README: "each guard admitted them" before the fix. For the two double-quote names the pre-fix expression returned 1, which is a refusal; the red failures there show only the missing early exception. The real pre-fix admissions are the backtick spellings and the stored `ANSI_QUOTES` form `"rrv"qq"`, which the unit test does not exercise. The test's "admitted" message has the same imprecision. (2) README: "the refusal happens before any dictionary read" and "such a name is unsupported". The refusal happens after the dictionary rows are read, only for a peer object naming a guarded table, and before the regex. A delimited name with no such object migrates. (3) A backtick name cannot be selected through the app's connection at all (1064 or 1049). | Optional wording fix in the README, and optionally an `ANSI_QUOTES` stored-form case. No code change. | `matcher/pre-fix-expression.txt`, `storage/laravel-connect.out`, `conditions/codex-delimited-name/red-sqlite.txt` |

**Unchanged.** I-1 to I-6 and A1-I1 to A1-I3.

### Conditions

1. **Conditions 1 and 2:** closed in addendum 1, and they remain closed. The matcher expression is unchanged (`git diff 855610be 25a00840` adds only the guard and the docblock).
2. **Condition 3 (I-6):** unchanged and carried forward.
3. **No new condition.**

### SHA-256 at `25a00840`

The git blob and the worktree are identical for each file.

| Path | SHA-256 |
| --- | --- |
| `app/Domain/Commerce/ProductionPolicy/CapabilityMigrationOwnership.php` | `60757cb1d9242eec6594956756a8adc4e31ca2162547fdc6e3c2360ed792c2f2` |
| `app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php` | `656fab100558cc915df8ce2a30714a12ca0c66165ad594e060f5a8dabd6789c4` |
| `database/migrations/2026_10_07_243000_inquiry_notification_intents.php` | `ab74169f24b9ad7692730723045dc38db74dadf366e17f6f7d575457cf67cd0f` |
| `tests/Unit/SchemaQualifierDelimiterTest.php` | `2e5265d7cd00c47efc5c75cee0318c60714e50e1e0af863a6a7377b792b66207` |

### Evidence index (`review-evidence/addendum2/`)

- `sha256-and-scope.txt`: the first-parent log, the merge's parents and conflicts, the path partition, the checks on the five 253 files, the census union, the `25a00840` stat, and the hashes.
- `storage/`:
  - `delimiter-storage.sql` and `.out`: accepted and refused names, stored bodies and resolution, the Codex P2-2 peers, the ``FROM`bb` `` counterexamples, and the `ANSI_QUOTES` forms.
  - `laravel-connect.php` and `.out`.
  - `codex-p2-2-end-to-end.out`, plus the two `migrate:fresh` logs.
  - `cleanup.txt`.
- `matcher/`:
  - `stored-bodies.php` and its output (22 cases, 0 mismatches; also the lookbehind variant);
  - `pre-fix-expression.php` and its output.
- `native/`: `run.sh`, `summary.txt`, text and JUnit for each selection, and `private-instance-lifecycle.txt`.
- `mutation/`: `mutate.py`, six diffs, text and JUnit for each mutation, `pre-mutation-sha256.txt` and `summary.txt`.
- `sqlite-SchemaQualifierDelimiterTest.txt` and `.junit.xml`, and `pint.txt`.

### Cleanup

- **Mutations.** All six were reverted. `git diff --quiet -- app database` returned exit 0, and the three hashes match `pre-mutation-sha256.txt`.
- **Worktree.** It is at `25a00840`. `git status --short` shows only `?? …/independent-review/review-evidence/addendum2/`, plus this appended section in `DECISION.md`.
- **Throwaway schemas.** These were dropped before the native runs (`storage/cleanup.txt`): `rv.dot`, `rv/*c`, `rv--c`, `rv#c`, `rv sp`, `rv"q`, `rv`bt`, `rv\bs`, `bb`, `a`b`, `a`c`, `aa`bb` and `rvpeer`. The instance then listed only the system schemas and `vaseyaudio_review_trigscan`, with 0 non-`sys` routines and 0 triggers.
- **Private mysqld.**
  - pid 18910 on :3709 was shut down with `mysqladmin shutdown`. `/proc/18910` was then absent, nothing was listening on 3709 (only 16 TIME_WAIT entries), and no `mysqld` process had `--port=3709`.
  - `$scratchpad/review-trigscan-mysql2` (231M) was removed.
  - Other lanes' daemons were left running: 3531, 3567, 3641, 3642 and 3644.
- **Recording error, mine.** My working lifecycle file was inside the removed directory, and it was deleted before I copied it. As a result, the pre-shutdown schema listing and the last `err.log` line were lost. `native/private-instance-lifecycle.txt` says so and records an independent post-shutdown re-check instead.
- **Credentials.** The throwaway password does not appear in any evidence file.
