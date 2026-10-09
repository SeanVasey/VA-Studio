# Paid252 Codex round 12: what a hidden tab and a refusal must keep

Development evidence from the Claude Code harness for PR #56, code commit `a7e8c496`. Not Foundation or final acceptance.
This is a client-only change to `resources/js/components/PaidGrantJourney.tsx`.

## Findings (two Codex P2s)

1. **`PaidGrantJourney.tsx:103`, frames dropped on hide.** Hiding the tab called `clear(true)`, which removed every hidden
   download frame. Removing a frame aborts its request in the browser. The server may still commit that redemption,
   so switching tabs could consume a download attempt without delivering the file.
2. **`PaidGrantJourney.tsx:184`, replay dropped on refusal.** A download refusal called `clear(false, true)`, which dropped
   an unrelated uncertain authorize replay (its request key and nonce). That authorize may have committed. The
   independent review recorded the same issue as A5-I2.

## Fix

- **Hidden tab:** now `clear(true, true)`. It keeps the pending replay (as in round 7) and the hidden download frames.
  Everything shown is still cleared. `pagehide` still removes every frame and the replay.
- **Download refusal:** now `clear(true, true)`. It removes only its own frame (as in A4-L1) and keeps an unrelated
  pending replay. If access really changed, the next read gets a 403, and `refuse()` clears everything.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Vitest, red: five paid files, previous component | `743efa5a` component + tests | 2 failed, 35 passed, rc 1 (`frontend-red.txt`) |
| Vitest, green | `a7e8c496` | 37 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | `a7e8c496` | rc 0 (`tsc.txt`) |

**New tests.**
- **Frames survive a hidden tab:** a download frame stays connected while the tab is hidden, and the order view is
  cleared. After `pagehide` the frame is removed.
- **Replay survives a refusal:** a master authorize whose answer is lost keeps its exact-retry banner after an earlier
  contract download is refused.
