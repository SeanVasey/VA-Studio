# C3 retained retrieval identity and database freshness — development in progress

This isolated batch follows the C2 reservation candidate. It is not published or
approved for activation. Integrate the reservation batch's actual reviewed merge
before publishing this branch. The scope is
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
component-specific and are not a final integrated acceptance claim. Initial
native schema verification stopped after the first forward ALTER; that failure
is retained and under diagnosis. No final native, independent-review or preflight
approval is claimed.

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
