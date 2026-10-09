# Independent review — Paid252 composition, Addendum 4 (round 10: retry a refused download)

**Range:** `92b5cab2..dc5b5c0e870fd9f9115f3199a623abc8ef02f37e`. The head was fetched from `origin/harness/paid252-composition`.

| Commit | Content |
| --- | --- |
| `4cb35010` | Client-only: `PaidGrantJourney.tsx`, its test and `CHANGELOG.md` |
| `dc5b5c0e` | Docs (`hardening/codex-10/`) |

`3f7d295c..92b5cab2` committed my Addendum 3 files. They match my copies byte for byte, and no product file changed in that span.

Nothing was committed or pushed, and no product code was modified.

## Verdict

**APPROVE WITH CONDITIONS (unchanged)** for a development merge. The page is still unmounted and default-off, and the server guarantees are intact: no double redemption and no leak.

There is one new Low finding, **A4-L1**: the retry is offered while the original submission may still be in flight. Its fix is a condition before mount.

## (a) Can the kept token reach the DOM or survive a denial?

**No.**

- `kept` is a ref and is never rendered. The retry banner shows only the filename and expiry (`PaidGrantJourney.tsx:215`).
- The token appears only in the transient hidden form input during submission, the same as the existing download path. `finally` blanks the value and removes the form (`:172-186`).
- Both `refuse()` (`:95`) and `pagehide` (`:98`) drop `kept`. `keptUnused` also requires `!denied` (`:187`).
- A hidden tab keeps `kept` in memory, just as it keeps `pending`.
- A redeem refusal does not say why it was refused, so it calls `clear()` and not `refuse()`, and `kept` survives (`:179`). The only way to get the retry button back is a later successful status read, which requires current ownership.
- If a different account signs in, its status cannot list the old authorization id, so no retry is offered.

My case: a 403 status read after submission removes the retry path, the token never appears in the DOM, and the page is denied.

## (b) Can a stale status read, taken before the submission, offer the retry?

**A read taken before the submission cannot.**
- `submit()` clears `status` at submission (`:172`).
- A read still in flight at that moment would keep `busy` set, and the download button is disabled while `busy`.

**A4-L1 (Low, new): a read taken after the submission but before the server finishes it does offer the retry.**

1. The redeem POST runs in an iframe outside `call()`, so `busy` stays false. "Refresh preparation and download status" stays enabled (`:206`).
2. Redeem spends most of its time before committing: two frames plus the snapshot, up to the 60 s budget, and about 15–40 s natively on MySQL (original review, M2).
3. A status read during that window lists the authorization as `unused`, and the retry appears.
4. The customer has seen nothing yet and is likely to click it.

Reproduced by `it.fails` in `tests/frontend/paid-grant-review-addendum4.test.tsx:59`. The probe confirms it fails at `expect(retry()).not.toBeInTheDocument()` (line 67).

## (c) Can a retry double-redeem or leak?

**It cannot double-redeem.** The server refuses a second redemption at several points:
- `live()` returns 409 once a redemption exists;
- the unique index `paid_redemptions.authorization_once` blocks a second row;
- since round 9, the same buyer's second concurrent redeem is refused at admission (503), before any attempt is recorded.

**It does not leak.** No token or path reaches the DOM.

**But following the offered retry during A4-L1 can cost the customer an attempt.**
1. The retry's refusal (503 or 409) reaches its iframe.
2. The refusal handler calls `clear()`, which removes **every** frame (`:179`), including the original submission's frame that has not received its response.
3. In a browser, removing that frame aborts the original request before its headers arrive.
4. The server does not notice the abort until it writes output, so it still commits the redemption, and the attempt is consumed without the file.

My documenting case shows the original frame is disconnected after the retry's refusal.

Removing all frames on a refusal is older behaviour. It was already reachable since round 9 by authorizing and downloading again while a download is in flight. Round 10 makes it a path the UI explicitly offers.

**Condition C9 (before mount)**, one of:
1. Offer the retry only after that submission's own frame has reported its refusal, which proves the server finished it.
2. Offer it only on a status read issued at least the redeem budget plus the lock wait plus a margin (the A1-L1 rule) after the last submission.

In addition, a refusal should remove only its own frame.

## Runs (`review-evidence/addendum4/dc5b5c0e/`, head `dc5b5c0e`; vitest 5.0.1 on Node 24.21.0)

| Command | Result | rc | File |
| --- | --- | --- | --- |
| `npx vitest run tests/frontend/paid-grant-journey.test.tsx tests/frontend/paid-grant-review-addendum1.test.tsx tests/frontend/paid-grant-review-addendum4.test.tsx` | 3 files: 26 passed, 1 expected fail (17 + 7 + 2, plus A4-L1) | 0 | `frontend-vitest.txt` |
| Probe: copy of the Addendum 4 file with `it.fails` changed to `it`, deleted after the run | 1 failed at line 67, 2 passed | 1 | `frontend-addendum4-probe.txt` |
| `npx tsc --noEmit` | clean | 0 | `tsc.txt` |

`node_modules` was symlinked for these runs only and then removed.

**Not tested:**
- No browser run. Frame-abort semantics and the server continuing after a client abort are argued from platform behaviour, not observed.
- No PHP changed, so no PHP was run.

**Untracked files added:**
- this file;
- `review-evidence/addendum4/`;
- `tests/frontend/paid-grant-review-addendum4.test.tsx`.
