# Independent review: Paid252 composition, Addendum 7 (round 12: frames across hide, replay across refusal)

**Range:** `743efa5a..2c1b4f8ad1d47c8b586271dbdda3b1c3fce9c2b6`, fetched from `origin/harness/paid252-composition`.

| Commit | Content |
| --- | --- |
| `a7e8c496` | Client-only change to `PaidGrantJourney.tsx` and its test |
| `2c1b4f8a` | Docs |

No PHP changed in this range. The Addendum 6 files committed in `5e99f610..743efa5a` are byte-identical to my copies. Nothing was committed or pushed, and no product code was modified.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.**

- Both changes are sound, and both resolve real interruptions.
- **New finding A7-L1 (Low):** frame-cap eviction of an unresolved download. It existed before this round (A5-I1), but round 11 made it easy to reach.
- **New condition C10 (before mount):** fix A7-L1.

## (a) Does keeping frames while hidden weaken privacy-on-hide?

**No.**

- The frames are `hidden`, same-origin and `no-referrer`.
- They hold either an attachment, which the browser hands to its download manager, or the sealed refusal JSON. Neither contains a private field.
- The token was only ever in the form input, which is blanked and removed after submission.
- Everything displayed is still cleared on hide.
- My case confirms that after a hide, the DOM contains no token, terms, declared name, origin hash or filename, and that `pagehide` removes the frame.

**This is also a fix.** Before this round, a hide removed every frame. That aborted an in-flight download while the server could still commit its redemption, which is the A4-L1 consequence reached by another path.

**Residual (A7-I1, Info).** A hide increments `generation`, so a refusal that arrives while the tab is hidden is ignored. No retry is offered, and the frame stays until the cap, `pagehide`, a denial or unmount removes it. This is the conservative case already accepted in Addendum 5 (c).

## (b) Can a pending replay kept across a refusal outlive a real denial?

**Not in any way that has an effect.**

- After the refusal, `clear(true, true)` resets `reviewedSaved` and `statusAfter`. The kept replay therefore stays inert:
  - "Retry the exact request" needs a successful read;
  - set-aside needs a post-request status read plus the 80 s rule.
- If access really changed, the next read returns 403 and `refuse()` drops the replay.
- If a different account signed in through another tab, its successful read can enable the retry, but the replay then gets a 404 from the server's ownership check, and `refuse()` runs. This is the same outcome as round 7 (A1-I3).
- The banner text is generic.

My case confirms:
- the replay survives a download refusal with both buttons disabled;
- a 403 read removes it.

This resolves A5-I2.

## (c) The frame cap of 3 with longer-lived frames: **A7-L1 (Low)**

The cap removes the oldest frame on a fourth submission without checking whether that submission has reported back (`PaidGrantJourney.tsx:179`). An attachment download usually never fires `load`, so a completed download and an unresolved one look the same.

Frames now persist through hides, ignored refusals and completed attachments. Since round 11, every issued authorization has its own Download button, so four quick clicks are a realistic sequence.

Here is how the oldest submission can be lost:
1. The first submission holds the buyer's spool slot.
2. The next submissions are refused only after the locate and first frames, which takes seconds and tens of seconds on MySQL.
3. A fourth click inside that window evicts the first frame before its headers arrive.
4. The browser aborts that request, but the server may still commit its redemption. The attempt is consumed without the file.

This is reproduced by `it.fails` in `tests/frontend/paid-grant-review-addendum7.test.tsx`. The probe fails at line 102, where the first frame has been disconnected.

**C10 fix options (pick one):**
- Never evict.
- Evict only frames whose refusal was observed.
- Disable Download while a submission is younger than the redeem budget.

Frames are tiny and are already cleared on `pagehide`, denial and unmount, so dropping the cap entirely is the simplest of these.

## Runs (`review-evidence/addendum7/2c1b4f8a/`, head `2c1b4f8a`, vitest 5.0.1, Node 24.21.0)

| Command | Result | rc |
| --- | --- | --- |
| `npx vitest run tests/frontend/paid-grant-journey.test.tsx tests/frontend/paid-grant-review-addendum1.test.tsx tests/frontend/paid-grant-review-addendum4.test.tsx tests/frontend/paid-grant-review-addendum5.test.tsx tests/frontend/paid-grant-review-addendum6.test.tsx tests/frontend/paid-grant-review-addendum7.test.tsx` | 6 files, 39 passed and 1 expected fail (A7-L1) | 0 |
| Probe: the Addendum 7 file with `it.fails` changed to `it`, deleted afterwards | 1 failed at line 102, 2 passed | 1 |
| `npx tsc --noEmit` | clean | 0 |

`node_modules` was symlinked for these runs only and then removed. Not tested: a real browser, so the frame-abort behaviour is argued from platform semantics, not observed.

**Untracked files added:** this file, `review-evidence/addendum7/`, and `tests/frontend/paid-grant-review-addendum7.test.tsx`.
