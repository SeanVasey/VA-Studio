# Independent review: Paid252 composition, Addendum 11 (rounds 18 and 19: per-claim budgets, C11–C13)

**Range:** `afd9cd94..a535422086043edc30443eb3bf3a06b401c394d8`, fetched from `origin/harness/paid252-composition`; the
worktree is detached at `a5354220`. A usage limit interrupted the review. After it resumed, the coordinator asked me to
assess two Codex P2 findings on `a5354220` (§8). Round 20, which addresses them, will be reviewed when it lands.

| Commit | Content |
| --- | --- |
| `2c3f8cb8` | Commits my Addendum 10 record (byte-identical to my 15 untracked copies) |
| `fbf3806f`, `a7892c9b` | Codex round 18 plus my A10 findings: per-claim line budgets in `prepare()`, no re-verification once fulfilled (A10-L1), a "Finish preparing this order" control (A10-L2), the `PaidGrantClockedAssetFiles` double (A10-I2) |
| `74812874` | Round 19: a per-account heavy-work cache lock (C12), the projection instead of 410 plus a `busy` flag (C13, server), `continuePreparing()` (C13, client) |
| `02b2fce2` | `docs/ops/paid-delivery-runtime.md` (C11) and the round 19 record |
| `a5354220` | Adds the private-storage throughput floor to that document |

Sean delegated C11–C13 to the integration owner, who decided them as recorded. I wrote none of the code under review.
Nothing was committed or pushed, no product code was modified, and no MySQL daemon was started. **Reviewer:** Claude
(independent review lane). **Date:** 2026-10-08.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.**

- **Rounds 18 and 19 weaken no money, entitlement or authorization guarantee.**
  - A line still cannot be recorded after its database lease ends.
  - The failure frame still marks only its own claim.
  - A fulfilled order's bytes are still re-verified on every download.
  - The lock is availability-only: it is never a correctness guarantee.
  - Returning the projection goes through a complete authorized read frame with its own receipt.
- **Both Codex P2s on `a5354220` are valid (§8).** Both are Low by my scale, since neither can grant, consume or leak
  anything. Round 20 must also change `deadline()` (A11-L2).
- **No new finding at Medium or above.** The Info notes are below.
- **Conditions:** C12 and C13 are met in design. C11 is met as a document and stays open for the pre-mount verification
  and the two gaps below. C2 and C4–C8 are unchanged.

## 1. Per-claim budgets in `prepare()` (round 18)

- **A line cannot be recorded after its lease.** The lease is `$at + 300 s`, where `$at` is taken inside the claim frame
  and truncated to the second. The record frame still requires `CarbonImmutable::now() < $claim['expires_at']`, plus the
  same claim id, `claimed` state, body and origin hash, in-frame before inserting `paid_originals`
  (`PaidGrantDocuments.php:130-132`).
- **The failure frame marks only its own claim.** It updates only if the line is still `claimed` with this
  `claim_id` and has no original (`:149-153`). It runs under the line's budget. Once that budget has lapsed it is refused
  at its start, and the claim stays until its lease ends. `PaidGrantPreparationBudgetTest` (c) pins that case.
- **A11-I1 (Info): the line budget is not inside the lease.**
  - The budget starts when the claim frame has *returned*, after its commit and post-commit proofs. The lease starts at
    `$at`, inside the frame.
  - So the budget ends later than the lease by the claim frame's tail: up to 1 s of truncation plus the post-commit time.
    Natively that tail is the C2 identity-inspection cost.
  - A line that finishes in that gap is refused 409 by the lease check. It is then marked `failed`, and its attempt is
    spent.
  - The comment "started now to match its database lease" overstates this. Starting the budget from the claim frame's own
    instant would close the gap. Correctness is unaffected.
- **Asset deadlines (A10-I2) are now observable.** `PaidGrantClockedAssetFiles` applies the deadline it receives on the
  paid clock. It refuses a lapsed deadline and records a missing or too-distant one. The preparation and completion tests
  assert no violations and the expected number of lapses.

## 2. Skipping re-verification once fulfilled (A10-L1)

**Nothing is lost.**
- The fulfillment frame still runs and still requires `$graph === $bundle`, so a changed graph returns 409.
- Every redemption re-verifies the exact bytes before an attempt is recorded (Addendum 10 §1).
- The changed `PaidGrantDocumentJourneyTest` case is the right expectation. With the original missing, `prepare()`
  returns the identical projection and changes no row (no replacement claim). The redeem of that contract fails with
  `DeliveryException('target_unavailable')`, with zero `paid_redemptions`.
- What changes is only where the missing file shows up: at download instead of at a `prepare()` call that the page never
  offers for a fulfilled order.

## 3. The C12 heavy-work lock

`Cache::lock('paid-grant-heavy:'.sha256('paid-heavy-v1:'.accountId), 360)->get()` is non-blocking. One lock is held
around each claimed line in `prepare()`, and around each line's re-verification in `complete()`.

- **Deadlock: none.** `get()` never waits.
- **Leaks: none.**
  - Every acquisition is released in a `finally`.
  - A crashed worker's lock expires at the TTL. `DatabaseLock::acquire` then takes it over with `UPDATE … WHERE owner = ?
    OR expiration <= now`.
  - `PaidGrantHeavyWorkTest` covers release after success, after an exception, and after a crash.
- **Theft: none.** `DatabaseLock::release` deletes `WHERE key = ? AND owner = ?`, and the owner is a random token. A
  holder whose lock expired and was taken over cannot release the new holder's lock.
- **TTL versus the longest step.** See the Codex P2 in §8. When a step outlives the TTL, a second heavy step of the same
  buyer can run in parallel. That is availability only: claims, leases, claim-id checks and `batch_once` still decide
  every write.
- **Key and personal data.** The key holds a SHA-256 of a fixed prefix and the internal account id, and the
  `cache_locks` row stores only that key, a random owner and an expiration. No email, name or token.
  - The digest is not keyed, so it maps back to an account id by enumeration. Account ids are internal, so this is
    acceptable.
- **The `cache_locks` table** comes from Laravel's own migration (`key` is the primary key). Acquire and release run on
  the default connection, outside every paid transaction, before or after frames. They write nothing a receipt
  snapshots. Lottery pruning deletes only expired rows. `PaidGrantHeavyWorkTest` exercises the `database` store against
  the migrated table.
- **Blocking another buyer: not possible.** The key is per account. Only the same account's own requests can wait on
  each other.
- **What `busy` reveals.** A token-free boolean, returned only to the authenticated owner, saying that the same
  account's other work holds the lock. It gives nothing about any other buyer.
- **A11-I2 (Info): the lock needs a store shared by every app host.**
  - The default `CACHE_STORE=database` qualifies.
  - A per-host `file` or `array` store, or the `null` store (whose locks always succeed), would silently disable C12
    across hosts.
  - `docs/ops/paid-delivery-runtime.md` does not say this. It belongs in its requirements and pre-mount checks (C11
    below).
- **A11-I3 (Info): `complete()` gives up part-way when the lock is held.** If the lock is held between lines, the request
  answers `busy` and the next pass re-verifies from line 1. Two tabs of one buyer could in principle keep interrupting
  each other. In practice the holder re-acquires immediately after releasing, so the window is microseconds.

## 4. C13, server: the projection instead of 410

- **Only two outcomes changed:** a spent call budget at the loop top, and a held lock or another request's live claim.
  These now answer through `current()`, which is a fresh `PaidGrantCommands::run` with its own 60 s budget, receipt,
  fence and post-commit proofs.
- **Refusals that stay refusals:**
  - denial (403/404) and changed state (409) from `run()`;
  - the attempt cap (409) in the claim frame;
  - a render or record failure;
  - a line over its own 300 s bound in `complete()` (410).
- **One receipt per request.** `$projectionRead` is captured only once per request, because each early return happens
  before any capture. A second capture would throw anyway (`PaidGrantProjectionRead::capture` requires an unused read).
- **`busy` validation.** It is produced only by the controller (a PHP `bool`). Its only consumer is `documentAnswer()`,
  which requires `typeof busy === 'boolean'` and the exact keys. `PaidGrantHttpJourneyTest` asserts the key set
  `['origin', 'busy']`. No other response carries it.

## 5. C13, client: `continuePreparing()`

**Loops and timers.**
- Each run has a number. `stopContinuing()` bumps it on hide, `pagehide`, denial and unmount, and every await is
  followed by `current()`.
- Pending `setTimeout` pauses resolve harmlessly after a stop.
- A `call()` outcome other than `ok` or `lost` ends the run, and so does a stale generation (`skipped`).
- A hidden tab stops it and nothing resumes on return (covered by the continuation test).

**Tokens.** Document answers and progress text contain no token. Progress is built from line counts only.

**Throttle.**
- Document POSTs are at least 10 s apart, using `lastDocument`, which survives across runs, so one tab cannot exceed the
  route's 6 per minute.
- Two tabs of the same buyer can reach 12 per minute. The 429 is handled as `refused` and stops the run with a message.

**Polling.** At most one status read per 15 s, up to 20 POSTs per click, and a give-up 360 s after the last progress. My
probe P2 confirms the bounds: at most 20 POSTs and at most 27 reads in 400 s, then silence.

**A11-I4 (Info): a long completion is not seen through.**
- A completion pass that runs longer than the 320 s client timeout loses its answer.
- After that, the page polls. Status shows neither a live claim nor fulfilment, so it re-POSTs and gets `busy`, because
  its own earlier request holds the lock. It gives up after 360 s or 20 POSTs.
- Probe P2: "Still waiting for other work" or "Paused", then no further traffic, and "Finish preparing this order"
  enabled again.
- So the page reaches fulfilment without another click only if the pass ends within about 320 + 360 s of the POST.
  On a 30 GiB order that needs about 50 MiB/s. The 100 MiB/s floor in requirement 5 of the C11 document covers it.
  However, that document's pre-mount check ("ends … fulfilled, without a manual retry") should state this dependency.

**A11-I5 (Info, accessibility, not verified with a screen reader).**
- The progress region (`role="status" aria-live="polite"`) is rendered from the start, which is correct.
- But it sits inside `<section aria-busy={busy}>`, and `busy` stays `true` for the whole continuation. Probe P1
  confirms that `closest('[aria-busy="true"]')` is non-null.
- Assistive technology may defer announcements inside a busy subtree until it clears (WCAG 4.1.3 status messages).
- Move the live region outside the `aria-busy` subtree, or limit `aria-busy` to the controls.
- Every control is disabled during a continuation that can last tens of minutes. A visible Stop control (hide or leave
  is the only way out today) would also help.

**A11-I6 (Info): wall-clock spacing.**
- `lastDocument`, `waitingSince` and the spacing delay use `Date.now()`.
- Probe P3: a backward step of one hour while a request is in flight stalls the next POST for that hour, with every
  control disabled. A forward step can send one POST early, at worst a 429.
- Use `performance.now()` for these intervals.

## 6. C11 document (`docs/ops/paid-delivery-runtime.md`) against the code

| Claim | Check |
| --- | --- |
| redeem first byte ≤ 420 s (defaults), whole ≤ 7,620 s; maxima 1,920 s and 16,320 s | Matches: 60 + `snapshot_seconds` + 60, plus `transfer_max_seconds` (validated 1,800 and 14,400) |
| document whole ≤ 3,660 s | Conservative. `complete()` starts only while the call budget lasts (`:71`), so the bound is about 300 + 10 × 300 + 60 = 3,360 s plus frame tails. The extra 300 s is safe margin. |
| FPM pool, `request_terminate_timeout` 7,800 s, `max_execution_time = 0` | Covers both routes. Correctly notes that hashing is CPU time. Matches my C11 (a) and (d). |
| proxy read timeouts 480 s and 3,900 s | Match C11 (e) |
| buffering with a 1 GiB temp cap; private, 0700, sized 3 × 1 GiB temp path | Match C11 (b) and (c). `DeliveryAssetFiles::MAX_BYTES` is 1 GiB. |
| throughput floor ≥ 14 MiB/s, target 100 MiB/s | 3 GiB in 300 s minus a 65–76 s render needs about 13–14 MiB/s. Correct. |

**Missing from the document** (folded into C11 below):
- (f) a shared, lock-capable cache store for C12 (A11-I2);
- (g) the tie between the client waiting window and completion duration in the pre-mount check (A11-I4);
- (h) the time a redeem waits in the FPM `listen` backlog or in proxy queues before PHP starts. That wait spends the
  authorization lifetime, and round 20's request-start comparison cannot see it. `pm.max_children = 8` with an unbounded
  backlog makes it reachable; bound it (`listen.backlog`, proxy connect and queue timeouts) or size the pool for it.

## 7. Conditions

| ID | Status |
| --- | --- |
| C1, C3, C9, C10 | Resolved or met (unchanged). |
| C2 | **Open, extended.** The native measurement should also record how long the claim frame and the record frame take after their commit. That tail sets the lease gap (A11-I1) and the heavy-lock margin (§8). |
| C4–C7 | Open, unchanged. C7 now also covers the authorization lifetime in light of A11-L2. |
| C8 | Open, unchanged. Preparation uses no spool slot. |
| C11 | **Met as a document; stays open** until the pre-mount verification in that document is recorded, and (f)–(h) above are added. |
| C12 | **Met in design.** The TTL is corrected in round 20 (A11-L1). Requires the shared store in C11 (f). |
| C13 | **Met.** Info notes A11-I4, A11-I5 and A11-I6 are recommended, not required. |

## 8. The two Codex P2 findings on `a5354220`

**A11-L1 (Low): the heavy-lock TTL is shorter than the step it covers.** I agree with Codex.
- The lock is taken before the claim frame. The claim frame is bounded only by the remaining call budget, up to 300 s,
  and must pass `proveCurrent()` at its end.
- The line budget (300 s) starts after the claim frame returns. The record frame (and the failure frame) are bounded by
  that budget, plus their post-commit tails.
- So a step can hold the lock for up to about 300 + 300 s plus tails, longer than `HEAVY_LOCK_SECONDS = 360`.
- **Consequence:** an expired lock lets the same buyer's next request start a second heavy step in parallel. Its owner
  token keeps the first holder from deleting it. Correctness is unaffected, because claims, leases and claim ids decide
  the writes. By the time the lock expires the first claim's lease has also ended, so a re-claim is legitimate lease
  behaviour anyway.
- **The planned `2 × LEASE + 60` = 660 s** covers the claim frame, the line budget and a 60 s tail. The tail margin
  depends on C2. Round 20 should also check:
  - a crashed worker now blocks that buyer's preparation for up to 11 minutes, while `paidContinuation.wait` gives up
    after 6. That only means a later click is needed, but the comment justifying `wait` should be updated;
  - `complete()` uses the same TTL for a single 300 s line, which is generous but harmless.

**A11-L2 (Low): a redeem begun inside the lifetime can be refused because the server was slow.** I agree with Codex.
- `$admitted` is `now()` inside frame 1, after `locate()` and frame 1's own reads (`PaidGrantDownloads.php:127`).
- `expires_at` is `created_at + authorization_seconds`, with `created_at` taken inside the authorize frame
  (`:94`). The customer only receives the token after that frame's commit, its post-commit proofs and the network.
- So server latency is subtracted twice from a lifetime that can be as short as 30 s. On native MySQL, each owned frame
  measured 15–40 s (M2).
- Probe P4: the wall clock moves past `expires_at` while `locate()` runs, and a redeem that began before expiry is
  refused 410. Nothing is recorded, and the authorization stays usable for nothing.
- **Cost:** a fresh authorization, one of three per line per minute. No attempt is spent.

**For round 20 (request start plus 60 s on `expires_at`, the Free256 precedent):**
1. Capture the request instant server-side at `redeem()` entry, before `locate()`. Use it for both frames' `live()`
   checks, as `ProductionFreeGrantDownloads` does with `$requested`.
2. **`deadline($before['auth'])` must change too.** After frame 1 it still requires `now() < expires_at`, and
   `remaining <= 600`. Left as is, it refuses the same late-admitted requests with 410. With the extra 60 s, a
   600 s policy gives up to 660 s remaining, which exceeds the 600 bound and would refuse even fresh redeems.
3. **Effects that are acceptable:**
   - valid-to-start widens by at most the 60 s observation budget, since request start precedes admission by at most
     that;
   - the nominal lifetime becomes `authorization_seconds + 60`, still fixed per batch, so the A1 round-4 window proof
     holds;
   - `status()` (unused or expired), the client's displayed expiry and the A1-L1 80 s rule are unaffected;
   - tests that pin `expiresAt = created + authorization_seconds` will need updating.
4. Time spent queued before PHP starts is still not covered. See C11 (h).

## Runs

PHP 8.4.26 through the worktree runner (`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require
"vendor/phpunit/phpunit/phpunit";' -- <args>`), with SQLite defaults and `public/build` absent. Every run was at head
`a5354220`. Evidence is in `review-evidence/addendum11/a5354220/`.

| # | Command | Result | rc | Wall | Raw output |
| --- | --- | --- | --- | --- | --- |
| 1 | `--filter PaidGrant tests/Feature` (22 files) | **116 tests, 115 passed, 1 skipped** (the native-only `PaidGrantSchemaRecoveryTest` case, the census entry), 4,342 assertions; HeavyWork 7, PreparationBudget 3, CompletionBudget 4 | 0 | 658 s (load 5–6) | `sqlite-paid-family.{txt,xml}` |
| 2 | `--filter 'PaidGrantPreparationBudgetTest\|PaidGrantHeavyWorkTest\|PaidGrantCompletionBudgetTest\|PaidGrantDocumentJourneyTest\|PaidGrantHttpJourneyTest' tests/Feature` (new and changed) | 36 tests, 964 assertions | 0 | 212 s | `sqlite-new-and-changed.{txt,xml}` |
| 3 | Untracked probe `ReviewProbeAddendum11Test`: P4, redeem begun before expiry is refused once `locate()` ends after expiry (A11-L2) | 1/1, 17 assertions | 0 | 7 s | `sqlite-review-probe.{txt,xml}`; source `ReviewProbeAddendum11Test.php.txt` |
| 4 | `/home/user/VA-Studio/vendor/bin/pint --test` on the 8 changed PHP files | passed | 0 | | `pint.txt` |
| 5 | `python3 -I scripts/ci/test-database-receipts.py` | 34 tests OK | 0 | | `census-self-test.txt` |
| 6 | `npx vitest run` on all 10 tracked `tests/frontend/paid-grant-*.test.tsx` (vitest 5.0.1, Node 24.21.0) | 10 files, 58/58 | 0 | | `frontend-vitest.txt` |
| 7 | Untracked probe `tests/frontend/paid-grant-review-addendum11.test.tsx`: P1 live region inside `aria-busy` (A11-I5); P2 long completion and polling bounds (A11-I4); P3 backward clock step (A11-I6) | 3/3 | 0 | | `frontend-review-addendum11.txt` |
| 8 | `npx tsc --noEmit`, without and with the probe | clean | 0, 0 | | `tsc.txt`, `tsc-with-probe.txt` |

**Notes on the runs:**
- **Not recorded as runs.** Two drafts of probe P3 failed on my own test design before the passing run in row 7:
  - the clock step came after the pause was already scheduled;
  - one expectation did not account for the third progress POST.
- **No pinned renderer file changed** in the range (`PaidGrantFiles`, `PaidGrantRendererProcess`, `PaidGrantPdfRenderer`,
  `PaidGrantText`, `PaidGrantRenderInput`, `PaidGrantRenderProfile`).
- **Symlink.** `node_modules` was symlinked for rows 6–8 only and then removed (`unlink`).

## Not tested

- **Native MySQL.** This includes the claim-frame tail behind A11-I1 and §8.
- **Real infrastructure.** The `database` lock under real concurrent FPM workers, and a real proxy or FPM queue.
- **A screen reader** for A11-I5.
- **A real browser.**

## Files added by this review (untracked; nothing committed)

- This file.
- `review-evidence/addendum11/a5354220/`. It includes the PHP probe source as `.txt`; `tests/Feature/ReviewProbe*` does
  not exist.
- `tests/frontend/paid-grant-review-addendum11.test.tsx`: three documenting cases, all passing against current behaviour.

**Worktree housekeeping.** My untracked Addendum 10 copies were byte-identical to `2c3f8cb8` and blocked checkout. I moved
them to `/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/rv56a11-parked-addendum10/`.
