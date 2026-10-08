# Paid252 Codex round 7: a hidden tab keeps the exact replay

Development evidence from the Claude Code harness for PR #56, code commit `c0a68b2d`. Not Foundation or final acceptance.
Client-only change to `resources/js/components/PaidGrantJourney.tsx`; no server, schema, guard, receipt or lifetime change,
and no file pinned in `resources/contracts/*/profile-assets.json` was touched.

## Finding (Codex P2, `PaidGrantJourney.tsx:97`)

The `visibilitychange` handler treated a hidden tab as leaving. It aborted the in-flight request and `clear()` dropped
the pending request, including its request key and nonce. If the authorize had committed on the server, the customer
came back with neither the token nor the exact replay that recovers it. The only way forward was a new authorization,
which duplicates a live one and spends the line's issuance limit.

## Fix

- **Hidden tab:** still bumps the generation and aborts the in-flight request, so a late answer is ignored. It still
  clears everything shown: the listing, the order, terms, status, the authorization and any entered order reference.
  It keeps `pending` (`clear(true)`). If a request was in flight, it shows the "could not be confirmed" message. The
  kept replay is memory only and is never rendered: the banner shows generic text.
- **`pagehide` (actual departure):** unchanged. It clears everything, including the pending request.
- On return, the existing rules apply: the exact retry needs a fresh saved read, and the set-aside gate (rounds 3–4) still
  needs a post-request status read.

The same hide-and-clear pattern exists in `FreeGrantJourney.tsx`. It is not changed here, because the finding is on the
paid journey and Free256 is merged; it is noted for a follow-up.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Vitest, red (new test, previous component) | `392afd6a` + tests | 1 failed, 15 passed, rc 1 (`frontend-red.txt`) |
| Vitest, green | `c0a68b2d` | 16 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | `c0a68b2d` | rc 0 (`tsc.txt`) |

The new test hides the tab while an authorize is pending. It checks that:
- the fetch is aborted;
- the order, terms and any download button disappear;
- the late answer is ignored and the alert shows;
- after the tab is shown again and a saved read is taken, the retry sends the same path and a byte-identical body.

The existing departure test now also asserts that `pagehide` leaves nothing to replay.

## Not tested

No browser run (jsdom with mocked responses).
