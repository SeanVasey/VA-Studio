# Paid252 Codex round 3: setting aside a lost authorize

Development evidence from the Claude Code harness for PR #56, code commit `e479a8cb`. Not Foundation or final acceptance. Client-only
change to `resources/js/components/PaidGrantJourney.tsx`; no server route, schema, guard, receipt, deadline or
authorization lifetime changed, and no file pinned in `resources/contracts/*/profile-assets.json` was touched.

## Finding (Codex P2, `PaidGrantJourney.tsx:166`)

When an authorize commits but its answer is lost until the authorization expires, `pending` stays set. The exact retry
then gets 410 (the server's replay path calls `live()` on the stored row), and every authorize button stays disabled by
`!!pending`, so the customer cannot ask for a new authorization without leaving the page.

## Fix

A second button in the retry banner, "Set aside and request a new authorization", shown only for a pending authorize
(never for finalize, which has no expiry and is always recovered by its exact replay). It is enabled only when all of
these hold:

- a saved download-status read finished **after** this pending request was created. `savedStatus()` records the pending
  request it was issued under (`statusAfter`), compared by identity, so a read from before the request never counts;
- that read is for the pending request's origin, and its line shows **no** `unused` history entry of the same file kind.
  `unused` is the server's projection for an authorization that is neither redeemed nor past `expires_at`
  (`PaidGrantDownloads::status`). If one is live, only the exact retry is offered, which recovers that same row and
  token, so the per-line limit of 3 authorizations per 60 s (`PaidGrantDownloads::authorize`, 429) is not spent twice.

Setting aside clears `pending`, the read marker and `reviewedSaved`, and says so in the alert. The next authorize is a
new request with a new `requestKey` and nonce. Nothing is consumed by setting aside: download attempts are counted from
redemptions, not authorizations, and an orphaned authorization simply expires.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Vitest, red (new tests, original component) | `7283c339` + tests | 1 failed, 13 passed, rc 1: the set-aside button does not exist (`frontend-red.txt`) |
| Vitest, green | `e479a8cb` | 14 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | `e479a8cb` | rc 0 (`tsc.txt`) |

The finalize case ("never offers to set aside an uncertain finalization") is a guard that passes before and after.

## Not tested

- No browser run; the jsdom test drives the real component with mocked responses.
- The server keeps only the latest 20 authorizations per line in the status history. If more than 20 newer
  authorizations were made from other tabs inside one 60 s lifetime (the server allows 3), a live one could fall outside
  the window; that cannot happen under the current rate limit.
