# Independent review — Paid252 composition, Addendum 2 (round 8, the A1-L1 fix, main merge)

**Range:** `8f751d0d368222c310e37d623eab1e8d5f0f2956..a2126d28966aedcf38ffb99a47825a1b3fdc3e10`. The head was fetched from
`origin/harness/paid252-composition`, and the worktree is detached there.

**Commits:**
- `e5c2e1dc`: round 8, unwritten spool bytes.
- `b574aa38`: the A1-L1 fix.
- `db315e12`: merge of main `72045620`, which brings in #57 (identity key rotation).
- `b43298f1`, `848cdebd` and `a2126d28`: docs and evidence.

`848cdebd` committed Addendum 1's files. I compared them with my untracked copies before checking out: 31 of 32 are byte-identical. The one that differs is `tests/frontend/paid-grant-review-addendum1.test.tsx`, where the integration owner turned the two `it.fails` cases into `it` and added the `performance.now()` control.

Nothing was committed or pushed, and no product code was changed.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.**
- A1-L1 is **resolved**, with residual A2-I1.
- Round 8 is sound against crafted and partial snapshots. It has one ordering nit, A2-I2, that is absorbed by the 16 MiB reserve.
- The merge with main changes no paid file.
- Condition C8 (A1-L2, slot hold time) and conditions C2 and C4–C7 from the original review stay open.

## (a) Does the 80 s rule close both A1-L1 paths?

**Yes.** In `b574aa38`:
- every `execute()` stores `sentAt.current = performance.now()`, including each retry;
- `savedStatus()` captures `issuedAt` when it issues the read;
- the gate requires `statusAfter.issuedAt - sentAt.current >= paidRequestTimeouts.authorize` (`PaidGrantJourney.tsx:155`).

How each path closes:
- **Path (i), a lost retry:** the retry re-stamps `sentAt` after the read, so the difference goes negative and the gate closes.
- **Path (ii), a hidden tab or network error:** a read issued less than 80 s after the original send never qualifies.

Both reproductions now run as `it` with a controlled clock and pass.

**Can `sentAt` be stale or reset?**
- It is one ref per mounted component, written only in `execute()`. `execute()` is reachable only for the single current `pending` (finalize and authorize are refused while one exists).
- After a set-aside, the next authorize re-stamps it. A `call()` that returns early because of `inflight` or `denied` still re-stamps it, which is the conservative direction.
- `pagehide` and unmount drop `pending`, so no older stamp can be paired with a new request.
- `performance.now()` is monotonic. A wall-clock change cannot make the rule pass early; at worst a suspended device delays it.

**A2-I1 (Info, residual).** The 80 s window is measured from the *client send*. It covers the server's 60 s budget, the 5 s lock wait and a 15 s margin only once the request has been admitted. A request held more than about 15 s in front of PHP (a proxy or PHP-FPM listen backlog after the client gave up) can still start, and commit, after a qualifying read.
- Impact is unchanged from A1-L1: one orphan authorization, no attempt consumed, no token shown.
- Owner note for mount: keep the proxy and FPM queue timeouts below that margin.

## (b) Is round 8's subtraction safe?

**It is safe against crafted and partial snapshots.** `pending()` (`PaidGrantPrepareStream.php:273-299`) subtracts the snapshot's current size only when `lstat` shows a regular single-link file no larger than the reservation. Otherwise it counts the full reservation.
- A symlink, a hard link or an oversized file therefore cannot shrink the pending amount.
- Before the holder creates its snapshot, and between unlink and release, the amount counted is the full reservation, which is conservative.
- Forging a sparse or replaced file needs write access to the 0700 spool as the same user, which is outside the threat model.

My cases (`tests/Feature/PaidGrantSpoolReviewAddendum2Test.php`, each with a control):
- a held snapshot that is hard-linked (nlink 2), or grown past its reservation, counts its full reservation and the nested admission refuses;
- the oversized holder also refuses its own seal.

**A2-I2 (Info): probe order.** `admit()` reads `freeBytes()` (`PaidGrantPrepareStream.php:210`) before `pending()` reads the other slots' written sizes (`:211`).
- Bytes another holder writes between the two probes are subtracted from its pending amount but are not yet in the free-space figure. The admission therefore over-counts free space by exactly those bytes.
- My second case reproduces this deterministically: the control refuses, and adding 400 bytes written after the probe makes the same admission pass with 499 bytes too little.
- Real exposure is the write rate over a sub-millisecond window, far inside `RESERVE_BYTES` (16 MiB).
- **Suggested fix:** take the size probe before `freeBytes()`, which makes any concurrent progress conservative.
- Free256's `ProductionFreeGrantSpool` subtracts full reservations, so it does not have this ordering exposure.

## (c) Does the merge with main change the paid paths?

**No paid file changed.**
- `git diff db315e12^1 db315e12` touches nothing under `app/Domain/Grants`, `resources/js`, `routes`, `config`, the paid tests or `tests/Support` except the new rotation race worker.
- `git diff db315e12^2 db315e12 -- app config routes` contains only the paid branch's own additions (insertions only), so the merge resolved without edits to shared files.

**Paid behaviour changes indirectly through identity code (#57, reviewed there).**
- `ProductionCustomerAccess` now verifies stored owner, credential and recipient digests with `IdentityPolicy::matches()` under the current key or any `app.previous_keys` entry. This is a strict widening to configured keys only.
- `sameOwner()` compares no key-derived value, and the paid records decrypt through Laravel's encrypter, so key rotation does not lock out retained paid orders.
- The census union adds only identity race methods.
- The original review's **L3** (an idempotent authorize replay recomputes its token with the current `app.key`, so a live replay after rotation returns 503) is now operationally reachable. It still fails safe: `redeem` compares hashes and is unaffected.
- The coordinator's merged-tree family run (90 tests, rc 0, `hardening/review-a1-l1/evidence/family-merged-db315e12.txt`) was **not** repeated here.

## Runs (`review-evidence/addendum2/a2126d28/`, head `a2126d28`)

| Run | Result | rc | File |
| --- | --- | --- | --- |
| PHP 8.4.26, SQLite, `--filter PaidGrantSpool tests/Feature`: `PaidGrantSpoolReservationTest`, `…ReviewAddendum1Test` and the untracked `…ReviewAddendum2Test` | 9/9 (4 + 3 + 2), 34 assertions | 0 | `sqlite-spool.{txt,xml}` |
| `npx vitest run tests/frontend/paid-grant-journey.test.tsx tests/frontend/paid-grant-review-addendum1.test.tsx` (vitest 5.0.1, Node 24.21.0) | 2 files, 23/23 | 0 | `frontend-vitest.txt` |
| `npx tsc --noEmit` | clean | 0 | `tsc.txt` |
| `pint --test tests/Feature/PaidGrantSpoolReviewAddendum2Test.php` | passed | 0 | `pint.txt` |

`node_modules` was symlinked for these runs only and then removed. `public/build` is absent.

**Not tested:**
- the paid family on the merged tree (the coordinator's run is cited instead);
- native MySQL;
- a multi-process spool run or a real filesystem, since `freeBytes` is stubbed;
- a browser.

**Untracked files added:**
- this file;
- `review-evidence/addendum2/`;
- `tests/Feature/PaidGrantSpoolReviewAddendum2Test.php`.
