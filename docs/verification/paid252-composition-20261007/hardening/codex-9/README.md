# Paid252 Codex round 9: one held spool slot per buyer

Development evidence from the Claude Code harness for PR #56, code commit `9c19dabb`. Not Foundation or final acceptance.
`PreparedDeliveryStream` and every other main-resident or pinned file are unchanged.

## Finding (Codex P2 `PaidGrantPrepareStream.php:90`; same as independent review A1-L2 / condition C8)

A slot lease moves into `PreparedDeliveryStream` and is released only when the stream closes. Since round 1, that
can be up to `transfer_max_seconds` (7,200 s). There are three global slots, and the retained policy allows three
attempts. One buyer running three slow redemptions could therefore hold every paid slot for over an hour, and every
other customer's redemption would fail with `target_unavailable` (closed, before any attempt is recorded).

## Fix

- **Why the lease is not released early:** releasing a slot when its snapshot is ready would drop the disk bound. The
  unlinked snapshot keeps its blocks until the stream closes.
- **Holder sidecar:** `PaidGrantPrepareStream::handle()` takes an optional 64-hex `$holder`. Admission, under the
  global admission lock, refuses when any other held slot records the same holder in its 64-byte `slot-N.holder`
  sidecar. Otherwise it records the holder in its own sidecar before reserving space.
- **Sidecar handling:** the sidecar follows the reservation sidecar's rules. It is created by temp-and-rename, opened
  only when it is a regular single-link 0600 file owned by this user, never repaired, and fails closed when unreadable
  for a held slot. A stale sidecar of an unheld slot is ignored and overwritten by the slot's next holder.
- **Caller:** `PaidGrantDownloads::redeem()` passes `sha256("paid-spool-holder-v1:" . account_id)`. A buyer's second
  concurrent redemption is then refused before any attempt is recorded.
- **What remains:** three different slow buyers can still fill the three slots. That is pool capacity, so the slot
  count and transfer deadlines stay an operator decision before mount (C8).

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Red: the new test against the previous adapter | `17c05c1d` adapter + tests | 1 failure, rc 1 (`spool-red.txt`): the same buyer was admitted to a second slot |
| Green: `--filter PaidGrantSpool tests/Feature` | `9c19dabb` | 10 tests, 37 assertions, rc 0 (`spool-green.txt`) |
| Green: paid family, SQLite | `9c19dabb` | 93 tests, 3,902 assertions, 1 skipped, rc 0 (`family-green.txt`) |
| Pint `--test` | `9c19dabb` | passed (`pint.txt`) |

The new test holds a slot for buyer A and asserts three outcomes during the hold:
- a second slot for buyer A is refused;
- buyer B is admitted;
- a call with no holder is admitted.

After the stream closes, buyer A is admitted again, and a malformed holder is refused.
