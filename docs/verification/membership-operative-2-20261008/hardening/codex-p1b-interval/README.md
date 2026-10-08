# Codex P1 on PR #54, `BillingReconciliation.php:42`: bracket each provider read with two database-issued positions

Status: complete for development (SQLite full directories and focused native MySQL; see "Results" and "Not tested").

- Branch `harness/membership-operative-2`, worktree `/home/user/VA-Studio-member-ops2`, base head `3fed2392`.
- Not committed by the lane agent: the integration owner commits these files.
- PHPUnit: `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never <path>`,
  run from the worktree with `public/build` absent. Each result file ends with `rc=` written straight after PHPUnit returns.

## Finding (confirmed)

`f341b725` (see `../codex-p1/README.md`) ordered overlapping retrievals of one invoice by one `retrieval` position allocated
before the provider reads. A token allocated before a read does not order the reads: A takes position 1 and stalls before
`snapshots()`; B takes 2 and reads a settled graph; the provider reverses the payment; A reads the reversal and appends; B then
appends its older settled read, admitted because 2 > 1, and the stale settlement becomes the tail. The other append order was
also wrong: if B appended first, A's newer reversal was refused as `superseded_retrieval`, a normal job end, so the settled tail
stayed current after the provider had reversed the payment.

Both were reproduced before the fix: in one process (`red-BillingRetrievalIntervalOrderingTest-sqlite.txt`, failures 2 and 3:
B's settled read appended as sequence 3 after A's reversal; A refused as `superseded_retrieval`) and with two native MySQL
processes (`native/red-interval-race-at-base.txt`: B `saved`; A `superseded_retrieval`).

## Design

Migration 259000's `BillingSchema` changed in place (not applied in any shared environment; `migrate:fresh` is required for any
local or CI database migrated from an earlier 259000, and the owned-schema check refuses the old shape, so billing fails closed).
`TABLES` and its indices are unchanged.

- **End position.** `BillingLedger::endRetrieval(int $start)` allocates a second `retrieval` position in its own short committed
  transaction, with no transaction open (the same helper as `startRetrieval()`), and requires it to exceed the start.
  `BillingReconciliation::retrieve()` calls it right after the provider reads end (after `snapshots()` returns, or after the
  provider failure that ended the reads) and before the policy re-check, identity claim and append.
- **Observation columns.** `retrieval_end_position` (`BIGINT UNSIGNED` / `INTEGER`, NOT NULL) follows `retrieval_position`. It is
  UNIQUE (`…_observations_u2`), in the CHECK (`retrieval_end_position > 0 AND retrieval_end_position > retrieval_position`), and
  sealed with the row (the seal covers every column; the ledger's integer cast list includes it, so MySQL string reads re-seal).
- **Insert guard (SQLite and MySQL triggers).** The observation is refused unless the start and the end both exist in the
  positions table with kind `retrieval`; `end > start`; neither position appears as any observation's `retrieval_position` or
  `retrieval_end_position`; and, after the first row, the tail's `retrieval_end_position < NEW.retrieval_position`. This replaces
  the old `o.retrieval_position < NEW.retrieval_position` clause; the new rule implies the old one (tail start < tail end < new
  start), so it is strictly stronger.
- **Append rule (`BillingLedger::append`, under the existing MySQL identity row lock).** Admit only when the new start is above the
  tail's end: the new read began after the tail's read ended. Otherwise refuse and write nothing:
  - `superseded_retrieval` when the new read wholly preceded the tail's (new end < tail start). The tail already records a strictly
    later read, so the job ends normally, as before.
  - `concurrent_retrieval` in every other non-admitted case (the reads overlap by position). The database cannot tell which read saw
    the later provider state.
  `append()` also refuses `end <= start` as `invalid_value` before any read.
- **Job.** `RetrieveMembershipInvoice` releases a `concurrent_retrieval` with its existing backoff (60 s, then 600 s) while attempts
  remain; the third attempt rethrows, so the job fails visibly. `superseded_retrieval` is still a normal end. Unknown and
  incomplete outcomes keep their existing release behaviour (the release code moved into one private helper; same delays).
- **Hint coverage is unchanged and stays on the start.** `BillingHintRecovery::observedAfter` still compares
  `o.retrieval_position > hint_position`. Nothing reads `retrieval_end_position` for coverage (`grep` shows it only in the schema,
  the ledger and tests). The start is the right comparison: coverage needs the read to have begun after the hint arrived; a read
  whose end is after the hint but whose start is before it may have read state older than the hint
  (`test_a_hint_received_between_a_retrievals_start_and_end_positions_is_not_covered_by_it`).
- **First retrieval.** No tail, so the observation is admitted (given valid, unused, ordered positions).
- **Refused and failed retrievals.** A first retrieval that ends unknown, provider-incomplete or binding-refused still writes no
  identity and no observation; it now leaves two anonymous `retrieval` position rows (start and end). A retrieval that throws for
  any other reason inside the reads (rethrown before the end is allocated) leaves one. Position rows name no invoice, binding or
  provider reference.

## Why this is correct

AUTO_INCREMENT/AUTOINCREMENT hands out ids from one counter in strictly increasing allocation order and never reuses a committed
id, and each position is committed before the next step of its retrieval. For a retrieval R, `start(R)` is committed before its
first provider read and `end(R)` after its last. So `start(N) > end(T)` means N's start was allocated after T's end was allocated,
which was after T's last read returned; N's first read happened after N's start was committed. Every provider read of N
therefore happened after every provider read of T, and N saw provider state at least as new as T's. Admitting only such an N
means each admitted row's read strictly follows the previous tail's read, by induction across the whole chain, with no clock
compared. Applied to the finding: A = [1, 4], B = [2, 3]. If A appends first, B's start 2 ≤ A's end 4: refused. If B appends
first, A's start 1 ≤ B's end 3: refused for retry, and the retry's start (5 or later) is above 3, so it is admitted and records the
reversal. Conversely, `end(N) < start(T)` means all of N's reads finished before any of T's began, so T is strictly newer and
refusing N as superseded loses nothing. Any other relation is an overlap where the order of the reads is unknown, so refusing
and retrying is the only safe choice; a retry takes a fresh interval and converges once no other retrieval of the invoice
overlaps it. Under sustained overlap the job exhausts its three attempts and fails visibly (fail closed, never a stale tail).

Consequence for the existing R-6 interleaving (a fresh retrieval runs to completion between the stale one's last provider read
and its append): the stale end position is allocated after the fresh retrieval, so the two reads overlap by position and the
stale one is now `concurrent_retrieval` (retried) instead of `superseded_retrieval`. The database cannot see that the stale read
returned before the fresh one began. The stale snapshot is still never appended and the tail stays the reversal. A stale read
whose end position committed before the fresh retrieval began is `superseded_retrieval` as before.

Alternatives rejected: ordering by the end position alone (a retrieval can take its end late after an early read, the mirror of
the finding); a lock or lease held across provider I/O (no transaction may span provider reads, and a stalled worker would block
the invoice); classifying every non-admitted case as `concurrent_retrieval` (works, but wastes a retry when the database can prove
the read was wholly earlier, so the clean `end < tail start` case keeps `superseded_retrieval`).

## Tests

Written first and recorded red against the unchanged application code at `3fed2392` (test files only changed):

- New `BillingRetrievalIntervalOrderingTest` (11, SQLite and MySQL): the finding's exact positions in both append orders (A first:
  B refused `concurrent_retrieval`, tail stays reversed; B first: A refused `concurrent_retrieval`, a retry with a fresh interval
  admitted); wholly later admitted and wholly earlier `superseded_retrieval`; enclosing interval and start equal to the tail's end
  are concurrent; ledger refuses a non-positive start, `end <= start`, and an open transaction; first retrieval records start and
  end with only the start present during the reads; a refused first retrieval leaves its two anonymous positions; the finding
  through the real `BillingReconciliation` in both orders (Fibers; `tests/Support/SuspendingBillingGateway.php` suspends before the
  first read or after the last); a wholly earlier read through the reconciliation (`superseded_retrieval`, using a one-shot
  `TransactionCommitted` listener to run the fresh retrieval right after the stale end-position commit); a hint received between a
  retrieval's start and end is not covered by it.
- `BillingRetrievalJobTest`: new data-provided case, `concurrent_retrieval` released 60 s / 600 s and the last attempt fails with
  the reason, nothing appended. The existing "overtaken" case now runs the newer retrieval after the stale end-position commit
  (a wholly earlier read), so it still asserts a normal end with no release and no failure.
- `BillingSchemaPreparationTest`: new guard test (end position missing, NULL, 0, negative, equal to the start, below the start, a
  `hint` position, never issued; start or end reused as either column on another invoice; a start at or below the tail's end
  refused; a start above it admitted). The `observation()` helper allocates an end after the start; the immutability test now
  counts three positions.
- New native `BillingNativeIntervalRetrievalRaceTest` (2, native MySQL only; `tests/Support/membership-billing-interval-race-worker.php`):
  two processes, A stalls before its first provider read. `reversal_first`: B reads settled and waits after its last read until A
  appended the reversal; B is refused `concurrent_retrieval`, tail reversed. `settled_first`: B appends settled while A is stalled;
  A is refused `concurrent_retrieval`; the retry is admitted as `reversed`.

Adapted existing tests (no safety assertion weakened; the refusal reason changes because the interleaving is an overlap by
position, as explained above):

- `BillingOverlappingRetrievalTest` (2 cases) and `BillingClockSkewOrderingTest` (3 cases): expect `concurrent_retrieval` instead
  of `superseded_retrieval`; every tail, `currentSettled` and no-award assertion is kept. The first overlapping case also asserts
  the retry converges (reversed, sequence 3). One method renamed: `…is_refused_as_superseded_and_the_newer_reversal_stays_the_tail`
  to `…is_refused_and_the_newer_reversal_stays_the_tail` (it is not in the SQLite skip census).
- `BillingNativeStaleRetrievalRaceTest` (2 native cases): expect `concurrent_retrieval` (the stale worker stalls after its last read,
  before its end position). `BillingNativeDedupRaceTest`: a denied racer may be `superseded_retrieval` or `concurrent_retrieval`.
- `BillingObservationLedgerTest` forged-seal case and `BillingWebhookRedeliveryTest::appendVerdict` allocate an end position.
- `tests/Support/membership-billing-stale-race-worker.php` also reports `end_position`.

Independent review input (folded in before reporting):

- **CP-1 (High):** an overlapping retrieval must not end normally. Covered by the design above: `concurrent_retrieval` is released
  with the backoff and the last attempt fails visibly; `BillingRetrievalJobTest::test_a_retrieval_whose_read_overlapped_the_tails_read_is_released_with_the_backoff_and_records_nothing`
  (3 data sets) asserts it is not treated as a normal end. Mutation `mutation-cp1-concurrent-as-normal-end-sqlite.txt` (the job
  ends normally on `concurrent_retrieval`, scratch copy): 3 failures, rc=1. Hint coverage stays on the start position.
- **CP-4 (Low, test gap):** new `BillingSweepHintsCursorTest::test_hints_sharing_one_received_at_second_are_each_examined_exactly_once_across_page_boundaries`
  (five uncovered hints in one intake second; pages of two examine each exactly once in 3 pages; `--limit=1 --all --dispatch`
  examines 5 in 5 pages and queues 5). Written independently of the reviewer's untracked probe, same idea. Mutation
  `mutation-cp4-no-tie-break-sqlite.txt` (keyset reduced to `received_at > ?`, scratch copy): only this new case fails, 8 tests,
  1 failure, rc=1 (the other 7 stay green, confirming the gap).
- **CP-2, CP-3:** recorded under "Not tested / for Sean"; nothing built.

Additional mutation: `mutation-trigger-only-sqlite.txt` removes the application interval check from `append()` (scratch copy).
The SQLite trigger still refuses every overlapping or wholly earlier append (`observation_contention` after the retry loop, so the
reasons change and 7 of 11 interval cases fail), and no stale row is appended: the trigger is an independent backstop.

Census: `scripts/ci/database-sqlite-skips.json` gains the two `BillingNativeIntervalRetrievalRaceTest` methods (180 to 182 pairs).

## Results

SQLite red (before the fix):

- `red-BillingRetrievalIntervalOrderingTest-sqlite.txt`: 11 tests, 18 assertions, 7 errors (`endRetrieval()` undefined), 4 failures
  (refused first retrieval left 1 position not 2; B's stale settled read appended as sequence 3 after A's reversal; A refused as
  `superseded_retrieval`; the stale reconciliation was not refused because no end commit existed), rc=2.
- `red-BillingRetrievalJobTest-sqlite.txt`: 16 tests, 47 assertions, 4 failures (the 3 new `concurrent_retrieval` cases ended
  normally instead of releasing/failing; the overtaken case's seam had no end commit to hook, so the newer retrieval appended
  after the stale one), rc=1.
- `red-BillingSchemaPreparationTest-sqlite.txt`: 13 tests, 35 assertions, 5 errors (the helper writes `retrieval_end_position`,
  which the old schema lacks), 1 failure (the new guard test: a row without an end position was admitted), rc=2.

SQLite green (after the fix):

- `green-BillingRetrievalIntervalOrderingTest-sqlite.txt`: OK, 11 tests, 66 assertions, rc=0.
- `green-BillingRetrievalJobTest-sqlite.txt`: OK, 16 tests, 54 assertions, rc=0.
- `green-BillingSchemaPreparationTest-sqlite.txt`: OK, 13 tests, 77 assertions, rc=0.
- `green-BillingOverlappingRetrievalTest-sqlite.txt`: OK, 4 tests, 20 assertions, rc=0.
- `green-BillingClockSkewOrderingTest-sqlite.txt`: OK, 8 tests, 26 assertions, rc=0.
- `green-BillingSweepHintsCursorTest-sqlite.txt`: OK, 8 tests, 122 assertions, rc=0.
- `sqlite-ProductionMembershipBilling.txt` (+ `.xml`): OK, 179 tests, 886 assertions, 4 skipped (the four native-only race
  methods), rc=0. Before: 161 tests (+11 interval, +3 job, +1 schema, +1 sweep same-second, +2 native interval).
- `sqlite-ProductionMembership.txt` (+ `.xml`): OK, 44 tests, 130 assertions, 3 skipped, rc=0.
- `sqlite-ProductionMemberOriginals.txt` (+ `.xml`): OK, 24 tests, 63 assertions, 1 skipped, rc=0.
- `pint.txt`: `/home/user/VA-Studio/vendor/bin/pint --test` on all 19 changed or new PHP files, passed, rc=0.
- `receipts-self-test.txt`: `python3 -I scripts/ci/test-database-receipts.py`, 34 tests OK, rc=0.
- `test-focused-tests.txt`, `test-phpunit-shards.txt`: the census-reading script self-tests, OK, rc=0.

Native MySQL 8.4.11 (private `mysqld --no-defaults` on 127.0.0.1:3917, datadir in the session scratchpad; lifecycle in
`native/private-instance-lifecycle.txt`, per-file summary in `native/ledger.txt`):

- `native/red-interval-race-at-base.txt`: the new native test at base `3fed2392` (scratch copy: `git archive 3fed2392` plus only
  `BillingNativeIntervalRetrievalRaceTest.php` and its worker): 2 tests, 14 assertions, 2 failures, rc=1. `reversal_first`: B's
  stale settled read was `saved` after A's reversal; `settled_first`: A was refused `superseded_retrieval` (a normal job end).
- Green on the lane tree (`3fed2392` plus this change; each file run alone; driver confirmed by the native-only cases running,
  not skipping):
  - `native/mysql-BillingNativeIntervalRetrievalRaceTest.txt`: OK, 2 tests, 28 assertions, rc=0 (two processes, two connections).
  - `native/mysql-BillingNativeStaleRetrievalRaceTest.txt`: OK, 2 tests, 27 assertions, rc=0.
  - `native/mysql-BillingSchemaPreparationTest.txt`: OK, 13 tests, 77 assertions, rc=0 (MySQL triggers, CHECK, `_u2` unique and
    the owned-schema check with the new column).
  - `native/mysql-BillingRetrievalIntervalOrderingTest.txt`: OK, 11 tests, 66 assertions, rc=0.
  - `native/mysql-BillingClockSkewOrderingTest.txt`: OK, 8 tests, 26 assertions, rc=0.
  - `native/mysql-BillingSweepHintsCursorTest.txt`: OK, 8 tests, 122 assertions, rc=0 (includes the CP-4 same-second case).
  - `native/mysql-BillingNativeDedupRaceTest.txt`: OK, 1 test, 15 assertions, rc=0.
  - `native/mysql-BillingOverlappingRetrievalTest.txt`: OK, 4 tests, 20 assertions, rc=0.
  - `native/mysql-BillingRetrievalJobTest.txt`: OK, 16 tests, 54 assertions, rc=0.
  - `native/mysql-BillingObservationLedgerTest.txt`: OK, 14 tests, 54 assertions, rc=0.
  - `native/mysql-BillingWebhookRedeliveryTest.txt`: OK, 19 tests, 81 assertions, rc=0.
- Each run file has a JUnit `.xml` next to it and records `elapsed_seconds` (80 to 1053 s per file on this shared container). The
  instance was shut down with `mysqladmin` and its datadir deleted (`native/private-instance-lifecycle.txt`).

## Not tested / for Sean

- The full 178-test billing directory on MySQL (focused files only, as instructed), Foundation CI, a real provider, a real queue
  worker releasing and re-running the job (the release is asserted with Laravel's fake queue interactions).
- Liveness under sustained contention: three overlapping retrievals of one invoice in a loop could exhaust the three attempts;
  the job then fails visibly (fail closed). Not load-tested.
- MySQL replication or failover: the argument assumes one primary's AUTO_INCREMENT counter (as in `../codex-p1/README.md`).
- CP-2 (review): positions are bare counter values. If position rows were lost and the counter regressed (failover to a replica
  behind the primary, or a restore from backup) while a retrieval was in flight, the database could reissue an id that the
  in-flight retrieval already holds as its start or end, or hand out ids below an existing tail's end; the ordering argument then
  no longer holds for that retrieval. The guards still refuse a position reused by an existing observation, but not one whose
  row was lost. Nothing is built for this; an operational rule (drain retrieval jobs and verify the counter after a failover or
  restore) or a per-epoch marker would be needed before production.
- CP-3 (review): `created_at`, `retrieved_at` and `freshness_deadline` still come from worker clocks. The append's monotonic
  `created_at` check fails closed (a refusal, retried), never stale, but a clock-ahead worker can make a tail refuse later
  appends with `clock` for the size of the skew and keeps its freshness window measured on its own clock. Nothing is built.
