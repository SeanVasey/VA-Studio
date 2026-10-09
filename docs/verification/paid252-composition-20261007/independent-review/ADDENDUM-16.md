# Independent review: Paid252 composition, Addendum 16 (round 24: reopen an issued authorization's own order)

**Range:** `8fc077f24136e865975187e7056ade91b4debec3..915ca2c9217d273ee1afa23e0486e084cae7c475` (one commit). The
worktree `/home/user/rv56-a16` is detached at `915ca2c`.

| Commit | Content |
| --- | --- |
| `915ca2c` | Round 24 (Codex P2 4228014577), all in `resources/js/components/PaidGrantJourney.tsx`:<br>• Each issued entry keeps `{ auth, originId, orderId }`, with both ids taken from the request's own `p.origin`.<br>• A new derived list, `unshownIssued`: one entry per order that holds an issued, unsubmitted authorization and is not the shown order. It is empty after a denial.<br>• One "Reopen order {orderId} to download its authorized file" button per such order. It calls the existing `open(originId, orderId)` and is `disabled={busy}`.<br>Also: the new `tests/frontend/paid-grant-reopen-issued.test.tsx` (2 tests), one CHANGELOG sentence and `hardening/codex-24/`. |

**No PHP changed.** `git diff --stat 8fc077f 915ca2c -- app config database routes bootstrap` is empty
(`no-php-change.txt`). Outside `docs/verification/`, only `CHANGELOG.md`, the component and the new test changed.

I wrote none of the code under review. Nothing was committed or pushed, no product code was modified, and the main
checkout `/home/user/VA-Studio` was used read-only (its `node_modules` only, through a symlink).
**Reviewer:** Claude (independent review lane). **Date:** 2026-10-09.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge of `915ca2c`.**

- The fix is correct and narrow. The control is render-only: it calls the existing `open()`, a GET whose answer must match
  both retained ids. It never touches `pending`, `reviewedSaved`, `statusAfter`, `sentAt`, `kept` or the frames.
- The author's red, green, `tsc` and mutation M1 reproduce in my worktree. I ran four more mutations: three are killed
  only by my probe, and one is equivalent (§3).
- **One Low finding (A16-L1)** is outside the changed lines. A download refused and kept for retry has the same "no way
  back" gap that round 24 closes for issued authorizations. It is pre-existing (round 10), a recovery gap with no
  security effect, and does not block a development merge.
- Three Info items (§4).
- The conditions are unchanged (§5).

## 1. Adversarial questions

| Question | Finding |
| --- | --- |
| Can the control show the wrong order, or another account's order? | **No.** Both ids are captured in the authorize success path from `p.origin`. That is the `origin` state when Authorize was clicked, which was itself accepted only through `validPaidOrigin` plus an id/order match (`open`, finalize, the authorize path or the continuation).<br>`open()` accepts an answer only if `validPaidOrigin(x.origin)`, `x.origin.id === id` and `x.origin.orderId === orderId`.<br>Probe C: an answer with a foreign `orderId`, and one with a different batch id, are each rejected with "could not be confirmed". No order is shown, no Download button appears, and the control stays. A 404 (the server's answer for another account's batch) is a denial: the page is refused, and the control and every issued entry are dropped. All three requests are plain GETs to `/paid-grants/origins/{X}`. |
| Does it leak the token, request key or nonce into the DOM? | **No.** The button text carries only the order id, which the page already shows as "Order {orderId}". The React key (`originId`) is not rendered.<br>Probes A–D and H check `document.documentElement.outerHTML` for every issued token. Probe D also checks the pending authorize's `requestKey` and `nonce`, captured from the fetch body. None appears. |
| Does it disturb pending authorize replay, `reviewedSaved` or the 80 s set-aside rule? | **No.** `open()` was already shown (Addendum 14, probe A) to send one GET and leave the replay, `reviewedSaved` and `statusAfter` unchanged. Round 24 adds no state transition.<br>Probe D:<br>• An issued authorization on X (older than the index) is hidden, and an authorize on index-listed Z is lost and pending.<br>• The reference form and its submit are disabled, and the index omits X.<br>• The new control sends exactly `GET /paid-grants/origins/X` and shows X. "Reopen this order" (for Z) appears, and X's Download submits.<br>• After an index read, "Retry the exact request" sends a body identical to the original (same `requestKey`, `nonce`, `originHash`, `kind`).<br>The set-aside rule is untouched: `abandonable` still needs `status.originId === pending.origin.id`, so a status read on X cannot qualify Z's set-aside. |
| Does busy gating hold, and does a hidden tab during the reopen read keep the issued entries? | **Yes.** Probe F: while a reopen read is in flight, both reopen buttons are disabled. A hidden tab during the read aborts it (`skipped`) and `clear(true, true)` keeps `issued`, so both buttons return enabled and a later reopen shows the Download button. Mutation M5 (`disabled={false}`) is killed by probe F only. |
| Does it interfere with refusal/kept retry or with denial? | **No.** `submit()` removes the entry from `issued` before building the frame, so a submitted authorization has no control (the author's second test). The kept-refused list (`kept`, `retryable`) is not read or written by the new code.<br>`refuse()` runs `setIssued([])`, and `call()` returns `skipped` while `denied`, so `issued` cannot refill after a denial. The `denied ? [] :` guard is therefore redundant defence: mutation M4 removes it and nothing observable changes (§3). |
| Duplicates: one order with two issued authorizations; two orders; the shown order. | **As intended.**<br>• Probe A: master_wav and contract issued on X, then hidden, give exactly one button. Reopening restores both Download buttons and removes the control.<br>• Probe B: issued on X and Y give two buttons, in issuance order. Reopening one leaves only the other's button. The shown order never has one.<br>Mutation M2 (no de-duplication) is killed by probe A only. The author's tests do not cover duplicates (A16-I2). |
| Is the Codex finding real as stated? | **Real, and narrower than stated when nothing is pending, as the author says. Fully real while another authorize is pending.**<br>Probe E passes on both `8fc077f` and `915ca2c`: with nothing pending, the reference form is enabled, and typing X's reference reopens X with its Download button. That route is an idempotent finalize POST and needs the buyer to have kept the reference.<br>Probe D shows the case the author's README does not mention. While another order's authorize is pending, the form is disabled and the index omits X, so on `8fc077f` nothing on the page led back to X until the pending request was retried or set aside (at least 80 s for a set-aside).<br>On `8fc077f`, probe D fails exactly at the new control (`probe-on-8fc077f-caseD-line.txt`, line 161), after its form-disabled and index-omits assertions passed. |

## 2. A16-L1 (Low): a kept, refused download has the same gap

**Where.** `PaidGrantJourney.tsx`, frame `load` handler, lines 282–283, and `retryable`, lines 295–296. These lines are
unchanged in this range.

**What happens.**
1. When a download's frame reports `PAID_GRANT_UNAVAILABLE`, the authorization moves into `kept` as refused. Its entry
   left `issued` at submit.
2. If no later request has started, the page runs `clear(true, true)`, and a hidden tab does the same later.
3. `kept` holds only the authorization. It has no order id and no batch id.
4. `retryable` needs the shown order plus a saved status read of that order.

So for an order older than the 20-row index, the page offers no control, and after the clear it does not even display
the order id. The refusal message tells the buyer to "Refresh saved licenses", but the index does not list the order.

**Probe H** (passes on both `8fc077f` and `915ca2c`):
- After a refusal, nothing names or reopens X.
- The index omits X.
- `document.body.textContent` does not contain X's order id.
- Only typing X's reference, then a saved status read, brings back "Retry the authorized download".

While another authorize is pending, the form is disabled (probe D), so that route is closed too until the pending
request is resolved.

**Severity.** The same class as Codex 4228014577 (which Codex rated P2), on the path the author's README lists as
unchanged. It is Low here:
- the server refuses a spent or expired token, so there is no security or double-redeem effect;
- the buyer can recover through the reference form when nothing is pending.

The consequence is an unused authorization that may expire. It does not block a development merge.

**Recommended fix (optional, a follow-up round).** Keep `originId` and `orderId` on `kept` entries too, and include
refused kept entries whose order is not shown in the reopen list. For example, derive the list from `issued` plus
`kept.current.filter(m => m.refused)`. Probe H would then need its "no control" assertion inverted.

## 3. Runs (`review-evidence/addendum16/915ca2c/`)

Node 24.21.0 and vitest 5.0.1. `node_modules` was symlinked to `/home/user/VA-Studio/node_modules` for these runs only and
then removed (`unlink`). For every pre-fix or mutation run, the component was restored with `git checkout` and checked
clean with `git diff --exit-code` / `--quiet` (rc 0, recorded in each file).

| # | Source | Command | Result | rc | Raw output |
| --- | --- | --- | --- | --- | --- |
| 1 | `915ca2c` | `git diff --stat 8fc077f 915ca2c -- app config database routes bootstrap`, plus all paths, plus names outside `docs/verification` | paths empty. 8 files in all; outside docs only CHANGELOG, the component and the test | 0 | `no-php-change.txt` |
| 2 | component at `8fc077f` + new test file | `npx vitest run tests/frontend/paid-grant-reopen-issued.test.tsx` | **2 failed (2)**: no reopen control. Matches the author's red (same test sha256 `c38a8ce3bed08f22`). | 1 | `red-on-8fc077f.txt` |
| 3 | `915ca2c`, clean tracked tree, probe moved out | the same file | 2/2 | 0 | `green-reopen-issued.txt` |
| 4 | `915ca2c`, clean tracked tree, probe moved out | `npx vitest run` | **65 files, 1,128/1,128**. Matches the author's count. | 0 | `frontend-all.txt` |
| 5 | `915ca2c` | `npx tsc --noEmit`, without and with the probe (`tsconfig` includes `tests/frontend`) | clean | 0, 0 | `tsc.txt`, `tsc-with-probe.txt` |
| 6 | `915ca2c` + untracked probe | `npx vitest run --reporter=verbose tests/frontend/paid-grant-review-addendum16.test.tsx` (A–H) | **8/8** | 0 | `frontend-review-addendum16.txt` |
| 7 | component at `8fc077f` + probe | the same | 6 failed, 2 passed. E (the narrower claim) and H (A16-L1) pass, as documenting cases should. A, B, C, D, F and G fail on the missing control. | 1 | `probe-on-8fc077f.txt` |
| 8 | component at `8fc077f` + probe | `-t 'D: while another order'` | D fails at line 161, the first use of the new control, after its pending-state assertions passed | 1 | `probe-on-8fc077f-caseD-line.txt` |
| 9 | `915ca2c` + M1 (`orderId = ''`, the author's) | author's file + probe | 8 failed, 2 passed (E and H, which do not use the control) | 1 | `mutation-M1.txt` |
| 10 | `915ca2c` + M2 (no de-duplication) | author's file + probe | 1 failed: probe A only | 1 | `mutation-M2.txt` |
| 11 | `915ca2c` + M3 (the shown order also gets a button) | author's file + probe | 4 failed: the author's first test and probes A, B and D | 1 | `mutation-M3.txt` |
| 12 | `915ca2c` + M4 (no `denied` guard) | author's file + probe | **10/10 passed: an equivalent mutation**, because `refuse()` empties `issued` and `call()` skips while denied | 0 (expected) | `mutation-M4.txt` |
| 13 | `915ca2c` + M5 (never disabled) | author's file + probe | 1 failed: probe F only | 1 | `mutation-M5.txt` |

Mutations are applied by `mutate.py.txt` (run with `python3 -I`; each replacement is asserted to match exactly once). The
probe source is kept as `paid-grant-review-addendum16.test.tsx.txt` (sha256 `527018d6a2c58e1a`, identical to the untracked
test file).

**Not recorded as a run.** My first probe run (8/8, rc 0, at `915ca2c`) went to the terminal before I redirected output to
a file. Row 6 is the recorded rerun of the same probe.

**PHP not rerun.** No PHP changed since Addendum 15. Its runs at `27a151b6` and `b99b5dd` still hold for the server side.

## 4. Info

| ID | Finding |
| --- | --- |
| A16-I1 | **Redundant controls.** When a pending authorize and an issued authorization share an unshown order X, both "Reopen this order" and "Reopen order X to download its authorized file" appear, and both perform the same GET (probe G). This is harmless. Optionally, suppress the second while `pending.origin?.id === X`. |
| A16-I2 | **Test coverage.** The author's two tests do not cover de-duplication (M2), busy gating (M5), a mismatched reopen answer, or the pending-authorize case where the control is the only way back. My probe covers each one. Optionally, adopt probes A, D and F into the tracked file. |
| A16-I3 | **Pre-existing behaviours, unchanged.**<br>• **Focus.** The clicked control unmounts once its order is shown, so focus falls to the document body. "Reopen this order" behaves the same way, and A12-I4 handled the analogous Stop button by moving focus to the progress line. Consider focusing the order heading (WCAG 2.4.3).<br>• **Expired tokens.** `issued` entries are never pruned by `expiresAt`, so the control, and the Download button it restores, can outlive the token. The server refuses an expired token. |

## 5. Conditions

| ID | Status |
| --- | --- |
| C1, C3, C9, C10 | Resolved or met (unchanged). |
| C2 | Open, as extended in Addenda 9, 11 and 12. |
| C4–C8 | Open, unchanged. |
| C11 | Met as a document for items (a)–(j). Open for the pre-mount host verification, including (k) with the A15-L1 corrections (unchanged). Codex thread `PRRT_kwDOU5febs6qfw0X` stays deliberately open under C11. I did not query GitHub; this is its state as carried from Addendum 15. |
| C12, C13 | Met. |

**What changes:** nothing in the conditions. A16-L1 is a recommendation for a later round, not a new condition.

## Not tested

- A real browser, including real focus behaviour and real iframe downloads.
- The server side of `GET /paid-grants/origins/{id}` for another account. Its 404 was established in earlier addenda and
  is mocked here.
- PHP, which did not change in this range.

## Files added by this review (untracked; nothing committed)

- This file.
- `review-evidence/addendum16/915ca2c/`: the raw outputs above, `mutate.py.txt` and the probe source.
- `tests/frontend/paid-grant-review-addendum16.test.tsx`: eight cases (A–H), all passing against `915ca2c`. E and H are
  documenting cases that also pass on `8fc077f`.
