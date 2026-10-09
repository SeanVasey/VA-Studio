# Paid252 round 23: the admitted request instant is validated once, at capture

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. The change is
uncommitted on `harness/paid252-composition` at `55976099` ("55976099 + working tree").

**Not changed:**
- no schema, migration, guard, receipt, fence, raw proof or post-commit proof;
- no pinned file (`evidence/pinned-check.txt`);
- `docs/ops/paid-delivery-runtime.md`. Requirement 9 still describes the code: the request-start time is clamped to the
  last 60 s, and that now happens once, at capture.

## Finding (Codex P2 4224939409, `PaidGrantDownloads.php:167`)

Round 21 captured the request-start time in the controller, before the identity proof. `redeem()` then ran the same
age check (`admissible()`) a second time, after the proof.

**Trace (before the fix):**
1. `PaidGrantController::redeem()` captures `$receivedAt` from `REQUEST_TIME_FLOAT`. It is valid: within 60 s of now.
2. `identity()` calls `ProductionCustomerSessions::principal()`. Suppose it takes more than 60 s.
3. `redeem()` re-checks `$receivedAt` against the new now. It is now more than 60 s old, so it is treated as broken and
   replaced with now.
4. If the authorization expired during the proof, `live()` refuses 410 in frame 1, although the request arrived in
   time. Nothing is delivered.

## Fix

**New type: `App\Domain\Grants\Paid\PaidGrantRequestInstant`.**
- It is `final`, with a private constructor and `public readonly CarbonImmutable $at`.
- Its only factory is `capture(mixed $requestTime)`. That holds the round 21 rule unchanged, moved from
  `PaidGrantDownloads`:
  - `REQUEST_TIME_FLOAT`, else `LARAVEL_START`, else now;
  - only a finite PHP float or int;
  - up to 1 s ahead is clamped to now;
  - at most `ADMISSION_MAX_AGE_SECONDS` (60 s) old;
  - anything else falls back to now.
- No caller can make one from an arbitrary time. The only way in is validation against the clock at capture.

**`PaidGrantDownloads`:**
- `receivedAt()` now delegates to `PaidGrantRequestInstant::capture()` and returns the type, so the controller line is
  unchanged.
- `redeem()` takes `?PaidGrantRequestInstant` (null still means now).
- The admitted instant is `$receivedAt?->at ?? now`. There is no second age check. The private `admissible()` moved
  into the new type.
- `ADMISSION_MAX_AGE_SECONDS` stays on `PaidGrantDownloads`. Its doc now says it is checked once, at capture.

**Unchanged:** frames 1 and 2 and the post-frame check still compare the same admitted instant, including
`$this->deadline($before['auth'], $admitted)`. Tokens, identity, `locate()`, owner checks, the attempt limit and every
budget still run as before.

**Controller.** One comment now records that the value is validated once into a type only capture can create.

## Risk: can a request admitted at T redeem much later?

**Conclusion: yes, by up to the identity proof's duration. That is acceptable, and the fix widens nothing an attacker
controls.**

**1. How long the proof can take.** The prompt assumed the identity proof is bounded by the session-lock wait and a
budget of its own. That does not hold as stated:
- The session-lock wait (`block(20, 5)`, at most 5 s) runs in middleware *before* the controller. It is already
  inside the 60 s capture age, not after it.
- `ProductionCustomerSessions::principal()` → `ProductionCustomerAccess::current()` has no `PaidGrantDeadline`. It runs
  a short transaction of locking reads (`FOR UPDATE` on MySQL) plus a terminal re-read.
- So its duration is bounded only by:
  - the database's per-statement lock wait (MySQL `innodb_lock_wait_timeout`, 50 s by default);
  - the number of statements;
  - ultimately the paid pool's `request_terminate_timeout` (7,800 s at the defaults in C11).

**2. Why that is acceptable:**
- **Expiry is valid-to-start by design.** It is judged at the moment this request arrived (rounds 20–21). A request
  that arrives after expiry captures a fresh instant at or after expiry, and is refused 410 with nothing recorded
  (tested). A later request cannot reuse an earlier capture, because the instant lives only inside one request.
- **It cannot be backdated.** The value comes from the SAPI, never a header. It is validated against the clock when
  captured, and the type cannot be built any other way (structural test, mutation M2).
- **The client cannot stretch the proof.** The proof is database reads of the buyer's own identity rows, after the
  body is already parsed. Contention from the buyer's own concurrent paid requests is bounded by each frame's 60 s
  budget and the database lock-wait timeout.
- **Everything else is still proved current.** The authorization still allows one attempt. Frames 1 and 2 re-prove
  current identity, ownership, the unchanged authorization and order state, and the attempt limit, all at frame time.
  A buyer revoked, refunded or signed out during a slow proof is still refused. Only the expiry comparison uses T.
- **The old code had no cap either.** It never capped processing time for a token still live after the proof. It only
  refused when expiry fell inside the proof. So the fix stops a wrongful refusal and adds no new path.
- **Comparable to lateness already accepted.** Delivery after expiry already happens through the snapshot (up to
  `snapshot_seconds`, validated maximum 1,800 s) and the transfer.

**3. Adjacent, pre-existing, not changed here (for the integration owner).** The C11 first-byte bound
(`docs/ops/paid-delivery-runtime.md`, "locate + frame 1 ≤ 60 s …", proxy `fastcgi_read_timeout` 480 s) counts from
`redeem()`. It leaves out the session-lock wait and the identity proof. If the proof outlasts the 60 s proxy margin,
the server can commit the attempt while the proxy returns 504.
- This was already true before this round, for any token still live after the proof.
- This round extends it to tokens that expired during the proof.
- Options: an explicit identity-proof budget that refuses *before* `locate()`, so nothing is recorded, or a first-byte
  bound in C11 that includes the identity proof. Both are outside this finding.

## Tests (`tests/Feature/PaidGrantRequestInstantTest.php`, +3 new, 1 adapted)

| Test | What it proves |
| --- | --- |
| `test_an_identity_proof_longer_than_the_capture_age_bound_does_not_expire_a_request_received_in_time` (new, HTTP) | **The regression.** Received 2 s before expiry. The identity proof (`ProductionCustomerSessions::principal`) then takes 65 s of wall time, ending 63 s after expiry. Result: 200, the exact master bytes, and one recorded attempt. |
| `test_a_captured_instant_admits_the_redemption_after_more_than_the_age_bound_has_passed` (new, domain) | **The same rule at the domain boundary.** `receivedAt()` is captured 2 s before expiry. The clock then moves 65 s. `redeem(…, $instant)` delivers the exact bytes and records one attempt. |
| `test_the_admitted_instant_can_only_be_obtained_by_capture_time_validation` (new) | **Only capture can create the type.** The class is final, the constructor private and `at` readonly. `capture` is the only public static method. `redeem()`'s fifth parameter has this type. A value 0.0 (the epoch) falls back to now at capture. |
| `test_the_admission_instant_rule` (adapted to `->at`) | **The capture rule, unchanged.** −5 s and −60 s are accepted; +0.5 s is clamped to now. +1.5 s, −61 s, NaN, ±INF, null, strings, `true` and `[1]` all give now. `receivedAt()` equals `capture()`. |
| Unchanged | **Fallback still applies.** A stale or future value at capture (and every other rejected form) is refused 410 with nothing recorded. A request received after expiry is refused 410 with nothing recorded. A 5 s slow identity is admitted. |

The slow identity proof moves only the wall clock, through the existing `TransactionCommitted` hook on
`ProductionCustomerSessions::principal`. That matches the real effect: the frames' monotonic budgets start inside
`redeem()`, after the proof. No skipped test was added.

## Results (`evidence/`)

PHPUnit runs from the worktree root with `public/build` absent:
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never <args>`.

| Run | Source | Result |
| --- | --- | --- |
| Red | `55976099` product (unmodified) + the two new behaviour tests | 6 tests: 1 failure (410 instead of 200, HTTP) and 1 error (`PaidGrantException` 410 from `live()`, domain); rc 2 (`red-on-55976099.txt`) |
| Green, test file | `55976099` + final working tree (SHA-256 of the 4 PHP files in the file) | 7 tests, 129 assertions, rc 0 (`green-request-instant.txt`) |
| M1: the second age check after identity is reintroduced | final tree + mutation, restored and checked with `cmp` | the same 2 tests fail, rc 2 (`mutation-M1-second-age-check.txt`) |
| M2: a public constructor | final tree + mutation, restored and checked with `cmp` | structural test fails, rc 1 (`mutation-M2-public-constructor.txt`) |
| Paid family, `--filter PaidGrant tests/Feature` (25 files) | `55976099` + working tree | 136 tests (3 of them new), 4,540 assertions, 1 skipped (pre-existing), rc 0, 14 min 21 s (`family-green.txt`) |
| Pint `--test`, 4 PHP files | final working tree | passed, rc 0 (`pint.txt`) |
| Census self-test `python3 -I scripts/ci/test-database-receipts.py` | final working tree | 34 tests OK, rc 0 (`census-self-test.txt`). Run for completeness; no skip was added. |

**One edit during the family run.** While the family run was in progress, one docblock line in `PaidGrantDownloads.php`
was reworded ("validated once now, at capture" became "validated once, when captured"). The change is a comment only.
The test file and M1 were then re-run against the exact final file. Both results above come from that re-run.

The family run's assertion count (4,540) is not comparable with round 21's (4,662), because round 21 ran on a different
source (`703674d7`).

## Not tested

- **A real FPM `REQUEST_TIME_FLOAT`, or a real slow identity proof** (lock waits). The value is injected through the
  test request's server bag, and the slow proof is simulated by moving the wall clock.
- **Native MySQL.** The family ran on SQLite only.
- **The frontend.** No client file changed.

## For Sean

A download that reaches the server before its authorization expires now goes through even if the sign-in check that
follows takes more than a minute. Previously that case was refused and the customer got nothing.
