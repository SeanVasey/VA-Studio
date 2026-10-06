# Private membership plan and synthetic credit foundation

This WP-11/T30 child adds executable private plan review and credit movements.
It does not activate memberships, invoice processing, subscriptions, payments,
licensed downloads, grandfathered balances or production access. T30 and T31
remain open. The branch starts at policy-integrated main mirror
72fc6d7fca9e8ed22e969bd9269ba775a02a48e6, tree
7a5a17c8bcd363bb79b2267bfb7bbe06ee1b300a. No existing writer, account API,
workflow, provider configuration, shared registration or work-package status is
changed by this component.

All commands require local/testing and an explicit boolean
memberships.test_mode_enabled=true; the flag cannot enable production.
Customer movements also require existing test-account enablement and fresh
CustomerAccess evidence. There is no HTTP endpoint or automatic award worker.
Source and redemption identities must explicitly begin synthetic:.
An unverified client payment, checkout redirect or arbitrary invoice identity
cannot invoke a production award through this module.

## Plan contract

MembershipPlans::createDraft(data, operator) creates an immutable identity and
first immutable version. reviewRevision(plan, replacement, operator) is the
explicit capture point; applyReviewedRevision(review, operator) uses that
captured baseline and never captures another one on apply.

The exact data fields are title and policy. Policy requires every field:

| Field | Supported explicit value |
| --- | --- |
| schema_version | Integer 1 |
| unit | Lowercase ASCII token, 1–32 characters |
| allowance | Integer 1–1,000,000 |
| validity_seconds | Null, or integer 1–31,536,000 |
| rollover | Explicit none for this isolated synthetic bucket |
| reversal_allowed | Boolean |

There are no default benefits, prices, currency, billing period or live
availability. The none validator defines only this bounded bucket behavior;
approved rollover, carry, renewal, late invoices, cancellation, grandfathering
and legacy continuity require subsequent contracts and are not retired.
Titles retain exact bounded UTF-8 text. Changed versions append; original policy
remains pinned. Canonically identical input writes no version or audit.

The internal review has ten ordered keys: schema_version, intent, actor_id,
plan_id, plan_hash, version_id, version_hash, history_hash, audit_id, replacement.
Intent is revise_membership_test_plan. Whole raw identity/version/history hashes
and the latest participating audit cursor detect stale review and ABA.
Result fields are plan_id, version_id, number, title, policy, manifest_hash.
This internal capture must be protected by any future UI; it is not client
authority or a public plan projection.

## Ledger contract

CreditLedger::grantSynthetic(planVersion, customerAccount, sourceEventId,
operator) awards the frozen version's explicit allowance into one immutable
source bucket. A globally unique source hash and exact account/version request
hash prevent a duplicate source from minting again or moving to another owner.
The UTC deadline derives from the exact retained creation instant; it does not
read the clock again to compute expiry.
No actual invoice or verified payment adapter is implemented.

reserve(bucketId, integerAmount, syntheticResourceKey, key, principal, buyer),
consume(reservationEventId, key, principal, buyer) and
release(reservationEventId, key, principal, buyer) use existing internal
CustomerPrincipal ownership, credential and access-version checks.
reverse(consumptionEventId, key, operator) reverses one whole consumption only
when its original version permits it. expire(bucketId, key, operator) moves
available credits to expired at the frozen deadline. Reserved amounts are
retained until an explicit terminal movement.

Every reservation binds one exact hashed synthetic resource and amount.
It can be consumed or released once; a consumption can be reversed once.
Expired credits cannot be newly reserved or consumed. Release/reverse after the
deadline return credits to expired. Late audit/authority work crossing the
deadline rolls the new movement back. Exact replays return the original event
result with no extra event, author or audit; they never repeat a spend.

Balances are derived from the hash-linked, append-only event history; there is
no mutable balance cache. Available, reserved, consumed and expired are
nonnegative and partition the original allowance. Every historical event's own
attribution, action and minimized audit context is checked. An exact key with
changed amount, resource, owner access version or operation is refused.
An exhausted expiry is a write-free no-op.

Movement results contain bucket_id, event_id, kind, amount,
reservation_event_id and balance as of that retained event. read(bucketId,
principal, buyer) returns the current last_event_id, balance, unit, expires_at
and derived spendable_credits. An expired bucket may retain an unprocessed
available ledger amount while spendable_credits is zero.

## Authority, evidence and schema

Commands own a standalone transaction. All relevant User identities lock in
ascending order, then CustomerAccount for credit operations, then private plan,
immutable version and bucket anchor. Staff require current administer-catalog,
verified email and the existing panel MFA rule; customer commands use fresh
CustomerAccess before new work and before replay/return. Source models supply
identity hints only. Retained baselines use current locking reads.

After all Eloquent audit and authority callbacks, raw locking reads prove every
participating user/account/plan/version/bucket/event row, the entire event/audit
range and the actual own audit identity/context. No Eloquent callback follows
that proof. Clock eligibility is checked around it. Raw credentials and owner
keys are internal and never enter result or minimized audit projections.
The explicit retained-history ceiling is 10,000 versions/events per respective
anchor; exceeding it refuses without dropping or truncating evidence.

The additive 2026_10_06_230000 migration owns four new tables. SQL guards on
SQLite and MySQL retain identities, versions, buckets and movements; refuse
REPLACE/IGNORE collisions; and enforce event predecessor/sequence, integer
balance floor/partition, exact reservation resources/amounts, terminal/reversal
eligibility and current account ownership. Application canonical hash proof
remains necessary; SQL guards are not a replacement for current command
authorization or invoice verification.

Migration preflight refuses existing permanent/case/shadow objects, foreign
reserved guards and interrupted DDL prefixes before creating another object.
Operational down is code-only and removes no evidence, table or guard.
Any destructive retention migration requires separate review. Disposable test
fixtures use their existing isolated db:wipe lifecycle; parent schema fixture
teardown of empty additive children is owned by the integrator.

## Recorded component checks

Evidence is retained outside Git under membership-credit-evidence/, with exact
command arguments, disposable 32-byte synthetic APP_KEY description, source
hashes and raw JUnit/logs. No production key is used.

- Initial plan/ledger SQLite selection: 77 cases, 76 passed, 357 assertions;
  the one failure counted seven events in an actual six-event sequence. That
  test expectation was corrected; this was not an application failure.
- Expanded SQLite domain/SQL migration selection: 116/116, 448 assertions,
  zero errors/failures/skips, 16.892 seconds.
- Separate genuine MySQL 8.4.11 smoke selection: 4/4, 37 assertions, zero
  errors/failures/skips, 19.659 seconds. Nullable expiry and late release/reverse
  policy were executed, not inferred from SQLite.
- Initial independent-process MySQL race selection: 12/12, 964 assertions,
  zero errors/failures/skips, 74.110 seconds; complete original source copies
  and before/after hashes are retained. Seven competing write/replay/review/
  lifecycle cases use two independent primary workers plus observer, and five
  committed authority-withdrawal cases use an independent primary worker.
  Every case observes the exact relevant InnoDB PRIMARY/RECORD/WAITING row,
  requester and blocker. The original 15-second observation, 20-second barrier
  and 40-second process budgets are unchanged.
- Four new expiry-boundary regressions then proved an application gap:
  audit callbacks crossed the deadline and predeadline credit eligibility
  committed. All four original failures and their executable source are
  retained. The final deadline guard made those four plus Unicode/nested
  regressions pass: 6/6, 16 assertions, 1.345 seconds.
- Checkpoint scoped Pint and syntax checks passed on all 15 owned PHP files.
  Actual discovery lists 134 new component cases, including the same 12 native
  race identities in two methods; listing is not execution.
- Checkpoint focused SQLite source-bound compatibility selection: 136/136,
  551 assertions, zero errors/failures/skips, 38.576 seconds. This consists of
  the new 122-case domain/migration selection (465 assertions) and unchanged
  CustomerAccountAccessTest/CustomerAccountCommerceTest (14 cases/86 assertions).
  The same frozen checkpoint's MySQL selection completed: 134/134, 1,306
  assertions, zero errors/failures/skips, 683.726 seconds. All 12 native races
  observed their exact InnoDB waits. Both selections bind checkpoint
  5a2b054b38166dc0e367c935c6820c4844d76999 and its unchanged 15 PHP blobs.
- Independent review then reproduced a valid grant refused at a second boundary:
  a separate clock read computed expiry one second later than the retained
  creation instant. The SQL guard prevented the inconsistent bucket from
  committing. The reviewer's original one-error canary and the author's
  one-error/zero-assertion regression are retained separately with raw logs,
  JUnit and source. This is a grant availability correction, not evidence of
  an invalid durable credit award.
- The corrective child derives expiry from the captured UTC instant, adds
  MembershipGrantClockBoundaryTest and preserves the other 14 checkpoint PHP
  blobs. Corrected focused SQLite: 10/10, 71 assertions, zero
  errors/failures/skips, 2.032 seconds. Corrected genuine MySQL 8.4.11:
  11/11, 169 assertions, zero errors/failures/skips, 52.627 seconds. These
  selections execute the new clock regression, lifecycle/source/replay/expiry
  cases and four late audit-callback cases; MySQL also executes the unchanged
  duplicate-award independent-process race and observes its exact PRIMARY wait.
  Its normal durability remains flush-at-commit 1, sync-binlog 1 and doublewrite
  ON. Final scoped Pint and syntax pass all 16 PHP files. Corrected
  discovery lists 135 cases and preserves all 134 prior identities. Discovery
  is not a full execution of the corrected 135-case component.

The corrected commands, source-before/source-after hashes and raw outcomes are
clock-followup-sqlite-command.json / clock-followup-sqlite.xml / .log and
clock-followup-native-command.json / clock-followup-native.xml / .log in the
evidence directory. final-native-frozen-receipt.json retains the earlier full
checkpoint's exact source and 12 observed waits. tested-source-final.json binds
the final corrective commit, all owned blobs, retained negative evidence and
the limited affected selection. It does not relabel the checkpoint's full
MySQL result as execution of later code.

Earlier results apply only to their recorded bytes. They are not backdated to
later source. Final sensitive independent review must assess the actual frozen
tested commit. Full CI, native browser execution, production billing, member
UI, renewal/cancellation/dunning, legacy reconciliation and provider portability
remain outside this component. October 6 manual-only final-verification policy
is preserved.
