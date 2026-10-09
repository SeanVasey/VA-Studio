# Paid252: independent review A1-L1, a set-aside read must follow the last transmission

Development evidence from the Claude Code harness for PR #56, code commit `b574aa38`. Not Foundation or final acceptance.
This is a client-only change to `resources/js/components/PaidGrantJourney.tsx`, plus the independent review's addendum-1
tests.

## Finding (independent review Addendum 1, A1-L1, Low)

The set-aside gate (rounds 3 and 4) proves that no authorization committed **before** the qualifying status read is
live. It does not cover a transmission that commits **after** that read. Two paths reach this:
- **(i) A lost retry.** The exact retry's answer is lost too, but the earlier read still satisfies the gate.
- **(ii) An abandoned original.** The original was abandoned early, by a hidden tab (round 7), a proxy 502/504 or a
  network error. A read taken right afterwards shows nothing live while the original can still commit.

The cost is one extra live authorization whose token is never shown, plus one of the line's 3-per-60 s issuances. No
attempt, money or entitlement is affected.

## Fix (the reviewer's suggested rule)

- **Send time:** every transmission of the pending request records its send time (`performance.now()`, which is
  monotonic) in `execute()`, retries included.
- **Read time:** `savedStatus()` records when its read was issued, together with the pending request it was issued
  under.
- **Gate:** the gate also requires `issuedAt - sentAt >= paidRequestTimeouts.authorize` (80 s). By then the server has
  finished any transmission: a 60 s budget plus the 5 s session-lock wait.
- **Doc comment:** the comment that a retry "cannot race the original" is corrected. After an abandoned transmission, an
  exact retry can overlap the original. That overlap is harmless, because the replay path and the unique
  `(account_id, request_key)` index return the same row.

A1-I2 is also corrected: `../codex-4/README.md` called the retained policy "equality-checked", which round 6 replaced.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Vitest, red: `paid-grant-journey` + `paid-grant-review-addendum1`, previous component | `b43298f1` component + tests | 3 failed, 20 passed, rc 1: both reproductions, and a read taken 1 ms short of 80 s (`frontend-red.txt`) |
| Vitest, green | `b574aa38` | 23 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | `b574aa38` | rc 0 (`tsc.txt`) |
| `PaidGrantReviewAddendum1Test`, SQLite | `b574aa38` | 6 tests, 126 assertions, rc 0 |
| `PaidGrantSpoolReviewAddendum1Test`, SQLite (still holds after round 8) | `b574aa38` | 3 tests, 13 assertions, rc 0 |
| Pint `--test`, both reviewer PHP files | `b574aa38` | passed (`pint.txt`) |

**Test adjustments.**
- The reviewer's two `it.fails` reproductions are now ordinary `it` regression tests.
- The gate's positive cases drive `performance.now()` explicitly. The main journey test now also asserts that a read at
  79,999 ms after sending does not qualify and a read at 80,000 ms does.

## Open from the review (not changed here)

- **A1-L2 / C8:** a held spool slot now lasts for the whole transfer, up to 7,200 s; this is an operator decision before
  mount.
- **C1:** the transfer-deadline values are for Sean to confirm.
- **C2, C4–C7:** open as recorded in `../../independent-review/DECISION.md`.

## After merging main `72045620` (identity key rotation)

`db315e12` merges `origin/main` into the branch. `CHANGELOG.md` keeps both entries. `scripts/ci/database-sqlite-skips.json`
is the union of main and this branch's additions (175 methods); `python3 -I scripts/ci/test-database-receipts.py`
passes 34 OK. On SQLite, `--filter PaidGrant tests/Feature` runs 90 tests (81 plus the reviewer's 9), 3,814 assertions, 1
skipped, rc 0 (`evidence/family-merged-db315e12.txt`).
