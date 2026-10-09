# Paid252: a refused download kept for retry keeps its order (independent review A16-L1)

Development evidence from the Claude Code harness for PR #56. Not Foundation or final acceptance. Source: `915ca2c9`
(round 24) plus the working tree recorded by SHA-256 in each evidence file.

## Finding (Addendum 16, A16-L1, Low, pre-existing)

Round 24 gave issued authorizations their order id. A download refused before anything is recorded is kept in memory for
retry (round 10), but its kept entry held no order id either. The refusal clears the shown order, so for an order older
than the 20-row index nothing on the page led back to "Retry the authorized download" except typing the order reference,
and that form is closed while another authorize is pending. Same root cause as Codex 4228014577.

## Fix

- Each kept entry records `{ auth, refused, originId, orderId }` from the shown order when the download is submitted
  (downloads are only offered for the shown order; `submit()` now also returns early without one).
- The round 24 reopen control covers kept refused entries as well as issued ones, still one control per order, never
  for the shown order, and none after a denial. After reopening, the existing saved-status read decides whether the
  retry is offered (the authorization must still be unused).
- Unchanged: tokens stay in memory only; a denial or leaving the page drops issued and kept entries; no PHP change.

## Results (`evidence/`)

| Run | Source | Result | Evidence |
| --- | --- | --- | --- |
| Red: new case in `paid-grant-reopen-issued.test.tsx` | `915ca2c9` component | 1 failed (no reopen control), 2 passed, rc 1 | `red-on-915ca2c9.txt` |
| Green: whole frontend suite | final working tree | 65 files, 1,129 tests passed, rc 0 | `frontend-all.txt` |
| `npx tsc --noEmit` | final working tree | clean, rc 0 | `tsc.txt` |

## Not tested

A real browser.
