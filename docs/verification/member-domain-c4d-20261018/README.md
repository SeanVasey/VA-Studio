# C4d member domain: customer index, pre-bytes proof and commit witness

Lane C4d (domain only) on branch `harness/member-domain-c4d-20261018`, from `origin/main` `7cd95b73`. It adds the
read model and domain checks the member HTTP lane (C4e) will mount, and closes the frozen ordinary afterCommit P2.
Nothing is mounted, registered or enabled: `routes/web.php`, `bootstrap/app.php`, `AppServiceProvider`,
`composer.lock` and the Filament tests are untouched (lane D1 owns the first three for now).

This is a local development candidate. Nothing has been pushed, no PR is open and no workflow was dispatched. The
lane touches payment-adjacent ledger writers, authorization and private data, so it needs an independent review of the
exact SHA before merge.

## Owner decision and its exact limits

Sean approved C4 on 2026-10-18 and confirmed `explicit_owner_confirmation_v1` as the member-license activation mode. The
code already accepts that mode and no other (`MembershipConsumptionTerms::from`, `MemberLicenseReviewDisplay`): a
retained technical record that the owner requested activation (`record_claim` `owner_requested_activation`). It is
neither legal assent nor write authority. No code change was needed for the decision itself.

The approval does **not** approve:

- any display wording, consumption terms or retention text;
- any policy, definition, profile or terms hash.

`production-memberships.approved_policy_hash` and the three `member-grants.approved_*_hash` values stay `null`, and
`enabled` stays `false`, in `config/production-memberships.php` and `config/member-grants.php`. This lane does not
invent or fill any of them. The decision is appended to `docs/architecture/decision-register.md`.

## Design

### Customer read model: `MembershipCustomerIndex::index`

`app/Domain/Memberships/Production/MembershipCustomerIndex.php`. Output (camelCase, like the Paid/PaidTax indexes):

```
{schemaVersion: 1, periodLimit: 100, hasMore, provenance,
 periods: [{id, planVersionId, periodStart, periodEnd, creditExpiresAt, allowance,
            balances: {available, reserved, consumed, expired},
            applicableDefinition: {id} | null,
            reservations: [{id, offerId, offerRevisionId, creditAmount, reservedAt, honorDeadline,
                            status: reserved | released | consumed, closedAt}]}]}
```

- **Gates.** `MembershipPolicy::current()` and `MembershipAwardConfiguration(requireComplete: true)` (the operative
  membership gate, as in the writers), then `MemberGrantPolicy::currentFor($rows)` inside the frame. Both families must
  admit; both default off. The principal, membership and member-grant provenance must agree.
- **Bound and sentinel.** `LIMIT = MembershipPolicy::MAX_PERIODS` (100). One query reads at most 101 of the owner's
  periods, ordered `period_start DESC, id DESC` (period ids are random UUIDs, so id order is not age). The 101st row
  only sets `hasMore`. It is authenticated exactly like a listed row: a tampered sentinel refuses the whole index.
- **Lock order.** In one transaction: `ProductionCustomerAccess::lock` (current-owner lock) first, then
  `BillingAwardFence::lock`, then the period window `FOR UPDATE` and the per-period reads. This is the order every
  award, reserve, release, expire and activation already uses ("T23 identity locks first; the fixed fence precedes
  every membership/billing lock", `MembershipBillingEvidence::lock`). Every fence holder is therefore serialized
  before it takes a membership row, so the index cannot form a lock cycle with those writers.
- **Per period (sentinel included).** Owner columns must equal the principal and the current identity origin. The
  member-history reader captures the exact period scope (period, events, redemptions and member graph). The period's
  immutable plan gives the policy binding. `MembershipPeriodRecords::historicalInvoice` reconstructs the retained
  invoice semantics from the sealed period payload; its original buyer must be the current durable owner (account,
  user, origin and public account id). `verifyHistoricalBinding` checks the original identity act.
  `MembershipLedgerAudit::verify` then audits the complete chain. Any refusal refuses the whole index.
- **Applicable definition.** It is the single approved member definition (`member-grants.approved_definition_hash`,
  with the approved profile and terms hashes and provenance, checked through `MemberGrantRecords::snapshot` and
  `SealedMemberGrantFacts::plan`). A period shows it only when the definition's plan version is the period's own;
  otherwise `null`. With today's null hashes the member-grant gate refuses, so nothing is shown.
- **Current proof** before commit: history `currentCallbacks`, audit, historical and current owner proofs, member-grant
  frame proof, then raw comparisons of the window, the definition, profile and plan rows and each period's plan.
- **Terminal proof** after the ordinary commit and every committed delegate: a read-only `IdentityCommittedFrame`
  repeats the history, audit and owner proofs (`proveCommitted`), the captured member-grant configuration and the raw
  window and support rows without locks, then `finish()`.
- **Refusals.** Domain reasons pass through as `MembershipException`. An `IdentityException` becomes `owner_refused`.
  Anything else (including member-grant refusals) becomes `index_refused`, with the cause kept as `previous`.
- **No writes.** The test asserts unchanged row counts across every membership and member table.

The index uses `DB::transaction` with a non-owned `MembershipRows` marker, as `PaidGrantReads::index` and the
reservation test fixtures do, rather than the writers' owned observer. A read has no durable effect, so a callback
failure at commit is only a refusal that is safe to retry. The committed-frame terminal proof re-reads everything after
all delegates have run, which is what the writers' committing-phase proof protects for writes.

### Provider identifier decision

Today `reserve`, `release` and `expire` take `$fixture['invoice']['id']` as their locator. Verified against the code,
this is **not** the Stripe invoice id. It is the billing ledger's own invoice-identity row id
(`BillingLedger::retainInvoice`, `BillingValues::id()`), and the provider reference stays encrypted in that row. It is
still a billing-ledger identifier that a customer has no reason to see, and exposing it would couple the customer
surface to billing internals.

So the index exposes **only the membership paid-period id** (`production_membership_paid_periods.id`, already returned
by `award`/`reserve` projections), and the domain resolves the billing row itself:
`MembershipReservationOperations::reserveInPeriod`, `releaseInPeriod` and `expireInPeriod` route the period id to its
retained invoice row with a non-locking join inside the owned frame (the same pattern as
`MembershipReservationContext::inspectAt` for redemption ids). That routing confers nothing: the context then locks and
authenticates invoice, owner, policy and period afresh, and the projection must name the requested period
(`changed_period`). Another customer's period refuses at the invoice owner check (`invoice_owner_mismatch`). An unknown
period refuses with `paid_period_absent`, and a redemption of a different period with `reservation_absent`. C4e should
map all of these to one not-found response so the reasons are not an existence oracle.

### Pre-bytes check: `MembershipCustomerRead::proveBeforeBytes`

Operations step 6 (`docs/handoff/2026-10-07/owners/memberships-operations.md`) requires fresh owner, credential, flag,
deadline and graph fences after callback work and after commit, before a prebuilt private response is released.
`index($principal, $actor, MembershipCustomerRead::begin())` registers the original principal, actor, deadline,
projection and gate baselines in the index's private registry. `proveBeforeBytes()` is one-use. It re-runs the complete
owned read under the **original** 60-second deadline (no renewal): owner lock, fence, gates, window, every audit and the
committed terminal proof. It releases only when the fresh projection and the gate baselines are identical. A read
cannot be captured twice or adopted by another index call, and it refuses serialization and cloning.

A re-run was chosen over re-proving captured snapshots because `MemberActivationHistoryReader` admits exactly one
committed frame per snapshot, and a second raw-row reader for the member graph would duplicate that closure. The cost is
a second complete read, including a brief owner and fence lock.

Other reads C4e will serve:

- `MemberLicenseAuthorizations::review` (display) and `MemberGrantActivations::readActivated` (activation identifiers)
  end in their own committed-frame `finish()`, and `readActivated` also re-observes the retained original file. Neither
  has a pre-bytes hook yet. **It is needed, not unnecessary.** A withdrawn owner must not receive the review display or
  identifiers that were prebuilt before the withdrawal. It belongs to C4e because both live behind the activation
  owner's permit registry (`MemberGrantActivations`), which C4e composes with the mount. The same re-run-and-compare
  shape fits both: re-run `review` and compare the display hash, and re-run `readActivated` and compare the result.
- The writers (`reserve*`, `release*`, `expire*`, `award`) return committed receipts. A pre-bytes refusal there could
  only hide an effect that is already durable. Their responses should still pass an owner and flag check before bytes
  (a refused receipt, `committed_receipt_refused`, never a retry). That is a C4e controller concern.

## Deferred P2: ordinary afterCommit refusal

**Defect.** `MembershipReservationOperations::runOwned` and `MembershipAwards::award` ran `$connection->commit();` and
then set `$committed = true`. `Connection::commit()` calls `PDO::commit()`, then the transaction manager's `commit()`,
which runs ordinary `afterCommit` callbacks, then fires `committed`. A throwing callback or committed delegate escaped
after the physical commit but before the flag was set, so a durable effect became a generic `operation_refused`.
`MemberGrantDefinitions::author` had the same shape (`authoring_refused`).

**Fix** (commit `ac084751`). `MembershipCommitWitness` extends `DatabaseTransactionsManager` and replaces the plain
owned manager in all three runners. `arm()` runs immediately before the owned `commit()`. It requires the captured
primary at level 1, in a transaction, in exception error mode. The overridden `commit()` records the witness only for
the armed connection's outermost level (1 to 0), with the captured primary idle and still exception-mode, and it records
it **before** calling `parent::commit()`, which runs the callbacks. Reaching that point at all proves `PDO::commit()`
returned. A failure after the witness now maps to `committed_receipt_refused`. In `runOwned` it also sets
`$committed = true`, so the release workflow-lock cleanup keeps the committed classification.

Kept unchanged on purpose:

- An idle PDO alone is still not commit proof.
- A `PDO::commit()` that throws (for example a lost connection during COMMIT) never reaches the witness. It stays
  `operation_refused`, as before. That is a genuinely unknown outcome, listed under untested conditions; exact retries
  converge because reserve keys, period-per-invoice award and release/expire are idempotent.
- A foreign raw `PDO::commit()` inside a committing delegate is detected by the existing frame checks. It also stays a
  generic refusal.

**Other operations with this shape.** Every `->commit()` under `app/Domain/Memberships` and `app/Domain/Grants/Member`
was checked:

- `MemberOriginalSourceCapture::inspect` and `MemberActivationReads::inspect` are read-only transactions. A
  misclassified refusal there has no durable effect, so they were left unchanged.
- `MemberOriginalSourceCapture::inspectActivation` (confirmation and activation writes) already maps
  `$committed || ! $primary->inTransaction()` to `committed_activation_receipt_refused`. That is conservative, never a
  generic refusal for a committed write, so it was left unchanged. Its idle heuristic can also report
  committed-unknown for a failure before `beginTransaction()`, which is noted as adjacent.
- The rest of the membership domain uses `DB::transaction` inside caller-owned frames, or is the synthetic family.

**Red/green.**

| Run | Source | Result |
| --- | --- | --- |
| r1 SQLite `MembershipCommitWitnessTest` | `7cd95b73` app sources | 5 tests, 73 assertions, **4 failures**: reserve, committed delegate, release and award each got `operation_refused`; the beginning-delegate control passed |
| r2 SQLite `MemberGrantDefinitionsCommitWitnessTest` | `7cd95b73` app sources | 1 test, **1 failure**: got `authoring_refused` |
| r3 MySQL `MembershipNativeCommitWitnessTest` | `7cd95b73` app sources (`git diff HEAD -- app` empty) | 3 tests, 29 assertions, **3 failures**: award `operation_refused`, authoring `authoring_refused`, reservation `operation_deadline` (see native timing below) |
| g1 SQLite both files | fixed sources | 6 tests, 103 assertions, 0 failures |

## Tested source

- **Commits:**
  - `ac084751`: the commit witness.
  - `9820a81b`: the index, the pre-bytes proof and period routing.
  - `47aba91f`: a merge of `origin/main` `e8dfe3cd` (PRs #157 and #158, Filament 5.10.2 and Livewire 4.4.7). The merge
    touches none of this lane's files.
  - The packet commit and the final census commit change no application or test file.
- **Final verification source:** `HEAD=47aba91f`, with an empty `git diff HEAD -- app tests composer.lock` recorded at
  the start of every final run.
- **Vendor:**
  - Final runs used vendor installed by `COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction` from the merged
    lock: exit 0 in 65 s, from the local Composer cache. That vendor has `filament/*` v5.10.2 and `livewire/livewire`
    v4.4.7; `vendor/composer/installed.json` SHA-256 prefix `7c568382f43fcf86`, `composer.lock` prefix
    `442031a538b3a8f6`.
  - Pre-merge runs (red/green, the first SQLite pass, mutations and the interrupted pre-merge native run) used the
    original vendor copy matching `7cd95b73`'s lock (Filament 5.8.4, Livewire 4.4.6).
- **Tools:** PHP 8.4.26 (CLI), PHPUnit 12.5.34 and the direct PHPUnit command with worktree autoload. `public/build`
  was absent. Runs used `nice` and at most two PHP processes.
- **Native server:** a private Oracle MySQL Community Server 8.4.11 (`/opt/mysql-8.4.11/bin/mysqld`):
  - its own `--datadir` under the session scratchpad, port 3351, its own socket and pid file;
  - database `vaseyaudio_member_c4d`, declared disposable via `VA_LOCAL_DISPOSABLE_MYSQL`.

  After the first red run it was restarted with
  `--skip-log-bin --innodb-flush-log-at-trx-commit=2 --innodb-doublewrite=OFF --performance-schema=OFF`. These flags
  change only durability and instrumentation, never isolation, locking or commit visibility. The container restarted
  at about 19:50 UTC and killed every process. The server then completed InnoDB crash recovery on restart
  (`CHECK TABLE mysql.user` OK), and its test database was dropped and recreated. The other lane's server on port 3341
  was not touched.

## Tests added

| File | Engine | Cases | Covers |
| --- | --- | --- | --- |
| `ProductionMembershipBilling/MembershipCommitWitnessTest` | both | 5 | throwing afterCommit on reserve, release and award; throwing committed delegate; pre-commit control stays generic |
| `ProductionMemberOriginals/MemberGrantDefinitionsCommitWitnessTest` | both | 1 | throwing afterCommit on definition authoring; dedupe retry |
| `ProductionMembershipBilling/MembershipNativeCommitWitnessTest` | MySQL only | 3 | the same three writers; commit confirmed from an independent InnoDB session; `innodb_trx` empty; replay |
| `ProductionMembershipBilling/MembershipCustomerIndexTest` | SQLite (not driver-branching) | 13 | exact projection with no private identifiers; no writes; replay; consumed and released status; 100 bound and sentinel; sentinel authenticated; ledger audit failure; foreign owner; withdrawn owner; withdrawal during commit; pre-bytes success, one use and non-adoption; pre-bytes after withdrawal, ledger change and gate withdrawal; default-off and nested-transaction refusals |
| `ProductionMembershipBilling/MembershipNativeCustomerIndexTest` | MySQL only | 3 | owner, fence, period and redemption rows held (NOWAIT 3572) at commit and released after; the index waits at a writer-held fence (1205) and retains nothing; sequential reserve, then the pre-bytes refusal |
| `ProductionMembershipBilling/MembershipPeriodAddressedOperationsTest` | both | 4 | period-addressed reserve, expire and release equal the locator results; unknown and malformed ids; another customer's period; a redemption of another period |

## SQLite results (in-memory)

Final, at `47aba91f` with the merged vendor:

| Run | Selection | Tests | Assertions | Failures / errors / skips | Exit | Time |
| --- | --- | --- | --- | --- | --- | --- |
| s-A | `tests/Feature/ProductionMembershipBilling` (the whole directory, including Billing and the new files) | 398 | 3,451 | 0 / 0 / 35 | 0 | 2,944 s |
| s-B | `tests/Feature/ProductionMembership`, `ProductionMemberActivation`, `ProductionMemberGrants`, `ProductionMemberOriginals`, `tests/Unit/ProductionMemberGrants`, `tests/Unit/ProductionMemberOriginals` and the 20 `tests/Unit/{Billing,Member,Membership}*Test.php` files | 794 | 2,924 | 0 / 0 / 19 | 0 | 1,920 s |

The same two selections also ran before the merge, at `9820a81b` with the original vendor, with identical counts:
398/3,451/0/0/35 (2,323 s) and 794/2,924/0/0/19 (1,590 s). All 54 skips are declared native-only skips on SQLite. JUnit
and console files are retained locally in the lane scratchpad; they are not committed.

## Native MySQL 8.4.11 results

Native evidence is **partial**. Every native failure below happened in the existing writer paths, at their fixed
60-second budget: the `reserve` fixture or operation, or the existing award race barrier. Each happened before or
instead of a C4d assertion. No cap was raised and no test was weakened.

**Final chunks at `652ec430`.** The merged vendor was used throughout. The application and test sources equal
`47aba91f`; `git diff HEAD -- app tests composer.lock` was empty. Each chunk ran one PHPUnit process,
`c4-mysql.sh --filter '/::<method>$/' <file>`, over the server's unix socket, with the server at nice 0 and PHP at
nice 10. Results and the command for each chunk are in the lane's local `c4-ev/progress.txt`.

| Chunk | Case | Result |
| --- | --- | --- |
| c01 | `MembershipNativeCommitWitnessTest` reservation | fail: `operation_deadline` in the reserve under test; retry r01 `operation_refused` (pre-commit, at about 60 s) |
| c02 | `MembershipNativeCommitWitnessTest` award | **pass** (1/22): committed refusal, independent-session commit, empty `innodb_trx`, replay |
| c03 | `MembershipNativeCommitWitnessTest` authoring | **pass** (1/9) |
| c04 | `MembershipNativeCustomerIndexTest` owner, fence and period locks | error in the `reserveFixture` setup (`MembershipReservationContext.php:786` deadline); retry r04 the same |
| c05 | `MembershipNativeCustomerIndexTest` writer-held fence | error in the `reserveFixture` setup (deadline) |
| c06 | `MembershipNativeCustomerIndexTest` sequential reserve and pre-bytes | error in the `reserveFixture` setup (deadline) |
| c07 to c09 | `MembershipCommitWitnessTest` reservation, committed delegate, release (on MySQL) | fail or error at the reserve deadline |
| c10 | `MembershipCommitWitnessTest` award (on MySQL) | **pass** (1/19) |
| c11 | `MembershipCommitWitnessTest` pre-commit control (on MySQL) | **pass** (1/17) |
| c12 | `MemberGrantDefinitionsCommitWitnessTest` (on MySQL) | **pass** (1/9) |
| c13 | `MembershipPeriodAddressedOperationsTest` reserve, expire and release | error: the period routing resolved and the context ran, then hit the reserve deadline at the committing proof |
| c14 | `MembershipPeriodAddressedOperationsTest` another customer's period | **pass** (1/17): refused with no writes |
| c15 | `MembershipNativeCatalogCurrentnessTest` unrelated AUTO_INCREMENT insert (C4a regression) | **pass** (1/18) |
| c16 | `MembershipNativeAwardRaceTest` concurrent awards (existing) | fail: "worker did not reach the bounded exact record barrier" (timing) |

**Earlier native runs:**

- **Pre-merge, at `9820a81b` with the original vendor, over TCP.** `MembershipNativeCommitWitnessTest` plus
  `MembershipNativeCustomerIndexTest` printed four passing progress marks (`....`) for its first four cases: reservation
  witness, award witness, authoring witness and the index lock test. The first container restart then killed it, so
  there is no JUnit file and the result is recorded as interrupted, not as a pass.
- **Two complete attempts at `47aba91f` over TCP:** one alongside a SQLite run, one alone. Each gave 6 tests, 4 errors
  and 1 failure, all in the fixture award or reserve at their deadline.

**Not obtained natively on the final source:** the index lock evidence, the writer-held-fence evidence, the
pre-bytes-after-reserve evidence, the reservation and release witness, and the period-addressed positive path. Each
needs a reserve that finishes within 60 seconds. These remain open native gates for the reviewer, or for a quieter
host. The SQLite equivalents pass.

### Native timing finding

A MySQL general log of an existing main test (`MembershipReservationLifecycleTest`, award inside the fixture) showed
the owned award transaction issuing about 70,000 statements over 40 seconds on this container, mostly schema and
currentness metadata reads. The writers' fixed 60-second deadline therefore leaves little margin here, and the native
award and reserve paths pass or time out depending on host load. The same existing test failed once with
`operation_deadline` (`MembershipReservationContext.php:786`) and passed once (10 minutes wall). A second general log,
taken during chunk c09 on the final source, showed the fixture award at 53.6 s over 70,253 statements (committed) and
the reserve at 62.8 s over 83,845 statements (rolled back at the deadline). In both, about 25,000 prepared executions
at roughly 1 ms each, including PHP time, plus several hundred `information_schema` schema-currentness lookups,
dominate. That is a
representative-latency condition of the existing writers, not something this lane changes. No cap was raised.

## Mutations

Mutations ran before the merge, at `9820a81b` with the original vendor. The merge changed none of the mutated files or
their tests. Each mutation was applied in a hard-link copy of the worktree by atomic file replacement, so the tested
worktree was never edited. Each was reverted and the copy compared identical (`diff -rq app tests`) before the copy was deleted.

| Mutation | Change | Killed by | Result |
| --- | --- | --- | --- |
| M1 remove the commit witness | `MembershipCommitWitness::commit` never records the witness | `MembershipCommitWitnessTest`, `MemberGrantDefinitionsCommitWitnessTest` | 6 tests, 5 failures (all four writer cases plus authoring got the generic refusal); the pre-commit control passed |
| M2 drop the sentinel | window `LIMIT 101` becomes `LIMIT 100` | bound and sentinel tests | 2 tests, 2 failures (`hasMore` false; tampered sentinel no longer read) |
| M3a drop the owner lock | `$access->lock()` becomes the non-locking `current()`, and the current and committed owner proofs are removed | index positive, pre-bytes and withdrawal-during-commit tests | 4 tests, 2 errors and 1 failure. The non-locking owner read is refused inside the frame (`IdentityMetadataAdmission`), so the kill is partly incidental. The native NOWAIT test is the direct lock detector, but it was not run against this mutant |
| M3b drop the owner check | lock kept; the current and committed owner proofs removed | `test_owner_withdrawal_while_the_index_commits_refuses_the_terminal_proof` | 4 tests, 1 failure (the index returned success to a withdrawn owner) |
| M4 skip the pre-bytes check | `proveCapturedBeforeBytes` no longer re-runs or compares | both pre-bytes refusal tests | 3 tests, 2 failures |

## CI safeguards and census

- On the merged tree: `test-phpunit-shards` 52 tests OK, `test-ci-scope` 27 OK, `test-workflow-cadence` 14 OK and `test-focused-tests` 53
  OK, each exit 0.
- **Census delta.** The final commit appends six methods to `scripts/ci/database-sqlite-skips.json` (281 to 287; the
  hash-pinned first 150 are unchanged): the three `MembershipNativeCommitWitnessTest` methods and the three
  `MembershipNativeCustomerIndexTest` methods. Each skips on SQLite in `setUp`. The regenerated native selection
  (`phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json
  --mysql-native-selection`, exit 0) proved 2,456 selected of 9,418 discovered cases in 235 of 688 files, up from 2,450
  of 9,389 in 233. The merged tree gives the same counts (main's two PRs add no selected cases). The count comments in `.github/workflows/final-verification.yml` and `.gitlab-ci.yml` follow, and the
  generated `phpunit-ci-mysql-*` files were deleted. Lane D1 also appends to this census; whichever lane merges second
  redoes this commit.
- `git diff --check origin/main...HEAD` is clean.

## Native gap characterization: reserve cost, baseline vs C4d

Requested before review, to decide whether the reserve's 60-second overrun is a test-environment artifact, a C4d
regression or a real latency problem.

### Method

- **Sources.**
  - *Base:* a temporary detached worktree of `origin/main` `e8dfe3cd`, with a byte copy of this lane's vendor (the same
    lock), removed afterwards.
  - *Head:* C4d `b2509f4b`, whose application and test sources equal `47aba91f`.
- **Test.** Both ran the **same existing** main test, back to back, on the same private MySQL 8.4.11 (port 3351, unix
  socket): `MembershipReservationLifecycleTest::test_real_sources_reserve_once_and_exact_retry_and_award_replay_preserve_the_retained_identity`.
  Its fixture runs one owned award, then the test runs one owned reserve.
- **Measurement.** A MySQL general log per run, summarized per owned transaction by its first
  `SAVEPOINT va_membership_award_*` and the matching `COMMIT`/`ROLLBACK`.
- **Server configs.**
  - *Config A* (the earlier lane config): `innodb_flush_log_at_trx_commit=2`, `--skip-log-bin`, 256M buffer pool,
    doublewrite off, performance_schema off.
  - *Config B* (requested): `innodb_flush_log_at_trx_commit=2`, `--skip-log-bin`, `sync_binlog=0`, 512M buffer pool.
  - Both are durability and caching settings only; isolation is `REPEATABLE-READ` throughout.
- **Concurrent load.** Lane D1's mysqld (port 3341) was **running during every measurement**
  (`pgrep -fa mysqld`). Load averages at run start ranged from 2.0 to 7.1.

### Results

| Run | Source | Config | Award (owned txn) | Reserve (owned txn) | Test result |
| --- | --- | --- | --- | --- | --- |
| A-base | main `e8dfe3cd` | A | 43.3 s, 70,253 statements, COMMIT | 60.2 s, 95,303 statements, ROLLBACK | error: reserve deadline |
| A-head | C4d `b2509f4b` | A | 47.3 s, 70,253 statements, COMMIT | 66.0 s, 90,255 statements, ROLLBACK | error: reserve deadline |
| B-base | main `e8dfe3cd` | B | 47.5 s, 70,253 statements, COMMIT | 63.9 s, 90,255 statements, ROLLBACK | error: reserve deadline |
| B-head | C4d `b2509f4b` | B | 50.3 s, 70,253 statements, COMMIT | 62.3 s, 90,255 statements, ROLLBACK | error: reserve deadline |

Every award transaction issued exactly 70,253 statements (23,353 prepared executions) on both sources and configs.
Under config B the reserve issued exactly 90,255 statements on both sources. Per transaction, the attribution
markers were identical between base and head:

- award: 314 identity-schema inspections, 945 membership-catalog lookups, 1,152 `SELECT DATABASE()`, 5,529
  `information_schema` executions and 1,072 reads of membership or member data rows;
- reserve: 412, 1,107, 1,475, 7,005 and 1,197 respectively.

### Conclusions

- **C4d did not change the writers' cost.** Statement counts and their composition are identical. Wall times differ
  only with load, and the same overrun happens on main.
- **Not a server-tuning artifact.** Config B made no material difference. The work is statement-latency bound.
- **A real latency property of the existing writers.** On this 4-CPU container a complete reserve needs about 62 to
  66 s, while its fixed budget is 60 s, so it is refused at the final check. A faster host shortens this, but the cost
  scales with the statement count, not with data size. The production risk is that a member reserve takes tens of
  seconds and fails closed under load.
- **Native chunk reruns were not attempted.** The tuned reserve still exceeds 60 s on both sources. No cap or deadline
  was raised.

### Where the statements come from

About 95% of the statements are repeated schema and ownership currentness checks, not ledger data. Data-row reads are
about 1,100 to 1,200 of roughly 23,000 to 30,000 executions.

- **The dominant unit** is `IdentityMigrationOwnership::inspect`, a whole identity-parent schema inspection of about
  70 prepared statements: `SHOW CREATE TABLE` for `users`, `customer_accounts`, `quote_owners`, ..., plus
  `information_schema` constraint, trigger, index and foreign-key lookups. It runs 314 times per award and 412 per
  reserve.
- **Call sites.** A call-site count in an instrumented scratch copy attributes those inspections to three repeated
  guards. It ran the same test on the SQLite path, not committed. Its counts cover the test's reserve and replay calls,
  so they are proportions, not one-reserve totals.
  1. Every `MembershipRows::assertCurrent()`, called by each `current()`, `rows()` and `one()` read, calls
     `MembershipSchema::assertOwned()`, which calls `up(false)`, which re-runs the identity inspection and the
     membership catalog checks. This is the largest share, for example 100 calls through `MembershipRows::current`
     in the profiled reserve operations, plus those from `MembershipBillingEvidence::lock`, `SealedMembershipPaidInvoice::proveCurrent`
     and `CurrentEligibleLicenses::proveCurrent`.
  2. Every `ProductionCustomerAccess` read (`lock`, `proveCurrent`, `historical`, `verifyHistoricalBinding`) builds
     `IdentityRows`, whose `assertPermanent()` re-runs the identity inspection. This is the next largest share (160 calls in the profiled
     reserve operations).
  3. `BillingSchema::inspect`, via `BillingRetrievalSchema::assertOwned`, also calls `MembershipSchema::assertOwned()`.
- **Possible fix (a design note for a separate, reviewed lane, not implemented here).** Prove schema ownership once
  per owned frame and at the terminal raw phases, carrying a frame-scoped schema proof, instead of on every row read.
  Any such change touches the R-4/currentness guarantees and needs its own review.

## R-4 and the catalog AUTO_INCREMENT P2

Both are **already fixed on main** by C4a, PR #115, merged as `990020c1` (`docs/verification/member-source-currentness-20261013/`;
ledger entry "#115 C4a source/currentness merge receipt"). They are not on a surface this lane changes, so nothing was
redone. What is on current main:

- **R-4 native source admission.** `MemberGrantPolicy::currentFor(MembershipRows)` and `proveCurrent()` bind the exact
  policy issuer, rows, native PDO, active transaction and original configuration. Member-local provenance requires
  MySQL for verified production, and the terminal check reads captured raw container maps. The index uses
  `currentFor`/`proveCurrent`/`proveCapturedConfiguration` as they are.
- **Catalog false conflict.** `MembershipCatalogDefinition::mysql` strips only the volatile table-option
  `AUTO_INCREMENT=N` from `SHOW CREATE TABLE` before `MembershipCatalogEvidence::definitions()` compares it.
  `MembershipNativeCatalogCurrentnessTest::test_unrelated_auto_allocated_insert_preserves_original_proof_but_selected_row_change_refuses`
  covers it. That file ran in the native run below.

## Frozen-renderer launch path under PHP-FPM (finding, read-only)

**Component.** `App\Domain\Grants\Member\MemberOriginalRendererProcess::render` builds
`[PHP_BINARY, '-n', ...extensions, ..., scripts/render-member-original.php]` and runs it with Symfony Process. Activation
renders the member original inside the request (`MemberGrantActivations::activate`, then materialization, then this
renderer).

**What is unresolved for FPM requests.**

1. Under PHP-FPM, `PHP_BINARY` is the FPM daemon. Reproduced here: `/usr/sbin/php-fpm8.4 -n -d memory_limit=128M
   scripts/render-member-original.php` exits 64 and prints the php-fpm usage text. Every member render would fail
   closed with `render_failed`, so member activation over HTTP cannot succeed on an FPM host.
2. M-16 (`docs/verification/php-cli-resolver-20261009/`) fixed this for the Free, Paid and PaidTax renderers without
   editing their pinned files: each already accepted a process factory, and `AppServiceProvider` passes
   `PhpCliProcess::factory(PhpCliBinary)` outside the CLI. **The member renderer has no such seam.** Its constructor
   takes only an observation closure that receives no process or command, by design ("the renderer owns its child
   process", `docs/ops/member-originals-runtime.md`). No container binding can change its executable.
3. Its bytes are pinned. `MemberOriginalRendererProcess.php` is listed with SHA-256 `f0470e91…` in
   `resources/contracts/production-member-v1/profile-assets.json` (`MANIFEST_HASH` `caa3218c…`). That manifest is
   part of the released r1 profile hashes (`MemberGrantRenderProfileRegistry::RELEASED['r1']`), and `verify()` requires
   the current descriptor to equal the stored profile exactly. Any edit to the renderer is therefore a new member render
   profile, not a patch.
4. It derives two more values from the parent process: `ini_get('extension_dir')` (the FPM pool's ini, not the CLI
   binary's) and `dirname(PHP_BINARY, 2)/lib/<arch>` as the child loader path. Under FPM both describe the wrong
   binary.

**Recommendation for C4e** (or a small prerequisite lane before it):

- *Component:* release a member render profile r2. Change `MemberOriginalRendererProcess` to take the validated
  `App\Support\PhpCliBinary` as a constructor argument (not a general process factory, so the renderer keeps owning
  its process). Spawn `PhpCliBinary::path()`, which is `PHP_BINARY` unchanged under `cli`/`cli-server`. Derive
  `LD_LIBRARY_PATH` with `PhpCliProcess::libraries($path)`. Take the extension directory from the validated CLI binary
  instead of the parent ini: extend the cached `PhpCliBinary` probe to report `PHP_EXTENSION_DIR`, or pass bare
  extension names that the child resolves against its own compiled default.
- *Profile:* add a new manifest (for example `resources/contracts/production-member-v2/profile-assets.json`) with
  version `production-member-original-pdf-v2`, a new `MANIFEST_HASH` and a new `RELEASED['r2']` pair. Keep r1's bytes
  and hashes as history. Do not re-pin r1 or touch the five historical frozen profiles. No r1 member profile was ever
  approved (the approved hashes are null), so retiring r1 for new renders strands nothing.
- *Composition:* bind the renderer in the container from C4e's mount commit (the file D1 owns today), under the
  default-false flags.
- *Tests:*
  - a unit test with `PhpCliBinary`'s SAPI seam simulating `fpm-fcgi`: configured, unconfigured, invalid and
    version-mismatched binaries;
  - a red check showing the current r1 renderer under FPM returns `render_failed` (exit 64);
  - a genuine private php-fpm 8.4 pool smoke on the M-16 harness pattern, rendering one synthetic member original
    through the HTTP kernel and comparing its SHA-256 with the CLI render of the same payload;
  - profile-registry tests: r2 verifies, r1 stays byte-exact in `RELEASED`, and `git diff -- resources/contracts`
    touches only the new member manifest;
  - the existing member activation integration tests on SQLite and native MySQL.

Moving the render out of the request (a queue or CLI worker) is not recommended. `MemberLicenseAuthorization` is a
same-process confirmation ticket, so the activation would need a redesign. Running the web tier under a CLI SAPI
server (Octane or FrankenPHP) would sidestep the problem, but it is a host decision (U-02), not a code fix.

## C4e plan

1. After D1 merges, mount private session-bound member routes under a default-false, build-time flag. Map refusals to
   statuses, with every period, redemption and owner refusal mapped to one not-found shape.
2. Index: call `MembershipCustomerIndex::index(..., $read)`, build the body, then call `$read->proveBeforeBytes()`
   immediately before sending. A refusal sends no body.
3. Redemption: `reserveInPeriod`/`releaseInPeriod`/`expireInPeriod` with the listed period id. Map
   `committed_receipt_refused` to "submitted; refresh to see the result", never to a retry prompt.
4. Activation: review, confirm and activate, with the pre-bytes hooks described above for review and `readActivated`.
5. Prerequisite: the member render profile r2 and FPM launch path above.
6. Register the real capability bindings (`SealedMembershipPaidInvoice`, `SealedMembershipPolicyFacts`,
   `CurrentEligibleLicenses`, the member-grant authorities). Today only `tests/Support/MemberActivationFixtures.php`
   binds them. Add slow-client bounds, rate limits and the census entries for any native HTTP tests.
7. Open owner inputs before anything is enabled: display wording, consumption terms, retention text and all approved
   hashes (none approved by the 2026-10-18 decision).

## Untested conditions

- No independent-process race between the index and reserve. The native evidence is lock-holding and lock-order
  evidence (NOWAIT probes and a writer-held fence), plus the structural owner, then fence, then membership order shared
  with every writer.
- Readers that take no fence (`readActivated`/`MemberActivationHistoryInspection`, staff definition authoring) share
  immutable plan and definition rows with the index in a different order. InnoDB deadlock detection would refuse one
  side (a retryable read refusal, no effect). This is not exercised.
- A `PDO::commit()` failure or lost connection during COMMIT in the three writers stays `operation_refused`, an unknown
  outcome. It is not exercised.
- Index cost: up to 101 complete period audits and member-history captures per call, doubled by the pre-bytes re-run.
  Representative latency was not measured. The index reuses the same guarded readers as the writers (see the
  characterization above), so its statement count will be of the same order per period.
- The existing reserve exceeds its 60-second budget on this container on main as well (62 to 66 s, about 90,000
  statements). Native positive paths that need a reserve cannot pass here until the writers' guard cost is reduced or a
  faster host is used.
- The FPM finding is static analysis plus a direct child-launch reproduction. No FPM pool was run for the member
  renderer.
- The index has no paging. Periods older than the newest 100 are reported only through `hasMore`.
- Native positive evidence for the index, the reservation and release witness and the period-addressed reserve was
  not obtained on the final source (see the native results).
- No HTTP, browser, host, provider or production evidence. No Foundation run.
