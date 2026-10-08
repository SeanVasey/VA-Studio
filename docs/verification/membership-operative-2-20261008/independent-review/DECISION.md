# Independent review: membership operative lane 2 (PR #54)

- **Reviewed head:** `6219f283df9bb644ee04a45bb9323ede2e401a80` (`harness/membership-operative-2`). It merges `main` at `705a712e` into code commit `212d0d4c`.
  - The merge changes none of this lane's application or test files: `git diff --stat 212d0d4c 6219f283 -- app/Domain/Memberships app/Jobs app/Console tests/Feature/ProductionMembershipBilling tests/Feature/ProductionMembership` is empty.
  - The only lane file the merge touches is `scripts/ci/database-sqlite-skips.json`. There it takes the union with main's tax-255 entries; the census self-test passes (`review-evidence/census.txt`).
- **Delta under review:** `2bd78cce..212d0d4c`. The base `2bd78cce` is the PR #51 head (lane 1).
- **Reviewer:** an independent reviewer agent that authored no lane commit and changed no app code, test, flag or registration.
  - The same agent wrote lane 1's Addendum 1, where conditions R-6 and A1-1 to A1-4 were set. Each fix is judged against the condition as written there (`../../membership-operative-1-20261007/independent-review/DECISION.md`).
- **Date:** 2026-10-08 (UTC).
  - A container reboot happened after the review worktree was created and before any test ran. No run was lost; every run cited here was made after the reboot.
- **Commits:** none. Nothing is committed or pushed; the integration owner commits.

## Decision

**APPROVE WITH CONDITIONS.** This approves a development merge only, default-off and unregistered, on the same terms and exclusions as lane 1's decision (no activation, no provider I/O, no route or gateway binding, no award or activation writer, no policy facts).

R-1, R-6, A1-1, A1-2 and A1-3 are **closed** against the conditions as written. A1-4 is **closed for the payload and the message**; its durable-attempt-record half stays open, as the lane itself states.

- No path creates a grant, award, credit event, subscription state or reversal; no added line in `app/` writes one (grep exit 1).
- Routes, bootstrap, providers, config, database migrations, Composer and npm manifests and lockfiles, and `.github` are unchanged.
- Nothing binds the gateway, intake, reconciliation, job or command (grep exit 1).
- The shipped config keeps `enabled` and `provider_io_enabled` false (`review-evidence/surface.txt`).

The review found four new Low findings (L2-1 to L2-4) and several Info items. None is Medium or above, and none is a money or authorization bypass. The most consequential is L2-1, measured natively:
- While any append holds the new identity-row lock, the claim of a **different** invoice's identity waits for the whole hold.
- Concurrent retrievals of different invoices deadlock inside the guard triggers. The append's retry absorbs it, but at a cost of 5 to 18 s per retrieval on this host.

**The auto-discovered `membership-billing:sweep-hints` command is acceptable as shipped** (judgement below). Root should still record that acceptance, and L2-2 must be fixed before the sweep is relied on.

## Environment

- **Worktrees.**
  - Review worktree: `/home/user/VA-Studio-review-member2`, made with `scripts/dev/mkworktree.sh` at `6219f283`. It has the main checkout's locked `vendor/` symlinked and its own Composer autoload.
  - Scratch worktree at the same SHA (`$scratchpad/review-member2-mut`): held every mutation, so no mutation touched the worktree the suites ran in. Removed at the end.
- **Toolchain.** PHP 8.4.26, PHPUnit 12.5.34, SQLite `:memory:`. Synthetic `APP_KEY` (`base64:U1NT…M=`); every value is synthetic.
- **Native.**
  - Private `mysqld` 8.4.11 with `--no-defaults --user=root`, listening on 127.0.0.1:3751 (free per `/proc/net/tcp`).
  - Flags: `--socket=` (empty), `--mysqlx=OFF`, `--innodb-buffer-pool-size=512M`. Datadir under the session scratchpad.
  - Databases: `vaseyaudio_review_member2` (head) and `vaseyaudio_review_member2_mut` (mutations).
  - Port 3306, 3711 and the other lanes' instances were never touched.
  - Lifecycle: `review-evidence/native/private-instance-lifecycle.txt`; server log: `native/mysqld-server.log`.
- **Runners.** `sqlite/run-sqlite.sh`, `native/run-native.sh` and `native/run-native-mut.sh` record PHPUnit's own exit status with `rc=$?` on its own line straight after `php` returns. It appears in each run's text file and in `native/ledger.txt` (`phpunit_rc=`).

## Commands and results

`$P` = `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' --`. Counts come from each run's JUnit XML; the text summaries agree. Every run is at `6219f283` with a clean `app database config routes tests`, except the native mutation runs, which are marked as such.

### SQLite

| Selection | Result | Exit |
| --- | --- | --- |
| `tests/Feature/ProductionMembershipBilling` | OK, 143 tests, 626 assertions, 1 skipped (`BillingNativeStaleRetrievalRaceTest`, native only) | 0 |
| `tests/Feature/ProductionMembership` | OK, 44 tests, 130 assertions, 3 skipped (the existing native-only 257 cases) | 0 |
| `tests/Feature/ProductionMemberOriginals` | OK, 24 tests, 63 assertions, 1 skipped (the existing native-only 258 case) | 0 |
| Reviewer probes `probes/Lane2ReviewProbeTest.php` | OK, 7 tests, 13 assertions, 2 skipped (native only); see Probes | 0 |
| `vendor/bin/pint --test` on the 22 changed or new PHP files | `passed` | 0 |
| `python3 -I scripts/ci/test-database-receipts.py` (census self-test) | Ran 34 tests, OK | 0 |

The SQLite counts equal the lane's (`sqlite-directories.txt`: 143/626/1 and 44/130/3).

### Native MySQL 8.4.11, head `6219f283`

| Class or run (`native/<label>.*`) | Tests | Assertions | F/E/S | Exit | Wall time |
| --- | --- | --- | --- | --- | --- |
| `BillingNativeStaleRetrievalRaceTest` (two processes) | 1 | 15 | 0/0/0 | 0 | 142 s |
| `BillingNativeDedupRaceTest` | 1 | 15 | 0/0/0 | 0 | 141 s |
| `BillingOverlappingRetrievalTest` | 4 | 18 | 0/0/0 | 0 | 463 s |
| `BillingRetrievalJobTest` | 13 | 42 | 0/0/0 | 0 | 1533 s |
| `MembershipRowsClone` (`MembershipRowsFunctionClosureTest --filter clone`) | 1 | 1 | 0/0/0 | 0 | 145 s |
| `BillingSweepHintsCommandTest` | 5 | 26 | 0/0/0 | 0 | 439 s |
| `BillingObservationLedgerTest`, not run natively by the implementer | 14 | 54 | 0/0/0 | 0 | 1247 s |
| `BillingWebhookRedeliveryTest`, not run natively by the implementer | 19 | 81 | 0/0/0 | 0 | 1592 s |
| `BillingWebhookIntakeTest`, not run natively by the implementer | 6 | 48 | 0/0/0 | 0 | 527 s |
| `StripeSdkBillingGatewayTest`, not run natively by the implementer | 6 | 40 | 0/0/0 | 0 | 625 s |
| **Lane classes total** | **70** | **340** | **0** | **all 0** | |
| `probes-native`: reviewer probes `--filter test_native_` (run 1) | 2 | 52 | **1 F**/0/0 | **1** | 274 s |
| `probes-native-2-cross-invoice`: reviewer probes (run 2) | 2 | 13 | 0/0/0 | 0 | 270 s |

- **Run 1's failure is a finding, not a harness fault.**
  - The probe assumed the append lock is per invoice and asserted that a retrieval of the *same* invoice waits more than 3 s for it.
  - It did not wait (2.73 s), because the step before it (claim and append of a *different* invoice) was itself blocked for the whole hold (9.34 s). The same-invoice retrieval therefore started only after the lock was released.
  - Run 2 isolates that wait (L2-1).
  - Run 2's two methods were appended to the probe file after run 1, leaving run 1's methods unchanged. `probes/probe-revision-run2.sha256` is the hash of the revision run 2 used.
- **Not run natively:** `BillingDefaultOffBootTest`, `BillingPolicyTest`, `BillingProviderPinTest`, `BillingSettlementTest`, `BillingUnknownOutcomeTest`, `BillingSchemaPreparationTest`, and the rest of the two other directories. The implementer ran `BillingUnknownOutcomeTest` and `BillingSchemaPreparationTest` natively; the others are pure or default-off classes.

### Native mutation runs (scratch worktree, database `_mut`)

| Run | Mutation | Result | Exit |
| --- | --- | --- | --- |
| `mutation-R6-no-mysql-lock-native` | `lockIdentity()` call removed | lock race: 6 rounds, starts monotonic, tail `reversed`; OK 1 test, 45 assertions (the mutation **survives**) | 0 |
| `mutation-R6-no-mysql-lock-storm-native` | same | cross-invoice split: claim of B still waited 8.67 s (held by the probe's own `FOR UPDATE`); storm: 2 more deadlocks, 20/20 saved | 0 |

After each run the mutation was reverted with `git checkout -- app`, and the ledger records `restored_dirty=0`.

### Mutations (SQLite), `mutations/summary.jsonl`

`mutations/mutate.py` applied each mutation in the scratch worktree and ran the named classes. After each, it restored the file and verified the restored file's hash equals `HEAD`'s and that `git status` was clean (true for all 19). A mutation is killed when any selected run exits non-zero.

| Fix | Mutation | Verdict |
| --- | --- | --- |
| R-1 | `__clone` throws without first dropping the copy's pin | killed |
| R-1 | `__clone` removed | killed |
| R-6 | application `superseded_retrieval` check removed (the trigger still refuses) | killed |
| R-6 | equal start refused (`<` for `<=`) | killed |
| R-6 | trigger clause `o.retrieval_started_at <= NEW.retrieval_started_at` removed | killed |
| R-6 | `retrieval_started_at` captured after the provider reads | killed |
| R-6 | MySQL `lockIdentity()` removed | survives on SQLite (MySQL-only code) and natively (above) |
| A1-1 | `! $validated` dropped from the first-retrieval refusal | killed |
| A1-1 | invoice object and id clause dropped from `bindingValidated()` | **survives** (L2-4) |
| A1-2 | `release()` removed | killed |
| A1-2 | `$tries = 1` | killed |
| A1-2 | `superseded_retrieval` rethrown by the job | killed |
| A1-2 | sweep dispatches once per hint instead of once per invoice | killed |
| A1-2 | command skips the policy gate | killed |
| A1-3 | coverage compares `created_at` instead of `retrieval_started_at` | killed |
| A1-3 | same-second retrieval covers (`+1s` dropped) | **survives** (Info) |
| A1-4 | invoice ref stored in plaintext in the job | killed |
| A1-4 | generic exception message | killed |
| A1-4 | unfiltered reason in the message | killed |

## Probes (`probes/Lane2ReviewProbeTest.php`, workers beside it)

| Probe | Driver | Observation |
| --- | --- | --- |
| Sweep reachability | SQLite | 6 covered hints (older) and 1 uncovered hint (newest). `--limit=5 --dispatch` gives pushed 0 with the truncation warning; `--limit=7` gives pushed 1; `--limit=1001` gives exit 2 (cap). |
| Unroutable hints | SQLite | A hint for an unbound subscription and an `invoice_payment.paid` for an invoice with no identity are both reported as "2 examined hint(s) need nothing". |
| Superseded by a later `unknown` | SQLite | Owned identity. A refunded (`reversed`) read that began first is refused because a later retrieval ended `unknown` and appended first. The job returns normally; chain `settled, unknown`; `currentSettled` null. |
| `APP_KEY` rotation | SQLite | No plaintext ref in the serialized job. Under a new key with `previous_keys = [old]` the job decrypts. Without it the job refuses `ciphertext`, with message "…unavailable (ciphertext)." and no ref. |
| Deterministic refusal | SQLite | A first retrieval under a wrong binding throws `binding_refused_subscription` on every attempt, so the queue spends all three attempts (60/600 s backoff) on it. |
| Concurrent appends, same invoice (6 rounds) | MySQL | Both retrievals reach the append together. Three times the earlier-started one was refused (`superseded_retrieval`); three times it saved first. It never became the tail after the later one. `retrieval_started_at` is monotonic along the 10-row chain; no error or deadlock. |
| Lock scope (run 1) | MySQL | With `FOR UPDATE` held on invoice A for 8 s: a duplicate webhook delivery for A took 0.64 s (`scheduled=dispatched`); the sweep scan took 0.61 s; claim and append for invoice B took 9.34 s (blocked). |
| Cross-invoice split (run 2) | MySQL | With the lock on A held 8.0 s: the **claim** of invoice B's identity took 8.67 s; the subsequent append to B took 2.78 s. |
| Cross-invoice storm (run 2) | MySQL | 4 workers, 4 invoices, 5 retrievals each, released together. InnoDB `lock_deadlocks` went 0 → 2; the latest deadlock was two `INSERT … observations` waiting on S/insert-intention locks on the observations PRIMARY index. All 20 retrievals saved (the append's PDOException retry); per-retrieval time 4.9 to 18.4 s. |

## Judgement of each fix

### R-1 (`MembershipRows` clone): closed
- `__clone()` (`MembershipRows.php:98`) nulls the copy's pin and throws, so the copy's destructor has nothing to close.
- Evidence:
  - Both mutations are killed.
  - The lane's SQLite regression reproduces the original probe (clone, unset, `createCollation('BINARY')` refused, 0 callbacks).
  - The case passes natively.
- Info: an explicit `$rows->__destruct()` call on the live frame still closes its pin. That requires deliberate in-process code, the same class as I-1 in the original decision.

### R-6 (overlapping retrievals): closed

The condition asked for per-invoice serialization, or an append-time ordering rule with a named key, plus a native two-process regression. The lane delivered the second:
- Key `(invoice_id, retrieval_started_at)`, enforced twice: in `BillingLedger::append` (`BillingLedger.php:120`) and in the guard trigger.
- A native regression (`BillingNativeStaleRetrievalRaceTest`) that was red before and is green now.
- My same-invoice race adds simultaneous appends: six rounds, ordering always held.

- **Refusing rather than recording the superseded snapshot is right.**
  - A recorded "superseded" row would need a new outcome, and every tail reader would have to skip it. That is the R-6 bug class again.
  - The refused snapshot is older than a row already in the chain, and the provider state can be re-read.
  - The one cost found: a definitive read can be dropped in favour of a later `unknown` (probe). The tail is then `unknown`, `currentSettled` is null (fail-closed), and the `unknown` neither covers the hint nor stops the newer job's release. So the state is retrieved again by the retry, a redelivery or the sweep. Info.
- **Clock-bound ordering is acceptable with a condition (L2-3).**
  - Equal-microsecond starts being admitted is immaterial.
  - Cross-host skew is not: `retrieval_started_at` comes from the worker's clock, and coverage compares it with `received_at` from the intake host's clock (`BillingWebhookIntake.php:69`, `BillingHintRecovery.php:87`).
- **The `FOR UPDATE` append lock is not load-bearing for ordering.**
  - With it removed, the unique `(invoice_id, sequence)`, the trigger clause and the append retry still ordered six native rounds correctly.
  - It does add the cross-invoice blocking in L2-1.

### A1-1 (identity claimed before the binding is validated): closed, with a test gap
- `BillingSettlement::bindingValidated()` (`BillingSettlement.php:246-251`) requires account, invoice object and id, customer and parent subscription to match. A first-retrieval refusal claims only when that holds (`BillingReconciliation.php:75`).
- Killing `! $validated` turns the lane's regression red.
- Dropping the invoice-id clause is not caught (L2-4). The lane's `invoice_identity` case uses bindings that also fail on subscription, so the clause is never the deciding one.
- The code is correct.

### A1-2 (acknowledged-webhook recovery): closed, with L2-2
- **Job.** `$tries = 3`, backoff 60/600 s, and `release()` after an appended `unknown` or `provider_incomplete` (`RetrieveMembershipInvoice.php:30-71`). A first unknown throws, so the queue retries it. Every job mutation is killed.
- **Sweep, set dispatched.** It dispatches once per distinct uncovered invoice (dedupe mutation killed). It skips covered hints and ones it cannot route. It reads no provider and writes no row.
- **Sweep, safe when off.**
  - `BillingPolicy::current()` runs before any ledger read (`SweepMembershipBillingHints.php:31`).
  - With the shipped config the CLI prints "Refused (disabled)." and exits 1 (`surface.txt`); the gate mutation is killed.
- **Sweep, reachability.** It examines only the oldest `--limit` (at most 1000) retained hints (L2-2).
- **Retry and release against `superseded_retrieval` and A1-5:**
  - Duplicate work: a released job and a redelivery dispatch can both run. That is harmless: GET-only, one chained row each, and an overtaken one ends without a row or a retry.
  - Lost work: an overtaken definitive read is dropped (probe above), but the newer job releases or the hint stays uncovered for the sweep.
  - Deterministic refusals (`binding_refused_*`, `conflicting_invoice`, `disabled`) spend all three attempts (Info).
  - No combination found loses a hint permanently while the sweep reaches it.

### A1-3 (coverage time base): closed
- Coverage uses `retrieval_started_at`, in whole seconds, with the start in a later second than receipt (`BillingHintRecovery.php:85-87`). The `created_at` mutation is killed.
- The same-second rule is not pinned by any test (`+1s` mutation survives; Info). It only fails toward one more retrieval.

### A1-4 (payload and evidence): payload and message closed; durable record open
- The job carries only `BillingValues::encrypt` ciphertext (`RetrieveMembershipInvoice.php:37`), and messages name a code-shaped reason (`BillingException.php`). All three mutations are killed.
- **Rotation is safe.** With `APP_PREVIOUS_KEYS` the job decrypts. Without it, it refuses `ciphertext` with no plaintext; the queue retries, then fails, and the hint stays for the sweep.
  - The ledger's own payloads have the same key dependency, so the job adds none.
  - During a rolling deploy, a worker still on the old key retries after the 60 s backoff.
- The durable non-identity attempt record (or Sean's acceptance of hint plus failed job plus log) is still open, as the lane states. The condition carries forward.

## Findings

| ID | Severity | Location | Finding | Recommendation |
| --- | --- | --- | --- | --- |
| L2-1 | Low (performance/liveness; native) | `BillingLedger.php:115,183`; `BillingSchema.php` invoices and observations insert guards | (1) The append's `SELECT … FOR UPDATE` X-locks an invoice row for the whole append. The invoices insert guard (`… WHERE invoice_ref_hash = NEW.invoice_ref_hash OR source_invoice_hash = NEW.source_invoice_hash`) runs as a locking read inside the INSERT and waits on it, so the claim of **any other** invoice waits for every in-flight append (8.67 s under an 8 s hold). Invoice rows are otherwise never X-locked, so this wait is new in lane 2. (2) Concurrent retrievals of different invoices deadlock in the observations insert guard (2 in 20, with or without the new lock, so this predates the lane). The 3-attempt PDOException retry absorbed them; more concurrency can exhaust it (`observation_contention`). (3) The append holds the lock across `assertOwned` (R-5), about 2.7 s per append natively here. The lock is not needed for ordering (native mutation survives). | Before activation, together with R-5: make the guard subqueries index-only (split the `OR` into two `NOT EXISTS` on their unique indexes; check the observations guard plans), drop the `FOR UPDATE` or move `assertOwned` out of the lock window, and re-measure natively under concurrency, including the deadlock counter. |
| L2-2 | Low (liveness; operator tool) | `BillingHintSweep.php:17,29` | `scan()` reads `retrieval_hint` rows `ORDER BY received_at, id LIMIT --limit` with `--limit` capped at 1000. Hints are retained forever, so once 1000 retained hints exist the sweep examines only the oldest, which will normally all be covered; newer uncovered hints are never reached. The truncation warning tells the operator to raise a limit that is already at its cap. | Before the sweep is relied on (before root mounts intake): select uncovered hints in SQL, scan newest first, or add a cursor (`--after`). Add a regression with more than `--limit` covered hints older than one uncovered hint. |
| L2-3 | Low (forward) | `BillingReconciliation.php:40`; `BillingWebhookIntake.php:69`; `BillingHintRecovery.php:87` | Ordering (R-6) and coverage (A1-3) compare timestamps taken on different hosts: worker clocks for `retrieval_started_at`, the intake host's clock for `received_at`. Skew larger than the gap between two overlapping starts misorders them. Skew of a second or more lets a retrieval that began before a hint cover it. | Before activation: take both timestamps from the database clock (`UTC_TIMESTAMP(6)`, read outside any transaction before the provider reads), or record an NTP-synchronisation requirement for every app and worker host in the runbook. |
| L2-4 | Low (test gap) | `BillingSettlement.php:248` | The invoice object and id clause of `bindingValidated()` is untested: removing it leaves every test green. Without it, a first retrieval where the provider returns a different invoice for a binding whose account, customer and subscription match would claim the requested invoice's identity on the strength of another invoice's data. | Before C3/C2: add the regression (matching binding, provider returns another invoice id → throws, no rows). |
| I-1 | Info | `BillingHintSweep.php:46`; `SweepMembershipBillingHints.php:42` | Unroutable hints (unbound subscription; `invoice_payment.*` with no identity) are counted in "need nothing", the same as covered ones, so the dry run hides them. | Report them separately. |
| I-2 | Info | `RetrieveMembershipInvoice.php:55-71` | Deterministic refusals use all three attempts. A definitive read can be dropped as superseded by a later `unknown`; this is recovered by the retry or the sweep. | Optionally `fail()` on non-transient reasons. |
| I-3 | Info | `BillingHintRecovery.php:87` | The same-second non-coverage rule (A1-6) is not pinned by a test. | Add one. |
| I-4 | Info | `BillingSchema.php` (migration 259000 changed in place) | The README says 259000 "has never been applied anywhere". It has been on `main` since `e94417a8` (PR #51), so any development or CI database migrated from `main` since then has the old observations table. Billing then fails closed there until `migrate:fresh`. No production install exists. | Say "not applied in production or any shared environment", and note `migrate:fresh` for local databases. |
| I-5 | Info | `MembershipRows.php:87` | An explicit `__destruct()` call still releases the live pin (deliberate in-process code). | None. |
| I-6 | Info | `BillingNativeDedupRaceTest.php` | The relaxed native expectation (`saved` or `superseded_retrieval`) is justified by R-6 and still requires one identity and one contiguous chain of exactly the saved rows. | None. |

No finding is Medium or above.

## The auto-discovered `membership-billing:sweep-hints` command

**Acceptable as shipped.** Laravel discovers it from `app/Console/Commands`, like every command in the repository, and `php artisan list` shows it. "Unregistered" in this release boundary means no route, no provider or gateway binding and no schedule, and the command adds none of those:
- It is never scheduled (lane test).
- It checks the billing policy before any ledger read and refuses while billing is off (shipped default; CLI run in `surface.txt`; gate mutation killed).
- It is a dry run unless `--dispatch` is given.
- It prints hash prefixes and binding ids, never provider references.
- It makes no provider call and writes no row.
- With `--dispatch` it only enqueues `RetrieveMembershipInvoice`, which fails closed while no gateway is bound.

It is an operator surface, so root should record the acceptance. L2-2 must be fixed before the sweep is the recovery path A1-2 relies on.

## Conditions

1. **L2-2, before root mounts webhook intake:** make the sweep reach every uncovered hint, with a regression.
2. **L2-1, before activation (with R-5):** index-only guard reads, drop or narrow the append lock, keep `assertOwned` out of the lock window, then a native concurrency measurement including deadlocks.
3. **L2-3, before activation:** a single clock source for `received_at` and `retrieval_started_at`, or a recorded NTP requirement.
4. **L2-4, before C3/C2 consumes Billing evidence:** the invoice-id regression for `bindingValidated()`.
5. **Carried, unchanged:**
   - A1-4's durable non-identity attempt record, or Sean's acceptance of hint plus failed job plus log, before C3/C2.
   - R-4 before C4.
   - R-5 before activation. The identity inspection now also sits inside the MySQL append lock window, as the lane notes.
   - A1-5 (job not unique-guarded) stays Info. R-6 removed its harm to the tail.
6. **Release boundaries:**
   - `enabled` and `provider_io_enabled` stay false.
   - No gateway or route is bound.
   - No live mode or real Stripe I/O.
   - Sean's §0.3 facts remain unsupplied.

## Not reviewed

- Real Stripe I/O and a real queue worker: release and backoff are proven with Laravel's fake job interactions, and the sync queue does not re-run released jobs.
- The native classes listed above as not run, and Foundation CI (cost policy).
- Performance beyond the timings recorded here. R-5 is still unmeasured as a latency budget.
- Main's own changes brought in by the merge (tax-255 and others) beyond the census union.

## SHA-256 at `6219f283` (`review-evidence/sha256-changed-files.txt`)

Every file below is identical at `212d0d4c` and `6219f283` and in the review worktree, except the census file, which the merge changed (union).

| Path | SHA-256 |
| --- | --- |
| `app/Console/Commands/SweepMembershipBillingHints.php` | `c402db9138527ff844b86a2e2c684e066e8f5fba78b846b23f573242ab37cad8` |
| `app/Domain/Memberships/Billing/BillingException.php` | `8e9490b3ba5085d8df52723cb1780359abde63cc5ea85cc6199ceafec025c23b` |
| `app/Domain/Memberships/Billing/BillingHintRecovery.php` | `1c1de69f0e118e8c4a2c4680ec565613b2d0a73f2704fff272cb6391d1b2bdb0` |
| `app/Domain/Memberships/Billing/BillingHintSweep.php` | `3cec2cc745a9f636fc59d8923963bf70ac06ca436df454fc7002e22794bb6df3` |
| `app/Domain/Memberships/Billing/BillingLedger.php` | `56f499550cc47fe6ae59b6a48a3650b0fd02041bcb8fa84725209351dc75c8cb` |
| `app/Domain/Memberships/Billing/BillingReconciliation.php` | `cb059ba34b2a1c8237ebdc5fa1869f731b3065756e8a8882e4a5adeaf3f8d330` |
| `app/Domain/Memberships/Billing/BillingSchema.php` | `2f7c680c59912fca76fd4d81bb9826dbef7ecdbaa31ffab0f16ecb804c8ceea5` |
| `app/Domain/Memberships/Billing/BillingSettlement.php` | `dd57b8f9cbae7c34b95ccdf0ce4cd900efe730a24b647b688ca9f3666dfce02b` |
| `app/Domain/Memberships/Billing/BillingValues.php` | `78c2069fe6cd8b7a936ac2eae0da761e0d7f9dbc0e6d0e776305cc66ce400f01` |
| `app/Domain/Memberships/Billing/BillingWebhookIntake.php` | `50341abcb171e9d9be5d3f657982a0d036ec461f23d382529457ae7c675b090d` |
| `app/Domain/Memberships/Production/MembershipRows.php` | `3aadc3e510c1d206b4b266a4d056a2368776bb09e63c030a45091b9ba795d63e` |
| `app/Jobs/RetrieveMembershipInvoice.php` | `464d89993a95c4050a9664793cc2e6f535a8fc0f7b6b84d4c6db8fdafec1fb41` |
| `scripts/ci/database-sqlite-skips.json` (at `6219f283`; merge union) | `19e532834982f4afedc0d20ae5ea7efccd49bf08faa5930eb1d9ad275154966b` |
| `tests/Feature/ProductionMembership/MembershipRowsFunctionClosureTest.php` | `43d687142393a3a6adb7f828c85f613b4f3d3d74d40540762e99c30b15188ff1` |
| `tests/Feature/ProductionMembershipBilling/BillingNativeDedupRaceTest.php` | `0a95e08544ecda19a25b7f1bd727e86e4240309a355f0b8fd2430b13e07c61e7` |
| `tests/Feature/ProductionMembershipBilling/BillingNativeStaleRetrievalRaceTest.php` | `c91cdd2d5f4a050b782c6a470f4badd6160b565e061e4ef76835ecaf2b8ca490` |
| `tests/Feature/ProductionMembershipBilling/BillingObservationLedgerTest.php` | `39c7392672c8cf084954b7b3c9a4731d1ca0926e8cae8d6ef74067bb5e0ee1db` |
| `tests/Feature/ProductionMembershipBilling/BillingOverlappingRetrievalTest.php` | `3c14a7e118bddd32d4ed41c5476bd06cf78208413f617e948b0e412581e7376c` |
| `tests/Feature/ProductionMembershipBilling/BillingRetrievalJobTest.php` | `dcd0ba9b68d7202123b5dd73ba393c08c1e2c212e55b480e5c14b6e1c9277aca` |
| `tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php` | `5f8fbb5f12e8191854d9c40b3ed9f742dc59217c3256340e43c6b43cfa9a6550` |
| `tests/Feature/ProductionMembershipBilling/BillingSweepHintsCommandTest.php` | `7c178cc1eba97d3f3d671b4b7119be213fb41e10d60ab0f372dc3b36e37563f6` |
| `tests/Feature/ProductionMembershipBilling/BillingWebhookIntakeTest.php` | `55c18aa05e17fa5aed4dd4a9782bf5d6a087044071a80d3890617e2e3ed8cfde` |
| `tests/Feature/ProductionMembershipBilling/BillingWebhookRedeliveryTest.php` | `c29a02ff6786148981ab662292c2dec28d63c8123c13ac6dbdbbec7384424f54` |
| `tests/Support/InterleavingBillingGateway.php` | `990d4e06e1b491b68d976f34df6a5eacc43c87bb359a86318e3ccea4ce0283a4` |
| `tests/Support/membership-billing-stale-race-worker.php` | `eaa3ae295e24db5ac5c95933619ef596b3f136167ef1b86ccb16f27e80f8c008` |

## Evidence index (`review-evidence/`)

- `surface.txt`: frozen-surface diff, registration and write greps, `artisan list`, the shipped-config CLI refusal, and the migration file check.
- `census.txt`: the merge's census diff, the native-only entries, and the self-test (with its rc on its own line).
- `pint.txt`: the Pint result.
- `sha256-changed-files.txt`: the hashes above.
- `sqlite/`: `run-sqlite.sh`, plus text and JUnit for the three directories and the reviewer probes.
- `native/`:
  - `run-native.sh`, `run-native-mut.sh`, `chain.sh`, `chain2.sh`
  - `ledger.txt`, `private-instance-lifecycle.txt`, `mysqld-server.log`
  - text and JUnit for every native run in the tables, including the failing reviewer probe run 1
  - the mutation diff applied for each native mutation run
- `mutations/`: `mutate.py`, `mutate-apply.py`, `summary.jsonl`, and per-mutation `.diff` and `.txt`.
- `probes/`: `Lane2ReviewProbeTest.php`, the three native workers and their shared bootstrap, and `probe-revision-run2.sha256`.

## Cleanup

- **Private instance.** `mysqladmin shutdown` exited 0 at 13:08:02Z and pid 1886 exited. Port 3751 had no listener afterwards, and no `mysqld` referenced the datadir. The datadir (255M) was removed (exit 0).
  - Other lanes' instances and :3306 were not touched. No broad `pkill` was used.
- **Scratch worktree.** The mutation worktree `$scratchpad/review-member2-mut` was removed with `git worktree remove --force`. Its only untracked content was copies of the probe files; `app/` was clean.
- **Review worktree.** `git status` shows only the untracked `docs/verification/membership-operative-2-20261008/independent-review/`. Nothing was committed or pushed.
