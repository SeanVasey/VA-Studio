# C3 retained retrieval identity and database freshness

This development batch follows PR #113, merged at
`6c871b9c5b510c1926437bbdf6e96d661e2c2f30`. Its integrated native verification
source is `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`. Focused verification is
complete. The [separate independent review](DECISION.md) approves candidate
`526e6f5b12068ca3d9571dfcb47dc25194e24598` for development merge with no open P0/P1
in C3. Receipt-only review carry and exact-head Development preflight remain
merge gates. This is not activation or full Foundation acceptance.

## Behavior

CP-2 concerns a retrieval whose original numeric allocation disappeared and was
reissued. Start and end receipts now retain independent allocation tokens and one
common retrieval token. Append, diagnostic attempts and hint coverage prove the
exact retained endpoints. Values alone cannot issue an interval: the original
issuer and captured primary are required. Provider reads remain between separately
committed endpoints, outside application transactions.

CP-3 concerns worker-clock ordering and freshness. New allocation timestamps come
from native database UTC. Database read-end establishes the existing 600-second
evidence lifetime; actual provider payment time remains unchanged. The original
database sample and pre-query monotonic anchor carry the remaining lifetime
through the paid-invoice producer, award and reservation contexts, including
committed receipt verification. Later samples can only tighten or retire the cap.
The original operation's 60-second admission cap and separate business honor,
expiry and catalog limits remain in force.

Forward migration 263 adds nullable interval metadata without rewriting old
scalars, ciphertext or seals. Explicit record versions audit legacy history;
current authoritative tails require fresh upgraded evidence. Generic seal
semantics and historical migrations remain unchanged. Runtime accepts only the
complete graph; the upgrader additionally recognizes exact interrupted prefixes.

Required named guards and full current opened-table definitions are checked after
pinning. The table-definition comparison excludes only the volatile table-option
allocation counter. This closes the two required-source currentness findings
identified during independent review; it does not inventory unexpected extra
triggers.

The application remains default-off and unmounted. SDK 21.3.2 and API
2026-08-26.dahlia stay pinned. C4 owns member originals/readiness and atomic
consumption/activation; C5 owns commercial continuity.

## Evidence and exact source boundaries

[Commands](commands.md), the [source map](source-map.md),
[coverage reconciliation](coverage-review.md) and retained manifests distinguish
each invocation's source, driver, exits, skips and actual scope. Component passes
are not one final-head suite. Passing leaves inside failed invocations remain
separate supplemental observations.

Private MySQL **8.4.11** used its own datadir, loopback port 33413 and socket. The
integrated run used its separate synthetic database
`vaseyaudio_billing_retrieval_root`.

| Selection | Source | Tests / assertions | Result |
| --- | --- | ---: | --- |
| Integrated award/replay, reservation/retry and current 263 immutability | `0d2f06c7` | 3 / 63 | Zero errors, failures or skips |
| Integrated interval arbitration, hint/award, stale/overlapping reads and postcommit owner closure | `0d2f06c7` | 8 / 152 | Zero errors, failures or skips |
| Current required guards/table shapes | `71f545bd` | 2 / 34 | Zero errors, failures or skips |
| Retained upgrade and selected interrupted prefixes | `71f545bd` | 11 / 275 | Zero errors, failures or skips |
| Endpoint identity, earlier snapshots and native UTC/session timezone | `57c9bf9f` | 9 / 81 | Zero errors, failures or skips; predates currentness correction |
| Issued interval/identity and legacy transition | `5dc11691` | 12 / 164 | Two disjoint invocations, zero errors, failures or skips; predates currentness correction |

The two integrated invocations contain **11 distinct cases / 215 assertions**,
with unchanged captured source. The new interval race recorded two independent
workers waiting on the same actual primary-key lock, both with older empty
snapshots. One insert succeeded; the other was a `40001`/`1213` deadlock victim.
Both workers exited normally with idle transactions. The alternative `45000`
refusal branch was not exercised by that run.

The genuine old-writer continuity probe created an award using actual old source
`45d12994` (1/25), then upgraded that same retained SQLite file at `5dc11691` and
replayed through fresh reconciliation and the real paid-invoice authority
(1/103). The original public receipt, period, credit event and identity rows were
unchanged. Private database copies, keys and raw row captures are excluded from
this public packet. Separate authentic legacy-format fixtures are not substituted
for this original-writer probe.

SQLite observations include award/proof/freshness 32/320, reservation/eligibility
neighbors 47/429, historical guards plus explicit current 263 immutability 21/142,
corrected cursor 8/123, preparation/entry/closure 16/169 with one native-only skip,
and final schema 35/758 with five native-only skips. The source map lists the
remaining helper/producer cases and every retained skipped identity. Historical
through 260/262 cases, synthetic clock arithmetic and current 263 runtime coverage
are kept distinct.

Original failed attempts remain available:

- Pre-C3 allocation/worker-clock comparison: 4/53, four intended failures.
- Old required-source currentness comparison: 2/28, two intended failures; fixed
  native source passed 2/34.
- Terminal Context-check omission: 4/56, four intended failures; restored source
  passed 4/80. Other checks still refused the altered operation, so this comparison
  does not claim unauthorized durable effects.
- Two broader SQLite invocations: 51/316 with one timestamp assertion failure,
  then 54/353 with one cursor-order assertion failure. The corrected overlap and
  cursor fixtures use explicit retained ordering; the cursor is a controlled
  SQLite pagination fixture, not a native intake-clock test.
- Native setup/metadata diagnostics, the initial local launcher error and the
  unused-import Pint failure are retained separately from passing follow-ups.

Discovery at `67d85d6e` selected 2,068 MySQL cases in 199 files from 8,151 discovered
cases in 599 files, across 24 nonempty disjoint shards. It executed no test bodies
and configured an unreachable database endpoint. Thirty-six newer files use
fallback timing weights. Local policy self-tests passed 154 cases at `874d57fe`;
corrected Pint passed 56 changed PHP files at `51b6d59f`, with explicit unchanged
policy-input carry. Later workflow edits only qualify historical timing comments.
No Foundation or GitLab workflow was dispatched.

## Operational boundary and remaining conditions

The [forward 263 runbook](evidence/schema/runbook-facts-final.md) requires drained
billing/hint/membership writers, no surrounding application/PDO transaction and
the exact forward migration. Native DDL is not transactional. Recovery reruns the
same migration against only an exact recognized prefix; destructive `down()`
refuses. The public procedure is development evidence, not host verification.

All 19 interrupted prefixes have SQLite coverage. Native recovery individually
exercised prefixes 10, 12, 13, 15, 17, 19; prefixes 1–9, 11, 14, 16, 18 remain
SQLite-only. The regression peer's `lock_wait_timeout=5` bounds only that disposable
fixture; the migration introduces no host timeout policy.

Synthetic cap-consumption callbacks and arithmetic do not establish physical
elapsed freshness or host-clock accuracy. Allocation-loss probes simulate local
loss/reissue; they are not failover/restore verification. SQLite native time has
millisecond precision padded to six digits; MySQL uses `UTC_TIMESTAMP(6)` parsed
as UTC. Reported testcase durations include fixture setup/teardown, not operation
latency or throughput. A separate C4 SQLite staging test lasting 2.931 seconds
overlapped the last native invocation; it used its own in-memory database.

Representative contention/latency, queue coalescing, current unexpected-trigger
inventory and host/database time-service facts remain activation conditions.
The catalog AUTO_INCREMENT false-conflict P2 and member-original native-driver
gate remain in the coherent C4/currentness batch. Sean's previously requested
commercial, legal, account and host inputs remain pending. Synthetic fixtures do
not supply those inputs or authorize provider activity, customer entitlements,
deployment or cutover.
