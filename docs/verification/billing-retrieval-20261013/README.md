# C3 retained retrieval identity and database freshness — development in progress

This isolated batch follows PR #113, merged at
`6c871b9c5b510c1926437bbdf6e96d661e2c2f30`; that actual merge is integrated
as `fb5647b3`. The C3 batch is not published or approved for activation. The scope is
[the reservation packet's next batch](../membership-reservations-20261013/next-batch.md).

CP-2 concerns a retrieval whose original numeric allocation disappeared and was
reissued. New start and end receipts retain independent allocation tokens and one
common retrieval token. Append, diagnostic attempt and hint coverage must prove
the exact retained endpoints. Values alone cannot issue an interval; the original
issuer and captured primary remain required. Provider reads remain between the
separately committed endpoints, outside application transactions.

CP-3 concerns worker-clock timestamps and freshness. New allocation timestamps
come from native database UTC. Database read-end establishes the existing
600-second evidence lifetime; actual provider payment time remains unchanged.
An original database sample and pre-query monotonic anchor carry the remaining
lifetime through the paid-invoice producer, award and reservation contexts,
including committed receipt verification. Later samples can only tighten the
cap. The original operation's 60-second admission cap and separate business
honor/expiry/catalog limits remain in force.

Forward migration263 adds nullable interval metadata while retaining every old
scalar, ciphertext and seal. Explicit record versions audit legacy history;
authoritative tails require fresh upgraded evidence. Generic seal semantics and
historical migrations are not rewritten. Runtime requires the exact complete
schema; only the upgrader admits recognized interrupted prefixes. Operational
upgrade requires drained workers and an explicit retained-data procedure. Native
DDL is not transactional. Destructive rollback is refused.

The application remains default-off and unmounted. SDK21.3.2 and API
2026-08-26.dahlia stay pinned. No new commercial facts, provider writes or actual
activation are part of this change. C4 still owns original rendering/readiness
and atomic consumption/activation; C5 owns commercial continuity.

## Verification status

Source integration and focused verification are underway. Existing receipts are
component-specific and are not a final integrated acceptance claim. Private
MySQL 8.4.11 passed the populated-upgrade canary (1 test / 63 assertions) and
the endpoint identity, old-snapshot and timezone selection (9/81) at schema
source `57c9bf9f`. The producer's database-time canary passed 1/14 at `5dc11691`;
its remaining native cases continue. The initial native ALTER error remains
retained: MySQL rendered known ASCII CHECK literals with `_ascii` after ALTER,
requiring a narrow normalization correction.

Independent source review has identified a P1 in required-trigger validation
through an older REPEATABLE READ dictionary snapshot, plus a related possible
positions-engine admission gap. Current named definitions and focused native
before/after regressions are required before approval. These are separate from
the deferred inventory of unexpected extra triggers. No final native,
independent-review or preflight approval is claimed.

Completed SQLite selections include award/proof/freshness integration (32/320),
redelivery/reservation/eligibility integration (47/429), and historical guards
plus current-version immutability (21/142). The genuine old-writer continuity
probe created an award at `45d12994` (1/25), then upgraded that exact retained
SQLite file at `5dc11691` and replayed through fresh reconciliation and the real
paid-invoice authority (1/103). The original public receipt, period and credit
event rows were unchanged. This is synthetic loopback SQLite evidence.

Two broader SQLite invocations stopped on obsolete fixture assumptions about
application-clock timestamps: 51/316 with one failure, then 54/353 with one
failure. Their logs remain retained. The corrected hint interleaving uses
retained start/hint/end positions; cursor fixtures are being updated to retain
deterministic pagination times. Successful whole-file leaves remain separately
bound to their tested sources; these failed invocations are not passing runs.

Deterministic technical-window tests and synthetic cap-consumption callbacks do
not establish physical elapsed freshness or host time accuracy. Allocation-loss
probes are isolated simulations, not actual failover/restore verification.
Native SQLite time has millisecond precision padded to six digits; MySQL uses
UTC_TIMESTAMP(6) parsed explicitly as UTC, without session timezone conversion.

Representative contention/latency, queue coalescing, current unexpected-trigger
inventory and host/database time-service facts remain activation conditions.
The catalog AUTO_INCREMENT false-conflict P2 and member-original driver gate
remain in the coherent C4/currentness queue. Sean's already-requested commercial,
legal, account and host inputs remain pending; no defaults were inferred.

Use focused local checks and the cheap exact-head Development preflight for this
development merge. Foundation is manual exact-SHA final verification only; no
GitLab workflow may be dispatched without Sean's cost go-ahead.
