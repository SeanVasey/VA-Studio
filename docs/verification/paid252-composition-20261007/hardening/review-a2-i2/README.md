# Paid252: independent review A2-I2, read written sizes before free space

Development evidence from the Claude Code harness for PR #56, code commit `ea7a63b9`. Not Foundation or final acceptance.

**Finding (Addendum 2, A2-I2, Info).** Round 8 made `admit()` subtract only what other held slots have not yet written.
It probed free space first and the other snapshots' sizes second. A byte another holder wrote between the two probes
therefore counted as written but was missing from the free-space figure, which over-admits by exactly that many bytes.
The window is sub-millisecond and well inside the 16 MiB reserve, but the fix is free.

**Fix.** `pending()` (the size probe) now runs before `freeBytes()`. A byte written in between is then still pending and
also missing from free space: counted twice, the conservative direction.

| Run | Source | Result |
| --- | --- | --- |
| Red: `--filter PaidGrantSpool tests/Feature` | `a2126d28` adapter + tests | 1 failure (the A2-I2 case was admitted), rc 1 (`spool-red.txt`) |
| Green: same | `ea7a63b9` | 9 tests, 34 assertions, rc 0 (`spool-green.txt`) |
| Pint `--test` | `ea7a63b9` | passed (`pint.txt`) |

**Test change.** The reviewer's Addendum 2 test is committed. Its documenting case, which asserted the over-admission,
is now a regression test asserting refusal. The hook fires inside `freeBytes()`, which with the new order is after
the size probe and before the free-space value is used.

**Still open** (from the review, not changed here):
- **A2-I1 (Info):** the 80 s set-aside margin counts from the client send. A request queued at a proxy or PHP-FPM for
  more than about 15 s before PHP admits it can still commit after a qualifying read. For mount, keep those queue
  timeouts under the margin.
- **Conditions C8 (A1-L2), C1, C2 and C4–C7.**
