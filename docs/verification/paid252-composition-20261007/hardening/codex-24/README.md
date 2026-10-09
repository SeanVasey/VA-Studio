# Paid252 round 24: an issued authorization keeps its order

Development evidence from the Claude Code harness for PR #56. Not Foundation or final acceptance. Source: `8fc077f2`
(the integrated merge candidate) plus the working tree recorded by SHA-256 in each evidence file.

## Finding (Codex P2 4228014577, `PaidGrantJourney.tsx:110`)

An issued download authorization was kept in memory with only its batch id (`originId`). Hiding the tab clears the shown
order but keeps issued authorizations (round 11). `open()` needs both the batch id and the order id. For an order older
than the 20-row index, nothing on the page held the order id after the clear, so the Download button could not be brought
back from the index and the short-lived token expired unused.

**Narrower than stated.** The order-reference form stays enabled (no request is pending), so a buyer who knows the order
reference can still reopen the order and its Download button returns. The flaw is that the page itself offered no way
back. Round 22 fixed the same gap for a pending authorize ("Reopen this order"); this round does the same for issued ones.

## Fix

- Each issued entry keeps `{ auth, originId, orderId }`, with both ids taken from the server-validated origin the authorize
  request was sent for.
- While an issued, not yet submitted authorization's order is not shown, the page renders one
  "Reopen order {orderId} to download its authorized file" button per order. It calls the existing `open(id, orderId)`,
  a GET whose answer must match both ids. It is hidden after a denial and disabled while busy.
- Unchanged: submitting removes the issued entry (and so the button); a denial or leaving the page drops every issued
  authorization; the token is never rendered. No PHP, route or schema change.

## Results (`evidence/`)

Node 24.21.0, vitest from the locked tree.

| Run | Source | Result | Evidence |
| --- | --- | --- | --- |
| Red: new test file | `8fc077f2`, component unchanged | 2 tests, 2 failed (no reopen control), rc 1 | `red-on-8fc077f2.txt` |
| Green: whole frontend suite | final working tree | 1,128 tests passed, rc 0 | `frontend-all.txt` |
| `npx tsc --noEmit` | final working tree | clean, rc 0 | `tsc.txt` |
| Mutation M1: the issued entry keeps an empty order id | final tree + mutation, restored and checked with `cmp` | 2 tests fail, rc 1 | `mutation-M1-empty-order.txt` |

## Not tested

A real browser. The kept-after-refusal retry path (`kept`, round 10) is reached through a saved status read of the shown
order and was not changed.
