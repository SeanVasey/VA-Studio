# Paid252 round 25: a refusal reported after the last render shows its controls at once

Development evidence from the Claude Code harness for PR #56. Not Foundation or final acceptance. Source: `e4f9997e`
(plus the docs-only Addendum 17 commit `8a2f91dd`) and the working tree recorded by SHA-256 in each evidence file.

## Finding (Codex P2 4228172320, `PaidGrantJourney.tsx:297-298`)

A download frame that reports `PAID_GRANT_UNAVAILABLE` after the page last rendered (a hidden tab, or a later request,
made the frame non-current) only set `mark.refused` on a ref. Nothing re-rendered, so the reopen control from round 24 /
A16-L1 and the retry stayed hidden until an unrelated render. For an order older than the index, the short-lived token
could expire meanwhile. Independent review A9-I4 had recorded the same render gap as Info, and A17-I1 noted it again.

## Fix

A state counter is bumped whenever a kept download is marked refused, so the refusal itself renders. Nothing else
changed: the refusal message and page clearing still apply only to a current frame, tokens stay in memory only, and the
retry still requires a saved status read showing that exact authorization unused.

`tests/frontend/paid-grant-review-addendum9.test.tsx` asserted the A9-I4 limitation (no retry until another render). That
assertion is flipped to the fixed behaviour; the rest of the case is unchanged.

## Results (`evidence/`)

| Run | Source | Result | Evidence |
| --- | --- | --- | --- |
| Red: new case in `paid-grant-reopen-issued.test.tsx` | component as `e4f9997e` | 1 failed, 3 passed, rc 1 | `red-on-e4f9997e.txt` |
| Green: whole frontend suite (includes the flipped addendum 9 case) | final working tree | 65 files, 1,130 tests passed, rc 0 | `frontend-all.txt` |
| `npx tsc --noEmit` | final working tree | clean, rc 0 | `tsc.txt` |

## Not tested

A real browser.
