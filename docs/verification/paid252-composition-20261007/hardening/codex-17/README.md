# Paid252 Codex round 17: retry state is kept per submitted authorization

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. Client-only
change: no PHP, schema, guard or server behaviour changed.

## Finding (Codex P2, `PaidGrantJourney.tsx:178`, reviewed commit `d6ae43cf`)

`submit()` kept one `kept` ref. If a second issued file was submitted before the first frame answered:

- the second submission replaced the ref;
- the first token had already been removed from the issued list;
- a later pre-commit refusal of the first submission marked only its orphaned mark.

So no retry could be offered for the first authorization even when a saved read showed it unused, and the customer had
to mint another one. Independent review addendum 5 had pinned this as by-design behaviour.

## Fix

- `kept` is now a list of marks, one per submitted authorization. Resubmitting the same authorization replaces its own
  mark.
- Each frame's refusal marks its own submission.
- Every refused authorization that a saved read for the open order lists as `unused` with the same kind, and that is
  not already shown as issued, gets its own "Retry the authorized download" button.
- A denial or `pagehide` empties the list.
- The retry still resubmits that exact token. The server refuses a spent (409) or expired (410) token.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Vitest, journey file with the new test | `d6ae43cf` component | 1 failed (the new case), 22 passed, rc 1 (`frontend-red.txt`) |
| Vitest, the seven paid frontend files | `d6ae43cf` + this change | 46 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | same | rc 0 (`tsc.txt`) |

**New journey case.** Two files are authorized and both submitted before either frame answers. The earlier (contract)
frame is refused. A saved read lists the contract as `unused` and the master as `attempted`. Exactly one retry is
offered, for the contract, and it resubmits the contract token. No token is rendered.

**Addendum 5 test.** One test is retitled: its assertion (an unrefused authorization is not offered even when listed
unused) still holds, but it no longer describes replacement.

## Not tested

A real browser. Marks for downloads that completed are kept until the page is left. They are never offered, because
they are not refused, and their number is bounded by the issuance limit.
