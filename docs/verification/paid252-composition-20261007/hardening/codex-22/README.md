# Paid252 round 22: reopening the order of a pending authorize (client only)

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. The change is
uncommitted on `harness/paid252-composition` at `2f0d7ca2` ("2f0d7ca2 + working tree"). It touches only the client.
There is no server, schema or pinned-file change (`evidence/pinned-check.txt`).

## Finding (Codex P2 4224824643, `PaidGrantJourney.tsx:137`)

The customer could be stuck until a reload:

1. An authorize for an order older than the 20-row saved-order index loses its answer.
2. The tab is hidden. That clears the shown order but keeps the exact request (`pending`), as designed in round 7.
3. On return the exact retry gets 410, because the authorization, if it was ever committed, has expired. `pending`
   stays set.
4. The order is not in the bounded index.
5. The order-reference form is disabled while `pending` is set.
6. With no order shown, the saved status read, and so the set-aside path, could never be reached.

## Fix

The pending banner now shows **"Reopen this order"** when the pending request carries its order (`pending.origin`) and
that order is not the one currently shown.

- **What it calls.** The existing `open(pending.origin.id, pending.origin.orderId)`: an ordinary origin read with its
  usual validation, including the exact `id` and `orderId` match. `open()` has no `pending` guard (only the index buttons'
  `busy`), so no guard needed changing.
- **What it gates on.** `busy` only, like the index buttons.
- **Unchanged:**
  - the exact retry and its `reviewedSaved` requirement;
  - the set-aside rule (a saved status read issued at least the 80 s authorize timeout after the request was last sent,
    showing no live authorization of that file);
  - the order-reference form (still disabled while a request is pending).
- **Pending finalize: no such trap, so no button.** A pending finalize has an order reference but no order yet.
  - Its exact retry is reachable whenever a saved read has been taken: "Open paid licenses" is never disabled by
    `pending`, and it sets `reviewedSaved`.
  - The retry is idempotent and returns the retained order, which is the reopen path.
  - An uncertain finalize is never set aside, by design (round 3), so there is no set-aside path to unblock.
  - The test asserts that no reopen control appears for it.

## Tests (`tests/frontend/paid-grant-reopen-pending.test.tsx`, new, 3)

**1. An older order** (opened by its reference; the index lists only another order). Steps and checks:
- The authorize answer is lost; the tab is hidden and shown again.
- The index does not list the order, and the reference form is disabled.
- The exact retry gets 410, and the request stays pending.
- "Reopen this order" issues `GET /paid-grants/origins/{id}` and shows the order. The button then disappears.
- A saved status read 80 s after the retry shows the authorization expired, so "Set aside" is enabled and clears the
  request.
- Authorize and the reference form are enabled again.
- Neither the request key nor the nonce from the request body appears in the DOM.

**2. A newer order (in the index) behaves exactly as before:**
- the index button reopens it;
- the banner shows no duplicate control while the order is shown;
- set-aside works after the saved read.

**3. A pending finalize** shows no reopen control.

## Results (`evidence/`)

Node 24.21.0 with a temporary `node_modules` symlink, since removed.

| Run | Source | Result |
| --- | --- | --- |
| Red | `2f0d7ca2` client + new test | 1 failed (no "Reopen this order"), 2 passed, rc 1 (`red-on-2f0d7ca2.txt`) |
| Green | `2f0d7ca2` + working tree | 3 passed, rc 0 (`green-new-test.txt`) |
| Mutation: the button is never rendered | working tree + mutation (restored, checked with `cmp`) | 1 failed, rc 1 (`mutation-M-no-reopen-button.txt`) |
| All paid frontend files (12 existing + 1 new) | final working tree | 13 files, 71 tests passed, rc 0 (`frontend-paid-all.txt`) |
| `tsc --noEmit` | final working tree | rc 0 (`tsc.txt`) |

The newer-order and finalize cases pass before and after by design: they guard behaviour that must not change. The PHP
suite was not run, because no PHP file changed.

## For Sean

If a download authorization for an older order is interrupted and the tab is hidden, the page can now reopen that order
from the waiting message. The expired request can then be cleared without reloading the page.
