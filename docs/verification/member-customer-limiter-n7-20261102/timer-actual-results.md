# N7-F1 timer follow-up — carried actual results

Tested source is **`0159eec3e3a20874b6b7bc16c3bd92840f57baa8`**, parent
`e883fae652b3a19f204a95f5ba33709d3dae68e4`. This carries the existing draft and
accepted evidence; no new review or execution occurred during this publication
preparation. Main `3998060b41370707df50fbf7c7bdbf2f4c50a321` was integrated at
`4c883455a64a4bb4df32abc7f93ee6263f1c09f6`, changing documentation only.

The [limiter](../../../app/Http/Membership/MembershipCustomerLimiter.php) proves
the timer/counter epoch around one hit, admits a still-live fractional tail and
refuses partial, corrupt or changed state. It preserves consumed uncertain hits,
the ten-per-60-second account/tier quotas, configured-store locking, immutable
time bounds, safe release and final identity proof before domain entry. There
is no reset, refund, retry or timer/lease extension. The [new regression file](../../../tests/Feature/ProductionMembershipHttp/MembershipCustomerLimiterEpochTest.php)
adds 14 methods; the existing six limiter methods remain separate coverage.

## Positive baseline and retained failure

**20 disjoint selected tests / 1,323 assertions / zero errors, failures or skips**
passed across six bounded processes. New timing coverage is 14/886; unchanged
coverage is 6/437. This is neither a single-process 20-method pass nor a full suite.

| Recorded selection | Tests | Assertions |
| --- | ---: | ---: |
| Isolated fractional-tail method | 1 | 93 |
| `batch-01-new-4` | 4 | 425 |
| `batch-02-new-4` | 4 | 200 |
| `batch-03-new-5`, including the real 4.01-second stall | 5 | 168 |
| `batch-04-old-3` | 3 | 293 |
| `batch-05-old-3` | 3 | 144 |

Passing runs retained 120-second inner TERM/+2-second kill, 512 MiB, 150-second
outside and 64 KiB capture bounds. Safe receipts establish normal completion,
reaping, both EOFs/closed streams, profile/source/vendor closure and PHP/known
fixture-tool absence. Carried PHP 8.4.27/Reflection, two lints and Pint checks ran
before the commit on identical owned source bytes; they are not new checks.

The original combined attempt remains **TIMEOUT_UNCLASSIFIED**, exit 124 at
120 seconds, empty JUnit and unknown completed counts/cause. Later disjoint
passes neither explain nor replace it.

## Six negative experiments

**Six KILLED_SQLITE_ONLY classifications: six tests / 98 assertions / six expected
failures / zero errors or skips.** These are intentional failing mutant tests,
not 26 passing product tests. Each target exited 1 at its specified assertion;
outside owner exit 0 additionally required origin, lifecycle, predicates and
closure. One canonically suppressed PHPWarning trace per run was accepted under
the reviewed v3 classifier; no warning-free claim follows.

| Mutant | Credited first failure | Assertions |
| --- | --- | ---: |
| M01 | Fractional-tail read | 19 |
| M02 | First missing-timer iteration | 13 |
| M03 | Before-hit expired Array owner | 13 |
| M04 | Frozen-wall real monotonic stall | 13 |
| M05 | False release after takeover control | 27 |
| M06 | Identity-withdrawal status | 13 |

Later loop branches and independent conjuncts are not credited from the first
failure. Original v2 M01 remains **UNKNOWN**, without retroactive kill credit.
The [older native RUN01 failure and fbb RUN02 pass](actual-results.md), and the
older 14 mutations, remain evidence for their original source, not this delta.

## Recorded commands and provenance

[Timer summary](timer-actual-summary.json) carries the exact selected method names,
counts, source hashes and recorded command vectors. `recorded_commands` contains
the static invocation, isolated baseline, all five remaining baseline batches
and all six v3 mutant invocations. Only the absolute execution-root prefix is
replaced with `<local-source-root>`; these historical commands grant no rerun
authority. The original command sources are identified and hashed in
`source_records`: `selection-index.json` at `batches[].command` and
`EXECUTION.json` at `cases[].host_argv`, plus the static/isolated runner sources.
They are local provenance locators, not missing repository hyperlinks.

Inputs were classified before carry: public source identifiers/hashes, safe
summary counts, fixed outcomes and source command vectors are allowed. Raw logs,
JUnit bodies, configuration/customer/credential values and private-body digests
are excluded. The original draft and both safe summaries are hash-bound in the
JSON; their obsolete quota/pending statements are historical.

[Code/baseline decision](independent-timer-code-baseline-0159eec3-decision.md):
CONDITIONAL FIT for the exact delta and ACCEPT the selected baseline, no supported
P0–P2. [Six-actual decision](independent-timer-six-mutations-0159eec3-decision.md):
ACCEPT the six bounded classifications, no supported P0–P2 evidence discrepancy.
[Decision index](independent-decisions.json) records original and public-copy
hashes; the copies retain verdicts and limitations with only local paths redacted.

## Remaining gates and latest coordinator status

N7-F1 is implemented default-off. Intended backend transitions, shared cache/server
clocks and concurrency, hidden ABA/preemption, arbitrary custom providers, F9
headroom and account/history/host acceptance remain held. No exhaustive guard
coverage or native/shared-backend acceptance is claimed. Membership stays unmounted.

ROOT reports external code/security reviews completed on frozen PR #188 `e883`;
the old payer-quota blocker is historical and does not approve the new timer head.
ROOT also reports B1's separate a9b typed differential **1 test / 1,081 assertions
passed**, six cleanup checks clean and its lease returned. Physical native owner
`73355` is **RUNNING**, with no outcome recorded here. These account observations
do not expand N7 evidence. ROOT retains remaining checks, review and one coherent
PR #188 update; this carry performs no test, generator, network, push or CI action.
