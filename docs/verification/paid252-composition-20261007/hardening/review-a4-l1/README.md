# Paid252: independent review A4-L1, a retry waits for its own refusal

Development evidence from the Claude Code harness for PR #56, code commit `97801ddf`. Not Foundation or final acceptance.
This is a client-only change to `resources/js/components/PaidGrantJourney.tsx`, plus the review's addendum-4 tests.

## Finding (Addendum 4, A4-L1, Low; was condition C9)

The redeem POST runs in a frame outside `call()`, so a status read could run while the original submission was still
being prepared on the server. That read showed the authorization `unused` and offered the retry. If the retry was then
refused, `clear()` removed every frame, including the original's frame. In a browser that aborts the original
request, and the server may still commit its redemption, using up an attempt without delivering the file.

## Fix

- **Retry gate:** each submission carries its own `{ auth, refused }` mark, kept in memory only. The retry is offered
  only when the kept submission's own frame has reported `PAID_GRANT_UNAVAILABLE`, which means its request is over.
  The existing conditions still apply: a later saved read listing that exact id and kind as `unused`, no denial, and no
  new authorization displayed.
- **Frame removal:** a refusal removes only its own frame (`clear(false, true)` keeps the others). A denial and
  `pagehide` still remove every frame.
- **Not changed:** a frame whose answer arrives after a later request has started is still ignored (the existing
  generation guard), so a refusal that comes back late does not offer a retry. The customer then needs a new
  authorization once the old one expires, which is the conservative direction.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Vitest, red: `paid-grant-journey`, `-review-addendum1`, `-review-addendum4`, previous component | `dc5b5c0e` component + tests | 2 failed, 25 passed, rc 1: the A4-L1 reproduction and the frame case (`frontend-red.txt`) |
| Vitest, green | `97801ddf` | 27 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | `97801ddf` | rc 0 (`tsc.txt`) |

The reviewer's `it.fails` reproduction is now a regular test. The documenting case (the retry's refusal removes the
pending original frame) is rewritten as a regression test: while a contract download waits for its response, a newer
master download is refused, and only the master frame is removed.
