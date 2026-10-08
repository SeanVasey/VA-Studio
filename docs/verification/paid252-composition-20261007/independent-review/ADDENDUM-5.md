# Independent review: Paid252 composition, Addendum 5 (A4-L1 fix)

**Range:** `dc5b5c0e..283e5c8f080b7752b6526591234642acf617b063`, fetched from `origin/harness/paid252-composition`.

| Commit | Content |
| --- | --- |
| `97801ddf` | Client-only fix to `PaidGrantJourney.tsx`, plus the committed Addendum 4 test |
| `283e5c8f` | Docs |

No PHP changed. The Addendum 4 files committed in this range are byte-identical to my copies, except the test file, which the integration owner updated as described. Nothing was committed or pushed, and no product code was modified.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.** A4-L1 is resolved and C9 is satisfied. No new Low or higher finding.

## (a) Does the gate close both A4-L1 paths?

**Yes.**

- **Retry offered while the submission is still in flight.** The retry is now offered only when the kept mark is `refused` (`PaidGrantJourney.tsx:189-190`). That flag is set only by the submission's own frame reporting `PAID_GRANT_UNAVAILABLE` (`:181`). A refusal body arrives only after the server has finished that request, so a retry can no longer race an original still being prepared.
- **Stale status read.** The read must also be taken after the refusal. Both `submit()` (`:174`) and the refusal's `clear(false, true)` reset `status`, so the retry needs a fresh read showing that exact id and kind as `unused`.
- **One mark at a time.** Each `submit()` replaces the mark. A retry therefore hides its own button until it refuses in turn, and a newer download makes an older refused one no longer retryable, which is conservative.
- **Removing other frames.** A refusal now removes only its own frame, so it no longer aborts another download that is waiting for its response.

My cases cover:
- a retry offered after the refusal and a fresh read, then hidden while that retry is in flight;
- a newer refused mark that does not resurrect an older authorization;
- the integration owner's regression test, in which a refusal removes only its own frame.

## (b) Can keeping the other frames leak anything or leave a stale frame that matters?

**No leak.**

- The frames that remain are hidden, same-origin, `no-referrer`, and hold either an attachment response or the sealed refusal JSON. The token was only ever in the form input, which is blanked and removed.
- `refuse()`, `pagehide` and a hidden tab (`clear(true)` with `keepFrames` false) still remove every frame.

**Stale frames (A5-I1, Info).** Frames now persist longer: completed attachments, as before, plus refusals the generation guard ignored.

- The pre-existing cap of 3 (`:175`) removes the oldest frame on a fourth submission.
- That could abort a download only if the oldest frame were still waiting for headers while three newer submissions had been made. Since round 9, the same buyer's newer submissions are refused quickly, so this is a corner case that is no worse than before this change.

**Pending replay (A5-I2, Info, pre-existing).** A download refusal still calls `clear()` without `keepPending`, so it also drops an unrelated uncertain authorize replay if one exists. This was true before this range as well.

## (c) Late refusals ignored by the generation guard

**This is conservative and acceptable.**

- `generation` increments on every `call()`, including the status read that the page's message tells the customer to take. A refusal that arrives after any later request has started is ignored: no `refused` flag, no message, and the frame stays until the page is cleared.
- The result is that no retry is offered and the customer authorizes again, which uses one of the 3-per-minute issuances. No attempt is consumed and nothing leaks.
- My case reproduces this: a read during the submission, then the refusal, leaves no retry even after a second read, and `pagehide` removes the frame.

**UX note:** a busy-spool refusal usually arrives within the redeem's first frames, so customers who refresh quickly will often see no retry. If that matters before mount, the load handler could record `refused` regardless of generation, while still refusing to touch the UI.

## Runs (`review-evidence/addendum5/283e5c8f/`, head `283e5c8f`, vitest 5.0.1 on Node 24.21.0)

| Command | Result | rc |
| --- | --- | --- |
| `npx vitest run tests/frontend/paid-grant-journey.test.tsx tests/frontend/paid-grant-review-addendum1.test.tsx tests/frontend/paid-grant-review-addendum4.test.tsx tests/frontend/paid-grant-review-addendum5.test.tsx` | 4 files, 30/30 (17 + 7 + 3 + 3) | 0 |
| `npx tsc --noEmit` | clean | 0 |

`node_modules` was symlinked for these runs only and then removed. Not tested: a real browser; frame-abort behaviour is reasoned from platform semantics.

**Untracked files added:** this file, `review-evidence/addendum5/`, and `tests/frontend/paid-grant-review-addendum5.test.tsx`.
