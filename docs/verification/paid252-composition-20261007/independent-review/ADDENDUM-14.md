# Independent review: Paid252 composition, Addendum 14 (round 22: reopen a pending authorize's own order)

**Range:** `94d32518..400e501280f05a79a32a44db959a6b33751464ed`, fetched from `origin/harness/paid252-composition`; the
worktree is detached at `400e5012`.

| Commit | Content |
| --- | --- |
| `2f0d7ca2` | Commits my Addendum 13 record (byte-identical to my 13 untracked copies) |
| `400e5012` | Round 22 (Codex 4224824643): a "Reopen this order" button on the pending banner in `resources/js/components/PaidGrantJourney.tsx`, the new `tests/frontend/paid-grant-reopen-pending.test.tsx` (3), one CHANGELOG sentence and `hardening/codex-22/` |

**No PHP changed.** `git diff --stat 94d32518 400e5012 -- app config database routes` is empty (`no-php-change.txt`).
Outside `docs/verification/`, only `CHANGELOG.md`, the component and the new test changed.

I wrote none of the code under review. Nothing was committed or pushed, and no product code was modified.
**Reviewer:** Claude (independent review lane). **Date:** 2026-10-08.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.** There is no finding at Low or above, and the conditions
are unchanged (§3).

**The change.** The button renders only while a pending authorize exists (`pending.origin` is set only for authorize, not
for finalize), the page is not denied, and the shown order is not that request's own order. It calls the existing
`open(o.id, o.orderId)` with the pending request's own retained ids, and is disabled while `busy`.

## 1. Adversarial questions

| Question | Finding |
| --- | --- |
| Can reopening a different order while an authorize is pending lose or duplicate the pending replay? | **No.** `open()` is a GET read through `call('read', …)`. It never touches `pending`, `sentAt` or `statusAfter`, and sends no POST. Probe A: reopening sends exactly one `GET /paid-grants/origins/{batch}`, and the later exact retry sends a body identical to the original (same `requestKey`, `nonce`, `originHash`, `kind`). The request key and nonce never appear in the DOM. The only way a read drops the replay is a denial (403/404/419 → `refuse()`), which is the same for every read (probe D). |
| Can it bypass `reviewedSaved` or the 80 s set-aside rule? | **No.** `open()` sets neither `reviewedSaved` nor `statusAfter`. Set-aside still needs a saved status read (`savedStatus()`) for that order, issued at least `paidRequestTimeouts.authorize` (80 s) after the last send, that shows no live authorization of the kind. Probe A: after a reopen, a saved read issued 79 s after the send leaves "Set aside" disabled. The new test shows the positive path at 80 s and later. "Retry the exact request" still needs `reviewedSaved` from an index or status read. |
| Can it show the wrong order's data? | **No.** `open()` requires `validPaidOrigin(x.origin)`, `x.origin.id === id` and `x.origin.orderId === orderId`, using ids taken from the pending request's own retained origin. Probe B: an answer whose `orderId` differs is rejected with "could not be confirmed". No order is shown, and the replay and the button remain. A different account's order cannot be reached: the server refuses it with 404, which is a denial (probe D). |
| Do the hidden-tab path and busy gating still hold? | **Yes.** A hidden tab still runs `clear(true, true)`: the pending replay and frames are kept and the shown order is cleared, which is exactly the state where the button appears (the new test's first case). The button is `disabled={busy}`. Probe C: while the reopen read is in flight, both the button and "Retry the exact request" are disabled, and once the order is shown the button disappears. `authorize()` and the order-reference form still refuse while a request is pending. |
| Is a pending finalize correctly given no button? | **Yes.** A finalize `Pending` has `orderId` but no `origin` (no batch exists yet), so there is nothing to open. Its idempotent exact retry is what returns and shows the order. An uncertain finalize is never set aside (A1). The new test's third case covers this. |

**A14-I1 (Info).** The button rebuilds nothing from the URL or the index. It uses the ids captured when the authorize was
issued, from a server-validated projection, so an order older than the 20-row index is reachable again without
re-enabling the reference form. The retained `pending.origin` snapshot can be older than the server's current
projection. That is pre-existing: the authorize success path already re-shows `p.origin`, and `open()` shows the current
projection.

## 2. Runs (`review-evidence/addendum14/400e5012/`)

Node 24.21.0 and vitest 5.0.1. `node_modules` was symlinked to `/home/user/VA-Studio/node_modules` for these runs only and
then removed (`unlink`).

| # | Command | Result | rc | Raw output |
| --- | --- | --- | --- | --- |
| 1 | `git diff --stat 94d32518 400e5012 -- app config database routes` | empty: no PHP, config, database or route change | 0 | `no-php-change.txt` |
| 2 | `npx vitest run` on all 13 tracked `tests/frontend/paid-grant-*.test.tsx` | 13 files, 71/71 | 0 | `frontend-vitest.txt` |
| 3 | Untracked probe `tests/frontend/paid-grant-review-addendum14.test.tsx`:<br>• A: one GET, identical replay, no set-aside under 80 s.<br>• B: a mismatched `orderId` is rejected.<br>• C: busy gating.<br>• D: a 404 is a denial. | 4/4 | 0 | `frontend-review-addendum14.txt` |
| 4 | `npx tsc --noEmit`, without and with the probe | clean | 0, 0 | `tsc.txt`, `tsc-with-probe.txt` |

**Not recorded as a run.** My first probe run failed on two fixture errors in my own helper:
- the index button reads "Refresh paid licenses" once data is loaded;
- the pending order's first open consumed the case's reopen answer.

I fixed the helper before the recorded run.

**PHP not rerun.** No PHP changed since Addendum 13, whose paid family run at `94d32518` (133 tests, 1 census skip) still
holds. Pint and the census are likewise unchanged.

## 3. Conditions

| ID | Status |
| --- | --- |
| C1, C3, C9, C10 | Resolved or met (unchanged). |
| C2 | Open, as extended in Addenda 9, 11 and 12. |
| C4–C8 | Open, unchanged. |
| C11 | Met as a document (items (a)–(j)). Open only for the pre-mount verification. |
| C12, C13 | Met. |

## Not tested

A real browser.

## Files added by this review (untracked; nothing committed)

- This file.
- `review-evidence/addendum14/400e5012/`.
- `tests/frontend/paid-grant-review-addendum14.test.tsx`: four documenting cases, all passing against current behaviour.

**Worktree housekeeping.** My untracked Addendum 13 copies were byte-identical to `2f0d7ca2` and were moved, not deleted,
to `/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/rv56a14-parked-addendum13/`.
