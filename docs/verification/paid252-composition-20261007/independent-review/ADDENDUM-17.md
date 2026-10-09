# Independent review: Paid252 composition, Addendum 17 (the A16-L1 fix: a refused, kept download keeps its order)

**Range:** `915ca2c9217d273ee1afa23e0486e084cae7c475..e4f9997ec605572144b1ae92c49b29209b728564` (one commit), fetched from
`origin/harness/paid252-composition`. The worktree `/home/user/rv56-a16` is detached at `e4f9997`.

| Commit | Content |
| --- | --- |
| `e4f9997` | The A16-L1 fix, all in `resources/js/components/PaidGrantJourney.tsx`:<br>• Kept entries now hold `{ auth, refused, originId, orderId }`. `submit()` sets both ids from the shown `origin`, and now also returns early without one.<br>• The reopen list is built from `issued` plus refused `kept` entries. It still has one entry per order, excludes the shown order, and is empty after a denial.<br>Also: a third case in `tests/frontend/paid-grant-reopen-issued.test.tsx`, one CHANGELOG sentence, `hardening/review-a16-l1/`, and my Addendum 16 record. |

**No PHP changed.** `git diff --stat 915ca2c e4f9997 -- app config database routes bootstrap` is empty. Outside
`docs/verification/`, only `CHANGELOG.md`, the component and the test file changed (`no-php-change.txt`).

**My Addendum 16 record is committed byte-for-byte.** `ADDENDUM-16.md` and all 16 files under
`review-evidence/addendum16/915ca2c/` are `cmp`-identical to my untracked copies (`addendum16-identity.txt`). The
untracked probe `tests/frontend/paid-grant-review-addendum16.test.tsx` was not committed; only its `.txt` source was. I
parked my copies in the session scratchpad (`a16-parked/`) before the checkout.

I wrote none of the code under review. Nothing was committed or pushed, no product code was modified, and the main
checkout `/home/user/VA-Studio` was used read-only (its `node_modules` only, through a symlink).
**Reviewer:** Claude (independent review lane). **Date:** 2026-10-09.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge of `e4f9997`.**

- **A16-L1 is resolved.** After a refusal clears its order, a refused, kept download now offers "Reopen order … to download
  its authorized file". The retry is still gated exactly as before: it needs a saved status read of the shown order that
  lists that exact authorization, with the same kind, as unused.
- **The author's runs reproduce** in my worktree: the red, the green full suite and `tsc`. Four mutations of the new
  lines are each killed (§3).
- **No finding at Low or above.** There are two Info items (§4).

## 1. Adversarial questions

| Question | Finding |
| --- | --- |
| Can the kept ids name the wrong order? | **No.** `submit()` is reached only from two buttons, both inside `{origin && <section aria-label="Retained paid order">}` (`static-submit-callers.txt`):<br>• Download, for `shown` entries, whose `originId === origin.id`;<br>• Retry, through `retryDownload`, for `retryable` entries. These need `status.originId === origin.id`, and a history entry of that origin with the same id and kind.<br>So the authorization always belongs to the shown order whose ids are recorded. `open()` still rejects any answer whose batch id or order id differs (Addendum 16, probe C, rerun here). |
| A kept entry from order X while order Y is shown. | **Correct, and Y never gets a control** (probe I). An unanswered download gives no control.<br>When X's frame is refused after a later request (opening Y) has started, the refusal handler changes no state, by design: no clear and no message. So X's control appears on the next render, not at once (A17-I1). After that render, X has a control, Y has none, and no retry is offered on Y. |
| A refusal, then a denial. | **Everything is dropped.** `refuse()` empties `kept` and `issued` and sets `denied`. Probe J: after a refusal, X's control is enabled. A 403 on the index then removes the control, any retry and the reference form. No token is in the DOM. |
| Does the kept retry still need a saved status read showing that exact authorization unused? | **Yes. `retryable` is unchanged.** Probe H: reopening alone shows neither Retry nor Download, and one status read listing the authorization as unused brings Retry back, inside the Retained paid order section.<br>Probe K, after a reopen:<br>• no read: no Retry;<br>• `expired`, `attempted`, another id `unused`, and the same id under another kind `unused`: each read gives no Retry;<br>• the exact id and kind `unused`: Retry. |
| Can any token reach the DOM? | **No.** The control renders only the order id. Kept entries add two ids and no secret. Probes H–M check `outerHTML` for every token, and probes D (rerun) checks the pending request key and nonce. |
| Can the `!origin` early return in `submit()` drop a click that used to work? | **No.** Both callers render only while `origin` is set (above). Each `onClick` closes over the `submit` from the same render, whose `origin` is that render's non-null value. The new guard is therefore unreachable from the UI. It only makes the type sound for the ids it records. The 1,129-test suite passes, including every existing download and retry case. |
| A submitted but unanswered download, and a retried kept entry. | **Neither gives a control.** Only `refused` kept entries count. Probe M:<br>• An in-flight download followed by a hidden tab leaves no control.<br>• A retry resets the entry to `refused: false`, so a later hidden tab leaves no control.<br>Mutation K1, which counts every kept entry, is killed by probe M, probe I and the author's denial test. |
| Duplicates across issued and kept entries. | **One control per order.** Probe L: X1 refused and kept, plus X2 issued, gives one control. Reopening shows X2's Download and removes the control. The Addendum 16 cases A–G still pass unchanged. |

## 2. The Addendum 16 probe on `e4f9997`: the expected flip

I ran my unchanged Addendum 16 probe at `e4f9997` (`addendum16-probe-on-e4f9997.txt`):
- A–G pass: 7 of 8.
- **H fails at line 240, `expect(reopens()).toHaveLength(0)`, with 1 control found.** This is the intended change: H
  documented the A16-L1 gap.

The Addendum 17 probe (`tests/frontend/paid-grant-review-addendum17.test.tsx`) has three parts:
- A–G, unchanged;
- H, inverted to assert the fixed behaviour;
- new cases I–M.

## 3. Runs (`review-evidence/addendum17/e4f9997/`)

Node 24.21.0 and vitest 5.0.1. `node_modules` was symlinked to `/home/user/VA-Studio/node_modules` for these runs only and
then removed (`unlink`). Every run on the pre-fix component or under a mutation was followed by `git checkout` and
`git diff --quiet` (rc 0, recorded in each file).

| # | Source | Command | Result | rc | Raw output |
| --- | --- | --- | --- | --- | --- |
| 1 | `e4f9997` | `cmp` of the committed Addendum 16 against my copies | 17 of 17 identical | 0 | `addendum16-identity.txt` |
| 2 | `e4f9997` | `git diff --stat 915ca2c e4f9997 -- app config database routes bootstrap`, plus names outside `docs/verification` | empty; outside docs, only CHANGELOG, the component and the test | 0 | `no-php-change.txt` |
| 3 | component at `915ca2c` + the test file at `e4f9997` (sha256 `4ff40f85c0cedcf4`, as the author's) | `npx vitest run --reporter=verbose tests/frontend/paid-grant-reopen-issued.test.tsx` | **1 failed (the new case: no reopen control), 2 passed.** Matches the author's red. | 1 | `red-on-915ca2c.txt` |
| 4 | `e4f9997`, clean tracked tree, no probe | that file, then `npx vitest run` | 3/3; **65 files, 1,129/1,129**, matching the author's count | 0, 0 | `green-frontend-all.txt` |
| 5 | `e4f9997` | `npx tsc --noEmit`, without and with the probe | clean | 0, 0 | `tsc.txt`, `tsc-with-probe.txt` |
| 6 | `e4f9997` + the Addendum 16 probe, unchanged | `npx vitest run --reporter=verbose` | 7 passed, **H failed (the expected flip)** | 1 (expected) | `addendum16-probe-on-e4f9997.txt` |
| 7 | `e4f9997` + the Addendum 17 probe (sha256 `fdb8f6ea0d8e11c9`) | `npx vitest run --reporter=verbose` (A–M) | **13/13** | 0 | `frontend-review-addendum17.txt` |
| 8 | component at `915ca2c` + the Addendum 17 probe | the same | 4 failed (H, I, J, K: no control for a kept entry), 9 passed. L passes because the issued X2 already gives a control, and M is a negative case. | 1 | `probe-on-915ca2c.txt` |
| 9 | `e4f9997` + K1 (every kept entry counts, not only refused ones) | the author's file + the probe | 3 failed: the author's denial test and probes I and M | 1 | `mutation-K1.txt` |
| 10 | `e4f9997` + K2 (the list built from `issued` only) | the same | 5 failed: the author's new case and probes H, I, J and K | 1 | `mutation-K2.txt` |
| 11 | `e4f9997` + K3 (kept `orderId = ''`) | the same | 5 failed: the author's new case and probes H, I, J and K | 1 | `mutation-K3.txt` |
| 12 | `e4f9997` + K4 (kept `originId = ''`) | the same | 5 failed: the author's new case and probes H, K, L and M | 1 | `mutation-K4.txt` |
| 13 | `e4f9997` | static greps: the callers of `submit()` and `retryDownload()`, and their render guard | as §1 | 0 | `static-submit-callers.txt` |

Mutations are applied by `mutate17.py.txt` (run with `python3 -I`; each replacement is asserted to match exactly once).
The probe source is kept as `paid-grant-review-addendum17.test.tsx.txt`.

**Not recorded as runs:**
- **The first probe run.** My first Addendum 17 probe draft run (13/13, rc 0) went to the terminal. Before it, I fixed one
  error in my own case M, which expected a reopen control for the shown order. Row 7 is the recorded run of the final
  file.
- **PHP** was not rerun, because none changed.

## 4. Info

| ID | Finding |
| --- | --- |
| A17-I1 | **The control can appear late.** `kept` is a ref. A refusal that arrives after a later request has started changes no state (no clear and no message, by design since round 10), so the control appears only at the next render (probe I: none at first, present after the next status read).<br>This is the same lazy behaviour `retryable` already has, and nothing is lost: the entry is kept, and any interaction shows it. Optionally, bump a small state counter in the refusal branch so the control appears at once. |
| A17-I2 | **A kept control can outlive its token.** As with A16-I3 for issued entries, a refused, kept entry is never pruned by `expiresAt`, so its control can outlive the token. Reopening then offers no retry, because the status read shows it `expired` (probe K). The control's wording, "to download its authorized file", can mislead in that state. This is cosmetic. |

A16-I1 (redundant controls), A16-I2 (now partly covered by the author's third case) and A16-I3 (focus) are unchanged.

## 5. Conditions

| ID | Status |
| --- | --- |
| C1, C3, C9, C10 | Resolved or met (unchanged). |
| C2 | Open, as extended in Addenda 9, 11 and 12. |
| C4–C8 | Open, unchanged. |
| C11 | Met as a document for items (a)–(j). Open for the pre-mount host verification, including (k) with the A15-L1 corrections. Codex thread `PRRT_kwDOU5febs6qfw0X` stays deliberately open under C11. I did not query GitHub. |
| C12, C13 | Met. |

**What changes:** A16-L1 is resolved at `e4f9997`. Nothing else changes.

## Not tested

- A real browser, including real iframe refusals and focus.
- PHP, which is unchanged in this range.

## Files added by this review (untracked; nothing committed)

- This file.
- `review-evidence/addendum17/e4f9997/`: the raw outputs above, `mutate17.py.txt` and the probe source.
- `tests/frontend/paid-grant-review-addendum17.test.tsx`: cases A–M, all passing at `e4f9997`.

The Addendum 16 probe file was removed from `tests/frontend/`. Its source is committed as
`review-evidence/addendum16/915ca2c/paid-grant-review-addendum16.test.tsx.txt`, and a copy is parked in the scratchpad.
