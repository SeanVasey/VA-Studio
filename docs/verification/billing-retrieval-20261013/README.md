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

Focused verification is underway. Receipts bind each invocation to its actual
component or integrated source; their union is not one final-head suite run.
Private MySQL 8.4.11 passed the populated-upgrade canary (1 test / 63 assertions)
and the endpoint identity, old-snapshot and timezone selection (9/81) at schema
source `57c9bf9f`. Producer source `5dc11691` passed 12 distinct native cases /
164 assertions across two invocations, with no failures, errors or skips. The
initial native ALTER error remains retained: MySQL rendered known ASCII CHECK
literals with `_ascii` after ALTER, requiring a narrow normalization correction.

Independent source review found two P1 currentness gaps: an old REPEATABLE READ
dictionary snapshot could admit a missing/replaced required guard or a changed
endpoint table. At old application source `57c9bf9f` plus the archived new test,
both native regressions failed as intended (2/28, two failures). Current named
guard definitions and complete opened table structure are now checked, excluding
only the volatile table-option allocation counter. Fixed source `71f545bd`
passed 2/34 native and 35/758 SQLite, the latter with five declared native-only
skips. A separate reviewer checked the integrated source `67d85d6e`, closed both
findings in source and found no other P0/P1. Final approval still requires the
integrated native checks, migration-prefix selection and complete packet review.
The deferred inventory of unexpected extra triggers remains separate and open.

Completed SQLite selections include award/proof/freshness integration (32/320),
redelivery/reservation/eligibility integration (47/429), and historical guards
plus current-version immutability (21/142). The genuine old-writer continuity
probe created an award at `45d12994` (1/25), then upgraded that exact retained
SQLite file at `5dc11691` and replayed through fresh reconciliation and the real
paid-invoice authority (1/103). The original public receipt, period and credit
event rows were unchanged. This is synthetic loopback SQLite evidence.

Two broader SQLite invocations stopped on obsolete fixture assumptions about
application-clock timestamps: 51/316 with one failure, then 54/353 with one
failure. Their logs remain retained. Corrected hint interleaving uses retained
start/hint/end positions. The corrected whole cursor file passed 8/123 at
`a4c68a50`; its explicitly controlled pagination fixtures do not claim an intake
clock or lost-dispatch test. Successful whole-file leaves remain separately bound
to their tested sources; the two failed invocations are not passing runs.
Integrated preparation/entry/clock-closure files passed 16/169 at `e050e720`,
including one declared native-only skip.

Enumeration at `67d85d6e` selected 2,068 MySQL tests in 199 files from 8,151
discovered tests in 599 files, across 24 nonempty shards. No test bodies executed;
the configured discovery endpoint was unreachable. Thirty-six newer files use
fallback timing weights. The first discovery attempt failed before test bodies
because this isolated worktree's local launcher followed another worktree's
autoload path. A private local launcher copy corrected that environment issue;
the original failure and unchanged launcher-byte receipt are retained. Focused
test runners already used this worktree's explicit autoload path.

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
