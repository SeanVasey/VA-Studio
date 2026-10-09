# Paid252 Codex round 11: issued authorizations survive other actions

Development evidence from the Claude Code harness for PR #56, code commit `f785c494`. Not Foundation or final acceptance.
This is a client-only change to `resources/js/components/PaidGrantJourney.tsx`.

## Finding (Codex P2, `PaidGrantJourney.tsx:109`)

`call()` cleared the issued authorization at the start of every API action. The server stores only `token_hash`, and the
successful authorize had already dropped `pending`. A status refresh, opening another order or authorizing another
file before clicking Download therefore lost the token: the customer had to issue a new authorization, spending one of
the three per minute.

## Fix

- **Storage:** the single `authorization` state is replaced by a list of issued authorizations, each with its order id.
  An authorization stays in the list until it is submitted, a denial occurs (`refuse()`) or the page is left
  (`pagehide`).
- **Hidden tab:** a hidden tab keeps the list in memory, as it keeps the pending replay (round 7). Nothing is rendered,
  because no order is open.
- **Display:** every issued authorization of the open order is shown with its own "Download authorized file" button.
  Submitting one removes only that one.
- **Unchanged:** `call()` no longer touches the list. The round 10 retry stays hidden while the same authorization is
  still listed.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Vitest, red: four paid files, previous component | `283e5c8f` component + tests | 1 failed, 31 passed, rc 1 (`frontend-red.txt`) |
| Vitest, green | `f785c494` | 32 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | `f785c494` | rc 0 (`tsc.txt`) |

**New tests.**
- The first test:
  - authorizes the contract and refreshes status, then authorizes the master; both download buttons remain;
  - leaves the order, which hides both, then reopens it, which shows both again;
  - submits one, which removes only that one; neither token appears in the DOM.
- The second test, a guard that also passes on the old code: `pagehide` drops issued authorizations.

The independent review's addendum 5 (A4-L1 fix, APPROVE WITH CONDITIONS, no new finding at Low or above) and its tests
are committed with this round.
