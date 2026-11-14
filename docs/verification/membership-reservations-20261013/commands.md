# Reservation verification commands and receipts

This packet preserves completed development runs, including all setup errors and
intentional failing regressions. The corrected four-case native race selection
passed at `45d12994`; both original dual-success failures remain retained. Latest
source-bound native case results cover **11 distinct cases / 336 assertions**
across several invocations, while mixed-source focused SQLite covers **180 cases /
965 assertions: 179 passes and one declared skip**. Neither is a single full-suite
execution. Historical component notes retain their original source and limits.

The toolchain is PHP 8.4.26 / PHPUnit 12.5.34, activated with
`source /workspace/.va-studio-toolchain/activate.sh`. Environments, source hashes,
commands, selected cases, observed exit codes and JUnit totals are retained in
[evidence/](evidence/). Only source code, hashes, command notes and test logs are
archived. Private synthetic media, environment files and credential material are
excluded. No Foundation or GitLab workflow was dispatched for these checks.

| Check | Exact command or retained invocation source |
| --- | --- |
| Policy unit failures, combined assertion failure, corrected 78-case selection, Pint | [Policy commands](evidence/policy/commands.md); each `*.source.json` retains actual argv/environment and owned hashes |
| Ledger records plus old first-award compatibility | [Ledger commands](evidence/ledger/commands.md), [retained final shell script](evidence/ledger/ledger-focused.sh) |
| Entry admission, missing-TMPDIR setup error and unchanged-source retry | [Entry commands](evidence/integration-and-adapters/entry-commands.md) |
| License identity binding, catalog callbacks, original and reviewer regressions | [Eligibility commands](evidence/integration-and-adapters/eligibility-commands.md) |
| Real synthetic lifecycle fixture integration | [Lifecycle commands](evidence/lifecycle/commands.md), [retained wrapper](evidence/lifecycle/run-lifecycle.py), [actual argv/environment](evidence/lifecycle/sqlite-initial.source.json) |
| Existing first-award and ownership compatibility after ledger integration | [Retained shell script](evidence/integration-and-adapters/award-replay-integration.sh) |
| Earlier terminal-clock ordering reproduction | [Exact overlay invocation](evidence/integration-and-adapters/context-clock-regression.md), [actual argv/environment and overlay hash](evidence/integration-and-adapters/context-clock-order-red.source.json) |
| Integrated lifecycle, ownership and clock checks | [Retained runner](evidence/integration-and-adapters/run-focused.py), [actual argv/environment](evidence/integration-and-adapters/sqlite-integrated-lifecycle.source.json) |
| Integrated MySQL lifecycle canary | [Actual PHP argv/environment](evidence/integration-and-adapters/native-integrated-canary.source.json), [result and unchanged-source check](evidence/integration-and-adapters/native-integrated-canary.result.json) |
| Errored focused-native selection | [Actual argv/environment](evidence/integration-and-adapters/native-focused-boundaries.source.json), [complete output](evidence/integration-and-adapters/native-focused-boundaries.log), [result/source check](evidence/integration-and-adapters/native-focused-boundaries.result.json) |
| Failed initial native race selection | [Actual argv/environment](evidence/integration-and-adapters/native-reservation-races.source.json), [result](evidence/integration-and-adapters/native-reservation-races.result.json), [worker receipts](evidence/integration-and-adapters/native-race-workers/), [read-only timing diagnosis](evidence/integration-and-adapters/native-race-initial-diagnosis.md) |
| Failed isolated same-key selection | [Actual argv/environment](evidence/integration-and-adapters/native-reservation-races-isolated.source.json), [result](evidence/integration-and-adapters/native-reservation-races-isolated.result.json), [timestamp diagnosis](evidence/integration-and-adapters/native-race-isolated-diagnosis.json) |
| Standalone shadow SQL probe and native-only fixture correction | [Exact commands and overlap disclosure](evidence/integration-and-adapters/eligibility-shadow-fixture-commands.md), [probe source](evidence/integration-and-adapters/eligibility-shadow-sql-probe.php), [output](evidence/integration-and-adapters/eligibility-shadow-sql-probe.txt) |
| Corrected native eligibility selection | [Actual argv/environment](evidence/integration-and-adapters/native-eligibility-final.source.json), [result/source check](evidence/integration-and-adapters/native-eligibility-final.result.json) |
| Completed corrected native races | [Actual argv/environment](evidence/integration-and-adapters/native-reservation-races-contract.source.json), [result/source check](evidence/integration-and-adapters/native-reservation-races-contract.result.json), [explicit worker reconciliation](evidence/native-worker-reconciliation.json) |
| Race contract assertion correction | [Exact static commands and scope](evidence/integration-and-adapters/native-race-contract-commands.md); no native execution in that authoring lane |
| Final integrated Pint at 45d12994 | [Actual lint argv/pre-run hashes](evidence/integration-and-adapters/final-45d12994-pint.source.json), [exit and unchanged-source result](evidence/integration-and-adapters/final-45d12994-pint.result.json) |
| Earlier Pint at defe9580 | [Original command](evidence/integration-and-adapters/final-pint-commands.md), [explicit post-run source reconstruction](evidence/integration-and-adapters/final-pint-source-reconstruction.json) |
| Native race preparation | [Syntax/Pint command notes](evidence/integration-and-adapters/native-race-preparation-commands.md); no behavioral execution in that preparation lane |
| Selection policy and final native discovery | [Commands/history](evidence/selection/final-45d12994/commands-history.md), [exact final discovery script](evidence/selection/final-45d12994/discovery.sh), [source delta](evidence/selection/final-45d12994/source-delta.json) |

The following reproducible outer invocation is reconstructed from the retained
runner and actual PHP argv; the original outer shell command was not separately
saved. It selects the completed integrated SQLite run at `defe95802e7d09e7df12c61a98c1ebf1caee99c0`:

```sh
python /workspace/runtime/membership-reservation-evidence/run-focused.py \
  --name sqlite-integrated-lifecycle --driver sqlite \
  tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php \
  tests/Feature/ProductionMembershipBilling/MembershipReservationOwnershipTest.php \
  tests/Feature/ProductionMembershipBilling/MembershipReservationClockClosureTest.php
```

The source receipt preserves the actual PHP argv and environment, and the result
receipt records unchanged source. Result: **13 tests, 228 assertions, zero errors
or failures, one declared native-only skip, exit 0**. Twelve cases executed;
SQLite does not prove the skipped native callback-frame case or any native race.
The corrected counterpart to the one-case Context overlay red is included.

The completed integrated MySQL canary at the same exact source reports **1 test,
21 assertions, zero errors/failures/skips, exit 0**. The retained runner records
262.660 seconds wall duration; JUnit records 262.375136 seconds. All 1,266 captured
source hashes were unchanged. [The environment receipt](evidence/integration-and-adapters/mysql-environment.txt)
records MySQL **8.4.11**, the private datadir/socket, loopback port 33413 and owned
synthetic schemas. This one case proves the selected reserve/retry/award-replay
lifecycle only; the other native outcomes and limitations are recorded below.

The initial native race selection stopped after **2 tests / 76 assertions / one
failure / zero errors or skips / exit 1**, with all captured source unchanged.
The first different-key case passed; the second same-key/intent case refused
`operation_deadline` after 66.748257435 seconds in worker two. That failed
invocation is not concurrency acceptance. Three race methods were unexecuted.
The exact commit time and wait/work split were not recorded; the timing diagnosis
retains those limits. The reviewer-endorsed unchanged-source repeat then stopped
after **1 test / 33 assertions / one failure / zero errors or skips / exit 1**,
with 170.809 seconds runner wall time (170.532946 JUnit seconds). Worker one
succeeded in 45.284010462 seconds; worker two refused `operation_deadline` after
63.448939786 seconds. No competing full suite was running. A small standalone
shadow SQL probe overlapped on a separate schema; its duration was not measured.
Full-suite competition is not a sufficient explanation, and the three later
selected race methods again did not run. Both failed invocations remain retained.

The focused-native selection completed with **4 tests / 58 assertions / one
error / zero failures or skips / exit 2**, source unchanged. The first three
clock/ownership cases passed with 57 assertions; native temporary-shadow fixture
setup then received MySQL error 1066 (`Not unique table/alias: tracks`) from
`CREATE TEMPORARY TABLE tracks LIKE tracks`. The fourth case did not reach its
intended application assertion, and the fifth selected case did not run. The
native-only fixture correction is separately committed as `222b6e3d`, integrated
`9e2496c7`. The standalone probe reproduced 1066, proved replacement temporary
creation with two exactly equal typed rows, and verified the durable source
unchanged before removing its disposable schema. It exited 0; this probe is not
a feature-test result. The subsequent native eligibility selection at `9e2496c7`
passed **2 tests / 12 assertions / zero errors, failures or skips / exit 0**, with
unchanged source (226.797 seconds wall, 226.550235 JUnit). This does not relabel
the original errored invocation as a passing five-case selection.

The race assertion correction `7f51996c`, integrated `45d12994`, follows the
preexisting contract: only an identical admitted receipt or exact elapsed
`operation_deadline` refusal is accepted for the second idempotent caller. The
three affected methods now verify real elapsed time, closed transactions, full
ledger/cardinality/conservation before a separate parent invocation, and original
receipt plus byte-identical retained rows/audit afterward. No application code,
clock or deadline changed. The complete corrected selection then passed **4 tests /
203 assertions / zero errors, failures or skips / exit 0** at `45d12994`, with
all captured source unchanged. Runner wall duration was **1130.612 seconds**;
JUnit reported 1130.296659 seconds.

The same-key loser still refused `operation_deadline` after **60.823447676 seconds**.
The later independently admitted parent call returned the original receipt and
left the full audited retained rows byte-identical. Both duplicate-release races
returned identical concurrent receipts; their parent retries also preserved the
full ledger. Expiry won the equality race and the new reserve refused
`credit_expired`, with conserved final balances. These are exact observed outcomes,
not a claim of two successful concurrent same-key responses or a latency promise.
Frozen Carbon wall equality is synthetic; monotonic clocks, process concurrency
and database waits remained real.

Final Pint at **45d12994** passed **29 changed PHP files**, exit 0, with actual
argv and pre/post unchanged hashes retained. The Python outer wrapper was a root
tool call, not a saved script; its actual lint argv is the reproducible command
receipt. The older defe9580 pass, post-run hash reconstruction and unrelated
nonexistent-path lookup remain historical evidence.

[The SQLite identity census](evidence/sqlite-unique-case-summary.json) derives
**180 distinct cases / 965 assertions: 179 passes and one declared skip** from
the latest successful relevant component results at their documented sources.
This is mixed-source focused evidence, not one integrated 180-case execution.
Nine older award cases are replaced by the later root award/ownership result;
the earlier six lifecycle cases are represented by the integrated lifecycle
selection. The five native race cases were not run on SQLite and contribute no
passes or skips to this count.

Discovery executes `--list-tests-xml` and existing policy scripts; it does not run
database tests. The final 45d12994 census is **1,973 native cases / 187 files**, selected
from **8,047 total cases / 587 files**, across **24 nonempty shards**. All 2,091
captured source files were unchanged. The policy suites previously passed
**52 / 61 / 32 tests**; nine relevant files remained identical, so those suites
were not rerun. The earlier **1,971 / 8,045** census is retained separately under
`initial-565f19d1/`. Generated configuration/listing files are not duplicated in
this compact packet; their full original SHA-256/size receipts, census, manifests,
commands and successful discovery logs are retained. Existing fallback timing
weights for 24 untimed selected files are estimates, not observed runtimes.
The 45d12994 discovery refresh completed with unchanged counts, all 2,091 hashes
unchanged, and exactly the two test corrections as its delta from defe9580. It
used only listing commands with an unreachable 127.0.0.1:1/no-socket database
configuration; it imposed no database workload. Skip policy remains 231 SQLite
native-only methods / 16 MySQL SQLite-only methods. Policy passes were carried
by identical source hashes, not rerun or invented.

To independently validate copied artifact hashes and JUnit leaf totals and to
regenerate derived manifests without executing application code:

```sh
python3 docs/verification/membership-reservations-20261013/evidence/rebuild-manifests.py
```

The script also writes [native-case-reconciliation.json](evidence/native-case-reconciliation.json)
with every prior native result and invocation exit status, plus
[native-worker-reconciliation.json](evidence/native-worker-reconciliation.json)
with explicitly mapped process receipts and available before/after audit summaries.
Only completed released invocations are ingested. Native reconciliation reports
11 distinct latest passing cases / 336 assertions and retains all three original
nonzero invocations, including their superseded failed/error case histories.
The first different-key pass (43 assertions) and three boundary passes (57) remain
identified as successful leaves inside failed/errored invocations; those
invocations themselves are never relabeled green.

The script verifies each retained runtime copy, requires a completed exit receipt
for every registered JUnit run, checks aggregate counts against actual case
identities, and writes `junit-summary.json` and `artifact-digests.json`. It does
not convert failures into passing checks or add overlapping run counts together.

The final full staged `git diff --cached --check` returned exit 2 for seven
trailing-whitespace findings on blank context lines in the verbatim archived
`evidence/policy/policy-lane.patch` (lines 38, 40, 46, 52, 78, 82 and 99). Those
spaces are part of the original unified patch; stripping them would alter the
retained source artifact. The check on all other staged paths passed with:

```sh
git diff --cached --check -- . ':(exclude)docs/verification/membership-reservations-20261013/evidence/policy/policy-lane.patch'
```

The artifact hash remains exact. A prose-only interim canary inventory count was
also corrected from 1,268 to 1,266 using its actual receipt; 1,268 belongs to the
three-file integrated SQLite selection. Source receipts and test results did not
change.

Final staging also exposed 11 `.log` receipts ignored by repository rules: the
local artifact hashes were correct, but ordinary `git add` omitted those files.
The staged-file-count assertion failed before packet release. Only those exact
manifest-listed logs were then added explicitly with `git add -f`, and the
unpublished packet commit was corrected. The final committed-manifest verification
checks every artifact against Git blobs, so this local presence/tracking gap is
closed. No log content was rewritten.
