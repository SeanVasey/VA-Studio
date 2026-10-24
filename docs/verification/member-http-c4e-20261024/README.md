# C4e phase A: default-off member HTTP over the C4d member domain

Lane C4e, phase A, October 24, 2026, branch `harness/member-http-c4e-20261024`, from `origin/main`
`a08e79ca` (PR #165, D1). Phase A is the code and SQLite evidence. Native MySQL evidence (phase B) runs only after
lane G1 (schema ownership once per frame) merges, because the existing writers overrun their fixed 60-second budget on
this host without it.

This is a local development candidate. Nothing is pushed, no PR is open and no workflow was dispatched.

## Work package (stated before editing)

### Authority and limits

Sean approved C4 on 2026-10-18 with `explicit_owner_confirmation_v1` as the member-license activation mode only: a
retained technical confirmation that the owner requested activation, neither legal assent nor write authority. Display
wording, consumption and retention terms, and every policy, definition, profile and terms hash remain unapproved
inputs. This lane sets none of them. `production-memberships.enabled`, `member-grants.enabled` and every approved hash
stay as shipped (`false`/`null`). The decision register also says member HTTP activation needs its own decision; this
lane does not mount activation.

### Files owned

- New: `app/Http/Membership/*` (mount gate, privacy root seam, controller), `routes/production-memberships.php`,
  `app/Domain/Memberships/Production/MembershipHttpBinding.php` (request-bound identity capsule composed from the
  reviewed family 256 capsule), new tests under `tests/Feature/ProductionMembershipHttp/` and
  `tests/Feature/ProductionMembershipBilling/`, this packet, `docs/ops/production-membership-http-runtime.md`.
- Edited: `MembershipCustomerIndex.php` (F3 fence removal, F4 clock seam), `MemberOriginalSourceCapture.php`
  (F6 activation commit classification, one method), `MembershipNativeCustomerIndexTest.php` (F3 lock evidence),
  `config/production-memberships.php` (`http_mounted => false`), `routes/web.php` (gated include),
  `bootstrap/app.php` (privacy root seam), `tests/Feature/StagingEnvironmentAdmissionTest.php` (gate census),
  `scripts/ci/database-sqlite-skips.json` (append only), `scripts/ci/database-mysql-selection.json` if regenerated,
  the two workflow count comments, `CHANGELOG.md`.
- Not touched: G1's files (`IdentityMigrationOwnership`, `IdentityRows`, `SchemaProofFrame`, `MembershipSchema`,
  `MembershipAwardTransaction`, the member and membership observers), `resources/contracts/**`, D1's
  `app/Http/ProductionFree/*`, `AppServiceProvider`, `composer.lock`, CLAUDE.md.

### Assumptions

1. Phase A mounts the membership read and redemption family only: the customer index, and period-addressed reserve,
   release and expire. The activation family (review, confirm, activate, readActivated) stays unmounted: it needs the
   member render profile r2 for PHP-FPM, the review and readActivated pre-bytes hooks, and Sean's separate decision on
   member HTTP activation.
2. The real membership capability bindings (`SealedMembershipPaidInvoice`, `SealedMembershipPolicyFacts`,
   `CurrentEligibleLicenses`, member grant authorities) are not registered in `AppServiceProvider`: the config file says
   "No invoice, license or grant producer is installed", and installing one is not part of this approval. Tests bind
   them as `MemberActivationFixtures` does. Without them every mounted route refuses as not found.
3. No frontend page ships. Customer display wording is an unapproved owner input, and the D1 precedent mounts JSON
   only. The JSON contract is documented in the runtime doc for a later page.
4. F3 option (a): every writer of a member's period, event, redemption and activation rows takes that owner's
   T23 lock (`ProductionCustomerAccess::lock`, `FOR UPDATE` on the owner's `users`, `customer_accounts`, origin,
   verification and challenge rows) before its first membership read and holds it to commit. The index already takes
   the same lock first, so the global fence adds no consistency for the index and is dropped (analysis below).

### Acceptance criteria

- The mount is off unless the literal `production-memberships.http_mounted === true` in `local`/`testing`; a stale
  route table is refused before any session, identity or SQL work; production and staging never mount.
- Every domain refusal on a mounted route maps to one identical not-found response (F8), except: a writer's
  `committed_receipt_refused` is a fixed "submitted, refresh" response, and a writer's `operation_refused` is a fixed
  "outcome unknown, retry the same request" response (F7). Neither says nothing happened.
- The index body is sent only after `MembershipCustomerRead::proveBeforeBytes()` and a fresh capsule proof (C2/F3).
- Per-tier rate limits (reads, writes) with separate limiter prefixes.
- F3: the index no longer touches `BillingAwardFence` (SQLite query census, native NOWAIT plan); F4: an expired
  original deadline refuses pre-bytes with `operation_deadline` (test with a captured clock); F6: activation's commit
  classification uses `MembershipCommitWitness`.
- Census: the new native methods are appended to the SQLite census (first 150 unchanged), the native selection is
  regenerated, both count comments match, and the four safeguards pass.

### Tests

- New HTTP feature tests (default-off matrix, stale table, journey with test-bound capabilities, F8 one-not-found for
  unknown/foreign/malformed ids, F7 outcome-unknown and submitted mappings, privacy headers, body bounds, rate limits).
- New domain tests for F3 (no fence query), F4 (deadline), F6 (pre-commit idle refusal stays `source_refused`).
- Re-run: `MembershipCustomerIndexTest`, `MembershipPeriodAddressedOperationsTest`, `MembershipCommitWitnessTest`,
  the activation and authorization files affected by F6, the staging admission and validator tests, the four
  safeguards, `composer validate --strict`, `npm test` and the typecheck.
- Mutations on the not-found mapping, the outcome-unknown mapping, the mount gate, the rate limiter, the fence removal
  and F6.

## What phase A built

### Domain fixes

**F3, option (a): the customer index no longer takes the global billing award fence.** `MembershipCustomerIndex`
now locks the current owner and then the owner's period window, never `BillingAwardFence`. The pre-bytes re-run uses
the same path, so it no longer takes the fence either.

Why the owner lock is enough. The index reads one owner's periods, credit events, redemptions and member-activation
graph. Every code path that writes those rows was enumerated (`INSERT`/`UPDATE` under `app/Domain/Memberships` and
`app/Domain/Grants/Member`):

| Writer | Rows written | Owner lock before its first membership read |
| --- | --- | --- |
| `MembershipAwardContext` (award) | period, award event | `access->lock` at line 73, before the invoice evidence and its fence |
| `MembershipReservationContext` reserve, release, expire | redemption, reserve/release/expire events | `access->lock` at line 130, first lock in the constructor |
| `MembershipReservationContext` `inspect` (activation capture) and `MemberActivationInspection::insertActivation` | consume event, activation, member origin and artifact rows | the same constructor (line 130) runs first, inside the same owned transaction |
| `MembershipPolicyVersions`, `MemberGrantRows` (staff) | plan versions, member profiles and definitions | immutable append-only rows; the index reads them by id or approved hash and compares raw bytes in its current and committed proofs |
| `BillingLedger`, `BillingWebhookIntake`, `BillingSubscriptionApprovals` | billing rows and hints | not read by the index: it reconstructs the invoice semantics from the sealed period payload |

`ProductionCustomerAccess::lock` reads the actor's `users` row, its single `customer_accounts` row, the origin,
verification and challenge rows with `FOR UPDATE` on MySQL (`IdentityRows::rows`). Award and reserve bind the
principal to the invoice's original buyer, so a writer that changes owner A's rows holds A's `users` and
`customer_accounts` row locks from before its first membership read until it commits. The index takes the same locks
first and holds them until it commits, so it sees every one of A's writers either wholly before or wholly after. The
fence serialized the index against every customer's writers and added no consistency for A's rows.

Lock order stays acyclic: writers take owner, fence, membership rows; the index takes owner, membership rows, a
subsequence of the same order. A writer for owner B that addresses A's period locks B's owner and the fence, then
refuses at the invoice owner check without writing. The index for A never waits for B's owner row or the fence.

Limits, recorded as untested conditions: on SQLite there are no row locks (local and testing only; verified
production requires MySQL); the native lock evidence is phase B; the terminal proof after commit takes no lock, as
before, and still refuses a concurrent change.

Option (b) (lower the bound) was not chosen. It keeps the global serialization, and the bound already is the owner's
own retained periods (at most 101 rows).

**F4.** `MembershipCustomerIndexAvailabilityTest` uses the existing synthetic monotonic membership clock
(`MembershipAwardMonotonicClock`, the namespaced `hrtime` used by `MembershipAwardDeadlineTest`). No production seam
was added. It pins three things: pre-bytes 59 s after the index is released; 61 s after, it refuses with
`operation_deadline` and the read is spent; and a re-run that starts at 30 s and crosses 60 s inside its own
transaction refuses, which a renewed budget would have admitted.

**F6.** `MemberOriginalSourceCapture::inspectActivation` (confirmation and activation writes) installs
`MembershipCommitWitness` instead of a plain `DatabaseTransactionsManager`, arms it immediately before the owned
commit, and classifies a failure as committed when the witness saw the physical commit. Before the owned commit call,
an idle PDO (a foreign rollback, a failed begin) is now a plain `source_refused`; before, it was reported as
`committed_activation_receipt_refused`. Only a commit call that ends with an idle PDO and no witness (that is,
`PDO::commit()` itself threw) keeps the conservative committed-unknown classification. The observers are untouched
(lane G1 edits them), and `MemberActivationObserver` checks the manager by identity, so the subclass is accepted.

### HTTP

| Method | Path | Domain call |
| --- | --- | --- |
| GET | `/production-memberships/periods` | `MembershipCustomerIndex::index` with `MembershipCustomerRead`, then `proveBeforeBytes()` |
| POST | `/production-memberships/periods/{period}/reservations` | `reserveInPeriod` (`offerId`, `expectedRevision`, `requestKey`) |
| POST | `/production-memberships/periods/{period}/reservations/{redemption}/release` | `releaseInPeriod` |
| POST | `/production-memberships/periods/{period}/expire` | `expireInPeriod` |

The D1 precedent is followed: own prefix, flag-gated include in `routes/web.php`, a root privacy seam prepended in
`bootstrap/app.php` with the same report and respond hooks, committed reads, and the staging census classification.
Operator detail is in `docs/ops/production-membership-http-runtime.md`.

- **Mount.** `MembershipHttpMount` is a copy of `ProductionFreeHttpMount` on `production-memberships.http_mounted`
  (literal `false`). Local and testing only; staging and production never mount.
- **F8.** Every `MembershipException` from the domain, an unknown route, a route-pattern miss and the unmounted family
  return one byte-identical body: `404 {"code":"MEMBERSHIP_NOT_FOUND"}`. That covers `paid_period_absent`,
  `invoice_owner_mismatch`, `reservation_absent`, `changed_period`, `foreign_period`, `owner_refused`,
  `invalid_value`, `honor_not_expired`, `credit_not_expired`, the gates and the deadline.
- **F7.** For a writer only: `committed_receipt_refused` is `202 {"code":"MEMBERSHIP_SUBMITTED","refresh":true}`;
  `operation_refused` and any unclassified writer failure are `503 MEMBERSHIP_OUTCOME_UNKNOWN` with `Retry-After: 1`
  and `"retry":"same_request"`. A committed receipt also passes the capsule and the mount before its bytes; if that
  fails, the response is the same 202, never a refusal.
- **Pre-bytes (C2).** The index JSON response is built, then the capsule is proved, `proveBeforeBytes()` re-runs the
  owned read under the original deadline, the mount and the capsule are proved again, and only then is the body
  returned. Any failure there returns the not-found body.
- **Capsule.** `MembershipHttpBinding` is a copy of the reviewed family 256 capsule on the membership and member-grant
  configuration parents with `MembershipPolicy::facts()`. A missing, forged or changed session, or a withdrawn account,
  is `403 MEMBERSHIP_UNAVAILABLE` before any domain call; it says nothing about periods.
- **Rate limits.** Reads `throttle:10,1,production-memberships-reads`, writes `throttle:10,1,production-memberships-writes`,
  plus `block(20, 5)`. Same IP-keyed signature as D1.
- **Body.** JSON only, at most 1 KiB, parsed by D1's `ProductionFreeBody::json` duplicate-key pass (reused, not edited).

### Not built in phase A, and why

| Item | Reason |
| --- | --- |
| Activation routes (review, confirm, activate, readActivated) | Need r2 for PHP-FPM, their own pre-bytes hooks (C2) and Sean's separate decision on member HTTP activation (decision register) |
| Pre-bytes hooks for `review` and `readActivated` | Only needed when those reads are mounted; C2 forbids mounting them without it, and they are not mounted |
| Member render profile r2 | A new member manifest under `resources/contracts/` and a new released profile pair; a separate reviewed lane, as the C4d packet allows |
| Capability bindings in `AppServiceProvider` | Installing the invoice, license and grant producers is not part of the C4 approval |
| Frontend page | Customer wording is an unapproved owner input |
| Per-customer rate-limit key | D1 parity first; a follow-up |

## Files changed

| File | Change |
| --- | --- |
| `app/Domain/Memberships/Production/MembershipCustomerIndex.php` | F3: no `BillingAwardFence`; docblock and lock-order comment |
| `app/Domain/Grants/Member/MemberOriginalSourceCapture.php` | F6: `MembershipCommitWitness` in `inspectActivation`, armed before the owned commit; classification |
| `app/Domain/Memberships/Production/MembershipHttpBinding.php` | New: request capsule (copy of the family 256 capsule on the membership parents) |
| `app/Http/Membership/MembershipHttpMount.php` | New: default-off mount gate (copy of `ProductionFreeHttpMount`) |
| `app/Http/Membership/MembershipPrivacy.php` | New: root seam, F8 not-found, F7 submitted and outcome-unknown |
| `app/Http/Membership/MembershipController.php` | New: index with pre-bytes; reserve, release, expire with receipt allowlist |
| `routes/production-memberships.php`, `routes/web.php` | New routes file; gated include |
| `bootstrap/app.php` | Prepend `MembershipPrivacy`; JSON rendering, report and respond hooks for the prefix |
| `config/production-memberships.php` | `http_mounted => false` |
| `tests/Feature/ProductionMembershipHttp/MembershipHttpMountTest.php` | New: default-off matrix, runtime withdrawal, shipped gates, intake bounds, CSRF, rate limits (33 cases) |
| `tests/Feature/ProductionMembershipHttp/MembershipHttpTest.php` | New: journey, F8, F7 outcome-unknown, F7 submitted, pre-bytes withholding (5 cases) |
| `tests/Feature/ProductionMembershipBilling/MembershipCustomerIndexAvailabilityTest.php` | New: F3 source census plus behaviour, F4 deadline (4 cases) |
| `tests/Feature/ProductionMemberGrants/MemberActivationCommitWitnessTest.php` | New: F6 (3 cases) |
| `tests/Feature/ProductionMembershipBilling/MembershipNativeCustomerIndexTest.php` | F3 native expectations: fence free, owner-lock wait, completion while another session holds the fence |
| `tests/Feature/StagingEnvironmentAdmissionTest.php` | Gate census: `MembershipHttpMount` is local/testing only |
| `scripts/ci/database-sqlite-skips.json`, both workflow comments | Census and selection count |
| `CHANGELOG.md`, `docs/ops/production-membership-http-runtime.md`, `docs/handoff/2026-10-17/RESUMPTION.md`, this packet | Documentation |

## Tested source and commands

Commits on `harness/member-http-c4e-20261024` (local only):

| Commit | Content |
| --- | --- |
| `ab53eb05` | Work-package statement |
| `a97b49bf` | F3 fence removal, F6 witness, F4/F3/F6 tests, native F3 expectations |
| `e5ec6eb1` | Default-off HTTP mount, privacy seam, capsule, routes, light mount test, staging census |
| `7892227f` | Heavy HTTP test; index tail withholds the body behind not-found on any pre-bytes failure |
| `5cd139b5` | Census and selection count |
| `b05f63fc` | Tests that kill the mutation survivors |

Application, route, bootstrap and configuration sources are identical from `7892227f` to the final head; later commits
change only tests, census and documentation. Every run below records its `HEAD` and the number of uncommitted
application, test, route, bootstrap or configuration files (`dirty`) at its start. Tools: PHP 8.4.26 CLI, PHPUnit
12.5.34, Node 24.22.0, the direct PHPUnit command from the brief (SQLite in memory) with `--fail-on-phpunit-warning`,
`public/build` absent, `nice -n 10`, at most three of this lane's PHP processes at once while other lanes also ran.
Vendor: a byte copy of `/home/user/wt/o4/vendor` (same `composer.lock` as `a08e79ca`) plus `composer dump-autoload`;
`node_modules` from `npm ci` (exit 0).

| Run | Source | Selection | Result |
| --- | --- | --- | --- |
| avail1 | `ab53eb05` + F3/F4 edits | `MembershipCustomerIndexAvailabilityTest` | 4 tests, 68 assertions, exit 0, 109 s |
| f6a | `ab53eb05` + F6 edits | `MemberActivationCommitWitnessTest` (2 cases then) | 2 tests, 36 assertions, exit 0, 65 s |
| idx1 | `a97b49bf` | `MembershipCustomerIndexTest` | 12 tests, 191 assertions, exit 0, 577 s |
| f6b | `a97b49bf` | `MemberLicenseAuthorizationPhaseTest` | 6 tests, 133 assertions, exit 0, 226 s |
| f6c | `e5ec6eb1` | `MemberGrantActivationRetentionTest` | 5 tests, 116 assertions, exit 0, 313 s |
| f6d | `e5ec6eb1` | `tests/Unit/MemberActivationOwnershipTest.php` | 14 tests, 27 assertions, exit 0 |
| mount2 | `a97b49bf` + HTTP files | `MembershipHttpMountTest` | 33 tests, 1,070 assertions, exit 0 (mount1 failed one case on a typo in its own data, fixed) |
| http1 | `e5ec6eb1` + test | `MembershipHttpTest` | 5 tests, 334 assertions, exit 0, 278 s |
| http2 | `e5ec6eb1` + test | `MembershipHttpTest` with the added assertions | 1 error: the test re-activated a withdrawn account, which the identity trigger refuses ("Customer identity is retained"); test reordered |
| http3 | `e5ec6eb1` + test | `MembershipHttpTest` | 5 tests, 389 assertions, exit 0, 255 s |
| routes1 | `e5ec6eb1` + test | `StagingEnvironmentAdmissionTest` | 15 tests, 231 assertions, exit 0 |
| routes2 | same | `tests/Unit/StagingRuntimeValidatorTest.php` | 80 tests, 464 assertions, exit 0 |
| routes3 to routes5 | same | D1 `ProductionFreeGrantHttpConfigurationAdmissionTest`, `ProductionFreeGrantHttpBindingTest`, `ProductionFreeGrantHttpTest` | 9/47, 26/144, 39/1,168; each exit 0 |
| grants1 | `5cd139b5` | `tests/Feature/ProductionMemberGrants` | 25 tests, 474 assertions, 7 skipped (native-only), exit 0, 877 s |
| act1 | `5cd139b5` | `tests/Feature/ProductionMemberActivation` | 30 tests, 82 assertions, exit 0 |
| bill1 | `5cd139b5` | `MembershipPeriodAddressedOperationsTest` | 4 tests, 69 assertions, exit 0 |
| bill2 | `5cd139b5` | `MembershipCommitWitnessTest` | 5 tests, 94 assertions, exit 0 |
| bill3 | `5cd139b5` | `MembershipNativeCustomerIndexTest` | 4 skipped on SQLite (declared in the census), exit 0 |
| orig0 | `5cd139b5` | `MemberGrantDefinitionsCommitWitnessTest` | 1 test, 9 assertions, exit 0 |
| avail2 | `5cd139b5` + test edits (equal to `b05f63fc`) | `MembershipCustomerIndexAvailabilityTest` | 4 tests, 69 assertions, exit 0 |
| f6e | same | `MemberActivationCommitWitnessTest` | 3 tests, 50 assertions, exit 0 |
| http4 | same | `MembershipHttpTest` | 5 tests, 390 assertions, exit 0, 200 s |
| safeguards | `5cd139b5` content | `python3 scripts/ci/test-{phpunit-shards,ci-scope,workflow-cadence,focused-tests}.py` | 52, 27, 14, 53 tests OK; each exit 0 |
| ops | same | `python3 -m unittest tests/ops/test_staging_capability_profile.py` | 17 tests OK |
| typecheck | same | `npx tsc --noEmit` | exit 0 |
| vitest | same | `npm test` | 68 files, 1,172 tests passed, exit 0 |
| composer | same | `composer validate --strict` | valid, exit 0 |
| diff | final | `git diff --check a08e79ca..HEAD`; a pattern grep of the diff for live or test keys, webhook secrets, private keys and passwords | clean; none |

### Final confirmation on the final source

PENDING: recorded in the packet commit that follows the final runs.

## Mutations

Each mutant was applied in a hard-link copy of the worktree (`scratchpad/c4e-mut`) by atomic file replacement, so
the tested worktree was never written; each was reverted and compared byte for byte, and `diff -rq app routes tests`
against the worktree was empty at the end. Driver and specs: `scratchpad/c4e-mut-driver/`.

| ID | Mutation | Test run | Result |
| --- | --- | --- | --- |
| M1 | F8: domain refusals become `409` instead of the one not-found | mount test, shipped gates | **Killed** |
| M2 | F7: a writer's `operation_refused` falls through to not-found | HTTP generic writer refusal | **Killed** |
| M3 | F7: a writer's `committed_receipt_refused` falls through to not-found | HTTP committed write | **Killed** |
| M4 | F7: a post-commit capsule or mount refusal returns not-found instead of submitted | HTTP committed write | **Killed** |
| M5 | Mount check removed from the privacy seam and the controller head | HTTP journey (runtime withdrawal, gates open) | Survived first (the index tail still checks the mount, and query events miss raw PDO); **killed** after the test also requires zero domain transactions |
| M5b | M5 plus the tail mount check | HTTP journey | **Killed** |
| M6 | Read limit 10 becomes 1000 | mount test, rate limits | **Killed** |
| M7 | Writes share the read limiter prefix | mount test, rate limits | **Killed** |
| M8 | Index skips `proveBeforeBytes()` | HTTP pre-bytes withholding | **Killed** |
| M9 | Index skips both tail capsule proofs | HTTP pre-bytes withholding (session-marker case) | **Killed** |
| M10 | Capsule drops its session-marker digest comparison | HTTP every refusal (forged marker) | Survived: equivalent. `ProductionCustomerSessions::principal()` already refuses a marker whose digest differs (`hash_equals`), so the capsule check is a second layer, as in D1 |
| M11 | F3: the fence lock is re-added to the index | availability, owner-lock census | **Killed** |
| M12 | F4: captured deadline becomes `PHP_INT_MAX` | availability, after the original deadline | **Killed** |
| M13 | F4: the re-run gets a renewed inner 60 s budget | availability, re-run | Survived first (the outer original-deadline check still refused, later); **killed** after the test requires that the re-run never reaches its commit |
| M14 | F6: back to the old `committed \|\| ! inTransaction()` heuristic | F6 idle PDO before the commit call | **Killed** |
| M15 | F6: classification ignores witness and commit step (`$committed` only) | F6 throwing afterCommit | **Killed** |
| M15b | F6: witness ignored, idle-at-commit fallback kept | F6 reopened transaction after a witnessed commit | Survived the throwing-afterCommit case (the fallback also sees an idle PDO); **killed** by the added reopen case |
| M16 | Receipt allowlist bypassed (raw domain projection) | HTTP journey | **Killed** |

## Census and selection

- `scripts/ci/database-sqlite-skips.json`: the two renamed `MembershipNativeCustomerIndexTest` methods keep their
  positions (284 and 285, beyond the hash-pinned first 150); the new
  `test_native_index_completes_while_another_customers_writer_holds_the_global_fence` is appended (291 to 292 entries).
  The first-150 hash is unchanged (`d93da4e7…`). This is a rename in place plus one append, not a pure append: the
  old names no longer exist, and the census must match the skipped methods exactly.
- The MySQL skip census is unchanged: no new test is SQLite-only.
- `phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json
  --mysql-native-selection`: exit 0, 2488 selected of 9568 discovered cases in 237 selected files (was 2487 of 9523).
  The generated `phpunit-ci-mysql-*` files and manifest were deleted. Both workflow count comments updated.

## Phase B: native evidence plan (after G1 merges)

Run on a private `/opt/mysql-8.4.11/bin/mysqld` (never `/usr/sbin/mysqld`, which is 8.0), own datadir under the
session scratchpad, unused port, `VA_LOCAL_DISPOSABLE_MYSQL`, database `vaseyaudio_member_c4e`, `REPEATABLE-READ`,
no deadline raised, on the exact merged SHA (C4e rebased or merged onto main with G1), one PHPUnit process per chunk.

1. `MembershipNativeCustomerIndexTest` (three of its four methods are new or renamed by F3):
   owner and period locks held with the fence free; a wait (1205) at a writer-held owner `users` row with nothing
   retained; completion while another session holds the fence; pre-bytes after a reserve.
2. `MembershipNativeCommitWitnessTest` reservation case and `MembershipCommitWitnessTest` reserve, delegate and release
   cases on MySQL (the reservation and release witness).
3. `MembershipPeriodAddressedOperationsTest` positive case on MySQL.
4. `MemberActivationCommitWitnessTest` and `MembershipHttpTest` on MySQL (F6 and the HTTP mapping natively).
5. F3 latency. A scratch harness (not committed): build `memberActivationFixture()`, add synthetic older periods in
   the retained format (as `MembershipCustomerIndexTest::syntheticPeriods`) to reach 1, 10 and 100 periods; for each,
   time three index plus pre-bytes calls and record statements per call from a general log. Then, with another
   customer's session holding `production_membership_billing_award_fence` `FOR UPDATE`, repeat the 100-period call
   to show it completes and does not wait, and have that session run a real award for another customer while the
   index of 100 periods runs, to show neither waits for the other. Report wall time and statement counts; if the
   100-period index exceeds its own 60 s budget after G1, that is a finding (lower the bound or page), not a cap to
   raise.

The pre-G1 native probe the brief allowed was not attempted: every native case above needs `memberActivationFixture`,
whose reserve overruns its fixed 60 s budget on this host before G1 (C4d packet and review N2), so a pre-G1 run
would measure the known writer overrun, not the index.

## Untested conditions

- No native MySQL evidence for anything in this lane (phase B). The F3 fence removal is proved natively only by the
  phase B NOWAIT and latency runs; on SQLite it is pinned by a source census and by behaviour.
- No independent-process race between the index and a writer; the argument is the owner-lock enumeration above.
- The per-period history reads on MySQL: if any is a non-locking consistent read taken before the owner lock is
  granted, a writer that committed while the index waited would make the index refuse (fail closed), not leak. Not
  exercised.
- `PDO::commit()` that throws while the PDO still reports a transaction (for example a lost connection with a stale
  status) is reported by activation as `source_refused` and by the writers as `operation_refused`. HTTP presents the
  writers' case as outcome-unknown; activation is not mounted.
- No HTTP evidence under PHP-FPM, a real web server, a proxy or a browser. No slow-client bound beyond the session
  block and the throttle.
- Rate limits are per client address (D1 parity), not per customer.
- No Foundation run, no hosted CI, no host, provider or production evidence.
