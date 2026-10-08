# Membership operative lane 2 (R-1, R-6, A1-1 to A1-4)

- Branch `harness/membership-operative-2`, worktree `/home/user/VA-Studio-member-ops2`, created at `2bd78cce` (head of PR #51).
- Nothing is committed or pushed by this lane; the integration owner commits the working tree.
- Date: 2026-10-08 (UTC). PHP 8.4.26, PHPUnit 12.5.34, SQLite `:memory:`, MySQL 8.4.11 (private instance, port 3741).
- Scope held: default-off and unregistered. No route, provider binding, config default, provider I/O or applied migration. `enabled` and `provider_io_enabled` stay false.
- Sources: `docs/verification/membership-operative-1-20261007/independent-review/DECISION.md` (original decision and Addendum 1).

PHPUnit is invoked as `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`. Every red and green file ends with PHPUnit's own `rc=` line, written on its own line straight after the command returns. "Red" runs the new regression against the unchanged application code (the tests were written first); "green" runs the same selection after the fix.

## Results at a glance

| Finding | Severity | Red (before) | Green (after) |
| --- | --- | --- | --- |
| R-1 `MembershipRows` clone | Low | `R-1/red.txt`: 17 tests, 1 failure | `R-1/green.txt`: 17 tests, 63 assertions, OK |
| R-6 stale settled after reversal | Medium | `R-6/red.txt`: 3 tests, 2 failures; `red-job-overtaken.txt`: 1 test, 1 failure; `red-schema-guard.txt`: 2 tests, 2 errors; `native/R-6-race-red.txt`: 1 test, 1 failure (native) | `R-6/green.txt`: 3 tests, 12 assertions; `green-job-overtaken.txt`: 1 / 3; `green-schema-guard.txt`: 2 / 4; `native/R-6-race-green.txt`: 1 test, 15 assertions (native) |
| A1-1 mode/invoice_identity claim | Low | `A1-1/red.txt`: 14 tests, 2 failures | `A1-1/green.txt`: 14 tests, 54 assertions, OK |
| A1-2 retries and sweep | Low | `A1-2/red-job.txt`: 10 tests, 4 failures; `red-sweep.txt`: 5 tests, 4 errors (command absent) | `A1-2/green-job.txt`: 10 / 28; `green-sweep.txt`: 5 / 26 |
| A1-3 coverage time base | Low | `A1-3/red.txt`: 1 test, 1 failure | `A1-3/green.txt`: 1 test, 6 assertions, OK |
| A1-4 payload and message | Low | `A1-4/red.txt`: 2 tests, 2 failures | `A1-4/green.txt`: 2 tests, 11 assertions, OK |

## Per finding

### R-1 (Low): `MembershipRows` clone releases the live pin

- **Change.** `MembershipRows::__clone()` throws `MembershipException('clone_refused')`. It first sets the half-built copy's own `$pin` to null, because PHP still runs `__destruct` on a copy whose `__clone` threw. My first attempt (throw only) stayed red: the copy's destructor closed the shared pin statement. `app/Domain/Memberships/Production/MembershipRows.php`.
- **Regression.** `MembershipRowsFunctionClosureTest::test_a_clone_of_a_captured_frame_is_refused_and_destroying_it_never_releases_the_live_pin` is the reviewer's `probe-clone-sqlite` scenario: clone, unset, then `createCollation('BINARY', …)` on the live handle. Red: SQLite admitted the replacement (`registered = true`). Green: clone refused, `createCollation` returns false, the callback runs 0 times, `assertCurrent()` still admits. On MySQL the case asserts only the clone refusal and an admitted reader.

### R-6 (Medium): overlapping retrievals can make a stale `settled` snapshot the tail

- **Design.** Serialize by start order at append time, never across provider I/O.
  - Each retrieval records `retrieval_started_at` (microsecond UTC, 26 bytes) before its first provider read.
  - In the append transaction, on MySQL under `SELECT id … WHERE id = ? FOR UPDATE` on the invoice identity row, the append refuses (`superseded_retrieval`, nothing written) a retrieval whose start is strictly earlier than the tail's start. An equal start is admitted.
  - **Refuse, not "append as superseded".** A superseded row would need a new outcome value (CHECK change) and every tail reader (`currentSettled`, coverage, any future consumer) would have to learn to skip it, which is the same class of bug as R-6. A refusal keeps the vocabulary and every reader unchanged, and the newer row already records the later state. The cost is that the older snapshot leaves no row. `RetrieveMembershipInvoice` treats `superseded_retrieval` as a normal end (no retry, no failure).
  - The guard trigger enforces the same rule in the database: the previous-row clause now also requires `o.retrieval_started_at <= NEW.retrieval_started_at`.
- **Schema change to never-applied migration 259000.** `BillingSchema` (the migration is a thin wrapper, so its file is unchanged) gains `retrieval_started_at VARCHAR(26) ascii_bin NOT NULL` on the observations table after `retrieved_at`, a CHECK `length(retrieval_started_at) = 26`, and the extra trigger clause. Migration 259000 has never been applied anywhere, so no data migration or new migration is needed. Both drivers share one `guards()` and one `definition()`, so SQLite and MySQL trigger text and column checks stay consistent, and the existing trigger-text and owned-schema tests stay green. The value is part of each row's seal.
- **API.** `BillingLedger::append()` takes a required `CarbonImmutable $startedAt`. The only production caller is `BillingReconciliation::retrieve()`, which captures it before the provider reads. `BillingValues::utcMicro()` formats it.
- **Regressions.**
  - `BillingOverlappingRetrievalTest` (SQLite and MySQL): the second retrieval runs between the first one's last provider read and its append (`tests/Support/InterleavingBillingGateway`, outside any transaction, as real provider I/O is). Covers an owned identity (chain stays `settled, reversed`; `currentSettled` is null), a stale first retrieval after a fresh one created the identity, and in-order retrievals still append.
  - `BillingSchemaPreparationTest`: older start refused by the trigger, equal and later admitted; non-microsecond start refused by the CHECK.
  - `BillingRetrievalJobTest::test_a_retrieval_overtaken_by_a_newer_one_completes_without_a_retry_and_records_nothing`.
  - **Native two-process:** `BillingNativeStaleRetrievalRaceTest` with `tests/Support/membership-billing-stale-race-worker.php` (a port of the reviewer's `addendum1-stale-race-worker.php`, without the test-clock hacks: ordering is by the real clock and a file barrier). Stale process reads settled and stalls after its last provider read; fresh process starts after that read, reads refunded, appends. Red (old code): stale appended as sequence 3 `settled` after sequence 2 `reversed`. Green: stale `denied superseded_retrieval`, chain `settled, reversed`, `currentSettled` null.
  - The method is native-only (`markTestSkipped` off MySQL) and is added to `scripts/ci/database-sqlite-skips.json`. `python3 -I scripts/ci/test-database-receipts.py`: 34 tests OK (`R-6/test-database-receipts.txt`).
- **Existing test changed.** `BillingNativeDedupRaceTest` asserted that two simultaneous retrievals both save. The native run (first pass) showed the one that began first can now legitimately lose (`superseded_retrieval`). The MySQL branch now asserts: every result is `saved` or `denied superseded_retrieval`, at least one saved, one identity, and a contiguous chain holding exactly the saved observations. The SQLite branch is unchanged (two sequential saves).
- **Residual.** Ordering is as exact as the workers' clocks: starts equal to the microsecond are admitted, and clock skew between app servers can misorder close starts. The start orders retrievals, it does not prove that every provider read of the later one happened after every read of the earlier one.

### A1-1 (Low): `mode` / `invoice_identity` refusals claim the identity

- **Change.** `BillingSettlement::bindingValidated()` (pure) is true only when the retrieved account, invoice (object and id), customer and parent subscription all match the binding. On a first retrieval, a `refused` verdict throws `binding_refused_<reason>` and writes nothing unless it is one of the post-validation refusals with `bindingValidated` true. Account, customer and subscription refusals still always throw (unchanged, including the PaymentIntent-customer case). `invoice_identity` is never validated, so it always throws. A `mode` refusal for a binding whose account/customer/subscription all match is recorded under that binding (pinned by a test): the identity is claimed only after the graph validated the binding.
- **Regression.** `BillingObservationLedgerTest`: with `mode` and `invoice_identity` (a gateway double returning another invoice id), two non-matching bindings each throw `binding_refused_<reason>` with no invoice or observation row, then the correct binding succeeds with sequence 1; plus the validated-binding `mode` case.

### A1-2 (Low): bounded retries and an operator sweep

- **Job.** `RetrieveMembershipInvoice`: `$tries = 3`, `backoff() = [60, 600]`. A first retrieval that is unknown already throws, so the queue retries it. Under an owned identity an `unknown` or `refused/provider_incomplete` observation is appended, then the job `release()`s with the backoff for that attempt while `attempts() < tries`; the last attempt completes (its observation is the record). `BillingReconciliation::isInconclusive()` names those two cases. A definitive outcome never releases.
- **Command.** `membership-billing:sweep-hints [--dispatch] [--limit=100]` (`app/Console/Commands/SweepMembershipBillingHints.php`, `BillingHintSweep`, and `BillingHintRecovery`, which holds the coverage and binding-resolution code moved out of `BillingWebhookIntake` so intake and sweep judge coverage identically). It calls `BillingPolicy::current()` first, so while the policy is off (the shipped default) it prints `Refused (disabled).` and exits 1 without reading the ledger or dispatching, with or without `--dispatch`. Dry run lists uncovered hints by hash prefix and binding id (no provider references). `--dispatch` queues one retrieval per distinct uncovered invoice. No schedule is registered (a test asserts it).
- **Regression.** `BillingRetrievalJobTest` (attempt 1 releases at 60, attempt 2 at 600, attempt 3 completes; incomplete outcome releases; definitive outcomes never release; a first unknown throws and writes nothing) and `BillingSweepHintsCommandTest` (refused when off; dry run lists and dispatches nothing; `--dispatch` dispatches once per uncovered invoice, leaves covered hints and unbound hints alone, writes no ledger row and does not modify events; a second sweep after the retrieval ran dispatches nothing; not scheduled).

### A1-3 (Low): coverage compared the append time

- **Change.** `BillingHintRecovery::observedAfter()` compares the observation's `retrieval_started_at` with the hint's `received_at`, in whole seconds: the retrieval must have begun in a later second than the hint's receipt (`retrieval_started_at >= (received_at + 1s).000000`). Same-second still does not cover (Addendum 1, A1-6, accepted).
- **Regression.** `BillingOverlappingRetrievalTest::test_a_retrieval_that_began_before_the_hint_does_not_cover_it_and_one_that_began_after_does`: a retrieval begins at +10 s, the hint arrives at +20 s (its dispatch lost), the observation is appended at +30 s. Red: it covered the hint. Green: a duplicate delivery re-dispatches; a retrieval that began at +50 s covers.

### A1-4 (Low): plaintext invoice ref in the job payload; generic message

- **Change.** The job keeps the provider invoice reference only as `BillingValues::encrypt(...)` ciphertext (the ledger's own sealing) and exposes it through `invoiceRef()`; `serialize($job)` and so `failed_jobs.payload` hold the binding id (an internal row id) and ciphertext, no `in_…` value. I considered carrying only a hash: the worker needs the real ref for the provider call, and a hash cannot be reversed. `BillingException` messages now read `Production membership billing unavailable (<reason>).`; only a `[a-z0-9_]{1,80}` reason enters the message, anything else reads `unavailable`. All reasons in this domain are code literals.
- **Regression.** `BillingRetrievalJobTest`: serialized job contains no invoice ref and still retrieves it after `unserialize`; first-retrieval refusal message names `binding_refused_subscription` and contains neither the invoice ref nor the binding id; a non-code reason string does not leak into a message.
- Existing assertions `$job->invoiceRef === …` in `BillingWebhookIntakeTest` and `BillingWebhookRedeliveryTest` became `$job->invoiceRef() === …`; `BillingWebhookRedeliveryTest::appendVerdict` passes the new `startedAt` argument.

## Verification

- **SQLite directories** (`sqlite-directories.txt`, JUnit XML beside it): `tests/Feature/ProductionMembershipBilling` **143 tests, 626 assertions, 1 skipped** (the new native-only race; the lane 1 directory was 115 / 520 / 0 skips), `tests/Feature/ProductionMembership` **44 tests, 130 assertions, 3 skipped** (the existing native-only 257/258 cases). No failures or errors.
- **Pint:** `vendor/bin/pint --test` over every changed or new PHP file (22 files): passed (`pint.txt`).
- **CI census:** `scripts/ci/database-sqlite-skips.json` gains the one new native-only method. The existing four lane 1 native-only skips are still not in the file (root's normalization, unchanged by this lane). The shard partitioning was not touched or re-run (`scripts/ci/phpunit-shards.py` not part of this lane).

## Native MySQL 8.4.11 (private instance)

- `mysqld --no-defaults --initialize-insecure`, then started with `--port=3741 --bind-address=127.0.0.1 --socket= --mysqlx=OFF --user=root --innodb-buffer-pool-size=512M`; schema `vaseyaudio_member2`; datadir in the session scratchpad. Ports 3306 and the other lanes' instances (3711, 3721, 3731) were never touched. Shut down with `mysqladmin shutdown` (log: `native/mysqld-server.log`, "Shutdown complete"); datadir deleted.
- Runner `native.sh` (scratchpad, not committed): synthetic `APP_KEY`, `APP_ENV=testing`, `DB_*` pointed at 3741, `--colors=never`. PHPUnit's own `rc=` is on its own line in each file; `native/ledger.txt` has start and end times.
- **R-6 two-process race:** red on the unchanged application code (`native/R-6-race-red.txt`: the stale process was saved as sequence 3 `settled` after sequence 2 `reversed`), green on the fix (`native/R-6-race-green.txt`: 1 test, 15 assertions, stale `denied superseded_retrieval`, chain `settled, reversed`, `currentSettled` null).
- **Changed classes, each run to its own summary, all rc=0, zero skips:**

| Class | Tests | Assertions |
| --- | --- | --- |
| `BillingNativeDedupRaceTest` | 1 | 15 |
| `BillingSchemaPreparationTest` (new CHECK, column and trigger text accepted by the native owned-schema comparison) | 11 | 47 |
| `BillingOverlappingRetrievalTest` | 4 | 18 |
| `BillingRetrievalJobTest` | 13 | 42 |
| `BillingSweepHintsCommandTest` | 5 | 26 |
| `BillingUnknownOutcomeTest` | 11 | 90 |
| `BillingNativeStaleRetrievalRaceTest` (above, run alone) | 1 | 15 |

- **Not run natively (time):** the rest of `tests/Feature/ProductionMembershipBilling` (`BillingObservationLedgerTest`, `BillingWebhookRedeliveryTest`, `BillingDefaultOffBootTest`, `BillingPolicyTest`, `BillingProviderPinTest`, `BillingSettlementTest`, `StripeSdkBillingGatewayTest`) and the `MembershipRows` clone case on MySQL. Native cost was about 1.6 minutes per test, so the full directory is roughly two hours. A first full-directory attempt was stopped after it found the `BillingNativeDedupRaceTest` expectation problem (fixed above); no complete native directory result exists. Two later partial attempts were stopped at the tool's 30 minute background limit without a summary and are not cited.
- Foundation's final exact-SHA run remains the evidence for the remaining native cases.


## Still open (not touched)

- The paid-invoice authority and the staff binding writer wait on step 0.4. Nothing here reads `currentSettled()` outside `BillingLedger`.
- R-4 (`MemberGrantPolicy` admits `verified_production` on SQLite) and R-5 (per-read `IdentityMigrationOwnership::inspect` cost, now also inside the append lock window on MySQL) from the original decision.
- A1-5: the retrieval job is still not unique-guarded. R-6 removes the harm of overlapping retrievals to the tail, and the sweep dispatches once per invoice, but duplicate dispatches from separate deliveries can still each run and a superseded one writes nothing.
- A1-4 evidence: a first-retrieval refusal still leaves no ledger row. The reason is now in the failed-job message and the job payload no longer holds the ref, but a durable non-identity attempt record (or Sean's acceptance of hint plus failed job plus log) is still a condition before C3/C2.
- The sweep command and job backoff are untested against a real queue worker and real provider; the retry/release behavior is proven with Laravel's fake job interactions, and the sync queue does not re-run released jobs.
- `membership-billing:sweep-hints` is auto-discovered by Laravel from `app/Console/Commands` like every other command in the repo. It is policy-gated and unscheduled; root should confirm that this counts as acceptable under "unregistered".
