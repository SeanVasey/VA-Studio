# Reservation source map

The initial integrated reference is
`defe95802e7d09e7df12c61a98c1ebf1caee99c0`. Its application/test/configuration,
migration, dependency, route, CI and referenced contract-resource inventory
contains **2,137 files** in
[reference-defe9580.json](evidence/reference-defe9580.json). Archival worktree
merge `faa47b3293180e946f9266eeedbf2241878c9f0a` has identical bytes for every
listed file; that local merge is not substituted for a tested commit. The 29
changed application/test source files are additionally retained in
[reference-source-defe9580/](evidence/reference-source-defe9580/).

The current integrated reference is
`45d1299406821627f4ba02af186e324a70c6ade0`, recorded in
[reference-45d12994.json](evidence/reference-45d12994.json). Its complete Git diff
from `defe9580` is exactly two test files: the native-only eligibility shadow setup
(`222b6e3d` → `9e2496c7`) and the reservation race contract assertions
(`7f51996c` → `45d12994`). Application and migration bytes remain `defe9580`.
The two exact test-source corrections are retained separately in evidence capsules.

This is a source reference, not proof that every condition was tested. Integrated
SQLite/canary and both original race failures are bound to `defe9580`; corrected
native eligibility passes at `9e2496c7`. The four revised/remaining race methods passed at
`45d12994`, with all recorded source hashes unchanged. Earlier component results apply to their documented mixed source. See
[runs.json](evidence/runs.json) for per-run source and command paths and
[junit-summary.json](evidence/junit-summary.json) for every full case identity,
observed status, assertion count and JUnit time. Counts below are per run and
must not be summed as unique coverage.

| Completed run | Tests / assertions | Failures / errors / skips | Exit | Source meaning |
| --- | ---: | ---: | ---: | --- |
| `entry-sqlite` | 6 / 0 | 0 / 6 / 0 | 2 | Missing TMPDIR setup error; same source as retry |
| `entry-sqlite-v2` | 6 / 135 | 0 / 0 / 0 | 0 | Three owned files committed `3b1f087a`, integrated `5bdeb350`; admission refusals only |
| `license-binding-unit` | 7 / 7 | 0 / 0 / 0 | 0 | Two exact hashes, committed `a1a492c2` |
| `eligibility-canary` | 1 / 12 | 0 / 0 / 0 | 0 | Earlier exploratory adapter; no exact source inventory retained; superseded |
| `eligibility-clock-bytes-red` | 2 / 5 | 2 / 0 / 0 | 1 | Deliberately omitted final native-byte loop in intermediate new adapter |
| `eligibility-sqlite` | 14 / 108 | 0 / 0 / 0 | 0 | Six hashes/capsule committed `0c4bc2c4`, before review corrections |
| `eligibility-review-red` | 2 / 4 | 1 / 1 / 0 | 2 | Unchanged `0c4bc2c4` application plus two new cases; double-wrapper-restore cleanup error retained |
| `eligibility-review-red-v2` | 2 / 4 | 2 / 0 / 0 | 1 | Same application, cleanup corrected; two intended regression failures |
| `eligibility-review-green` | 16 / 123 | 0 / 0 / 0 | 0 | Fixed application, before native-only identical-row shadow fixture strengthening |
| `eligibility-review-final` | 16 / 123 | 0 / 0 / 0 | 0 | Six hashes/capsule committed `a54ffb6a`, integrated `defe9580`; SQLite does not execute the MySQL branch |
| Policy `unit-initial` | 52 / 132 | 7 / 0 / 0 | 1 | New tests incorrectly compared the minimized exception message instead of the private reason |
| Policy `sqlite-policy` | 78 / 245 | 1 / 0 / 0 | 1 | Roundtrip assertion incorrectly required associative insertion order after canonical JSON |
| Policy `sqlite-policy-final` | 78 / 252 | 0 / 0 / 0 | 0 | Five final owned hashes committed `87e0dc42`; existing policy/preparation tests also selected |
| `ledger-initial` | 45 / 79 | 0 / 0 / 0 | 0 | Pure records; no separate exact source receipt retained for this first invocation |
| `ledger-focused` | 54 / 164 | 0 / 0 / 0 | 0 | Nine hashes, four owned blobs committed `9c38a4cb`; 45 pure audit cases plus nine existing first-award cases |
| Lifecycle `sqlite-initial` | 6 / 122 | 0 / 0 / 0 | 0 | `69eb2739` plus two fixture/test files committed `0823230d`; earlier eligibility/Context source |
| `award-replay-integration` | 15 / 141 | 0 / 0 / 0 | 0 | `94c190b6` with worktree delta; eight hashes verified equal to later `09fd6df9` |
| `context-clock-order-red` | 1 / 15 | 1 / 0 / 0 | 1 | Integrated `565f19d1` plus explicit one-class Context overlay; not a merged-base regression |
| `sqlite-integrated-lifecycle` | 13 / 228 | 0 / 0 / 1 | 0 | Exact integrated `defe9580`, unchanged after execution; includes corrected Context clock case |
| `native-integrated-canary` (MySQL) | 1 / 21 | 0 / 0 / 0 | 0 | Exact integrated `defe9580`, unchanged after execution; private MySQL 8.4.11 selected reserve/retry/award replay |
| `native-reservation-races` (MySQL, failed) | 2 / 76 | 1 / 0 / 0 | 1 | Same unchanged source; first case passed, second caller in same-key case refused operation_deadline; remaining three methods unexecuted |
| `native-focused-boundaries` (MySQL, errored) | 4 / 58 | 0 / 1 / 0 | 2 | Same unchanged source; first three cases passed 57 assertions; fourth fixture setup hit MySQL1066 before its intended application assertion; fifth selected case unexecuted |
| `native-reservation-races-isolated` (MySQL, failed) | 1 / 33 | 1 / 0 / 0 | 1 | Unchanged defe9580; same-key retry again refused after elapsed admission; remaining three cases unexecuted |
| `native-eligibility-final` (MySQL) | 2 / 12 | 0 / 0 / 0 | 0 | Test-only shadow setup fix at 9e2496c7; application unchanged, recorded source unchanged |
| `native-reservation-races-contract` (MySQL) | 4 / 203 | 0 / 0 / 0 | 0 | Exact 45d12994; revised contract assertions, unchanged original budget; observed same-key loser refusal followed by original-identity parent retry |

The initial narrower archive inventory omitted 25 contract-resource members
present in the integrated runner receipt; that metadata comparison failed on
missing entries, not differing bytes. Read-only comparison against the commit
and workspace matched all 25, and the reference inventory was expanded.
[The correction record](evidence/reference-inventory-correction.json) preserves
this archival error; no frozen resource was edited or re-pinned.

[Source-binding verification](evidence/source-binding-verification.json) checks
component receipts against their later commits and retained source capsules. The
Context red separately hashes the complete integrated source and the explicitly
loaded `Context-clock-order-red.php`; the overlay restores the earlier development
clock ordering without modifying the worktree. It cannot support a claim that the
merged first-award base contained reservation behavior.

Initial policy source hashes are retained even where original differing test
bodies have no separate capsule. The exploratory eligibility canary and initial
ledger run have limited exact-source evidence, explicitly recorded above. No
unknown command, byte history or passing result is reconstructed as observed.

The [deduplicated SQLite case census](evidence/sqlite-unique-case-summary.json)
contains **180 distinct cases, 965 assertions, 179 passes and one declared skip**.
It selects the latest successful relevant result for each full class/method/data
identity across the documented component sources. Contributions are policy
78/252, binding 7/7, ledger unit 45/79, entry 6/135, final eligibility 16/123, root
award/ownership 15/141 and integrated lifecycle/ownership/clock 13/228. Older
award and lifecycle repetitions add no coverage. This is not a single integrated
180-case run. The native race file was not executed on SQLite, so its five
methods contribute no SQLite skips or passes. The later native-only eligibility
branch correction and race assertion changes do not imply a new SQLite run.

The [native case reconciliation](evidence/native-case-reconciliation.json)
contains **11 distinct latest passing identities / 336 assertions**, zero latest
failures/errors/skips. Contributions are the canary 1/21, earlier different-key
race 1/43, three earlier boundary cases 3/57, corrected eligibility 2/12 and final
races 4/203. These are exact leaf results across source-bound invocations, not a
single green 11-case run. The earlier race and boundary invocations retain their
nonzero exits; individual passing leaves do not relabel a whole invocation.
All three nonzero native invocations and superseded results remain in the history.

The final same-key second worker refused after **60.823447676 real seconds**;
its independent parent retry returned the original IDs, honor and receipt. Both
duplicate-release workers returned matching receipts. All three idempotent
scenarios record identical full audit and retained-row hashes before and after
the separate parent retry. The equality case refused reserve with `credit_expired`
and passed its full audit. Seven complete process transcripts are explicitly
mapped to invocations in the [worker reconciliation](evidence/native-worker-reconciliation.json).
Optional missing fresh-retry summary fields in older/non-retry cases do not
replace those testcases' own audit assertions or imply a passing result.

The failed native race invocation is retained with both full worker transcripts
and [read-only timing diagnosis](evidence/integration-and-adapters/native-race-initial-diagnosis.md).
Worker startup finished before admission. The same-key second operation lasted
66.748257435 seconds; exact commit/lock-release timing and wait/work split remain
unknown. The failed case did not reach its final parent ledger audit. It is not
counted as concurrency acceptance, and no later result will erase the failure.
The isolated repeat also failed: its second call lasted 63.448939786 seconds.
A small standalone shadow SQL probe overlapped on a different disposable schema,
with no measured elapsed duration; no competing full suite was present. The
[isolated diagnosis](evidence/integration-and-adapters/native-race-isolated-diagnosis.json)
retains the unknown commit/wait split and missing final ledger audit. Competition
from a full suite therefore cannot sufficiently explain the dual-success failure.

The focused-native invocation retains its MySQL1066 temporary-shadow fixture
setup error and all three completed passing cases. The first three assertions
cover terminal clock owner proof, committed receipt refusal without rollback, and
reservation proof issuer/frame ownership. The later corrected two-case native
eligibility selection passes 2/12 at `9e2496c7`, after the separately recorded
standalone SQL probe. No native-only fixture error is represented as product
refusal or successful shadow detection.

Final Pint checked all 29 changed PHP files at `45d12994`; actual argv and all
selected source hashes before/after are retained, exit 0. The older `defe9580`
Pint pass and explicitly post-run hash reconstruction remain historical. The
race test correction's own syntax/Pint receipts establish no native behavior.

The latest archived discovery is bound to `45d12994`: 1,973 native cases from
8,047 total, 24 nonempty shards, all 2,091 hashes unchanged. Its recorded source
delta is exactly the two corrected test files. Discovery establishes selection coverage,
not runtime acceptance. Syntax/Pint preparation at `2e26a6ba` and the static
correction at `7f51996c` remain historical; the later completed native receipts
provide the recorded behavior. Synthetic Carbon equality timestamps do not establish
physical deadline performance, actual commercial terms, host/provider readiness,
or C4 consume/activation.

[archive-index.json](evidence/archive-index.json) records original runtime paths,
SHA-256 values and byte lengths of copied receipts. The packet manifest covers
only this agent's commands/source map/evidence files; README, independent-review
DECISION and shared ledgers are separately owned and excluded from its seal.
