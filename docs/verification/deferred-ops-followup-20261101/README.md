# DEF-OPS follow-up, 2026-11-01

## Current status — actual evidence reviewed 2026-11-02

**Focused DEVELOPMENT APPROVED** at exact executable source
`f694596031b5a0dea5c9979f74344bcdba1cbf34`, with no P0–P2 identified.
The [sanitized actual receipt index](actual-receipts-f6945960.md) is the current
selection/status record, with [byte-exact independent final decision](independent-defops-final-f6945960-DECISION.md).
The original b1ab native22/1523/2-failure result remains NON-GREEN; only its two
order-sensitive audit-context assertions were repaired and independently reverified
with focused2/57 native and SQLite runs. Native commerce evidence is narrowed to
three named methods (3/162), not a whole-file pass. Backup coverage spans76distinct
cases across uid1000 plus exactly4 root-only cases. Intended old-source/mutation
failures, reached variants, skips and remaining limits are preserved in that index.

No finding is yet merged/closed by this packet. Ordered integration remains
member → MIG → A5 → DEFOPS; integrated-SHA/preflight/protection duties remain root-owned.

## Historical preparation and earlier evidence

Everything below is dated preparation/history, including superseded queued/not-run
plans and earlier source-only acceptance. It is retained for provenance; those
queues are not the current status and no earlier failure is rewritten as a pass.

Base: `2ab0c41e946e30cc402afc8ec107a3670e399abd`. Branch: `harness/deferred-ops-followup-20261101`.

## Work package (committed before code)

Exactly the three open P3 findings from the `faceb5f8` review are owned here.
Files: `TestIdentityLinkRelay.php`, `TestCommerceEvidence.php`, their focused command tests,
`tests/ops/test_staging_backup_offhost.py`, and this packet. No other lane's files are owned.

Acceptance:

- P3-1: exclude staff recipients using database comparison semantics, including mixed case,
  accent and trailing-space collation cases. Preserve bounded batched lookups, reserved-address
  admission, private capture verification and output privacy.
- P3-2: refuse SQL comment splitting, including executable comments, in the plain SELECT guard.
  Inspect actual query callers; preserve valid uncommented SELECTs and existing read-only,
  repeatable-read framing and callback lifetime.
- P3-3: pin malformed/missing record sizes and failed ciphertext stat through behavioral tests.
  Existing backup implementation stays unchanged unless a reproduced defect requires correction.

Plan: inspect current callers/tests; add focused regressions; implement narrow fixes; freeze a
prepared commit; execute allowed static/pure checks. Database/native/ops work waits for the PCC
coordinator's slot release. No PCC run is restarted or duplicated. No push, PR, CI or live action.

Required later evidence: focused command suites on SQLite; private Oracle MySQL 8.4.11 collation
and command framing checks; focused off-host backup tests and digit/stat mutations; exact tested
SHA independent authorization review. Any native census integration changes are reported to the
coordinator rather than editing shared files here. SQLite does not prove MySQL collation.

## Evidence

Preparation in progress. No tests or checks claimed yet.

## Prepared candidate and bounded evidence

Prepared code: `f6783d2a4903d88e14f66d7742439370ba3b91b2`. Code is frozen pending
coordinator release. The later packet-only commit does not change executable source.

- P3-1: each arm of a bounded UNION ALL projects the requested recipient with a bound
  parameter, while `LOWER(email) = ?` performs the staff match in the database. No stored
  staff address is re-keyed in PHP. The existing one-query listing assertion remains.
  The regression exercises list and print privacy, mixed case, a collating non-admin
  positive, and, on MySQL, an explicitly pinned accent-insensitive PAD SPACE collation
  for accent and trailing-space variants. No SQLite collation claim is made.
- P3-2: callers use generated Eloquent/aggregate SELECTs and bound values; none requires
  SQL comments. The narrow guard now refuses `/*`, `--`, and `#`, including executable
  comments. This intentionally also refuses these markers within SQL literals; current
  command queries bind values. READ ONLY/isolation/callback framing is unchanged.
  Comment-split FOR SHARE/UPDATE and executable comments are also exercised through the
  existing real command callback regression (not run yet).
- P3-3: production backup script unchanged. Existing missing-size/failed-listing coverage
  is retained. New tests reject equal malformed record/remote sizes and verify that failed,
  empty-success, and malformed-success ciphertext stat results leave neither shipped nor
  temporary record after a verified remote transfer. Retry-copy cleanup remains asserted.

Completed, using Docker `va-pcc-runtime-20261101`, user `1000:1000`, workdir
`/workspace/VA-Studio-defops-followup`, PHP 8.4.27:

```sh
php -l app/Domain/Customers/TestIdentityLinks/TestIdentityLinkRelay.php
php -l app/Console/Commands/TestCommerceEvidence.php
php -l tests/Feature/TestIdentityLinkRelayCommandTest.php
php -l tests/Feature/TestCommerceEvidenceCommandTest.php
php vendor/bin/pint --test app/Domain/Customers/TestIdentityLinks/TestIdentityLinkRelay.php app/Console/Commands/TestCommerceEvidence.php tests/Feature/TestIdentityLinkRelayCommandTest.php tests/Feature/TestCommerceEvidenceCommandTest.php
```

All four lint checks passed; Pint passed all four files. Host `python3 -m py_compile
 tests/ops/test_staging_backup_offhost.py` and `git diff --check` passed. The worktree owns
its `.env` copied from `.env.example` and Composer autoload metadata, generated with
`composer dump-autoload --no-scripts --no-interaction --quiet`; package directories are
linked to the existing locked vendor solely for static/pure checks. This is **not** a
physically contained renderer vendor and is not used to claim renderer execution.

Tiny pure check (no Laravel setup, filesystem fixtures or database):

```sh
php -r 'require "vendor/autoload.php"; $test = new Tests\Feature\TestCommerceEvidenceCommandTest("test_the_statement_guard_refuses_comment_splitting_and_executable_comments"); $test->test_the_statement_guard_refuses_comment_splitting_and_executable_comments(); echo "Pure SQL guard regression passed (13 assertions); no application/database bootstrap.\n";'
```

Passed all 13 assertions at `72692029` (same PHP source as prepared candidate). Directly
loading the base command from `git show 2ab0c41e:app/Console/Commands/TestCommerceEvidence.php`
reproduced that `isPlainSelect("select 1 FOR/**/SHARE")` returns true. An initial attempt
to call the full regression against that old class hit PHPUnit's uninitialized failure
formatter (`Configuration Registry::get()` TypeError) when the first assertion failed;
that attempt is not counted as a test result. The subsequent direct boolean check
established the old-source defect without PHPUnit formatting.

## Required slot work (not run)

No SQLite, native MySQL, ops execution, browser, renderer, build or full suite has run
for this candidate. No authorization review or merge acceptance is claimed.

After PCC coordinator release, run the two complete focused command files with the
handoff direct PHPUnit invocation and CI testing environment:

```sh
env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:$(head -c32 /dev/zero | base64) DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning tests/Feature/TestIdentityLinkRelayCommandTest.php tests/Feature/TestCommerceEvidenceCommandTest.php
python3 -m unittest discover -s tests/ops -p 'test_staging_backup_offhost.py'
```

Native: provision own Oracle MySQL **8.4.11**, private datadir/socket and free port
confirmed with `ss -ltnp`. Do not share or signal another server. Replace the DB tuple
above with this disposable instance and set `VA_LOCAL_DISPOSABLE_MYSQL` to its exact
database. Run each focused file separately, including the collation regression and
READ ONLY framing tests. Record actual commands, version, counts and failures.
No locks/concurrency/performance claim is made by this change.

Mutations still required: restore PHP stored-address re-keying (native accent and
space cases must fail); remove comment rejection (pure and command regression must
fail); remove both shipped-size digit checks (equal malformed sizes must fail);
remove stat success/digit checks (new stat regression must fail). Individual equality
operand digit checks may be equivalent while the other operand is digit-checked;
report observed survivors/equivalence explicitly. Revert each mutation before the next.

Integration: no new environment keys, kit changes, migrations or native-only skipped
methods; no shared census/workflow/ledger edits made. Lead should incorporate receipts
and any selection count changes after native execution. Exact tested SHA independent
authorization review is mandatory before publication. Merge order remains
PCC → member → MIG → A5 display → this batch.

## Independent source-only review

The separate reviewer assessed exact candidate `8a54c76b05a734a1d41af490ec86abd92ce67023`
and found no P0–P2. The byte-exact decision is retained in
[`independent-defops-source-8a54c76b-DECISION.md`](independent-defops-source-8a54c76b-DECISION.md).
Decision: **source-only conditional acceptance**, not final authorization/merge approval.
Executable source remains `f6783d2a4903d88e14f66d7742439370ba3b91b2`.

All queued gates remain unexecuted: both complete command suites on SQLite and isolated
Oracle MySQL 8.4.11, native collation and framing evidence, focused backup execution,
relay/comment/digit/stat mutations, and independent review of the final actually tested
SHA and receipts. Record failures, skips, repairs and equivalent mutation survivors.
This docs-only review receipt launches no tests and changes no runtime source. PCC's
exclusive slot and the prescribed merge order remain in force.

## Coordinated cache-prefix assertion repair (scope addition before editing)

PCC coordinator assigned `PruneExpiredCacheRowsTest::test_expired_locks_go_and_held_locks_stay_held`
after its completed sweep reproduced the same assertion-only failure at PCC `db5e4b93`
and base main `2ab0c41e`: each focused reproduction exited 1 (1 test / 3 assertions).
External logs: `/workspace/pcc-evidence/cache-prefix-case.log` and
`/workspace/pcc-evidence/cache-prefix-main-case.log`. These are coordinator evidence,
not fresh DEF-OPS executions. With `.env.example` APP_NAME, database raw-key ordering
places `live-lock` before `vaseyaudio-cache-held-synthetic`; stripping the prefix after
ordering does not make the transformed list alphabetic.

Additional owned file: `tests/Feature/PruneExpiredCacheRowsTest.php`. Acceptance: compare
exact surviving raw keys independent of ordering, explicitly exercise configured, empty,
and prefixes ordering the held key before/after `live-lock`, and preserve expired-lock
count, held-lock denial and release assertions. No command/runtime behavior change.
Focused file execution remains queued behind member → MIG → A5; include its new dataset
cases in eventual discovery/count integration. Prior source-only review predates this
repair and PCC integration; final actually-tested-SHA review remains required.

Integrated actual origin/main PCC merge `a7bc63cc5c5785353f13e882f49d0ab9dcff55b3`
as `0e1656fb660f4a2cb9fb08f8bae0007be695c37b`, without conflicts. Main's policy,
workflows and census retained. No new runtime gate execution is claimed.

Cache repair prepared: four named datasets now compare exact raw surviving keys after
sorting both expected and actual lists with `SORT_STRING`. Configured prefix is retained
as a case; explicit empty, `a-cache-` and `vaseyaudio-cache-` prefixes verify both raw
ordering directions, with explicit configured-store prefix assertions. Existing held-lock
acquisition, two-expired count, another-owner denial and owner release remain intact.
Docker PHP lint and `php vendor/bin/pint --test tests/Feature/PruneExpiredCacheRowsTest.php`
passed; `git diff --check` passed. No PHPUnit/database/native/ops/heavy execution launched.
Before any such execution, materialize physically contained exact locked vendor and
regenerate own autoload metadata; existing linked static-check dependencies are insufficient.

## Native audit-context assertion repair scope — 2026-11-02

Root authorized this addition after the actual first native relay run at exact
`b1ab9c6694d8eb19c19db1681f2cf61b816903c4`: 22 tests / 1523 assertions /
2 failures / 0 errors/skips, actual exit 1, 3105.2176 seconds process time.
Private completed receipts are `/workspace/defops-evidence-20261102/native-relay-execution/`.
Both failures, at relay test lines 365 and 394, compare complete audit contexts:
MySQL JSON changed object-key order while the actual keys, values and types agree.
The actual native case/accent/PAD SPACE regression passed 104 assertions. This is
not a whole-file pass; later assertions after those two failures were not reached.

Only the two complete expected/actual context arrays will be sorted by key using
`ksort(..., SORT_STRING)` before retaining strict `assertSame`; every later assertion
remains. No product behavior or loose equality changes. Freeze the repair source,
then run exactly the two affected methods once on own native MySQL and once SQLite.
Preserve the original failed full-file result; do not repeat its 22 methods.

Root narrows the stale full-native commerce plan under the CI cost policy to these
three methods: `test_every_reference_form_gives_the_same_minimized_snapshot_using_only_select_statements`,
`test_the_statement_guard_refuses_locking_and_into_clauses_and_stays_a_single_inert_callback`,
and `test_a_write_attempted_while_the_snapshot_reads_is_refused_and_the_guard_ends_with_the_command`.
Other commerce native methods remain unexecuted unless separately recorded.
Required relay re-key/comment controls await precise root-reviewed substitution
plans and separate mutation authorization. No additional ops mutation run is needed.
Distinct-server correctness overlap was root-reviewed and allowed; elapsed numbers
are observations, not performance/headroom acceptance. Own server cleanup after the
first run proved zero test clients, transactions, data locks, pending MDL and tables.
