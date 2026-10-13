# C2 membership reservation lifecycle — focused verification complete

This batch follows PR #112, merged at `bd230c52a605e05aa89158ab161309091afd17e2`.
It is not an activation, host, provider, commercial-policy or release claim.

The coherent change adds explicit v2 redemption terms, current catalog/license
proof, complete retained credit-ledger verification, owned reserve/retry,
honor-expiry release, and available-credit expiry. V1 policy bytes and the original
first-award record format remain retained; award replay audits later valid events.
Consume remains a later C4 operation coupled to actual original activation in the
same transaction. Runtime registration and routes remain absent.

The v2 documents explicitly name exact catalog revisions and license/asset/terms
hashes, credit costs, honor duration/cap, release destination and available-credit
expiry. Only authored synthetic fixtures currently supply those facts. Sean's
commercial and legal inputs remain outstanding; no defaults were inferred.

A request key is scoped to the original buyer origin and reservation purpose.
The selected paid period belongs in the authenticated intent. Thus the same key
cannot silently select another period. Retry preserves original retained IDs and
honor deadline; it returns current audited balances and never revives a terminal
reservation. Release is owner-triggered cleanup at/after retained honor expiry,
not voluntary cancellation. It returns credit to available before period expiry,
or expired at/after that boundary, exactly as explicitly authored by v2.

The complete audit checks seals, every retained column, payload/version/purpose,
original owner, policy/cost/selection intent, sequence/prior seals, balance
conservation, redemption joins, orphan rows and one terminal event per reserve.
New reserves preserve capacity under the 10,000-event bound for every live
reservation's later release and a finite period's available-credit expiry.

Owned entry consumes an exact one-use operation permit before source callbacks
or writes. A distinct internal mint permit cannot authorize additional writes.
Read-only reservation inspection uses current invoice/policy/catalog evidence and
never receives a mutation permit. All operations preserve normal Laravel callback
phases, one original admission budget and the committed read-only receipt frame.
A refused postcommit response does not imply rollback or authorize automatic retry.

## Verification status

Application source is frozen at
`defe95802e7d09e7df12c61a98c1ebf1caee99c0`; final test source is
`45d1299406821627f4ba02af186e324a70c6ade0`. The two later commits change only the
native temporary-table fixture and the bounded-admission race assertions. The
separate non-author reviewer found no remaining P0/P1 in the application or those
test corrections; final evidence review and its recorded DECISION remain pending. No merge or release approval is claimed here.

The completed integrated SQLite selection passed **13 tests / 228 assertions**,
with one declared MySQL-only skip and zero failures or errors. The source-bound
component census contains **180 distinct SQLite cases / 965 assertions**: 179
passes and that one skip, across documented mixed sources. This is not one
180-case run on the final integrated source. The five process-race cases were
not executed on SQLite and do not contribute skipped or passing cases.

The native lifecycle canary passed **1 test / 21 assertions**, with zero failures,
errors or skips, on MySQL **8.4.11** at the frozen source. It exercises real v2
reservation creation, exact retry and original award replay. Native ownership and
clock checks passed **3 tests / 57 assertions** within a four-case invocation that
then stopped on a temporary-table fixture error. The corrected shadow fixture and
committed catalog case passed **2 tests / 12 assertions**, zero errors, failures
or skips, at `9e2496c7`. No application repair was needed for the MySQL1066 setup
error; the test now proves the temporary table contains identical durable rows.

The initial process-race run stopped with **2 tests / 76 assertions / 1 failure**,
zero errors or skips: the different-key last-credit race passed 43 assertions;
the same-key second worker refused with `operation_deadline`. The first worker
succeeded in 54.94 seconds and the second finished in 66.75 seconds. Both native
lock barriers were observed. The boundary suite was using a separate schema on
the same private instance. A controlled unchanged-source rerun without another
fixture suite also failed: **1 test / 33 assertions / 1 failure**, zero errors or
skips. Its first worker succeeded in 45.284 seconds and second refused in 63.449
seconds. A small standalone temporary-table SQL probe overlapped that run.
Competing suite load therefore does not sufficiently explain the limitation.
Neither failed same-key invocation reached its final ledger audit; no post-error
ledger state is inferred from those receipts.

The written [reservation acceptance contract](../membership-award-20261013/next-writer.md)
permits a bounded loser refusal. The test correction requires first success and
either an identical second receipt or exactly `operation_deadline` after at least
60 real seconds with closed transactions. Before any fresh invocation it requires
full authenticated audit, cardinality, balances and original IDs/honor. A separate
explicit retry must then return the original receipt and leave the entire audit
and raw period/event/redemption rows identical. Other errors, including
`committed_receipt_refused`, cannot satisfy this test. There is no product retry
or budget extension. The corrected four-case race selection passed **4 tests /
203 assertions**, zero failures/errors/skips, at `45d12994`, in 1,130.612 seconds
wall time with all 1,266 recorded source hashes unchanged. The same-key second
worker refused at 60.823 seconds; the two duplicate-release second workers
returned the same receipts. Their fresh retries preserved both authenticated
audit and raw retained rows. The available-expiry/reserve race conserved the
allowance. Both earlier failures remain retained as failures.

Deduplicated successful native coverage is **11 distinct cases / 336 assertions**
on MySQL 8.4.11 across the source-bound runs above. This includes passing leaves
from the original stopped invocations; those invocations themselves remain
failed/errored. It is not one 11-case green invocation.

The private server uses datadir `/workspace/runtime/mysql-policy-data`, host
socket `/workspace/runtime/mysql-policy-run/mysql.sock`, and loopback port 33413.
The reservation and race schemas are separately owned disposable fixtures.
Real process IDs, connection IDs, row waits and monotonic durations are retained;
explicit synthetic Carbon wall times establish boundary cases and keep the
fixture honor valid for the separate retry. That retry proves retained identity
and balances under the synthetic wall time; it does not prove a physical honor
window survives the measured contention. These fixtures are not host measurements
or proof of actual commercial policy. The 60-second original
admission cap includes waiting and does not promise to interrupt blocking SQL.

The retained Context regression restores the earlier clock ordering through a
single-class overlay and fails 1 test / 15 assertions as intended. The corrected
case passes in the integrated selection. Eligibility regressions likewise retain
their original failures and fixed 16-test / 123-assertion whole-file result.
These are intermediate development-source reproductions, not claims that merged
PR #112 implemented the new reservation behavior. Original setup and assertion
errors remain recorded separately.

Final Pint passed 29 changed application/test PHP files at `45d12994`, with all
pre/post source hashes unchanged. Native discovery at `45d12994` selected 1,973 cases in
187 files from 8,047 total cases in 587 files, across the existing 24 shards; all
2,091 source hashes were unchanged.
Discovery does not execute database tests. The unchanged CI policy checks retain
their earlier 52 / 61 / 32 passing-test results with explicit source comparison.
Exact commands, per-run results, source histories and deduplication are in
[commands.md](commands.md), [source-map.md](source-map.md) and [evidence/](evidence/).

## Conditions and next dependency

The source review found one deferred P2: MySQL catalog definition comparison
includes the volatile `AUTO_INCREMENT` table-option counter, so an unrelated
insert can cause a false conflict. It does not admit incorrect authority. The
[deferred ledger](../../handoff/2026-10-07/codex-deferred-20261011.md) records it
once for a coherent catalog/currentness follow-up before activation.

Current unexpected-trigger inventory, representative latency/throughput, and
all carried Billing and member-original activation conditions remain open. The
failed deadline race is evidence relevant to that performance condition, not a
reason to renew or lengthen an issued proof's budget. No Foundation or GitLab
workflow was dispatched, and no full matrix, browser, provider or host acceptance
is claimed. Preflight and exact-head merge remain after final independent review.

The [next batch](next-batch.md) follows this C2 slice. C3 should reuse the existing
pinned Stripe 21.3.2 read adapter
and address retained retrieval allocation identity and database-clock freshness
(CP-2/CP-3). It must preserve old retained records while requiring fresh upgraded
evidence for new authority. C4 then couples actual member-original activation to
consumption; no standalone consume or real grant exists in this batch. Commercial,
legal, account and host inputs remain pending with Sean.
