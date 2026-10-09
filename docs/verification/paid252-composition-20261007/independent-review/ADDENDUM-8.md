# Independent review: Paid252 composition, Addendum 8 (frames are never evicted)

**Range:** `2c1b4f8a..421374f2b3c79c1a58067693766b6e11810f26a5`, fetched from `origin/harness/paid252-composition`.

| Commit | Content |
| --- | --- |
| `99b7f5aa` | Removes the frame cap in `PaidGrantJourney.tsx` (client-only, one line) |
| `421374f2` | Commits the Addendum 7 record and test, with `it.fails` changed to `it` and the frame count changed to 4 |

No PHP changed. Nothing was committed or pushed by me, and no product code was modified.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.**

- **C10 is met:** A7-L1 is resolved.
- **Nothing new at Low or above.** Conditions C2 and C4–C7 from the original review stay open.

## C10

- The eviction is gone (`PaidGrantJourney.tsx`, `submit()`). The comment says frames are removed only on their own refusal, a denial, leaving the page, or unmount.
- The committed Addendum 7 case confirms this: four submissions in quick succession leave 4 frames, and the first one is still connected.

## Can frames now grow without bound or leak?

**Frames are bounded by the authorizations submitted on this page.**

- A frame is created only in `submit()`, once per submission (`:181`, `:189`).
- A submission either:
  - consumes one issued authorization, which `submit()` removes from `issued`; or
  - retries a kept authorization, which requires that authorization's own refusal to have been seen. That refusal already removed its frame (`:187`).
- So each authorization has at most one live frame at a time. The server issues at most 3 per line per 60 s.
- The frames that persist are completed attachments, refusals ignored by the generation guard, and submissions still in flight.
- `pagehide`, a denial (`clear()`, `:94`) and unmount (`:106`) remove all of them.

**There is no leak.** The frame contents are unchanged from Addendum 7 (a): hidden, same-origin, no-referrer, holding an attachment or the sealed refusal JSON. No token or private field is in them.

**A8-I1 (Info).** A very long-lived page accumulates one hidden iframe per completed download. Each costs negligible memory, and they are cleared when the page is left.

## Runs (`review-evidence/addendum8/421374f2/`, head `421374f2`, vitest 5.0.1, Node 24.21.0)

| Command | Result | rc |
| --- | --- | --- |
| `npx vitest run` on `tests/frontend/paid-grant-journey.test.tsx` and `paid-grant-review-addendum{1,4,5,6,7}.test.tsx` | 6 files, 40/40 | 0 |
| `npx tsc --noEmit` | clean | 0 |

`node_modules` was symlinked for these runs only and then removed. Not tested: a real browser.

**Untracked files added:** this file and `review-evidence/addendum8/`.
