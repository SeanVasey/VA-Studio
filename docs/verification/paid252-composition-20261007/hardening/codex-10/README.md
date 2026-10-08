# Paid252 Codex round 10: a refused download keeps its unused authorization

Development evidence from the Claude Code harness for PR #56, code commit `4cb35010`. Not Foundation or final acceptance.
This is a client-only change to `resources/js/components/PaidGrantJourney.tsx`. No server code, schema, guard, receipt
or lifetime changed.

## Finding (Codex P2, `PaidGrantJourney.tsx:167`)

The page discarded the authorization token when it submitted the download. Since round 9 a buyer holds at most one
spool slot, and any busy spool now refuses before `paid_redemptions` is written. In both cases the authorization stays
live and unused. The page had no token left to retry with, so the customer had to request a new authorization and use
up the three-per-minute issuance limit.

## Fix

- **On submission:** the token moves to a memory-only ref (`kept`) that is never rendered. The status shown so far is
  cleared, so any status read afterwards was taken after the submission.
- **On refusal:** the attachment's refusal body does not say why the request was refused, so the page is cleared as
  before. The message now says that an authorization still unused can be retried after a saved status read.
- **Retry button:** "Retry the authorized download" appears only when all of these hold:
  - a saved status read for the open order lists that exact authorization id and kind as `unused`;
  - no denial has happened;
  - no new authorization is displayed.

  The button submits the same token again.
- **When the token is dropped:** a denial (`refuse()`) and leaving the page (`pagehide`) drop it.
- **Why a wrong retry is safe:** the server refuses a spent token (409) or an expired one (410). A retry can never redeem
  twice.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Vitest, red: both paid files, previous component | `92b5cab2` component + tests | 1 failed, 23 passed, rc 1 (`frontend-red.txt`) |
| Vitest, green | `4cb35010` | 24 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | `4cb35010` | rc 0 (`tsc.txt`) |

The new test runs this sequence:
1. Authorize, then download.
2. The download is refused, which clears the page; no retry is offered and the token appears nowhere in the DOM.
3. Open the order and read status, which shows the authorization unused.
4. Retry, which posts the same action and token again.
5. Once status shows the authorization attempted, no retry is offered.

## Not tested

No browser run (jsdom with a stubbed attachment frame).
