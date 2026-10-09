# Independent review: Paid252 composition, Addendum 12 (round 20: lock TTL, authorization window, lease-bounded line budget, Stop control)

**Range:** `a5354220..36be2a33d63ef73d67079e67d8fc819a7c226d04`, fetched from `origin/harness/paid252-composition`; the
worktree is detached at `36be2a33`. While I was reviewing, the coordinator forwarded two Codex P2 findings on `36be2a33`
that round 21 will fix. I give my view of both as they stand at this head (§7).

| Commit | Content |
| --- | --- |
| `9605e95c` | Commits my Addendum 11 record. My 14 untracked copies were byte-identical; the 15th, my A11 probe, matched `9605e95c` and was later updated in `2c827d3a`. |
| `2c827d3a` | Round 20 code:<br>• `HEAVY_LOCK_SECONDS = 2 × LEASE + 60`, with the client wait raised to 660 s (A11-L1).<br>• `AUTHORIZE_BUDGET_SECONDS = 60`, added to `expires_at`.<br>• Redeem's admitted instant taken at entry, before `locate()`; `deadline($auth, $admitted)` with a maximum of 660 s (A11-L2).<br>• The line budget bounded by the claim's lease end (A11-I1).<br>• `performance.now()` spacing (A11-I6).<br>• The live region moved outside `aria-busy`, and a Stop control (A11-I5).<br>• Tests: three updated, `PaidGrantAuthorizationWindowTest` (5), two `PaidGrantHeavyWorkTest` cases, four continuation cases, and my A11 probe's P1 and P3 flipped to the fixed behaviour. |
| `36be2a33` | Docs: `hardening/codex-20/`, plus C11 (f)–(h) as requirements 6 and 7 and verification bullets in `docs/ops/paid-delivery-runtime.md` |

I wrote none of the code under review. Nothing was committed or pushed, no product code was modified, and no MySQL daemon
was started. **Reviewer:** Claude (independent review lane). **Date:** 2026-10-08.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.**

- **A11-L1 and A11-L2 are resolved.** A11-I1, A11-I2 (by C11 requirement 6), A11-I5 and A11-I6 are resolved.
- **A11-I4 is documented.** It has one precision note, A12-I3.
- **No new finding at Low or above** in round 20's own changes.
- **The two Codex P2s on this head are valid and Low (§7).** The leftover-snapshot finding should be fixed before
  mount. It is being fixed in round 21.
- **C11 is met as a document** and stays open for the pre-mount verification. I recommend adding (i) and (j), below.

## 1. Expiry semantics

| Question | Finding |
| --- | --- |
| Can an authorization be redeemed later than intended? | Only within stated bounds. The nominal lifetime is `authorization_seconds + 60`, at most 660 s. It is fixed per batch from the retained policy. The admitted instant is taken at domain entry. The 60 s observation budget starts just before it, so a request admitted in time must finish locating and frame 1 within 60 s. Probe P1: a 61 s `locate()` is refused 410 with nothing recorded. The latest possible commit is `expires_at + 60 + snapshot_seconds + 60`, the A9-I1 window plus the 60 s A11-L2 allowance. |
| Does admitting before `locate()` let an unauthenticated or wrong-account request do anything new? | No. `$admitted` is a server timestamp with no authority. The controller's `identity()` (policy capture, customer session principal, actor), then `locate()` (current-buyer lock, authorization by public id and account), then both frames (token hash with `hash_equals`, `$auth === $locator['auth']`, owner, identity, producer `lockedRead`, `retained()`, `provePure`) all still run before anything is recorded. The existing cross-account and token-tamper cases pass (`PaidGrantReviewAdversarialTest`). |
| Idempotent replay | `authorize()` replays return the stored row with an unchanged `expires_at` (`PaidGrantAuthorizationWindowTest` (e) asserts the same row and a 60 + 60 s lifetime). `deadline()` without `$at` still uses `now()`, so a replay after expiry is refused 410. I read that from the code; no test here asserts it. |
| `status()` history and the set-aside proof (A1 rounds 3 and 4) | `unused`/`expired` compares the read's `$at` with the same `expires_at`. The proof needs a lifetime fixed per batch, which `created_at + policy + 60` still is. Issuance (3 per line per 60 s, by `created_at`) and the A1-L1 80 s rule (authorize budget 60 + lock 5 + margin 15) are unchanged. |
| `deadline()` | Now `($at ?? now)` with `remaining <= 600 + 60`. My A11-L2 point 2 is addressed, and a 600 s policy redeems (`PaidGrantAuthorizationWindowTest` (c)). |
| Authorize budget | It still starts before the frame (`start(60)`). `$at` is inside the frame and the token is returned only after the frame's post-commit proofs, so the token reaches PHP's output within 60 s of `$at`. Adding 60 s keeps the policy lifetime counting from the latest moment the token can be usable. |

## 2. Heavy-lock TTL (660 s) and the client wait

- **The longest legitimate hold.** It is the stretch from taking the lock to the claim frame's `$at`: at most the call
  budget left, up to 300 s, and in practice the claim frame's own pre-commit work. After that comes the lease, now 300 s
  exactly (§3), plus one tail.
  - That tail is the record frame's post-commit proofs, when they cross the lease end. The failure frame is then refused at
    its start, because the budget has lapsed.
  - So the 60 s margin covers one post-commit tail, which C2 must measure natively.
  - `PaidGrantHeavyWorkTest` "lock outlasts a slow claim frame followed by a long line" pins the arithmetic.
- **A crashed holder now blocks that buyer for up to 11 minutes.** That is availability for one account only.
- **The client wait of 660 s** means up to 44 status reads at 15 s, plus at most 20 document POSTs per click. Each read is
  one owned read frame on the server, so a waiting tab costs about 4 frames a minute (the M2 cost per frame natively).
  That is acceptable and bounded.
- **A12-I3 (Info): the 20-POST cap binds before the 660 s window.** When the holder is the page's own lost completion
  request, no claim is visible, so every poll re-POSTs and gets `busy`.
  - Probe P3: the page stops with "Paused after 20 requests" 620 s after the click, not after 320 + 660 = 980 s.
  - The C11 verification bullet ("320 s plus its wait window") therefore overstates the window for completion by about
    6 minutes.
  - At the 100 MiB/s floor a 30 GiB completion takes about 5 minutes, so it still fits. The bullet should name the cap.

## 3. A11-I1: the line budget bounded by the lease

- **Sub-second handling is right.** `lease_ends_ns = $tick − $now->micro × 1000 + 300 s` takes the monotonic tick next to
  the same `CarbonImmutable::now()` whose `startOfSecond()` becomes `$at`. That is the monotonic image of `$at + 300 s`,
  exactly the stored `expires_at`, apart from the nanoseconds between the two clock reads.
  - `shortenTo()` only ever shortens the budget, so it is always the lease end.
  - The record frame's wall-clock check `now < expires_at` remains the authority.
- **Can it fail a line that would have fitted? Only in one edge, with nothing lost.**
- **A12-I2 (Info).** The record frame must now also finish its post-commit proofs before the lease end. A record frame
  that commits inside the lease, but whose post-commit tail crosses the lease end, answers 410 although the original is
  committed.
  - Probe P2: 410, `paid_originals` 1, work `complete`, no fulfillment. The next call fulfils the order with no further
    attempt (`attempts` [1]).
  - Before round 20 the budget ran past the lease by the claim-frame tail, so that line would have answered normally.
  - The page treats the 410 as `refused` and stops; the next click continues.
  - A wall-clock step can still make the lease (wall) and the budget (monotonic) disagree; that is pre-existing.

## 4. Stop control, focus and the live region

- **Stop does not leak a running timer or request.**
  - `stopContinuing()` clears every pending pause timer (`timers` set) and bumps the run, so a suspended
    `continuePreparing` stays suspended and becomes unreachable.
  - `stopPreparing()` also bumps `generation`, aborts the in-flight request and resets `inflight` and `busy`.
  - The aborted `call()` returns `skipped` under `owns()` and does not touch state.
  - The continuation test "stops a request in flight when Stop is chosen and sends nothing more" covers this.
  - A request already sent may still finish on the server. The progress text says so.
- **The live region is announced.** It is always rendered, and is now a sibling of the `aria-busy` section, not a
  descendant. My A11 probe P1, flipped in round 20, asserts no `aria-busy="true"` ancestor.
- **No token anywhere.** Progress text holds counts only, and the document answer carries no token.
- **A12-I4 (Info): focus is lost after Stop.** Stop removes its own button, so focus falls back to `document.body`
  (probe P4). Keyboard and screen-reader users lose their place (WCAG 2.4.3 focus order). Move focus to the progress
  line (`tabIndex={-1}`) or to the prepare control when stopping.

## 5. C11 document, items (f)–(h)

| Item | Status |
| --- | --- |
| (f) shared, lock-capable store | Requirement 6, with a verification bullet. Closes A11-I2. |
| (g) wait window versus completion duration | Verification bullet added. Precision: the 20-POST cap binds first (A12-I3). |
| (h) queueing before PHP | Requirement 7 (`listen.backlog`, short proxy connect and queue timeouts, `pm.max_children`), with a verification bullet. Closes it. |

**C11 is now met as a document, with the pre-mount verification open.** I recommend adding two items prompted by §7:
- **(i)** The private spool must be on a host-local filesystem with coherent `flock`, per web host. Slots, holder checks
  and round 21's recovery all depend on it. A network filesystem with host-local or emulated locks would let two hosts
  share a slot.
- **(j)** If a persistent-worker runtime (for example Octane) is ever used, verify that the request-start value round 21
  reads is per request.

## 6. Conditions

| ID | Status |
| --- | --- |
| C1, C3, C9, C10 | Resolved or met (unchanged). |
| C2 | **Open.** The native measurement must also give the post-commit tail of the claim and record frames, which bounds the heavy-lock margin (§2) and A12-I2. |
| C4–C8 | Open, unchanged. |
| C11 | **Met as a document; open** for the pre-mount verification and the recommended (i) and (j). |
| C12, C13 | Met. A12-I3 and A12-I4 are Info. |

## 7. The two Codex P2 findings on `36be2a33` (round 21)

**4224514947: the admitted instant is still taken after the controller's identity proof. Valid, Low.**
- `PaidGrantController::redeem` runs `identity()` (policy capture and `ProductionCustomerSessions::principal`) and body
  parsing before `PaidGrantDownloads::redeem()` takes `$admitted`.
- So customer-session resolution, a full identity read natively (M2), plus Laravel bootstrap and the session-lock wait
  (`block(20, 5)`), are still counted against the lifetime.
- The impact is the same class as A11-L2: a refusal with nothing recorded, costing one re-authorization.
- **My view of the planned fix** (capture `REQUEST_TIME_FLOAT` in the controller before `identity()`):
  - **It cannot be spoofed under PHP-FPM.** PHP registers `REQUEST_TIME_FLOAT` itself after importing FastCGI params, and
    a client header cannot create that key.
  - **It must still be clamped.** Accept the value only if it is numeric, finite and not later than `now()`. Never admit
    earlier than `now() − 60 s`, the observation budget, otherwise fall back to the bound. That keeps the widening bounded
    even if a runtime reports a stale value (a persistent worker, C11 (j)) or the request sat in a queue.
  - Pass it in as data only. Every authority check stays where it is.

**4224514955: a leftover named `slot-N.snapshot` retires its slot permanently. Valid. Low, but should be fixed before
mount.**
- `slot()` marks a slot unusable if `slot-N.snapshot` exists at all.
- The holder creates that file (`x+b`) after taking the slot `flock`, and unlinks it before releasing the lease. Its
  `finally` removes it on PHP-level failures.
- **How a file is left behind.** A worker killed during the snapshot (up to `snapshot_seconds`) leaves the file. Kills
  include C11's own `request_terminate_timeout`, an FPM reload past `process_control_timeout`, or the OOM killer.
- **Impact.** Three such kills retire all three slots. Paid delivery then fails closed site-wide, with
  `target_unavailable` before any attempt, until an operator deletes the files. The leftover is also a private copy of a
  master that stays in the 0700 spool.
- **My view of the planned fix (recover only while holding that slot's exclusive `flock`).** It is sound:
  - A live holder keeps the slot `flock` from `slot()` until after unlink. So holding it exclusively proves no live
    process owns the slot's file. A child that inherited the descriptor would keep the lock and block recovery, which is
    safe.
  - Recover only a regular file (checked with `lstat`, so a symlink is never followed) with `nlink` 1, the spool owner's
    uid, mode 0600 or 0400, and size at most `DeliveryAssetFiles::MAX_BYTES`.
  - Unlink it by name inside the 0700 spool, and keep skipping anything malformed (the no-repair rule).
  - Let the new holder overwrite the slot's `.reserve` and `.holder`, as it does already.
  - Report each recovery to operators (for example through `PaidGrantPrivacy::report`), because a leftover means a worker
    died.
  - All of this depends on coherent `flock`, hence C11 (i).

## Runs

PHP 8.4.26 through the worktree runner, with SQLite defaults and `public/build` absent. Every run was at head `36be2a33`.
Evidence is in `review-evidence/addendum12/36be2a33/`.

| # | Command | Result | rc | Wall | Raw output |
| --- | --- | --- | --- | --- | --- |
| 1 | `--filter PaidGrant tests/Feature` (23 files) | **123 tests, 122 passed, 1 skipped** (the native-only `PaidGrantSchemaRecoveryTest` case, the census entry), 4,494 assertions. AuthorizationWindow 5, HeavyWork 9. | 0 | 615 s | `sqlite-paid-family.{txt,xml}` |
| 2 | `--filter 'PaidGrantAuthorizationWindowTest\|PaidGrantHeavyWorkTest\|PaidGrantPolicyRevisionTest\|PaidGrantReviewAddendum1Test\|PaidGrantReviewAdversarialTest' tests/Feature` (new and changed) | 26 tests, 527 assertions | 0 | 195 s | `sqlite-new-and-changed.{txt,xml}` |
| 3 | Untracked probe `ReviewProbeAddendum12Test`:<br>• P1: a 61 s `locate()` gives 410 with nothing recorded.<br>• P2: a record-frame tail across the lease end gives 410 with the line kept, then fulfilment with one attempt. | 2/2, 37 assertions | 0 | 13 s | `sqlite-review-probe.{txt,xml}`; source `ReviewProbeAddendum12Test.php.txt` |
| 4 | `/home/user/VA-Studio/vendor/bin/pint --test` on the 7 changed PHP files | passed | 0 | | `pint.txt` |
| 5 | `python3 -I scripts/ci/test-database-receipts.py` | 34 tests OK | 0 | | `census-self-test.txt` |
| 6 | `npx vitest run` on all 11 tracked `tests/frontend/paid-grant-*.test.tsx`, including my A11 probe as round 20 updated it (vitest 5.0.1, Node 24.21.0) | 11 files, 65/65 | 0 | | `frontend-vitest.txt` |
| 7 | Untracked probe `tests/frontend/paid-grant-review-addendum12.test.tsx`:<br>• P3: the 20-POST cap ends the wait 620 s after the click (A12-I3).<br>• P4: focus is on `body` after Stop (A12-I4). | 2/2 | 0 | | `frontend-review-addendum12.txt` |
| 8 | `npx tsc --noEmit`, without and with the probe | clean | 0, 0 | | `tsc.txt`, `tsc-with-probe.txt` |

**Notes on the runs:**
- **Not recorded as a run.** My first run of probe P2 failed (rc 2) on my own test: I reset the commit counter, so the
  still-registered listener spent 301 s again at the next call's bundle frame. The product assertions before that line
  had passed (410, line kept). The recorded run in row 3 replaced that output.
- **No pinned renderer file changed** in the range.
- **Symlink.** `node_modules` was symlinked for rows 6–8 only and then removed (`unlink`).

## Not tested

- **Native MySQL**, including the post-commit tails in §2 and §3.
- **Real infrastructure.** Real FPM, proxy and queue behaviour; `REQUEST_TIME_FLOAT` under the chosen runtime; real worker
  kills leaving spool files.
- **A screen reader** for §4.
- **A real browser.**

## Files added by this review (untracked; nothing committed)

- This file.
- `review-evidence/addendum12/36be2a33/`. It includes the PHP probe source as `.txt`; `tests/Feature/ReviewProbe*` does
  not exist.
- `tests/frontend/paid-grant-review-addendum12.test.tsx`: two documenting cases, both passing against current behaviour.

**Worktree housekeeping.** My untracked Addendum 11 copies were moved, not deleted, to
`/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/rv56a12-parked-addendum11/`. Fourteen
were byte-identical to the committed files. The 15th, the A11 probe, matched `9605e95c` and was updated by round 20.
