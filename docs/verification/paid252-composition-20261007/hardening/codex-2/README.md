# Paid252 Codex round 2: reads get their full server budget

Codex P2 on `0397231e`: the round 1 timeout table gave every read only 30 s, but `PaidGrantReads::index()` starts the default
60 s `PaidGrantDeadline`, show and download status use the same default through `PaidGrantCommands::run()`, and the routes may
first wait up to 5 s for the session lock. A valid read finishing between 30 and 60 s on a slow database was aborted and
discarded, so a customer could not open or refresh paid licenses while the server stayed within its budget.

Reads now abort at 80 s, the same rule as finalize and authorize: the 60 s server budget plus the 5 s lock wait plus a 15 s
margin. The doc comment on `paidRequestTimeouts` now states the rule for every request, and the test asserts both the table
and that `read >= 60 s + 5 s + 15 s`.

| Run | Result |
| --- | --- |
| Vitest with the old expectations against the new value | 1 failed, 11 passed, rc 1 (`frontend-red-old-expectations.txt`) |
| Vitest, updated expectations | 12 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | rc 0 (`tsc.txt`) |

No PHP changed. The round 1 table in `../codex-1/README.md` is superseded for reads by this round.
