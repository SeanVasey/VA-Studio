# C4e phase A: default-off member HTTP over the C4d member domain

Lane C4e, phase A, October 24, 2026, branch `harness/member-http-c4e-20261024`, from `origin/main`
`a08e79ca` (PR #165, D1). Phase A is the code and SQLite evidence. Phase B, the native MySQL evidence, ran after G1
merged, on `origin/main` `707649a6` merged into the branch as `34fd070c` (see
[Phase B: native results](#phase-b-native-results-on-the-merged-source-october-24)); it found that the index does not
fit its budget beyond a few periods (F9).

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

**F3, revised after the independent review (N1, N2): the index locks only the owner.** The first phase A commit
(`a97b49bf`) dropped `BillingAwardFence` but kept `FOR UPDATE` on everything else the index reads. The review at
`b3deef84` showed that this still serialized every customer behind the shared plan-version, member-definition and
member-profile rows (N1: every award, reserve, release, expire and activation of every customer on the plan locks the
plan row; even a zero-period index locked plan, definition and profile), and that without the fence those shared rows
were locked in conflicting orders (N2: an InnoDB 1213 deadlock reproduced natively). The claims in the earlier packet,
CHANGELOG, docblock and runtime doc were wrong and are corrected here.

The design now in `MembershipCustomerIndex` (commit listed under Tested source):

- **Owner-scoped locks only.** The frame's first statement is a locking read of the owner's `users` row
  (`lockOwnerFirst`, added after the re-review, R1). Then `ProductionCustomerAccess::lock` locks the current owner's
  T23 rows (`users`, the single `customer_accounts` row, origin, verifications and challenge, `FOR UPDATE` on MySQL
  through `IdentityRows::rows`, in that fixed order), the same rows in the same order that every membership writer
  locks first and holds to commit. The only further locking reads are re-locks of the same owner rows by the current
  and historical identity proofs, and the owner's historical challenge rows locked by `verifyHistoricalBinding`
  (`ProductionCustomerAccess.php:177`; re-review R2).
- **Everything else is a consistent read.** The period window (no `FOR UPDATE`), each period's events, redemptions and
  member graph (`MemberActivationHistoryReader::capturePeriod(..., consistentRead: true)`, a new opt-in mode; writers
  keep the locking default), each period's plan row and the approved definition, profile and plan
  (`MemberGrantRows` with its existing non-locking flag; `definitionByHash` gained the same optional flag). The current
  proof re-reads the same rows in the same snapshot.
- **Pre-bytes** re-runs the same path, so it takes the same single lock.

Why this is correct (consistency):

1. All consistent reads of one REPEATABLE READ InnoDB transaction see one transactionally consistent snapshot, so the
   index can never audit a torn state (for example a reserve event without its redemption).
2. Every writer of the owner's rows is enumerated in the table below. Each takes the owner lock before its first
   membership read and holds it to commit; award and reserve bind the principal to the invoice's original buyer
   (`SealedMembershipPaidInvoice::sameOwner`). While the index holds the owner lock, none of them can commit.
3. The snapshot follows the owner lock. MySQL creates the REPEATABLE READ read view at the first consistent read,
   and the identity schema proofs in `new MembershipRows` and `ProductionCustomerAccess::lock` read identity rows
   (`SELECT id FROM production_identity_* LIMIT 1`, `IdentityMigrationOwnership::inspect`) before any `FOR UPDATE`.
   The first phase A design therefore always created the view before the owner lock: an index that waited behind a
   same-owner writer audited the pre-write state and was refused by the terminal proof (the re-reviewer showed this
   natively, R1). `lockOwnerFirst` makes the frame's very first statement a locking read of the owner's `users` row,
   which creates no read view, so the view is created after every same-owner writer holding that row has committed.
   The schema proofs were not reordered: they live in the identity domain and are changed by lane G1. A defensive
   layer remains: the terminal proof after the index commits runs in a new read-only committed frame
   (`IdentityCommittedFrame`, a new transaction whose snapshot follows the index commit). It re-reads the window,
   every period's complete history and the support rows and refuses on any difference (`changed_period_window`,
   `changed_history`, `changed_policy_facts`). The pre-bytes proof repeats the whole read and compares the projection.
   Staleness can therefore only refuse, never be released.
4. Plan versions, member profiles and member definitions are append-only and immutable: their UPDATE and DELETE
   triggers are `0 = 1` (`MembershipSchema::guards`, `MemberGrantSchema::guards`), their ids and hashes are unique
   (insert guards and unique keys on `policy_hash`, `profile_hash`, `definition_hash`), and every guarded read
   re-proves the exact triggers (`MembershipRows::assertCurrent`, `MemberGrantCurrentSchema::assertCurrent`). None of
   them has a status, retirement or current-pointer column; currentness is the approved hashes in configuration,
   which the index captures and re-proves. What a writer's `FOR UPDATE` on these rows protects is only the ordinary
   locking-reader default; there is no state a locking read could exclude that an unlocked read could see. An
   unlocked read can only miss a row committed after the read view, which refuses (`facts_absent`,
   `policy_facts_absent`, `history_scope`). The availability test also proves on the real schema that UPDATE and
   DELETE of those three rows are refused.

| Writer | Rows written | Owner lock before its first membership read |
| --- | --- | --- |
| `MembershipAwardContext` (award) | period, award event | `access->lock` at line 73 |
| `MembershipReservationContext` reserve, release, expire | redemption, reserve/release/expire events | `access->lock` at line 130, first lock in the constructor |
| `MembershipReservationContext` `inspect` (activation capture) and `MemberActivationInspection::insertActivation` | consume event, activation, member origin and artifact rows | the same constructor (line 130), inside the same owned transaction |
| `MembershipPolicyVersions`, `MemberGrantRows` (staff) | plan versions, member profiles and definitions | none needed: append-only, immutable rows, read unlocked and compared byte for byte |
| `BillingLedger`, `BillingWebhookIntake`, `BillingSubscriptionApprovals` | billing rows and hints | not read by the index |

Why this removes N1 and N2 (availability):

- The index never holds or requests a lock on the fence, a shared plan, definition or profile row, or any row or gap
  of the owner's membership tables. No other customer's writer, activation or index can wait for it there, and it
  cannot wait for them there.
- After the owner lock its only further locks are on the same owner's identity rows (re-proofs and historical
  challenges; R2). Its only waits are therefore on rows of that owner, which only transactions acting for the same
  owner hold. A lock-order cycle through the index would need another
  transaction that holds a later one of those rows and waits for an earlier one; every `ProductionCustomerAccess::lock`
  caller uses the same order. Identity-domain operations on the same owner (sign-in, recovery) lock the same rows in
  their own order; that pre-existing exposure is shared by every membership writer and was not changed here.
- Residual cross-customer effect: the owner lock's own reads (`customer_accounts` by `user_id`, origins by
  `account_id`, verifications by `origin_id`) take next-key locks, whose gaps can briefly delay another customer's
  identity insert into an adjacent gap. Gap requests never wait, so this cannot form a cycle; every membership writer
  has the same exposure.

Freshness (re-review R1, fixed): before `lockOwnerFirst`, every index that waited behind a same-owner writer that
changed something it reads was refused (`changed_period_window`) — deterministically, not occasionally. With the
owner row locked first, such an index answers with the writer's commit included. The native test
`test_native_index_waiting_behind_a_same_owner_writer_reads_that_writers_commit` pins this (results below); the
SQLite census pins that `lockOwnerFirst` precedes `new MembershipRows`.

Option (b) (restore the fence and lower the bound) was not needed: the unlocked design is safe by the argument above
and removes the shared contention instead of bounding it.

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
- **Index body allowlist (review N6, added after the review).** The index projection is rebuilt from exact key lists
  at every level (index, period, balances, applicable definition, reservation). Any other key refuses with the one
  not-found response, so a new domain field cannot reach a customer unreviewed.
- **No session before the seam (review N3, added after the review).** The runtime-withdrawal test asserts that an
  unmounted request carries no session cookie, and the shipped-gates test that a mounted one does, so removing the
  seam's own mount check (which the controller check would otherwise mask) now fails.

### Not built in phase A, and why

| Item | Reason |
| --- | --- |
| Activation routes (review, confirm, activate, readActivated) | Need r2 for PHP-FPM, their own pre-bytes hooks (C2) and Sean's separate decision on member HTTP activation (decision register) |
| Pre-bytes hooks for `review` and `readActivated` | Only needed when those reads are mounted; C2 forbids mounting them without it, and they are not mounted |
| Member render profile r2 | A new member manifest under `resources/contracts/` and a new released profile pair; a separate reviewed lane, as the C4d packet allows |
| Capability bindings in `AppServiceProvider` | Installing the invoice, license and grant producers is not part of the C4 approval |
| Frontend page | Customer wording is an unapproved owner input |
| Per-customer rate-limit key | D1 parity first; a follow-up |

## Independent review at `b3deef84` and follow-up

Decision: **APPROVE WITH CONDITIONS** (`scratchpad/c4e-review-b3deef84.md`), no P0 or P1. The HTTP layer, F4, F6, the
census rename and the tests held. Findings and their resolution:

| ID | Sev | Finding | Resolution |
| --- | --- | --- | --- |
| N1 | P2 | The index still took `FOR UPDATE` on the shared plan, definition and profile rows that every same-plan writer locks, so customers still serialized; even a zero-period index locked them. The F3 claims were wrong | The index now locks only the owner's T23 rows (design and proof under F3 above); claims corrected in this packet, `CHANGELOG.md`, the class docblock, the runtime doc and the native test docblock |
| N2 | P2 | Without the fence those shared rows were locked in conflicting orders (index plan then definition; zero-period index definition then plan; activation plan then definition); native 1213 | Same change: the index requests no lock after the owner lock, so no cycle through shared or membership rows can include it |
| N3 | P3 | Removing the seam's own mount check passed every test, but issued a session cookie | Cookie absence asserted for unmounted requests, presence for mounted ones (mutant SM7 killed) |
| N4 | P3 | F6 commit-throws fallback unpinned | Kept as an untested condition; activation is not mounted |
| N5 | P3 | Non-membership writer failure is 503; billing evidence read before the owner comparison (pre-existing) | Noted under untested conditions |
| N6 | P3 | Index body had no HTTP allowlist | Exact key allowlist at every level (mutants SM6, SM6b killed) |
| N7 | P3 | Per-IP rate limits | Deferred: a per-customer limiter before any non-local mount |

Follow-up commits (local only):

| Commit | Content |
| --- | --- |
| `bde867f6` | Index locks only the owner's T23 rows; consistent-read history capture; non-locking `definitionByHash`; source census and on-schema immutability test; native cases; census (two renamed in place at 284 and 291, two appended, 294 entries) |
| `a9147db8` | N6 index allowlist; N3 cookie assertions |
| `9d1e09f6` | Native selection count: 2490 of 9573 cases in 237 files |
| (packet commit) | This section, the corrected F3 text, the probe and the phase B plan |

Two files beyond the work package's stated ownership were edited, each with an opt-in default-preserving parameter:
`app/Domain/Grants/Member/MemberActivationHistoryReader.php` (`consistentRead`) and
`app/Domain/Grants/Member/MemberGrantRows.php` (`definitionByHash(..., $committed)`). Lane G1 does not touch either;
every existing caller keeps the locking default.

**Tested source for the follow-up: `9d1e09f6`.** The packet commit after it changes only documentation.

SQLite runs on the follow-up (the brief's direct PHPUnit command, in-memory SQLite, `public/build` absent; `git status
--short` empty at the start of each final run):

| Run | HEAD | Selection | Result |
| --- | --- | --- | --- |
| f2A | `9d1e09f6` | `tests/Feature/ProductionMembershipHttp` | 39 tests, 1,508 assertions, exit 0 |
| f2B | `9d1e09f6` | `MembershipCustomerIndexAvailabilityTest`, `MembershipCustomerIndexTest` | 17 tests, 300 assertions, exit 0 |
| f2C | `9d1e09f6` | `tests/Feature/ProductionMemberGrants` (writers' locking history capture unchanged) | 26 tests, 488 assertions, 7 native skips, exit 0 |
| f2D | `9d1e09f6` | `MembershipPeriodAddressedOperationsTest`, `MembershipCommitWitnessTest`, `MembershipNativeCustomerIndexTest`, `StagingEnvironmentAdmissionTest` | 30 tests, 394 assertions, 6 native skips, exit 0 |
| f2E | `9d1e09f6` | `MemberActivationHistoryReaderTest`, `MemberActivationHistoryQueriesTest`, `tests/Feature/ProductionMemberActivation` | 49 tests, 139 assertions, exit 0 |
| idx2 | working tree | `MembershipCustomerIndexTest` | 1 error in the reserve fixture (`private_bytes`): a hard-link copy made during the run changed a fixture file's link count; not a code fault. Re-run idx3: 12 tests, 191 assertions, exit 0 |
| safeguards | `9d1e09f6` content | the four `scripts/ci/test-*.py` | 52, 27, 14, 53 tests OK; each exit 0 |
| selection | `9d1e09f6` content | `phpunit-shards.py ... --mysql-native-selection` | exit 0, 2490 of 9573 in 237 files |

`git merge-tree` of `9d1e09f6` with G1's current head `a1414f1c` reports conflicts only in the two workflow count
comments and `docs/handoff/2026-10-17/RESUMPTION.md`; no application file overlaps.

Mutations of the follow-up. SQLite mutants ran in a hard-link copy by atomic replacement, each restored and compared;
`diff -rq app routes` against the worktree was empty before the copy was removed. Native mutants (NM, in the probe
table above) ran in the pre-G1 probe copy the same way.

| ID | Mutation | Test | Result |
| --- | --- | --- | --- |
| SM1 | window `FOR UPDATE` inside the index transaction | availability lock-shape census | **Killed** (also NM2b natively) |
| SM2 | per-period plan read back to `$rows->one(...)` | same | **Killed** (also NM1 natively) |
| SM3 | `definitionByHash` locking | same | **Killed** |
| SM4 | history capture without `consistentRead` | same | **Killed** (also NM4 natively) |
| SM5 | history reader ignores `consistent_read` | same | **Killed** |
| SM6 | `indexBody` returns its input unchecked | mount test, allowlist | **Killed** |
| SM6b | controller sends the raw projection | mount test, allowlist | **Killed** |
| SM7 | seam's own mount check removed (review R11) | mount test, runtime withdrawal (cookie) | **Killed** (7 failures) |

On SQLite the lock-shape mutants are killed by a source census, because SQLite has no row locks; the native probe
kills the plan, window, history and fence mutants by behaviour (NOWAIT 3572). The definition-read mutant (SM3) has no
native kill in the probe (the n1 definition probe would show it; not run as a separate mutant).

## Independent re-review at `9d1e09f6` and follow-up

Decision: **APPROVE** (`scratchpad/c4e-rereview-9d1e09f6.md`), N1 and N2 fixed, no P0 to P2. Its P3 findings:

| ID | Finding | Resolution |
| --- | --- | --- |
| R1 | The read view was always created before the owner lock (identity schema proofs read `production_identity_*` rows at statement 304; the first `FOR UPDATE` was statement 1203), so an index waiting behind a same-owner writer that changed something it reads was refused deterministically (`changed_period_window`; shown natively) | `lockOwnerFirst`: the frame's first statement locks the owner's `users` row; a locking read creates no read view. Schema proofs were not reordered (identity domain, lane G1). SQLite census pins the order; the native test `test_native_index_waiting_behind_a_same_owner_writer_reads_that_writers_commit` pins the behaviour |
| R2 | "After the owner lock it requests no further lock" was false: re-proofs re-lock the owner rows and `verifyHistoricalBinding` locks the owner's historical challenges | Wording corrected (class docblock, this packet) |
| R3 | Nothing pinned that writers keep the locking defaults | Census asserts both defaults (`capturePeriod` `consistentRead=false`, `definitionByHash` `committed=false`) and that every writer call site passes no flag |
| R4 | History-side stale race only by code reading | Native test `test_native_index_waiting_behind_a_same_owner_release_reads_the_released_history` with the genuine release worker; passed in phase B at `34fd070c` and `8cddd8b4` |

Commit `aafafabb` carries R1 to R3 and the two new native cases. A pre-G1 attempt to show R1 natively was not
conclusive: before G1 the index spends tens of seconds in schema guards before its first statement, so the writer
stand-in's wait bound expired first (the index still listed the writer's period, but the wait was not observed), and
the pre-bytes re-run overran the shared 60 s budget. The evidence is the phase B run on the merged G1 source below.

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

### Final confirmation on the first candidate (`b3deef84`, before the review)

**Tested source: `b3deef84`.** Its application, route, bootstrap and configuration files equal `7892227f`; the commits
after it change tests, census, documentation and one workflow comment only. The packet commit that follows changes
only this file and the GitHub workflow count comment. `git status --short` was empty at the start of every run.

| Run | HEAD | Selection | Result |
| --- | --- | --- | --- |
| fin1a | `c06886f8` | `tests/Feature/ProductionMembershipHttp` (mount test and the HTTP test before its last two assertions) | 38 tests, 1,460 assertions, exit 0, 270 s |
| fin1e | `b3deef84` | `MembershipHttpTest` | 5 tests, 416 assertions, exit 0, 261 s |
| fin1b | `b3deef84` | `MembershipCustomerIndexAvailabilityTest` | 4 tests, 69 assertions, exit 0 |
| fin1c | `b3deef84` | `MemberActivationCommitWitnessTest` | 3 tests, 50 assertions, exit 0 |
| fin1d | `b3deef84` | `StagingEnvironmentAdmissionTest` | 15 tests, 231 assertions, exit 0 |
| fin2a | `c06886f8` | `MembershipCustomerIndexTest` | 12 tests, 191 assertions, exit 0, 576 s |
| fin2b | `b3deef84` | `MembershipPeriodAddressedOperationsTest` | 4 tests, 69 assertions, exit 0 |
| fin2c | `b3deef84` | `MembershipCommitWitnessTest` | 5 tests, 94 assertions, exit 0 |
| fin2d | `b3deef84` | `MembershipNativeCustomerIndexTest` | 4 skipped on SQLite (census), exit 0 |
| finOA | `c06886f8` | first 10 files of `tests/Feature/ProductionMemberOriginals` | 70 tests, 244 assertions, 5 skipped (native-only), exit 0 |
| finOB | `b3deef84` | the other 11 files of `tests/Feature/ProductionMemberOriginals` | 129 tests, 875 assertions, 4 skipped (native-only), exit 0 |
| selection | `b3deef84` | `phpunit-shards.py ... --mysql-native-selection` | exit 0, 2488 of 9569 in 237 files; generated files deleted |
| safeguards | `b3deef84` + comment | the four `scripts/ci/test-*.py` | 52, 27, 14, 53 tests OK; each exit 0 |

`c06886f8` differs from `b3deef84` only in `MembershipHttpTest.php` (two added body-shape assertions), which fin1e ran
at `b3deef84`. `tests/Feature/ProductionMemberGrants` (grants1, 25 tests, 7 native skips) ran at `5cd139b5`, whose
application sources equal `b3deef84`; only `MemberActivationCommitWitnessTest` changed since, and fin1c ran it.

## Mutations

Each mutant was applied in a hard-link copy of the worktree (`scratchpad/c4e-mut`) by atomic file replacement, so
the tested worktree was never written; each was reverted and compared byte for byte, and `diff -rq app routes`
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

- `scripts/ci/database-sqlite-skips.json`, final state 294 entries (main had 291). All changes lie beyond the
  hash-pinned first 150 (`d93da4e7…`, unchanged); indexes are 0-based:
  - entry 284: `test_native_index_holds_owner_fence_and_period_locks_until_commit_then_releases_them` (C4d) renamed in
    place, finally to `test_native_index_locks_only_the_owner_rows_until_commit_then_releases_them`;
  - entry 285: renamed in place to `test_native_index_waits_at_a_writer_held_owner_lock_and_retains_no_lock_after_refusal`;
  - entry 291 (appended in phase A, renamed in the follow-up):
    `test_native_index_and_a_zero_period_index_complete_while_other_sessions_hold_the_fence_and_shared_rows`;
  - entries 292 and 293 appended: `test_native_index_never_waits_when_another_session_takes_the_definition_then_the_plan_mid_index`
    and `test_native_same_plan_award_for_another_customer_runs_while_the_index_is_open`.

  Renames in place are not a pure append; they are required because the census must match the skipped methods
  exactly and the old names no longer exist (the reviewer accepted this).
- The MySQL skip census is unchanged: no new test is SQLite-only. The same-plan writer case reuses the existing
  `tests/Support/membership-award-race-worker.php`, already in the native selection's helper list.
- `phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json
  --mysql-native-selection` at `9d1e09f6`'s content: exit 0, 2490 selected of 9573 discovered cases in 237 selected
  files (main: 2487 of 9523). Generated `phpunit-ci-mysql-*` files deleted; both workflow count comments updated.

## Pre-G1 native probe of the revised lock design

Characterization only, not acceptance: a private Oracle MySQL 8.4.11 (`/opt/mysql-8.4.11/bin/mysqld`, own datadir under
the session scratchpad, port 3407, `performance_schema` on, `REPEATABLE-READ`, `--skip-log-bin`,
`--innodb-flush-log-at-trx-commit=2`), database `vaseyaudio_c4e_preg1`, `VA_LOCAL_DISPOSABLE_MYSQL`, one PHPUnit process
at a time, no deadline raised. It ran the committed `MembershipNativeCustomerIndexTest` methods from a hard-link scratch
copy of the worktree in which only `indexFixture()` differs: it awards without a reserve (as the reviewer's probe did),
because the reserve overruns its 60 s budget on this host before G1, and it accepts a `committed_receipt_refused`
award receipt (the award is durable; its post-commit proof can overrun). Application sources were byte-identical to
the tested source.

| Run | Method (or mutant) | Result |
| --- | --- | --- |
| n1 | `test_native_index_locks_only_the_owner_rows_until_commit_then_releases_them` | **OK** (1 test, 28 assertions, 335 s): inside the index, NOWAIT gives `owner_account` and `owner_user` 3572; `fence`, `plan`, `definition`, `profile` and the owner's `period` 0; after commit all 0 |
| n2 | `test_native_index_waits_at_a_writer_held_owner_lock_and_retains_no_lock_after_refusal` | **OK** (1/23, 283 s): 1205 at the held `users` row, nothing retained. The fixture award's receipt was `committed_receipt_refused` (post-commit proof overran pre-G1; the award is durable) |
| n3 | `test_native_index_and_a_zero_period_index_complete_while_other_sessions_hold_the_fence_and_shared_rows` | **OK** (1/22, 385 s): with another session holding the fence, plan, definition and profile `FOR UPDATE` and a 1 s wait bound, the member's index (plus pre-bytes) and a zero-period customer's index both complete; the session still held all four |
| n4 | `test_native_index_never_waits_when_another_session_takes_the_definition_then_the_plan_mid_index` | **OK** (1/16, 425 s): inside the index's period phase another session took the definition, then requested the plan asynchronously; its plan request was already granted at the index's commit point, and the index completed (the N2 order, which deadlocked before) |
| n5, n5b | `test_native_same_plan_award_for_another_customer_runs_while_the_index_is_open` (first two versions) | n5: the award for another customer succeeded with no wait on the index, but the test's own result assertion was wrong (`??` on a null). n5b: the test waited for the whole award inside the index, which then exceeded its own 60 s budget pre-G1 (`operation_deadline`). Both were test-structure faults, fixed |
| n5c | the same method, final version | **OK** (1/22, 383 s): a real award for a second customer on the same plan, in a separate process started with the index transaction, took the shared plan row (or finished) while the index was open, never appeared in `performance_schema.data_lock_waits` blocked by the index's connection, and completed; the index completed with its one period |
| NM1 | index plan read back to `$rows->one(...)` (locking) | **Killed**: n1 sees `plan` 3572 |
| NM2 | window `FOR UPDATE` in every phase | Killed, but incidentally (the read-only committed frame refuses `FOR UPDATE`, 1792); replaced by NM2b |
| NM2b | window `FOR UPDATE` only inside the index transaction | **Killed**: n1 sees `period` 3572 |
| NM3 | fence re-added after the owner lock | **Killed**: n1 sees `fence` 3572 |
| NM4 | history capture back to its locking default | **Killed**: n1 sees `period` 3572 |

Not covered by the probe: the activation-specific shared reads of the history reader (they occur only for periods
with activations, which need a reserve) and the reservation-dependent pre-bytes case; both are phase B with the real
fixture. The server was shut down and its datadir removed after the runs (see Cleanup).

## Phase B: native evidence plan (after G1 merges)

Run on a private `/opt/mysql-8.4.11/bin/mysqld` (never `/usr/sbin/mysqld`, which is 8.0), own datadir under the
session scratchpad, unused port, `performance_schema` on (the same-plan writer case reads `data_lock_waits`),
`VA_LOCAL_DISPOSABLE_MYSQL`, database `vaseyaudio_member_c4e`, `REPEATABLE-READ`, no deadline raised, on the exact
merged SHA (C4e rebased or merged onto main with G1), one PHPUnit process per chunk, with the real
`memberActivationFixture()` (award and reserve):

1. `MembershipNativeCustomerIndexTest`, all seven methods:
   - only the owner's T23 rows held inside the index; fence, plan, definition, profile, the owner's period and
     redemption free; nothing retained after commit;
   - a wait (1205) at a writer-held owner `users` row, nothing retained;
   - the member's and a zero-period customer's index complete while another session holds the fence, plan,
     definition and profile rows;
   - mid-index, another session takes the definition and then the plan: its plan request is granted while the index
     is still open, and the index completes (the N2 order);
   - a real same-plan award for another customer, in a separate process, runs from the start of the index transaction
     to its commit and never waits for a lock the index holds;
   - an index waiting behind a same-owner writer at the owner row lists that writer's committed period;
   - pre-bytes after a reserve.
2. Re-review R4: the same-owner wait race with a reserve (a redemption and reserve event committed while the index
   waits at the owner row) next to the existing pre-bytes-after-reserve case, alongside the committed window case
   `test_native_index_waiting_behind_a_same_owner_writer_reads_that_writers_commit`.
3. The C4d C2 cases: `MembershipNativeCommitWitnessTest` reservation case, `MembershipCommitWitnessTest` reserve,
   delegate and release on MySQL (reservation and release witness), and the `MembershipPeriodAddressedOperationsTest`
   positive path.
4. `MemberActivationCommitWitnessTest` and `MembershipHttpTest` on MySQL.
5. Index latency at 1, 10 and 100 periods (scratch harness, not committed): `memberActivationFixture()` plus synthetic
   older periods in the retained format (as `MembershipCustomerIndexTest::syntheticPeriods`); three index plus
   pre-bytes calls each, wall time and statements per call from a general log; then the 100-period index while
   another customer's real award runs, showing neither waits. If 100 periods exceeds the 60 s budget after G1, that is
   a finding (lower the bound or page), not a cap to raise.

## Phase B: native results on the merged source (October 24)

### Source

- `origin/main` `707649a6` (through PR #170, including G1 as PR #168) merged into the branch as `34fd070c` (merge
  commit; conflicts only in `bootstrap/app.php`, `CHANGELOG.md`, the resumption note and the two count comments).
- `bd3172c6`: ledger receipts only. `8cddd8b4`: test-only fix to two watchers in
  `MembershipNativeCustomerIndexTest` (below). Application code is identical at `34fd070c`, `bd3172c6` and `8cddd8b4`.
- Vendor from the merged lock (`laravel/framework` 13.38.0, `livewire/livewire` 4.4.8); `composer install` reports
  nothing to install.
- Census 296 entries (main's 291 unchanged and first, then this branch's), pinned first-150 hash `d93da4e7…`
  unchanged. Native selection regenerated with `phpunit-shards.py --mysql-native-selection` on the merged tree: 2504 of
  9647 cases in 239 files (main 2499 of 9595); both count comments updated. The branch's own comment had been 2 cases
  stale since the R1 and R4 tests were added. Safeguards `test-phpunit-shards.py` 52, `test-ci-scope.py` 27,
  `test-workflow-cadence.py` 14, `test-focused-tests.py` 53: all OK.

### SQLite on `34fd070c`

Three PHPUnit processes, CI environment (`APP_KEY` set, `CI=true`, `:memory:`), run alongside the native chain, so
the wall times are not representative:

| Run | Files | Result |
| --- | --- | --- |
| mA | `ProductionMembershipHttp`, `ProductionMemberGrants`, `ProductionMemberActivation`, `ProductionMembership` | 161 tests, 2,415 assertions, 0 failures, 0 errors, 10 skips (3,350 s) |
| mB | `ProductionMembershipBilling` | 416 tests, 3,627 assertions, 0 failures, 0 errors, 40 skips (4,776 s) |
| mC | `ProductionIdentity`, `ProductionMemberOriginals`, the three `LegacyRedirect*` files, `StagingEnvironmentAdmissionTest`, `CustomerIdentityHttpTest`, `PublicStructuredDataTest` | 342 tests, 2,183 assertions, 0 failures, 0 errors, 20 skips (3,639 s) |

Every skipped method is in the SQLite census and no census method ran (checked from the JUnit files). A first attempt
without `APP_KEY` was discarded.

### Native MySQL

Private `/opt/mysql-8.4.11/bin/mysqld` 8.4.11, own datadir in the session scratchpad, port 3407 (checked free with
`ss -ltnp`), `REPEATABLE-READ`, `performance_schema` on, binary log off, `innodb_flush_log_at_trx_commit=2`, database
`vaseyaudio_c4e_b`, `VA_LOCAL_DISPOSABLE_MYSQL`, no deadline or lock-wait timeout raised, one PHPUnit process per
method or file with the real `memberActivationFixture()` (award, reserve, authored member definition). The container
restarted once mid-run; the server was restarted on the same datadir.

| Case | Source | Result |
| --- | --- | --- |
| `test_native_index_locks_only_the_owner_rows_until_commit_then_releases_them` | `34fd070c` | OK, 29 assertions: inside the index the owner's `users` and `customer_accounts` rows probe as held (3572); fence, plan, definition, profile, the owner's period and redemption free; nothing retained |
| `test_native_index_waits_at_a_writer_held_owner_lock_and_retains_no_lock_after_refusal` | `34fd070c` | OK, 23 assertions: 1205 at the held `users` row, nothing retained |
| `test_native_index_and_a_zero_period_index_complete_while_other_sessions_hold_the_fence_and_shared_rows` | `34fd070c` | OK, 21 assertions |
| `test_native_index_never_waits_when_another_session_takes_the_definition_then_the_plan_mid_index` (reverse order) | `34fd070c` | OK, 15 assertions: the other session's plan request was granted while the index was open |
| `test_native_same_plan_award_for_another_customer_runs_while_the_index_is_open` | `34fd070c` | OK, 21 assertions: a real award for a second customer took the plan row while the index was open, never waited on it, and committed |
| `test_native_index_waiting_behind_a_same_owner_writer_reads_that_writers_commit` (R1) | `8cddd8b4` | OK, 15 assertions: the index waited at the owner row, the writer committed a new period, the index listed it and pre-bytes passed. Earlier runs failed; see below |
| `test_native_index_waiting_behind_a_same_owner_release_reads_the_released_history` (R4) | `34fd070c` and `8cddd8b4` | OK both times, 16 assertions: a genuine release (separate process) committed while the index waited at the owner row; the index listed the reservation as released |
| `test_native_index_and_reserve_interleave_sequentially_and_pre_bytes_sees_the_change` | `34fd070c` | OK, 16 assertions: a reserve after the index makes pre-bytes refuse `changed_before_bytes`; the next index shows both reservations |
| `MembershipNativeCommitWitnessTest` (C4d C2) | `34fd070c` | OK, 3 tests, 55 assertions |
| `MembershipCommitWitnessTest` (reserve, delegate, release witness) | `34fd070c` | OK, 5 tests, 94 assertions |
| `MembershipPeriodAddressedOperationsTest` (period-addressed positive path) | `34fd070c` | OK, 4 tests, 69 assertions |
| `MemberActivationCommitWitnessTest` | `8cddd8b4` | OK, 3 tests, 50 assertions |
| `MembershipHttpTest`, one process per method | `8cddd8b4` | OK, 5 tests, 416 assertions (journey 129, one not-found 148, outcome-unknown then converge 34, committed write with refused receipt is submitted 46, index body withheld when pre-bytes refuses 59) |

R1 failed twice before the fix, for a test reason. First, under a host load of about 8 on 4 cores (SQLite running
alongside), the index passed the owner lock but its 60 s budget ran out in the period loop (`operation_deadline`).
Then, on an idle host, the index waited 50 s at the owner row and failed with 1205, because the writer never saw it
waiting. An instrumented copy showed why: the writer's watcher polled `information_schema.INNODB_TRX` every 20 ms,
and InnoDB refreshes that cache only after 0.1 s without a read, so the watcher kept seeing only its own transaction.
Under load the polls had been spaced out enough to work. `8cddd8b4` polls every 250 ms in both INNODB_TRX watchers
(R1 and R4); the `performance_schema` probes were not affected.

### Index latency (scratch harness, not committed)

`memberActivationFixture()` plus synthetic older sealed periods in the retained format (one month apart), one call
being `MembershipCustomerRead::begin()`, `index()` and `proveBeforeBytes()`, which share the index's 60 s budget. Idle
host, `8cddd8b4` application code:

| Periods | Calls | Index s | Pre-bytes s | Total s | Statements per call (general log) |
| --- | --- | --- | --- | --- | --- |
| 1 | 3 + 1 logged | 10.1 to 11.4 | 10.1 to 10.9 | 20.5 to 21.8 | 9,416 (1,982 `information_schema`, 62 `FOR UPDATE`) |
| 2 | 3 + 1 logged | 12.9 to 16.0 | 12.5 to 17.9 | 25.5 to 33.5 | 13,448 |
| 3 | 3 + 1 logged | 18.3 to 26.3 | 20.7 to 28.3 | 39.0 to 52.8 | 17,480 |
| 5 | 2 | 29.6, 30.6 | 28.8, 29.4 | 58.5 (completed), 60.0 (refused) | not logged |
| 10 | 2 | first call refused inside the index at 60.1 s; second index 55.4 | second: refused after 5.7 | 60.1, 61.1 (both refused) | 31,655 before the refusal in an earlier logged run |
| 100 | 1 | refused at 60.2 s | none | 60.2 (refused) | not logged |

Every `FOR UPDATE` in a logged call is on the owner's own T23 rows (`users`, `customer_accounts`,
`production_identity_origins`, `production_identity_verifications`, `production_identity_challenges`). Statements grow
by 4,032 per additional period per call (about 2,016 per pass), mostly identity schema inspection repeated per period:
per extra period 48 each of `SHOW CREATE TABLE` on `users`, `customer_accounts` and `quote_owners`, 188 `SELECT
DATABASE()` and the `information_schema` constraint lookups. Wall time also rose across repeated calls at the same
size, which is not explained here. Refusals at the budget surfaced as `operation_deadline`, `owner_refused` or
`index_refused` depending on where the deadline struck; every refused call threw before its pre-bytes check passed.

100 periods while another customer's real award runs (2 repetitions): the index refused `operation_deadline` at 61.4 s
and 61.9 s; the award committed (period ids recorded) about 44 to 46 s after the index started, while the index was
still open, held the shared plan row during the index and never appeared in `performance_schema.data_lock_waits`
blocked by the index's connection. The award took longer than G1's idle 19 s because both shared the host.

### Second integration with main (`8f166c5a`, PRs #171 and #172)

After phase B, `origin/main` `8f166c5a` was merged as `3f3d8672`: census 302 entries (main's 297 first, this branch's
two in-place renames at 284 and 285, then its five appended methods; first-150 hash `d93da4e7…` unchanged), native
selection regenerated at 2562 of 9753 cases in 248 files (main 2557 of 9701), safeguards 52/27/14/53 and
`test-database-receipts.py` 61 OK. Main's changes there (MIG and A5 migrations and code, capability boundary) do not
touch the member index or HTTP code. Focused checks on `3f3d8672`: SQLite `MembershipHttpMountTest`,
`StagingEnvironmentAdmissionTest`, `StagingRuntimeValidatorTest`, `TestCommerceProfileValidatorTest`,
`MembershipCustomerIndexAvailabilityTest` and `MemberActivationCommitWitnessTest`: 202 tests, 2,558 assertions, OK;
native `test_native_index_locks_only_the_owner_rows_until_commit_then_releases_them`: OK, 29 assertions. The rest of
phase B was not repeated on `3f3d8672`.

### Finding F9 (blocks any mount): the index does not fit its budget beyond a few periods

After G1, one index call (index plus pre-bytes) takes about 20 s at one period and reaches the 60 s budget at about
five periods; at 10 and 100 periods it always refuses. `MembershipCustomerIndex::LIMIT` is 100
(`MembershipPolicy::MAX_PERIODS`), so a customer with more than four or five paid periods would get a permanent
refusal. This is the case the plan named: a finding, not a cap to raise. The cost is per-period identity schema
inspection on the read path (the writers' frames got G1's once-per-frame proof; the index's per-period historical and
access proofs did not) plus the full pre-bytes re-run. Options, for review: carry G1's once-per-frame schema proof
into the index's per-period proofs; lower the page size to what fits with margin and page older periods; make the
pre-bytes check a cheaper currentness comparison rather than a second full pass. The lane does not choose here; the
member HTTP index must not be mounted anywhere until this is resolved.

Fix in progress (lane F9, branch `harness/member-index-f9-20261024`, unreviewed, not fully verified): see
`docs/verification/member-index-f9-20261024/README.md`, including its status at handoff.

## Untested conditions

- Finding F9 (phase B): the index plus pre-bytes exceeds its 60 s budget at about five periods and always at 10 and
  100; unresolved.
- Phase B native evidence is from one private server on one host, one PHPUnit process per case; the R1 and R4 races
  each ran once successfully after the watcher fix (R4 also once before it).
- The scratch latency harness is not committed and measured one fixture shape (one plan, one reservation).
- A same-owner writer that locks the owner's identity rows without taking `users` first would not be ordered by
  `lockOwnerFirst`; every `ProductionCustomerAccess::lock` caller reads `users` first.
- The real same-plan writer case and the reverse-order case run in one process with one or two independent sessions;
  there is no long-running multi-process race.
- Identity-domain operations (sign-in, recovery) on the same owner lock the owner's identity rows in their own order;
  a cycle with the index there is possible in principle, as with every membership writer, and is not exercised.
- Gap locks taken by the owner lock's own reads can briefly delay another customer's identity insert into an adjacent
  gap; not measured.
- `PDO::commit()` that throws while the PDO still reports a transaction (for example a lost connection with a stale
  status) is reported by activation as `source_refused` and by the writers as `operation_refused`. HTTP presents the
  writers' case as outcome-unknown; activation is not mounted (review N4: the activation fallback stays unpinned).
- A writer failure before commit that is not a `MembershipException` (for example a billing decrypt error) is
  outcome-unknown (503), and for a known foreign period id the billing evidence is read before the owner comparison
  (pre-existing, review N5).
- No HTTP evidence under PHP-FPM, a real web server, a proxy or a browser. No slow-client bound beyond the session
  block and the throttle.
- Rate limits are per client address (D1 parity), not per customer (review N7); a per-customer limiter is required
  before any non-local mount.
- No Foundation run, no hosted CI, no host, provider or production evidence.

## Cleanup

The pre-G1 server (port 3407) was shut down with `mysqladmin shutdown` after phase A; it was later started again for
the R1 work and for phase B (restarted once on the same datadir after a container restart) and shut down with
`mysqladmin shutdown` after the last phase B run. Its datadir, the probe copies and the mutation copies were removed.
Logs and JUnit files stay in `scratchpad/c4e-ev/runs/` and `scratchpad/c4e-ev/runs-b/`; the scratch latency harness
and its JSONL results in `scratchpad/c4e-ev/latency/`; mutation drivers and results in `scratchpad/c4e-mut-driver/`.
