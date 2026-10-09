# Paid252 Codex round 15: download-frame answers no longer depend on the API-request generation

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. Client-only
change: no PHP, schema, guard or server behaviour changed.

## Finding (Codex P2, `PaidGrantJourney.tsx:183`, reviewed commit `d00e0fa0`)

A redemption frame's `load` handler returned early unless `generation` was unchanged since submission. Every `call()`
and every hidden-tab transition increments `generation`. If the tab was hidden, or any status read started, before a
pre-commit refusal arrived, the refusal was ignored:

- the kept authorization was never marked refused, so the exact retry could never be offered;
- `submit()` had already removed it from the issued list.

The customer had to mint another authorization against the three-per-minute limit. The independent review recorded the
same conservative behaviour in addendum 5, test (c), with a UX note suggesting this change.

## Fix

- The handler now ignores an answer only when the page is unmounted or the frame is no longer in `frames.current`.
  Denial, departure and unmount remove frames, so their answers are still ignored.
- A `PAID_GRANT_UNAVAILABLE` refusal always marks the submission refused and removes its own frame.
- It clears the page and shows the refusal message only when no later request has started (the old generation
  comparison, now used only for UI). The "could not be confirmed" message follows the same rule.
- The retry still needs a saved status read that lists that exact authorization id and kind as `unused`.

A wrong retry stays harmless: the server refuses a spent (409) or expired (410) token.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Vitest: journey + addendum 5 with the new test | `d00e0fa0` component, new tests | 1 failed (new hidden-tab case), 24 passed, rc 1 (`frontend-red.txt`) |
| Vitest: the six paid frontend files | `d00e0fa0` + this change | 42 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | same | rc 0 (`tsc.txt`) |

- **New journey test:** a refusal arriving while the tab is hidden removes the frame. After return, a saved read showing
  `unused` offers the exact retry with the same token, and no token is rendered.
- **Addendum 5 case (c) flipped:** a refusal arriving after a status read started is now recorded without touching the
  page, and the next read offers the retry.
- **New addendum 5 case:** a frame answer after `pagehide` (frame removed) is ignored, and no retry is offered.
- **Wording:** one stale comment about the generation guard in addendum 5 was corrected.

## Not tested

A real browser.

- **Stale-read retry:** a status read issued before the refusal arrived can show the retry once the refusal is recorded.
  Because the refusal is pre-commit, `unused` is still accurate.
- **Refusals the server sends after committing:** a refusal after a commit (for example a post-commit proof failure)
  would make that retry a spent-token 409. It costs no attempt.
