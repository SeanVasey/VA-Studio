# Paid252: independent review A7-L1, frames are never evicted

Development evidence from the Claude Code harness for PR #56, code commit `99b7f5aa`. Not Foundation or final acceptance.
This is a client-only change to `resources/js/components/PaidGrantJourney.tsx`.

**Finding (Addendum 7, A7-L1, Low; condition C10).** On a fourth download submission, the 3-frame cap removed the oldest
frame whether or not it had reported back. Round 11 gave every issued authorization its own Download button, so this
became reachable. In a browser, removing a frame that has not answered aborts its request, and the server may still
commit the redemption, so the attempt is consumed without the file.

**Fix (the reviewer's first option).** Frames are never evicted to make room. A frame is removed on its own refusal, on a
denial, when the page is left and on unmount. Frames are tiny hidden same-origin elements, and their number is bounded
by the authorizations issued on the page, which the server rate-limits.

| Run | Source | Result |
| --- | --- | --- |
| Vitest, red: six paid files, previous component | `2c1b4f8a` component + tests | 1 failed, 39 passed, rc 1 (`frontend-red.txt`) |
| Vitest, green | `99b7f5aa` | 40 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | `99b7f5aa` | rc 0 (`tsc.txt`) |

The reviewer's `it.fails` reproduction is now a regular test. Its frame-count expectation changed from 3 (a cap) to 4,
because nothing is evicted. The first frame still stays connected.
