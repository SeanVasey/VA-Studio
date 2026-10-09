# Paid252 Codex round 8: subtract only unwritten spool bytes

Development evidence from the Claude Code harness for PR #56, code commit `e5c2e1dc`. Not Foundation or final acceptance.

## Finding (Codex P2, `PaidGrantPrepareStream.php:212`)

Round 5 (`../codex-5/`) subtracted each held slot's whole reservation. A slot that has already written part of its
snapshot is already reflected in `disk_free_space()`, so those bytes were counted twice. For example, a 1 GiB snapshot
with 0.9 GiB written was charged as 0.9 GiB of lost free space plus another 1 GiB, and legitimate concurrent transfers
were refused.

## Fix

`pending()` now subtracts, for each other held slot, its reservation less the current size of its `slot-N.snapshot`.
Bytes still in a userland buffer are not yet in the file size and stay counted, which errs toward over-reserving. A
snapshot that is not a regular single-link file, or is larger than its reservation, counts as nothing written: the
full reservation, the conservative direction. After the snapshot is complete and unlinked, the reservation is zero, as
before.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Red: `PaidGrantSpoolReservationTest` with the new case | `8f751d0d` adapter + tests | 1 error, rc 2 (`backend-red.txt`): a fully written other slot was still charged and the nested preparation refused |
| Green: `PaidGrantSpoolReservationTest` | `e5c2e1dc` | 4 tests, 14 assertions, rc 0 (`backend-green-file.txt`) |
| Green: paid family, SQLite | `e5c2e1dc` | 81 tests, 3,759 assertions, 1 skipped, rc 0 (`family-green.txt`) |
| Pint `--test` | `e5c2e1dc` | passed (`pint.txt`) |

The test copy now writes an explicit number of bytes (`before`) before the nested preparation runs. The existing cases
use 0, modeling two preparations starting together, as they intended. The new case checks two states:
- a fully written other slot leaves nothing pending;
- a half-written one leaves exactly the other half pending: refused at one byte short, admitted at the exact amount.

`freeBytes()` is a constant that stands for the probe after those bytes were written.
