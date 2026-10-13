# Initial native race failure: read-only diagnosis

The unchanged `defe95802e7d09e7df12c61a98c1ebf1caee99c0` run stopped after
**2 tests / 76 assertions / one failure / zero errors or skips / exit 1**.
The different-key last-credit case passed. The same-key/intent case failed its
requirement that the second concurrent caller succeed: that caller returned
`MembershipException(operation_deadline)`. Three remaining race methods were
not executed. This invocation is not counted as native concurrency acceptance.

Both retained worker receipts contain actual process commands, distinct PIDs and
connection IDs, readiness/start times, exact native record waits, captured results
and process exits. All four processes exited 0 after serializing their application
result; this does not mean every domain operation succeeded. Every worker ended
with Laravel transaction level 0 and PDO transaction false, with no teardown kill.

| Case | First operation | Second operation | First entry → fence observation | Second entry → buyer-row observation |
| --- | ---: | ---: | ---: | ---: |
| Different keys | 51.166898795 s, reserved | 64.665400044 s, insufficient_credit | 3.171389060 s | 1.535611589 s |
| Same key/intent | 54.943302333 s, reserved | 66.748257435 s, operation_deadline | 3.021361772 s | 1.399237432 s |

These are differences of retained monotonic nanosecond values, not estimates.
The [machine-readable diagnosis](native-race-initial-diagnosis.json) records the
original deltas and associated receipt paths. They are overlapping intervals and
must not be summed as independent work.

Both workers completed bootstrap, configuration and actor/principal preparation
and were ready before either public operation began. The original 60-second
operation budget starts inside `MembershipReservationOperations::run`, immediately
after the worker's `entered_ns` capture. The first worker then reached the parent
fence lock; the second reached the first worker's buyer `users` record lock.
In the failed case, the second barrier was observed 4.425108038 seconds after
first entry, and 1.399237432 seconds after second entry. The parent writes its
small transcript and immediately calls `DB::commit` in the source.

The exact parent commit timestamp was not retained. The observed barrier spans
therefore cannot exclude a hypothetical stall after the final barrier while
writing that transcript or committing, and they do not establish the exact total
artificial hold. Worker phase/lock-acquisition timestamps and the error backtrace
were also not retained, so the exact split between serialization wait and current
proof work cannot be recovered. The failed second operation returned 14.830825708
seconds after the first operation returned; the first may have committed before
its own read-only response proof finished.

The preceding different-key case's second operation also lasted more than
60 seconds, but reached its domain-specific insufficient-credit refusal before
terminal admission. That refusal does not establish a 60-second completion
promise. The 60-second bound rejects elapsed authority; it does not interrupt
every blocking operation at the exact deadline.

The focused native suite started at 04:50:52 UTC and races at 04:50:58 UTC, on
separate owned schemas in the same private MySQL 8.4.11 instance. They overlapped.
This may affect elapsed performance, but these receipts do not prove causality.

No changed or duplicate successful reservation identity was observed. The
same-key test asserted the second success before its final parent ledger audit,
so no post-failure audit is claimed. Source inspection shows exact-key retry reads
the existing identity, while new-row insertion belongs to the absent-key branch;
that inspection does not substitute for the missing completed native assertion.

The reviewer endorsed one controlled unchanged-source rerun after the competing
suite finishes, followed by the remaining three race cases serially. Those
outcomes remain pending. The failure and original 60-second deadline are retained;
no application/test edits, deadline increase, or automatic retry were performed
as part of this diagnosis.
