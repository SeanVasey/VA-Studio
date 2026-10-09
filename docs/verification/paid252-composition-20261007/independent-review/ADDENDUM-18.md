# Independent review: Paid252 composition, Addendum 18 (round 25: a refusal renders itself)

**Range:** `e4f9997ec605572144b1ae92c49b29209b728564..fe8cdcb088bb0f2a4922ce345019078350d6790e`, fetched from
`origin/harness/paid252-composition`. The worktree `/home/user/rv56-a16` is detached at `fe8cdcb`.

| Commit | Content |
| --- | --- |
| `8a2f91d` | Commits my Addendum 17 record. Docs only. |
| `fe8cdcb` | Round 25 (Codex P2 4228172320, which is my A9-I4 and A17-I1 with a consequence):<br>• `const [, setRefusals] = useState(0)`, and `setRefusals(n => n + 1)` in the frame's refusal branch, immediately after `mark.refused = true`.<br>• A fourth case in `tests/frontend/paid-grant-reopen-issued.test.tsx`.<br>• The A9-I4 assertion in `tests/frontend/paid-grant-review-addendum9.test.tsx` flipped to the fixed behaviour.<br>• CHANGELOG and `hardening/codex-25/`. |

**No PHP changed.** `git diff --stat e4f9997 fe8cdcb -- app config database routes bootstrap` is empty. Outside
`docs/verification/`, only the CHANGELOG, the component and the two test files changed (`range-delta.txt`).

**The only change to existing tests is the Addendum 9 flip.** `git diff --numstat e4f9997 fe8cdcb -- tests` shows:
- `paid-grant-reopen-issued.test.tsx`: 20 lines added, 0 deleted;
- `paid-grant-review-addendum9.test.tsx`: 3 lines changed, namely the A9-I4 comment and `not.toBeInTheDocument()` →
  `toBeInTheDocument()`. The rest of that case, including the later `rerender` check and the fetch count of 4, is
  unchanged.

**My Addendum 17 record is committed byte-for-byte.** `ADDENDUM-17.md` and all 16 evidence files are `cmp`-identical to
my untracked copies, both at `8a2f91d` and at `fe8cdcb` (`addendum17-identity.txt`, 34 matches).

I wrote none of the code under review. Nothing was committed or pushed, no product code was modified, and the main
checkout was used read-only (its `node_modules` only, through a symlink).
**Reviewer:** Claude (independent review lane). **Date:** 2026-10-09.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge of `fe8cdcb`.**

- The fix closes A9-I4, A17-I1 and Codex 4228172320. A refusal reported after the page last rendered now shows its
  reopen and retry controls at once.
- The bump sits after the frame guard, renders nothing for removed frames, and adds exactly one commit with no request,
  abort or generation change (§1).
- No finding at Low or above, and no new Info item.

## 1. Adversarial questions

| Question | Finding |
| --- | --- |
| Can a refusal from a frame removed by a denial, by pagehide or by unmount render or resurrect anything? | **No.** The bump is inside the refusal branch, which runs only after the guard `if (!active.current \|\| !frames.current.includes(frame)) return;` at the top of the same handler (line 284; bump at line 288, `static-render-effects.txt`).<br>A denial (`refuse()` → `clear()`) and pagehide (`leave()` → `clear()`) remove every frame from `frames.current`. Unmount sets `active.current = false`.<br>Probes N1 (denial) and N2 (pagehide) count React commits with a `Profiler`: a refusal on the removed frame adds **0 commits**, and the document HTML is byte-identical before and after. N3 (unmount) gives 0 commits and no `console.error`.<br>Mutation R1 (bump before the guard) is killed by N1 and N2. N3 cannot see it, because a state update on an unmounted component is a no-op in React 19. |
| Can the extra render abort an in-flight request or change `generation`? | **No.** A render runs no request code. `generation.current` is written only in `stopPreparing`, `leave`, `visibility`, the unmount cleanup and `call()`, never in the render body. The effects are `useEffect([], …)` (mount only) and `useEffect([message], …)`, and the bump leaves `message` unchanged.<br>Probe N4: a non-current refusal arrives while a status read is in flight. It causes exactly **+1 commit**, **0 new fetches**, the read's `AbortSignal` stays un-aborted, `aria-busy` stays true, and no refusal message or clear appears. The read then commits normally and offers the retry. That proves its generation was not superseded. |
| Does the retry still require a saved status read showing that exact authorization unused? | **Yes.** `retryable` is unchanged. Probes H and K (Addendum 17) pass unchanged. In the flipped Addendum 9 case, the retry appears from a read that had already completed, as A9-I4 always said it would at the next render. |
| No token in the DOM? | **Yes.** The counter value is never read (`const [, setRefusals]`). Probes H–N4 check `outerHTML` for every token. |
| The current-frame path. | **Unchanged.** It still runs `clear(true, true)` and shows the refusal message. The bump only adds an update that React batches into the same commit. |

## 2. Earlier probes at `fe8cdcb`: the expected flips

| Probe | Result | Why |
| --- | --- | --- |
| Addendum 16, unchanged | 7 of 8 pass. H fails at line 240 (1 control found). | The A16-L1 flip, already recorded in Addendum 17. |
| Addendum 17, unchanged | 12 of 13 pass. **I fails at line 277, `expect(immediately).toBe(0)` (got 1).** | **The intended round 25 flip.** I recorded A17-I1, the delayed control. |

The Addendum 18 probe (`tests/frontend/paid-grant-review-addendum18.test.tsx`) contains:
- Addendum 17's A–M, with I inverted. X's control is now present at once, and still no refusal message appears.
- A new block, N1–N4.

## 3. Runs (`review-evidence/addendum18/fe8cdcb/`)

Node 24.21.0 and vitest 5.0.1. `node_modules` was symlinked for these runs only and then removed (`unlink`). Every run on
the pre-fix component or under a mutation was followed by `git checkout` and `git diff --quiet` (rc 0, recorded in each
file).

| # | Source | Command | Result | rc | Raw output |
| --- | --- | --- | --- | --- | --- |
| 1 | `8a2f91d`, `fe8cdcb` | `cmp` of the committed Addendum 17 against my copies | 34 of 34 identical | 0 | `addendum17-identity.txt` |
| 2 | `fe8cdcb` | the path diff, the names outside docs, and `--numstat` on the tests, plus the Addendum 9 diff | as above | 0 | `range-delta.txt` |
| 3 | component at `e4f9997` + both test files at `fe8cdcb` | `npx vitest run --reporter=verbose` on the reopen-issued and addendum-9 files | **2 failed:** the author's new case, and the flipped A9-I4 case. 5 passed. Matches the author's red (test sha256 `5f7a51cb8175b922`). | 1 | `red-on-e4f9997.txt` |
| 4 | `fe8cdcb`, clean tracked tree, no probe | the same two files, then `npx vitest run` | 7/7; **65 files, 1,130/1,130**, matching the author's count | 0, 0 | `green-frontend-all.txt` |
| 5 | `fe8cdcb` | `npx tsc --noEmit`, without and with the probe | clean | 0, 0 | `tsc.txt`, `tsc-with-probe.txt` |
| 6 | `fe8cdcb` + the Addendum 16 and 17 probes, unchanged | `npx vitest run --reporter=verbose` | 7/8 and 12/13: the expected flips in §2 | 1, 1 (expected) | `addendum16-probe-on-fe8cdcb.txt`, `addendum17-probe-on-fe8cdcb.txt` |
| 7 | `fe8cdcb` + the Addendum 18 probe (sha256 `626df5d214dc080e`) | `npx vitest run --reporter=verbose` | **17/17** | 0 | `frontend-review-addendum18.txt` |
| 8 | component at `e4f9997` + the Addendum 18 probe | the same | 2 failed: I (no control at once) and N4 (0 commits, not 1). 15 passed: N1–N3 assert no render, which was already true before the fix. | 1 | `probe-on-e4f9997.txt` |
| 9 | `fe8cdcb` + R1 (bump before the frame guard) | the author's two files + the probe | 2 failed: N1 and N2 | 1 | `mutation-R1.txt` |
| 10 | `fe8cdcb` + R2 (bump removed) | the same | 4 failed: the A9 flip, the author's new case, I and N4 | 1 | `mutation-R2.txt` |
| 11 | `fe8cdcb` + R3 (bump only on the current-frame path) | the same | 4 failed: the same four | 1 | `mutation-R3.txt` |
| 12 | `fe8cdcb` | static greps: `generation` writes, effects and their dependencies, the guard and the bump | as §1 | 0 | `static-render-effects.txt` |

The mutations are applied by `mutate18.py.txt` (run with `python3 -I`; each replacement is asserted to match exactly
once). The probe source is kept as `paid-grant-review-addendum18.test.tsx.txt`.

**Not recorded as runs:**
- **The first probe run.** My first Addendum 18 probe run (17/17, rc 0) went to the terminal. Row 7 is the recorded
  rerun of the same file.
- **PHP** was not rerun, because none changed.

## 4. Info

None new.
- **Resolved:** A9-I4 and A17-I1.
- **Unchanged:** A16-I1 (redundant controls), A16-I3 (focus) and A17-I2 (a kept control can outlive its token).

## 5. Conditions

| ID | Status |
| --- | --- |
| C1, C3, C9, C10 | Resolved or met (unchanged). |
| C2 | Open, as extended in Addenda 9, 11 and 12. |
| C4–C8 | Open, unchanged. |
| C11 | Met as a document for items (a)–(j). Open for the pre-mount host verification, including (k) with the A15-L1 corrections. Codex thread `PRRT_kwDOU5febs6qfw0X` stays deliberately open under C11. I did not query GitHub. |
| C12, C13 | Met. |

**What changes:** nothing in the conditions.

## Not tested

- A real browser. In particular, whether a real browser fires `load` on a detached iframe at all. The guard makes that
  moot.
- PHP, which is unchanged in this range.

## Files added by this review (untracked; nothing committed)

- This file.
- `review-evidence/addendum18/fe8cdcb/`.
- `tests/frontend/paid-grant-review-addendum18.test.tsx`: cases A–M and N1–N4, all passing at `fe8cdcb`.

The Addendum 17 probe and record copies are parked in the session scratchpad (`a17-parked/`). Their committed sources
are under `review-evidence/addendum17/`.
