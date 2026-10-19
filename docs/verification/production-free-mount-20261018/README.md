# D1: production free grants (family 256) HTTP mount, commit observer and first-byte proof

Lane D1, October 18, 2026, branch `harness/production-free-mount-20261018`, based on main
`7cd95b73969ba0927e634a2753712239d7db3226`. This batch builds the default-off customer HTTP mount for family 256 and
the two owner-doc steps it depends on: the commit observer (step 3) and the before-first-byte committed proof
(step 5). It composes Codex's ff0 request-bound identity capsule onto the canonical 256 seams, closes the shared
generic/identity metadata P2 on the surfaces this path reaches, binds the family's renderer and appends the native
census. Nothing is pushed, mounted on a host or activated. The real sources adapter, fonts and render profile, staff
Filament actions (F-3) and render-worker wiring are out of scope.

## Commits

| Commit | Content |
| --- | --- |
| `b5c258f28868b7418c10dcc60e3ddeed320500d6` | Commit observer, owned frame, terminal proofs, generic metadata P2 on the frame (and the one reviewer test call adapted to the required `$graph`) |
| `235fab6c320f62acedfc697b6d39e656c7b71298` | Before-first-byte committed read |
| `30c4a4809b57735b88577b9594b705ea021a991e` | ff0 capsule composed on canonical 256, with its two restored tests |
| `a7505c3264caee1033d07c10a96c0b58c01a20e4` | Default-off HTTP mount, renderer binding, and the two tests whose premise was "nothing mounts 256" |
| `5d03dcec965f58fb879b1725d59b0fa31007d5b5` | New D1 tests and the native race worker |
| `5eae4a1f0b00c5279d05158a699ba87beb9afade` | Census append, branching helper entry, workflow comments |
| `07e4b0d52b7b01a3e4e8b8ac46e100909ff715b3` | Merge of main `e8dfe3cd` (PR #158, Filament 5.10.2 / Livewire 4.4.7 lock); no conflict, no D1 file touched by main |
| `0828829382e06d802fed720d060c3f1c098ae201` | Review F1: a vanished owned frame is `outcome_unknown`, with the E1/E1b regression tests |
| `5aed6e6a7d684fc4d9b5e5cd895ce49573a6dcbf` | Review F2: accept and redemption-commit graph tests |
| `14b267658f812fe3322bc4cc5b34e87def09bbfd` | Review F4: first-byte column comparison test |
| `cae94875f721e06cbf9b0b86da68f8f52192e4e0` | Review F5: one throttle prefix per route tier, with its test |

Intermediate commits are not individually green: after `a7505c32` the mount tests arrive in `5d03dcec`, and the census
lands in `5eae4a1f`.

The original tested source is `07e4b0d52b7b01a3e4e8b8ac46e100909ff715b3` (application, tests and census, merged with
main `e8dfe3cd`). The review follow-up source is `f98761839ebd9c70fcf19c83e808b2c92dceae5c` (below, under Independent
review). The documentation commits change no application, test, route, configuration or census file.

## Design

### Commit observer (owner doc step 3)

`ProductionFreeGrantTransactions::run` now owns its frame. It refuses to start inside another frame
(`transaction_forbidden`), installs a fresh `DatabaseTransactionsManager` and a connection-local
`ProductionFreeGrantCommitObserver` as the connection's dispatcher, and restores both in every outcome. The observer
follows `MembershipReservationObserver`:

1. On the owned `TransactionBeginning` it takes a random savepoint before any ordinary beginning listener runs. A
   second begin on this connection, including a framework savepoint, is nesting and refuses (`changed_transaction`).
2. The work receives the observer and registers its terminal proof once.
3. On the owned `TransactionCommitting` it admits the frame, delegates to the ordinary listeners first, then admits
   again and runs the terminal proof, then the F-4 parent floor and owned-schema proof
   (`ProductionFreeGrantSchema::assertOwned`, which refuses a session TEMPORARY shadow of a parent or owned table),
   then admits once more. Only then does Laravel issue the physical commit.
4. Admission is reflection-only and refuses a lost savepoint (raw commit or reopen by a callback), a replaced
   dispatcher or transaction manager, a different primary, a non-depth-one frame, and callback-capable raw metadata
   (below).
5. A failure before the commit rolls back only the frame this observer owns, and that is a refusal. A failure after
   the physical commit is the fixed `outcome_unknown`, never retried. Detection is not prevention: a committing
   listener's raw or implicit commit (or a commit or rollback followed by a reopen) can make the write durable before
   the terminal proof runs. When `abort()` then finds the begun frame no longer its own, the command reports
   `outcome_unknown`, not a refusal (review F1). A callback-created replacement transaction is left to its owner and
   the framework level is reset. A deadlock or lock wait timeout means the server rolled the frame back and stays
   `contention`.

Terminal proofs:

| Caller | Terminal proof re-run at commit |
| --- | --- |
| `ProductionFreeGrants::customerCommand` (review, accept, library index/show, authorize, both redemption commands) | the command's graph read compared byte for byte with its value after the work, then the optional request capsule (`$authority`), then `ProductionCustomerAccess::proveCurrent` with the original raw evidence, then the policy, then the frame |
| `ProductionFreeGrantDefinitions::staffCommand` (propose, approve, open/close, read, revoke) | staff authority and MFA on the locked row, policy, frame |
| `ProductionFreeGrantDocuments` (claim, publish, recover, failure record) | the origin's render state, policy, frame (failure record: frame) |

`customerCommand` now takes a required `$graph` closure, a pure read of what the command depends on. Reads pass their
own work. `accept` re-projects the new or replayed origin; `authorize` re-proves the entitled, unrevoked origin and
the exact authorization row it wrote; the second redemption command re-proves the entitled origin and exactly one
redemption, its own.

### First-byte proof (owner doc step 5)

The second redemption command returns the exact stored rows it leaves behind (origin, authorization, redemption,
original) and the raw owner evidence it locked. `ProductionFreeGrantDownloads::redeem` builds a one-use
`ProductionFreeGrantCommittedRead` with them and a monotonic deadline fixed at the redemption commit (the transfer's
own deadline, never renewed). `ProductionFreeGrantTransfer::begin()` runs it once before the first byte; `writeTo()`
calls `begin()` itself when nobody did. The proof:

1. admits the raw facade, container and connection metadata (`IdentityMetadataAdmission::capture`), then proves the
   policy unchanged and the metadata still the same;
2. opens `IdentityCommittedFrame` on the original deadline (SQLite `query_only`, MySQL `READ COMMITTED, READ ONLY`);
3. re-proves the owner there (`ProductionCustomerAccess::proveCommitted`);
4. reads the Free rows with plain non-locking SELECTs (`ProductionFreeGrantCommittedRows`): the owned schema and parent
   floor without a shadow, the four rows equal column for column (seal and ciphertext included), and no revocation;
5. finishes the frame.

Any failure is `outcome_unknown`: no byte, the redemption retained, no retry, no renewal. The customer may authorize
again if still entitled.

### Shared generic/identity metadata P2 (directly reached surfaces only)

Following B4, the deferred P2 is fixed only where this path reaches it:

- `ProductionFreeGrantRows::admittedConnection()` (used by the reader's birth, the owned frame in `run()` and every
  observer admission) requires the facade cache to be an actual array before `DB::connection()` reads it, and the
  connection's raw config, driver, name, database and prefix to be actual arrays and strings before any getter.
- The first-byte proof captures `IdentityMetadataAdmission` before any facade or connection getter and builds its
  `CurrentRows` from the admitted connection.

Everything else in the deferred P2 (unwrapped V1/generic/IdentityOriginalCommitWitness paths) stays frozen.

### HTTP mount

New classes under `app/Http/ProductionFree/` mirror the default-off mounts:

| Class | Mirrors | Role |
| --- | --- | --- |
| `ProductionFreeHttpMount` | `AccountFeatureMount`, `TaxHttpMount` | literal `production-free-grants.http_mounted === true` from plain loaded storage, and a cached environment of `local` or `testing` |
| `ProductionFreePrivacy` | `PaidTaxPrivacy`, `AccountFeaturePrivacy` | root seam prepended in `bootstrap/app.php`; unmounted 404 before any work; raw intake bounds; private headers; one error shape; refusal mapping |
| `ProductionFreeBody` | `AccountFeatureBody`, `PaidTaxForm` | 4 KiB JSON with unique keys; the exact `token` and `_token` form |
| `ProductionFreeController` | `PaidTaxController` | maps the capsule and body onto the domain; no business rule |
| `ProductionFreeDownload`, `ProductionFreeDownloadResponse` | `PaidTaxResponseOwner`, `PaidTaxStreamResponse` | proof before the first header and byte; becomes the error on refusal; 64 KiB writes with client-abort detection |

`routes/web.php` requires `routes/production-free-grants.php` only when the mount admits. `bootstrap/app.php` adds the
privacy middleware and its JSON, report and respond branches like Tax. The shipped value is the literal `false`.
`AppServiceProvider` binds `ProductionFreeGrantRendererProcess` like the other grant renderers; the binding stays inert
while unmounted. The send-timeout requirement (A6-2, mount part) is documented in
`docs/ops/production-free-http-runtime.md`.

### ff0 composition

Source: `codex/production-free-http-parent-guard-20261007` at `a4f9e63fdfbd72d781d0e97379235a0461a8ffad`, reviewed
successor `ff0f7a85be397f017a227967058f745ceea3da28`. Nothing was merged; each path was read with `git show` and
restored, adapted or dropped.

| ff0 path | Disposition | Why |
| --- | --- | --- |
| `app/Domain/Grants/Free/Production/ProductionFreeGrantHttpBinding.php` | **Restored and adapted** as `app/Domain/Grants/ProductionFree/ProductionFreeGrantHttpBinding.php` | The request-bound capsule is still valid and is what owner doc step 3 and 5 keep through the terminal proof. Adapted to the canonical seams: `ProductionFreeGrantPolicy` (config `production-free-grants`) replaces ff0's separate `production-free-grant-identity` policy, which does not exist on main; `ProductionFreeGrantException('identity_refused')` replaces the old family's status exception; configuration parents are `app`, `auth`, `production-customer-identity`, `production-free-grants`; reflection uses `getRawValue` and exact framework classes (B4's `AccountFeatureRequestGuard` style). ff0f7a85's nullable cached environment is kept. |
| `app/Domain/Grants/Free/Production/ProductionFreeGrantHttpIdentity.php` | **Composed into** `ProductionFreeGrantRequestIdentity` | The canonical request step now builds the capsule and exposes `proveCurrent()` and `authority()`; its public `from()`, `principal` and `actor` are unchanged. |
| `tests/Feature/ProductionFreeIdentity/ProductionFreeHttpBindingTest.php` | **Restored and adapted** as `tests/Feature/ProductionFreeGrants/ProductionFreeGrantHttpBindingTest.php` | Same twelve withdrawals through a real committed source callback and through a retained capsule; the purpose withdrawal points at the canonical config; ff0's 404 policy refusal maps to `disabled`. |
| `tests/Feature/ProductionFreeIdentity/ProductionFreeHttpConfigurationAdmissionTest.php` | **Restored and adapted** as `tests/Feature/ProductionFreeGrants/ProductionFreeGrantHttpConfigurationAdmissionTest.php` | Callback parents and typed leaves refused with zero callbacks, on the canonical parents; adds an explicit nullable-environment positive. |
| `docs/verification/production-free-http-parent-guard-20261007/ProductionFreeHttpNestedConfigurationCanaryTest.php` | Dropped (covered) | An evidence probe that writes a snapshot file beside itself; its `app` parent case is a data set of the restored admission test. |
| `docs/verification/production-free-http-marker-repair-20261007/ProductionFreeHttpCurrentMarkerIndependentTest.php` | Dropped (covered) | Identical to the restored binding test's `marker_forget` committed-callback case. |
| `ProductionFreeGrantIdentity.php`, `ProductionFreeGrantBoundary.php`, `ProductionFreeGrantLineage.php` | Dropped | Adapters for the old synthetic free family's `FreeGrantIdentity` / `FreeGrantRows` interfaces. Family 256 already locks and re-proves the T23 owner through `ProductionCustomerAccess` inside `customerCommand`; there is no 256 consumer for them. |
| `ProductionFreeGrantIdentityPolicy.php` | Dropped | Reads `production-free-grant-identity.*` and `free-grants.operative_enabled`, which are not part of family 256 (its policy is `ProductionFreeGrantPolicy`). |
| `app/Domain/Grants/Free/FreeGrantOriginalIdentity.php` and the `FreeGrants.php` lineage hook | Dropped | Shared old-family hooks; the owner doc requires root coordination and preservation of the old Test adapter. |
| `tests/Feature/ProductionFreeIdentity/ProductionFreeIdentityTest.php`, `FreeGrantOriginalFenceTest.php` | Dropped | Test the dropped old-family adapters. |
| `c59e739b` registration (FreeGrantController, routes, pages) | Not applicable | Already on main as the synthetic family; this mount does not reuse it. |

ff0's approach does not conflict with the canonical identity code: it uses the same `ProductionCustomerSessions` and
`request->user('customer')` seams and only adds the retained capsule.

## Endpoint and refusal mapping

See `docs/ops/production-free-http-runtime.md` for the routes. Fixed refusal reasons map as follows; every failure
body is `{"code": ..., "reason": ...}` with private headers.

| HTTP | Reasons |
| --- | --- |
| 404 | `not_found`, `not_open`, `not_entitled`, `disabled`, `environment`, `capability_absent`, unmounted |
| 403 | `identity_refused`, `token_refused`, `provenance`, cross-site request |
| 405 | method other than GET/POST, method override |
| 409 | stale input, used request key or token, cap reached, pending original, changed entitlement (default) |
| 410 | `expired`, `revoked` |
| 413 / 415 / 422 | body over 4 KiB / wrong media type or encoding / invalid input, query string, Range, duplicate keys |
| 419 | CSRF |
| 429 | `rate_limited` (`Retry-After: 60`), throttle |
| 503 | `contention` (`Retry-After: 1`, code `PRODUCTION_FREE_GRANT_CONTENTION`), `outcome_unknown` (code `PRODUCTION_FREE_GRANT_OUTCOME_UNKNOWN`), schema, spool and connection failures |

## Tests

New and changed test files (all under `tests/Feature/ProductionFreeGrants/` unless noted; counts at the review follow-up
source):

| File | Cases | What it proves |
| --- | --- | --- |
| `ProductionFreeGrantCommitObserverTest` (new) | 19 (2 native-only) | listeners run once before the proof and the frame is restored; revocation (in the authorize and in the accept commit), policy, owner, session-marker and staff-policy withdrawals inside a committing listener roll back; a listener's raw commit, commit then reopen, and rollback then reopen are `outcome_unknown`; nesting, a Free command inside a Free frame and a replaced dispatcher refuse; four callback-capable metadata plants are refused with zero callbacks in the final proof; native TEMPORARY parent and owned-table shadows planted at commit roll back |
| `ProductionFreeGrantFirstByteTest` (new) | 12 (2 native-only) | unchanged redemption delivers once; five post-commit withdrawals give zero bytes, retained redemption, `outcome_unknown`; a revocation inside the redemption commit rolls the redemption back; a compared column re-sealed after the commit gives zero bytes; one-use read and original-deadline refusal; refused `begin()` emits nothing; native isolation/lock test and the independent-process race |
| `ProductionFreeGrantHttpTest` (new) | 39 | cold and runtime default-off for six flag/environment cases; real-route journey; CSRF for JSON and form; 19 raw-intake bounds; contention 503 + `Retry-After`; library views do not spend the write or redemption limit; foreign and withdrawn owners; three post-commit withdrawals send no download header or byte |
| `ProductionFreeGrantHttpBindingTest` (restored ff0) | 26 | the capsule and its twelve withdrawals, through a committed source callback and a retained capsule |
| `ProductionFreeGrantHttpConfigurationAdmissionTest` (restored ff0) | 9 | callback parents/leaves refused with zero callbacks; nullable cached environment positive |
| `ProductionFreeGrantApprovalTest`, `ProductionFreeGrantCliRendererTest` (changed) | unchanged counts | the premise "nothing mounts or binds 256" became "the literal default-off mount and the renderer-only binding" |
| `ReviewFree256IdentityRotationTest` (changed) | unchanged | one direct `customerCommand` call passes the new required `$graph`; assertions unchanged |
| `tests/Support/production-free-race-worker.php` (new) | n/a | independent-process worker for the native tests |

Red/green: each protection listed under Mutations has a recorded failing control. That is not every protection: the
independent review found three with none (the accept and redemption-commit graphs, and the first-byte column
comparison; mutations MH, MG and MD survived). The follow-up adds tests that kill them (Mutations, follow-up table).
The two restored ff0 tests were adapted onto canonical classes, so their original red controls (on ff0's old source)
are not re-run here.

Runs at the tested source (vendor below), PHP 8.4.26, PHPUnit 12.5.34, SQLite 3.45.1, `public/build` absent:

| Run | Scope | Tests / assertions | F / E / S | Exit |
| --- | --- | --- | --- | --- |
| Baseline on main `7cd95b73`, vendor Filament 5.8.4 | `tests/Feature/ProductionFreeGrants` (SQLite) | 195 / 1295 | 0 / 0 / 5 | 0 |
| Final, SQLite | `tests/Feature/ProductionFreeGrants` (incl. all new files) | 294 / 2810 | 0 / 0 / 9 | 0 |
| Final, SQLite | 27 affected files, part a (list below) | 335 / 3788 | 0 / 0 / 4 | 0 |
| Final, SQLite | 26 affected files, part b | 244 / 1767 | 1 / 0 / 10 | 1 |
| Final, SQLite, isolated rerun | `ProductionTaxCommittedReadReceiptTest::test_refused_original_commit_cleans_probe_and_deferred_callbacks_then_allows_fresh_retry` (4 data sets) | 4 / 44 | 0 / 0 / 0 | 0 |

Every skipped case in the three final SQLite runs was reconciled against `scripts/ci/database-sqlite-skips.json`
(9, 3 and 8 distinct methods; none unlisted).

The one failure in part b is the `expiry` data set of the Tax committed-read receipt test, which spins a committing
listener until a monotonic deadline. It ran while a native MySQL run was using the other CPU slots and passed in
isolation at the same source. Its path reaches no family 256 class (the 53 affected files contain no `ProductionFree`
reference); it is recorded as a load-sensitive timing failure, not as a pass, and it was not re-run on main under the
same load.

The "affected" set is every `tests/Feature` and `tests/Unit` file outside the Free directory that references
`ProductionFreeGrantTransactions`, `IdentityCommittedFrame`, `CurrentRows`, `ProductionCustomerAccess`,
`ProductionFreeGrantRows`, `ProductionFreeGrantRendererProcess`, `routes/web.php` or `bootstrap/app.php` (53 files,
grep recorded in the lane's scratch evidence).

Vendor: every final run used the merged lock (`composer.lock` SHA-256
`442031a538b3a8f6aa7a31eb7a44791e30adcf28e2053b7e4ff363c3476cbcea`; Laravel 13.37.0, Filament 5.10.2, Livewire 4.4.7),
installed with `COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction` after the merge. All development runs
before the merge, including the mutations except the final M1/M2 rerun, used main `7cd95b73`'s lock (Filament 5.8.4,
Livewire 4.4.6).

Python safeguards at the tested source (Node 24): `test-phpunit-shards.py` 52 OK, `test-ci-scope.py` 27 OK,
`test-workflow-cadence.py` 14 OK, `test-focused-tests.py` 53 OK, all exit 0. No frontend file changed, so
`npm test` and `tsc` were not run. `git diff --check origin/main...HEAD` is clean and no changed file ends in a blank line.

## Native MySQL 8.4.11

Private Oracle MySQL Community Server 8.4.11 (`/opt/mysql-8.4.11/bin/mysqld --no-defaults --user=root`), datadir under the
lane's scratchpad (`d1-mysql/data`, `--initialize-insecure`), loopback port 3341, own socket, `--mysqlx=OFF`, defaults
otherwise (REPEATABLE-READ). Disposable schema `vaseyaudio_d1_free` declared with `VA_LOCAL_DISPOSABLE_MYSQL`;
`DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3341 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_d1_free`.
The container restarted at about 19:50 UTC, killing the server and the runs in flight; the server restarted on the same
datadir with a clean InnoDB/XA crash recovery, and every final native run below started after that.

| Run | Scope | Tests / assertions | F / E / S | Exit |
| --- | --- | --- | --- | --- |
| Final | `ProductionFreeGrantCommitObserverTest` (whole file) | 16 / 118 | 0 / 0 / 0 | 0 |
| Final | `ProductionFreeGrantFirstByteTest` (whole file) | 10 / 96 | 0 / 0 / 0 | 0 |
| Final, four chunks | `ProductionFreeGrantSchemaTest`, all 16 methods without data sets | 16 / 168 | 0 / 0 / 2 | 0 each |

The two Schema skips are methods in the reviewed MySQL skip census (`test_a_temporary_shadow_of_a_parent_or_owned_table_is_refused`,
`test_parent_floor_drift_is_refused_before_the_first_owned_ddl`); no native skip in any final run is unlisted. The 37 data
sets of `test_every_contiguous_empty_installation_prefix_resumes_to_the_exact_schema` are also MySQL-census skips and were
not run natively (each costs a full migration before it skips); their native counterpart
`test_native_every_contiguous_empty_installation_prefix_resumes_to_the_exact_schema` ran and passed. A second container
restart at about 22:00 UTC killed the first whole-file Schema run, and a whole-file attempt with server defaults timed out
at the lane's 20-minute chunk limit after five cases; it is not counted. The four counted chunks ran at `a5c5a866`, whose
only difference from the tested source is documentation, on the same datadir restarted with
`--innodb-flush-log-at-trx-commit=2 --sync-binlog=0` to shorten migrations (durability only; isolation stays
REPEATABLE-READ). The CommitObserver and FirstByte runs used the server defaults. The server was shut down normally at the
end (`Shutdown complete`).

What the native cases establish:

- **Observer re-proof with a TEMPORARY parent shadow.** A committing listener creates `CREATE TEMPORARY TABLE users AS
  SELECT ...` on the command's own session; the commit is refused and nothing is written. A second case shadows
  `production_free_revocations` (which would hide a durable revocation from the graph read itself); the owned-schema
  proof refuses `shadowed_schema` before the commit.
- **First-byte frame is READ COMMITTED, READ ONLY and non-locking.** An independent process holds `FOR UPDATE` locks on
  the origin row and the revocation gap while the transfer's first byte is proved with `innodb_lock_wait_timeout = 2`:
  the bytes arrive in under two seconds. A second independent process takes `LOCK TABLES production_free_origins WRITE`,
  waits until the frame is blocked on that table's metadata lock, and reads `performance_schema.events_transactions_current`
  for the frame's thread: `ISOLATION_LEVEL = READ COMMITTED`, `ACCESS_MODE = READ ONLY` (asserted). The session defaults
  are REPEATABLE-READ / read-write again afterwards.
- **Independent-process race, revoke vs redeem across the first byte** (separate OS processes, file barriers):
  (i) revocation commits after the redemption commit and before the first byte: `outcome_unknown`, zero bytes, the
  redemption retained; (ii) revocation in flight, held at its own commit, during the first byte: the frame neither
  waits for it nor sees it, the full bytes go with the right SHA-256, then the revocation commits; (iii) revocation
  holds its locks while the redemption starts: the redemption waits on the origin row (seen in
  `performance_schema.data_lock_waits`), then sees the committed revocation and records nothing (`revoked`, zero bytes).

Development attempts before the final runs (vendor Filament 5.8.4), retained as failures:

| Attempt | Result | Cause |
| --- | --- | --- |
| First native selection (4 cases) | 3 pass, race failed | interleaving (iii) started the redeemer's sign-in while the staff revocation held the staff row; sign-in reads users with a locking scan, so the redeemer never reached its gate. The worker gained a post-sign-in gate. |
| Race, second and third attempts | (i) and (ii) passed, (iii) failed | the lock-wait detector read `information_schema.INNODB_TRX`, which did not list the waiting redemption (it waited in `statistics` on the origin `FOR UPDATE`, visible in the processlist). The detector now reads `performance_schema.data_lock_waits`. |
| Race, fourth attempt | 1 / 20, pass | |
| Interrupted runs | none counted | two whole-file native runs were stopped by the harness background limit or by the lane (a source edit mid-run, and a mutation overlapping a run); their partial output is not counted. |

## Mutations

Each mutation was a temporary, uncommitted edit, reverted by restoring a byte copy of the file (verified with `cmp`).

| ID | Mutation | Red result |
| --- | --- | --- |
| M1 | Observer: remove the terminal re-proof and the parent floor at commit | `ProductionFreeGrantCommitObserverTest`: 5 failures (revocation, policy, owner, session marker, staff policy). Re-run at the tested source with the M2 filter set: 4 observer failures in that selection. |
| M2 | Transfer: skip `proveBeforeBytes()` in `begin()` | `ProductionFreeGrantFirstByteTest`: all five post-commit withdrawals and the refused-begin case fail; `ProductionFreeGrantHttpTest`: the revocation case fails (the marker and mount cases are also caught by the HTTP layer's own checks). Re-run at the tested source: 6 first-byte failures. |
| M3 | Config: flip the shipped literal to `'http_mounted' => true` | `route:list` then shows all six routes; `test_shipped_configuration_is_literally_default_off` and all six cold default-off cases fail. |
| M3b | Mount: `== true` instead of `=== true` | the `"true"`, `1` and `[true]` cases fail in both the cold and runtime default-off tests (6 failures). |
| M4 | Privacy: drop the `contention` mapping (and its `Retry-After`) | the contention test fails: 409 instead of 503. |
| M5 | Observer: remove the raw metadata admission from `source()` | all four metadata-plant cases fail (the refusal then comes later, from identity admission, after the metadata was read). |

M1 to M5 first ran on the uncommitted pre-merge tree; the mutated files (`ProductionFreeGrantCommitObserver`,
`ProductionFreeGrantTransfer`, `config/production-free-grants.php`, `ProductionFreeHttpMount`, `ProductionFreePrivacy`)
are byte-identical to the tested source. M1 and M2 were repeated at the tested source after the merge. The working
tree was verified clean after each reversal.

## Independent review

The independent review of `12441893` decided **APPROVE WITH CONDITIONS**, with no P0 or P1. The decision record is
copied verbatim in [`independent-review-12441893-DECISION.md`](independent-review-12441893-DECISION.md). The follow-up
commits address these findings:

| Finding | Severity | Change | Evidence |
| --- | --- | --- | --- |
| F1 | P2 | `ProductionFreeGrantTransactions::run` reports `outcome_unknown` when the begun owned frame is no longer the observer's to roll back (`abort()` now says whether it rolled back), and when `abort()` itself fails. Contention stays `contention`; a frame that never began stays a refusal. Membership's observer is unchanged. | Three tests (raw commit, commit then reopen, rollback then reopen); all three fail on the previous source with `changed_transaction`. |
| F2 | P2 | Tests only: a committing listener writes a sealed revocation inside the accept commit and inside the redemption commit. | Both refuse and roll back the command and the revocation. MH and MG now fail. |
| F4 | P3 | Test only: the redemption row's `bytes` is changed and re-sealed after the commit, with its update guard removed and restored verbatim so the schema proof passes. | Zero bytes, `outcome_unknown`, redemption retained. MD now fails. |
| F5 | P3 | One throttle prefix per tier: reads (index, show), review, writes (accept, authorize), redeem. Limits unchanged. | 25 library views, then authorize and redeem succeed; with the shared prefix the test gets 429. |
| F10 | P3 | Packet: duplicated parenthetical removed; the red/green claim restated. | This file. |

Deferred to the D1 follow-up batch (freeze-and-batch rule), unchanged in this source:

- **F3 (P3):** the accept graph re-projects the new origin only. It does not re-prove that the definition is still open
  at the same availability, or the cap count. Its outcome equals the serial order "accept, then close".
- **F6 (P3):** `ProductionFreeGrantTransfer::$stream` is public and the committed read is an optional constructor
  argument, so in-process code could stream without `begin()`. HTTP cannot reach it.
- **F7 (P3):** JSON is released after the controller's capsule re-proof, not at native send (B4's epoch release is not
  mirrored). Already listed under Untested conditions.
- **F8 (P3):** `run()` checks only the default connection for depth zero; ff0's dropped boundary and Membership's
  `runOwned` require every connection to be idle.
- **F9 (P3):** the frame's fresh transaction manager is not the container's `db.transactions`, so an `afterCommit` job
  or listener dispatched by ordinary code during the frame would run before the commit. Family 256 dispatches nothing.
  Residual.

### Follow-up commits, runs and mutations

| Commit | Content |
| --- | --- |
| `8cf31d32975cde6724ef5410652340c3dbd8016b` | Workflow comments: 2481 of 9495 cases in 235 files (discovery, below) |
| `f98761839ebd9c70fcf19c83e808b2c92dceae5c` | `StagingEnvironmentAdmissionTest` classifies `ProductionFreeHttpMount` as a local-only gate (see below) |

The follow-up tested source is `f98761839ebd9c70fcf19c83e808b2c92dceae5c`. The whole-directory SQLite run used
`cae94875`; the two later commits change only workflow comments and `StagingEnvironmentAdmissionTest`, which was run
separately. Vendor unchanged
(the merged lock above), PHP 8.4.26, PHPUnit 12.5.34, SQLite 3.45.1, `public/build` absent. Native runs used the same
private MySQL 8.4.11 server (port 3341, server defaults), on the same datadir.

| Run | Source | Scope | Tests / assertions | F / E / S | Exit |
| --- | --- | --- | --- | --- | --- |
| SQLite | `08288293` | `ProductionFreeGrantCommitObserverTest` (before the F2 accept case) | 18 / 120 | 0 / 0 / 2 | 0 |
| SQLite | `cae94875` | `tests/Feature/ProductionFreeGrants` | 300 / 2880 | 0 / 0 / 9 | 0 |
| SQLite | `f9876183` | `StagingEnvironmentAdmissionTest`, `StagingRuntimeValidatorTest`, `PaidGrantSpoolReservationTest` | 15 / 229, 80 / 464, 5 / 17 | 0 / 0 / 0 each | 0 each |
| Native | `08288293` | the three raw-commit, commit-reopen and rollback-reopen cases | 3 / 21 | 0 / 0 / 0 | 0 |
| Native | `cae94875` | the accept- and redemption-commit revocation cases and the column-alteration case (its MySQL branch reads `SHOW CREATE TRIGGER`) | 3 / 24 | 0 / 0 / 0 | 0 |

The nine SQLite skips are the same nine methods as before, all in `scripts/ci/database-sqlite-skips.json`. No new test
is native-only, so neither skip census changes. Discovery
(`python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`,
exit 0) now proves "2481 selected MySQL-native tests in 235 files (of 9495 discovered tests in 687 files)", 72 untimed
files; the five added selected cases are the Observer (+3) and FirstByte (+2) cases. The generated files were removed.
Python safeguards at the follow-up source (Node 24): 52, 27, 14 and 53 OK, all exit 0. `git diff --check
origin/main...HEAD` is clean.

`StagingEnvironmentAdmissionTest::test_source_census_routes_every_admitting_gate_through_the_helper_and_leaves_local_only_gates_explicit`
had failed since `a7505c32`: it requires every `local`/`testing` gate in `app/` to be classified, and the mount's gate
was not. The original affected-file selection (above) did not include this test, so the original runs did not show it.
The mount is meant to stay off staging, so it is classified as local-only, next to `TaxHttpMount`.

Each mutation was a temporary edit of the committed file, reverted with `git checkout` and checked clean:

| ID | Mutation | Red result |
| --- | --- | --- |
| MH | `accept` graph returns `[]` | `test_revocation_inside_the_accept_commit_rolls_back_the_origin_and_the_revocation` fails: the accept succeeds ("Must refuse entitlement_changed") |
| MG | redemption-commit graph returns `[]` | `test_revocation_inside_the_redemption_commit_rolls_back_the_redemption_and_the_revocation` fails: the redemption succeeds ("Must refuse revoked") |
| MD | first-byte row equality reduced to `count === 1` | `test_a_compared_column_altered_after_the_redemption_commit_sends_zero_bytes` fails: the transfer writes ("Must refuse outcome_unknown") |
| F1 control | `ProductionFreeGrantTransactions` and the observer at `12441893` | all three raw-listener cases fail with `changed_transaction` |
| F5 control | routes at `12441893` (one shared prefix) | the library-views case gets 429 |

## Census delta

- `scripts/ci/database-sqlite-skips.json`: four MySQL-only methods appended after entry 281 (285 entries; the diff is
  insertions only). The pinned hash of the first 150 entries is unchanged
  (`d93da4e7a13a8c6aff8f97f363eb6d93dde884bad53c9ae50eeb0ed32514c71c`, asserted by `test-focused-tests.py`). File SHA-256
  `7b7b53b17ec4d2b741ccb60af7b94e7ec633fc9c5abd38f4bd7590da4a7b5507`.
- `scripts/ci/database-mysql-skips.json`: unchanged (25 methods, `8d3d4cd2...ca5c4`). No new SQLite-only method.
- `scripts/ci/database-mysql-selection.json`: `tests/Support/production-free-race-worker.php` added to
  `branching_helper_files`. The two new driver-branching test files are selected as census owners.
- `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`, exit 0:
  - main `e8dfe3cd` test sources and census (temporarily checked out in this worktree, then restored): "Proved 2450
    selected MySQL-native tests in 233 files (of 9390 discovered tests in 682 files)", 70 untimed files. (The previous
    workflow comment said 9389; it was one case stale.)
  - tested source: "Proved 2476 selected MySQL-native tests in 235 files (of 9489 discovered tests in 687 files)", 72
    untimed files; selection case identity `6411314400d3f4100ddb56f8cbf3283f6cb488e1479a280fc694651ea01c8f32`. The 26
    added selected cases are exactly the two new census-owner files (16 + 10).
  - Discovery lists tests without running them. The generated `phpunit-ci-mysql-*` files were removed each time.
- `.github/workflows/final-verification.yml` and `.gitlab-ci.yml` comments now read 2476 of 9489 cases in 235 files and
  72 newer files (comments only).

## Default-off proof

- With the shipped configuration, `php artisan route:list --json` in a testing environment lists 166 routes and none under
  `production-free-grants` (tested source, merged vendor).
- `test_cold_default_off_registers_no_route_and_refuses_before_identity_or_sql` boots the real application for the
  shipped `false`, `"true"`, `1`, `null`, `[true]` and `true` in `production`: no route is registered, both a GET and a
  POST answer a private JSON 404, no SQL runs, and the renderer is bound but never resolved.
- `test_runtime_withdrawal_refuses_previously_registered_routes`: with routes registered, withdrawing the flag or
  switching to `production` refuses before identity or SQL.
- The family policy is unchanged: `enabled => false`, `provenance => null`, rehearsal only in `local`/`testing`.

## Open activation decisions (unchanged)

- Source adapter (F-7): refuse withdrawn, quarantined or taken-down assets; host-local; bound its own `open()`.
- Renderer r2 profile or hash-checked fonts.
- Approved terms text and assets.
- Production storage, spool sizing and the web server send timeout (`docs/ops/production-free-http-runtime.md`).
- A1-1 drain runbook before any render profile revision.
- A1-7 / F-8 seal-key rotation.
- `verified_production` provenance: the policy still refuses anything but `synthetic_rehearsal` in `local`/`testing`.

## Untested conditions

- No hosted CI, Focused feedback, Foundation or GitLab run. Nothing was pushed.
- Native MySQL ran only the three Free files above (Schema without its 37 census-skipped data sets). The rest of the native selection, including the other native
  files that use `IdentityCommittedFrame` or the bootstrap privacy chain, was not re-run natively.
- The 53 affected files ran on SQLite only, with the one load-sensitive Tax failure described above.
- JSON responses are released after the committed command and a capsule re-proof in the controller, not at a
  post-kernel native send (B4's epoch release is not mirrored for JSON). Downloads do prove at send time, before
  the first header.
- No real web server, PHP-FPM, proxy or send timeout. A client that stops reading is bounded only by the progress rate
  between chunks and by the server's send timeout, which is a documented deployment requirement, not a tested one.
- `connection_status()` abort detection was not exercised (the CLI has no client connection).
- Worker transactions (`ProductionFreeGrantDocuments`) re-prove policy, render state and frame at commit; render-worker
  wiring is out of scope and untested over HTTP.
- No real sources adapter, rights/scanner/catalog proof, approved terms, fonts, host storage or production provenance.
- The race covers three deterministic interleavings, not a randomized many-iteration stress run.
- F1: a listener's raw PDO commit, commit then reopen, and rollback then reopen are tested on SQLite and natively. A
  MySQL implicit commit (for example DDL issued by a listener) takes the same path but has no committed test. The
  observer detects such a commit only afterwards; it cannot prevent it, so the write may be durable and the answer is
  `outcome_unknown`.
- Review findings F3 and F6 to F9 are deferred (Independent review, above) and unchanged in this source.
- After the review follow-up, native MySQL ran only the six new cases (two runs above), not the three whole files again.
- The observer cannot qualify SQL past a session TEMPORARY shadow created and dropped entirely between two of its own
  proofs; it refuses any shadow present at the commit and at the first byte (F-4 residual, as recorded in the D1 review).
