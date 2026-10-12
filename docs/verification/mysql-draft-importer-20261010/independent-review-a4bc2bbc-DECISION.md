# Independent review: importer whole-schema pin H1 correction

- Reviewer: independent Codex reviewer session, 2026-10-12. No product or test implementation edits; separate detached review worktree.
- Reviewed product/test source: `a4bc2bbc5ad260084028fe671790197a2c767bd5`.
- Full PR comparison: `1c76572bfa8cd2437a2637afc5080d2b0aa90313..a4bc2bbc5ad260084028fe671790197a2c767bd5`.
- Corrective comparison: rejected `2aba5bede538aacd8493b56585f0fbd3bcd20d0d..a4bc2bbc5ad260084028fe671790197a2c767bd5`.
- Read root `AGENTS.md`, the October 11 handoff and H1 packet/rejected decision, the affected migration work package, importer plan/evidence, and the freeze-and-batch record.

## DECISION: APPROVE WITH CONDITIONS

The product correction is sound for a development merge. No open P0/P1 finding and no new actionable P2 finding were identified. All required focused database checks are complete. Before merge, integrate the complete evidence and corrected count comments, then obtain the final committed-head delta assessment. No later product/test source may inherit this approval without review. This is not production activation or complete Foundation acceptance.

## Findings and corrective assessment

1. **H1 closed in source.** Both base-table listings use a separate nonpersistent PDO created from Laravel's resolved writer configuration. The observer must have autocommit enabled, no transaction, a distinct connection ID and the same database/server UUID. The authoritative final listing therefore has a fresh committed data-dictionary view while every admitted durable table is protected by the writer's transaction-duration metadata lock. The writer itself does not establish an early consistent read during the pin.
2. **Configured connection retained.** The implementation uses the installed Laravel connection factory rather than constructing a reduced DSN. The factory's read/write path stores the resolved writer config on the connection and its connector preserves Unix sockets, TLS and PDO options. Persistent reuse is explicitly disabled for the observer. A multi-host choice resolving to a different server is refused. Socket and PDO-option behavior were exercised independently; real TLS and alternate-server routing were not.
3. **M1/L2 addressed.** The writer's metadata-lock wait is reduced to `min(existing, 5)` before opens/shadow checks and restored in `finally`; 1205/1213 become `catalog_target_busy`. The observer also has a five-second lock-wait cap. The runbook and evidence correctly distinguish a per-wait cap from a total deadline and warn that queued DDL can stall ordinary queries. Connection loss can still prevent restoration because the session is no longer usable; no success is claimed for that condition.
4. **Deferred shadow finding addressed.** `shadowed()` receives every pinned name and uses escaped identifiers. Opening a temporary shadow does not establish the intended durable lock, but the subsequent all-name shadow check refuses before writes. Independent checks covered an escaped name outside the evidence set, ordinary pin lifetime, and engine/shadow refusal with restored session state.
5. **Race tests target the rejected window.** The child holds the first table, observes the exact parent `SELECT 1 ... LIMIT 0` waiting after listing, changes the later target to MyISAM, then releases its lock. Assertions require both the expected blocked statement and actual engine change before accepting refusal. The red run reaches the final expected-refusal assertion for both targets. Symfony Process drains both streams while waiting for readiness; the peer and parent have cleanup paths. The process deadline is enforced when Symfony regains control, not as an independent interrupt of a parent PDO call; the corrected pin's own database wait cap and the peer's bounded loop cover the intended test path.
6. **L1/documentation corrected.** The final listing proves only the set at that instant. It does not prevent later table creation, enforce foreign trigger/routine dependencies, or cover other schemas. The README now names triggers on non-owned pinned tables and warns that a later refusal cannot undo an unpinned nontransactional side effect; it does not promise detection of a cross-schema write. That remains the documented owner decision for a later batch, not a newly closed production guarantee.

## Evidence independently executed

`git diff --check 1c76572b HEAD` passed in the clean detached review worktree. Reflection resolved the actual loaded class to that worktree; its SHA-256 matched `git show HEAD:app/Domain/Migration/CatalogOnboarding/CatalogDatabaseEvidence.php`:

```text
ce408e6a40f1dea59ca0f53ccaa3fadf922fe7191362942d4bb4171d13fca383
```

A reviewer-owned direct probe used the private Oracle MySQL Community Server **8.4.11** socket, PHP **8.4.26**, and only disposable schema `vaseyaudio_importer_review`, which it dropped on completion. It loaded the exact reviewed class without source changes. The app factory was wrapped only to retain the actual observer for inspection; the wrapper delegated connection creation to the original factory.

Actual command, after `source $TOOLCHAIN/activate.sh`:

```sh
env APP_ENV=testing APP_DEBUG=false CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php $SCRATCH/importer-evidence/reviewer/pin-probe.php \
  > $SCRATCH/importer-evidence/reviewer/pin-probe.txt 2>&1
```

Result: **12/12 checks passed**, exit 0. These are explicit probe checks, not PHPUnit cases. They cover successful timeout restoration; nonpersistent observer despite persistent writer configuration; preserved configured PDO initialization option and Unix socket despite deliberately invalid TCP host/port; distinct sessions; observer autocommit/no transaction; a writer read seeing a row committed after pin completion; an escaped non-evidence table blocking foreign ALTER until rollback; release after rollback; non-InnoDB refusal and timeout restoration; escaped non-evidence temporary-shadow refusal and timeout restoration.

The remaining native schema-drift selection was also run directly from this review worktree, using the separate disposable reviewer schema. Exact invocation (after toolchain activation and creating only that schema):

```sh
env APP_ENV=testing APP_DEBUG=false DB_URL= CACHE_STORE=array SESSION_DRIVER=array \
  QUEUE_CONNECTION=sync DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33396 \
  DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_importer_review \
  DB_SOCKET=$SCRATCH/mysql-run/mysql.sock CI=true VA_CI_DISPOSABLE_MYSQL=1 \
  TMPDIR=$PRIVATE_TMP \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
  -- --no-progress tests/Feature/PersistentCatalogDraftImportTest.php \
  --filter 'test_foreign_or_incomplete_live_owned_schema_refuses_review_and_apply_without_repairs#10-12' \
  --log-junit $SCRATCH/importer-evidence/reviewer/mysql-drift-green.xml
```

**Result: 3 tests, 23 assertions, 0 failures, 0 errors, 0 skips, exit 0**, 259.611 seconds. JUnit confirms precisely datasets #10, #11 and #12 with 8, 9 and 6 assertions. The reviewer schema was dropped after completion. Together with the supplied green native selections below, the required focused MySQL set is **11 tests / 118 assertions**; it is not the complete MySQL file.

## Supplied evidence independently inspected

The implementer, not this reviewer, executed the PHPUnit runs. Raw logs/JUnit are inspected separately from code review; incomplete or failed runs are not passes.

- **Required red:** `d55d023ac8fada1fd0b6aad811c421addbb0d3d9` has the rejected `2aba5be` app source plus the final tests. I independently ran `git diff --exit-code 2aba5be -- app` there and compared its test file with the reviewed head. Both comparisons passed. On private MySQL 8.4.11 the two named race datasets produced **2 tests, 18 assertions, 2 intended failures, 0 errors, 0 skips**, exit 1. Both reached “An engine change racing the pin was admitted” with null refusal; the earlier synchronization and actual-MyISAM assertions passed.
- **New native green cases:** inspected logs/JUnit at the candidate: **4 tests, 32 assertions, 0 failures/errors/skips**, exit 0, 439.620 seconds. Both H1 race datasets, bounded contention/restoration, and non-evidence temporary shadow passed.
- **Prior native importer cases:** inspected logs/JUnit at the candidate: **4 tests, 63 assertions, 0 failures/errors/skips**, exit 0, 388.292 seconds. Dry-run/resume, original transaction pin, resumed whole-schema pin, and nested/foreign-session interleave passed.
- **Complete SQLite file:** inspected `sqlite-final.txt` and JUnit: **60 tests, 336 assertions, 0 failures, 0 errors, 0 skips**, exit 0, 77.827 seconds. This used a private ordinary `/tmp` fixture directory and genuine filesystem metadata. Earlier environment/fixture refusals and the no-tests filter attempt remain retained; source guards and application/test code were not changed to obtain the pass.
- Policy self-test logs: **50 tests passed**; sharder self-tests: **52 passed**.
- Corrected native discovery log: **1,825 selected cases in 164 files, of 7,800 discovered cases in 562 files**. This is selection/count evidence, not execution of those database cases.
- Pint log: passed for the changed PHP files.

## Condition before development merge

Integrate the completed evidence, including `reviewer/mysql-drift-green.txt` and `.xml`, and corrected discovery counts, then review the final committed evidence/count delta and name its exact SHA. The currently uncommitted workflow comment changes were inspected and correctly say 1,825 selected / 7,800 discovered cases in 164 selected files; no workflow execution behavior changes. The final committed head does not yet exist, so this record does not approve an unknown future commit. No product/test source changes may inherit approval without assessment.

## Untested limits

No full MySQL file, complete repository SQLite suite, hosted Foundation/browser matrix, actual host/TLS transport, least-privilege deployment, multi-server routing/failover, lower_case_table_names 1/2, production import, real provider data, or foreign trigger/routine residual was independently accepted. The stock SQLite counterparts of native-only behavior do not establish MySQL concurrency. The importer remains a private development/staging component within existing activation and cutover boundaries.
