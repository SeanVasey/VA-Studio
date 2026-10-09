# Independent review: Paid252 composition, Addendum 15 (round 23: the admitted request instant is validated once)

**Range:** `55976099..27a151b66d10b80d07d54685791a2a3d0efd44dc` (one commit). **Integrated commit also reviewed:** `b99b5dd`,
the merge of `27a151b6` (first parent) with `origin/harness/claude-handoff-20261009` `d4b11262` (main `49489697` plus three
docs-only handoff commits).

| Commit | Content |
| --- | --- |
| `27a151b6` | Round 23 (Codex P2 4224939409):<br>• New `app/Domain/Grants/Paid/PaidGrantRequestInstant.php`: final, private constructor, `public readonly CarbonImmutable $at`, factory `capture()` holding the round 21 rule unchanged.<br>• `PaidGrantDownloads::receivedAt()` now returns that type. `redeem()` takes `?PaidGrantRequestInstant` and uses `$receivedAt?->at ?? now` with no second age check.<br>• A controller comment.<br>• `PaidGrantRequestInstantTest`: 3 new tests, 1 adapted.<br>• `docs/ops/paid-delivery-runtime.md`: the C11 (k) text, and the two nginx regex locations are quoted.<br>• CHANGELOG and `hardening/codex-23/`. |

**No schema, migration, config, route, renderer or lockfile change.** `git diff --stat 55976099 27a151b6 -- database config
routes bootstrap resources composer.json composer.lock package-lock.json` is empty (`range-stat.txt`).

I wrote none of the code under review. Nothing was committed or pushed, no product code was modified, and no MySQL daemon
was started. **Reviewer:** Claude (independent review lane). **Date:** 2026-10-09.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge of `27a151b6` and of the integrated commit `b99b5dd`.**

- The fix is correct. The admitted instant is validated once, at capture, against the server's own clock. Both frames
  and the post-frame `deadline()` check compare that same instant. Every other check (token, identity, ownership, order
  state, the one-attempt rule) is still judged at frame time.
- The retained red, both mutations and the green file reproduce in my own worktree.
- **One Low finding (A15-L1):** the new C11 (k) text undercounts the identity proof. Its first remedy, a lower
  `innodb_lock_wait_timeout`, cannot meet the bound in the worst case. This is a correction to a pre-mount deployment
  condition of an unmounted family, so it does not block a development merge. It is folded into C11 (k).
- Four Info items (§6).
- **`b99b5dd` is source-equivalent to `27a151b6` for the paid family.** The diff over every paid path is 0 bytes. The
  request-instant file and my probe pass on `b99b5dd`. This decision therefore carries to `b99b5dd`.

## 1. Adversarial questions

| Question | Finding |
| --- | --- |
| Can a client set the admitted instant? | **No.** The controller reads only `$request->server('REQUEST_TIME_FLOAT')`, which is `$_SERVER` through Symfony's `createFromGlobals()`.<br>**Real PHP-FPM 8.4.26** (`fpm-request-time-float.txt`): a FastCGI param `REQUEST_TIME_FLOAT=1.5` is overridden in `$_SERVER` by the SAPI's own `double`. It survives only in `getenv()`, which the code never reads. Client headers arrive as `HTTP_*` strings.<br>**Probe P1, over HTTP:** `REQUEST_TIME_FLOAT`, `Request-Time-Float`, `X-Request-Time`, `X-Request-Start` and `Request-Time` headers, and the same names injected as `HTTP_*` server keys, all claim a start 5 s before expiry. Each request gets 410 with nothing recorded. The control, using the server value only, gets 200. |
| What if a runtime supplies the server value as a string? | **Stricter only.** Only a PHP `float` or `int` is a candidate. A numeric string falls back to `LARAVEL_START`, then to now (`capture-edges.txt`; the author's fallback test gets 410). Under FPM the value is a `double` (above). |
| Does the clamp rule hold at its edges? | **Yes** (`capture-edges.txt`, 24 cases × 5 `LARAVEL_START` settings, 120 as documented). See §2 for the table. The only divergence is A15-I1. |
| Can the type be forged? | **Only by in-process code.** The class is final, the constructor private and `at` readonly, so `capture()` is the only ordinary way in. `unserialize()` of a crafted string, or `newInstanceWithoutConstructor()` plus a scope-bound closure, yields an instance with any `at`, and `redeem()` trusts it (probe P4: a forged instant 2 s before expiry delivers 120 s after expiry).<br>Neither path is reachable from untrusted input in this codebase: no `unserialize()` of request data; sessions use `json` serialization; cache `serializable_classes` is false; the type is never queued, stored or serialized (`static-reachability.txt`).<br>An initialized instance cannot be changed, even from class scope ("Cannot modify readonly property"). A clone carries the same validated value. See A15-I2. |
| Does `redeem()` judge only the expiry at the old instant? | **Yes.** `$admitted` is used by `live()` inside `$inspect`, which runs in frame 1 and again in the commit frame, and by `deadline($before['auth'], $admitted)`. Nothing else reads it.<br>Probe P3a: an authorization already redeemed is refused 409, although its instant is old and valid. Probe P3b: a buyer account deactivated after capture is refused 403. Nothing is recorded in either case. |
| Is expiry valid-to-start, and is an expired-on-arrival request refused cleanly? | **Yes.** `live()` requires `admitted < expires_at`. Probe P2: a request received exactly at expiry gets 410 with nothing recorded; 1 µs earlier gets 200 and the exact bytes. The author's test covers arrival 1 s after expiry. |
| Does removing the second age check widen anything an attacker controls? | **No.** The instant lives inside one request and cannot be backdated: SAPI-only, at most 60 s old at capture. The added lateness is the identity proof's own duration. Only the buyer's own session can run the proof for a token bound to the buyer's account, and every frame re-proves current authority.<br>A buyer could at most slow their own proof (for example, by holding their identity rows with concurrent paid requests) to redeem their own token after expiry. That is no gain over redeeming on time. A leaked token still needs the owner's session. The remaining exposure is the proxy 504 in C11 (k) (§5). |
| Does the new HTTP test prove a real 65 s wait? | **No, and the record should say so.** See §4. |

## 2. The capture rule (`capture-edges.php.txt`, run outside PHPUnit so `LARAVEL_START` can be defined per process)

Frozen now: `12:00:00.250000`.

| Input | Result |
| --- | --- |
| +1.0 s | clamped to now (accepted) |
| +1.000001 s | rejected → `LARAVEL_START` if admissible, else now |
| −60.0 s | admitted as given |
| −60.000001 s | rejected → fallback |
| −60.0000004 s | admitted. Carbon rounds to the microsecond, so a 0.4 µs leniency, immaterial. |
| int `now − 30` | 11:59:30.000000 (the integer drops the 0.25 s) |
| int `now + 1` (0.75 s ahead) | clamped to now |
| int `now + 2` | rejected → fallback |
| −5.123456 s | microseconds kept |
| 0, 0.0, −0.0, −1.0, 1e12 | fallback |
| NaN, ±INF, a numeric string, `true`, `false`, `null`, an array, a Stringable object | fallback |
| **1e20, `PHP_INT_MAX`, −1e15** | **throws `Carbon\Exceptions\InvalidFormatException`** (A15-I1) |

**Fallback order with `LARAVEL_START` defined:**

| `LARAVEL_START` | Result for a rejected `REQUEST_TIME_FLOAT` |
| --- | --- |
| −10 s | `LARAVEL_START` |
| −61 s | now |
| +0.5 s | clamped to now |
| +2 s | now |

`now` is read once, before both candidates.

## 3. Immutable typed admission

The structural test asserts final, private constructor, readonly `at`, `capture` as the only public static method, and the
type of `redeem()`'s fifth parameter. My M2 (public constructor) fails it (`mutation-M2.txt`). The bypasses in §1 need
in-process reflection or `unserialize()`.

## 4. What the slow-identity test does and does not prove

`slowIdentity()` listens for `TransactionCommitted` at transaction level 0 with `ProductionCustomerSessions::principal` on
the stack. That is the commit of the first `source()` transaction inside `principal()` (via
`ProductionCustomerPrincipal::forUser()`). The listener then calls `$this->travel(65)->seconds()`, which moves **Carbon's
test clock only**. The rest of the proof and `redeem()` run at the new wall time.

**What it proves:** the time judgement. The instant captured before the proof is the one compared with `expires_at` after
it.

**What it does not prove:**
- no real 65 s elapses;
- no InnoDB lock wait, FPM worker or proxy is involved;
- `hrtime()` does not move, so the monotonic `PaidGrantDeadline` budgets are untouched. This matches production, where
  those budgets start inside `redeem()`, after the proof.

The real-time consequences of a slow proof are exactly what C11 (k) covers. They remain untested.

## 5. C11 (k): the identity proof before `redeem()`

**The requirement is right and belongs in C11.** The `redeem` first-byte bound (≤ 420 s at the defaults, proxy 480 s) is
counted from `redeem()`. The session-lock wait (≤ 5 s) and the identity proof come before it, and the proof has no
deadline of its own. So a slow proof can still lead to a recorded attempt while the proxy answers 504. That was already
true before this round, for tokens still live after the proof. Round 23 extends it to tokens that expired during the proof.

**A15-L1 (Low): the author's count is wrong, and the first remedy is insufficient.** The author describes the proof as "a
short transaction of locking reads plus a terminal re-read". From the code (`static-reachability.txt` §C):

- **Two `source()` calls.** `principal()` calls `ProductionCustomerAccess::source()` twice: through
  `ProductionCustomerPrincipal::forUser()` and through `current()`.
- **Four `read()` passes.** Each `source()` runs `read()` inside a transaction and again as a terminal read in autocommit.
- **Statements per `read()`.** Each `read()` issues `5 + 4 + k` `SELECT … FOR UPDATE` statements on MySQL:
  - 5 in `read()`;
  - 4 in `historical()`;
  - 1 per verification observation, where `k` is the observation count, 1 to 128.

  On MySQL, `IdentityRows::rows()` appends `FOR UPDATE` whenever there is no committed frame, and an autocommit locking
  read still waits for row locks.
- **The total.** The proof therefore issues 4 × (9 + k) locking reads: **40 for a single-verification account, up to
  548.**
- **Metadata statements.** Every `rows()` call first runs `assertTable()`: `SELECT DATABASE()`, an `information_schema`
  query and `SHOW CREATE TABLE`. Each `read()` also runs `assertPermanent()`. Their metadata-lock waits are bounded by
  `lock_wait_timeout` (MySQL default 31,536,000 s), not by `innodb_lock_wait_timeout`.

**The bound the proof must fit.** The proof must fit the 60 s margin between the 420 s first-byte bound and the 480 s proxy
timeout, less the 5 s session-lock wait.

**Why lowering `innodb_lock_wait_timeout` cannot meet it:**
- Its minimum is 1 s. At k = 128 that still allows 548 s of row-lock waits.
- It does not touch metadata-lock waits at all.

**Recommendation, folded into C11 (k):**
- The explicit budget is the remedy that closes it. It should be measured from the captured request start, and it must
  be checked before anything is recorded: before `locate()`, and again before the commit frame inserts the attempt. Then
  any time spent before `redeem()` counts against the proxy timeout.
- If the database-timeout route is also used, it must set `lock_wait_timeout` as well as `innodb_lock_wait_timeout` for
  the paid connection, and be sized for 4 × (9 + 128) statements.
- The pre-mount verification bullet should name both variables and the statement count.

**The nginx quoting is correct and was needed** (A15-I4). Run with `nginx -t` (nginx 1.24.0):
- The reference block before this round fails with `unknown directive "36}/redeem$"`, rc 1.
- The quoted block at `27a151b6` and at `b99b5dd` passes, rc 0 (`nginx-quoting.txt`).
- Main's `ops/staging/nginx/vasey-staging.conf`, merged in `b99b5dd`, already uses the quoted form.

**The open Codex thread.** `PRRT_kwDOU5febs6qfw0X` (top comment 4222614269, `PaidGrantTransfer.php`: blocked downstream
writes hold global spool slots) stays **open**. It is C11 pre-mount runtime verification (requirements 1–4 and the
1 GiB slow-client bullet), not a clean review, and I do not call it resolved. I did not query GitHub for its state; this
is as reported in `docs/handoff/2026-10-09/CLAUDE-HANDOFF.md`.

## 6. Findings

| ID | Severity | Finding |
| --- | --- | --- |
| A15-L1 | Low (docs; deployment condition) | C11 (k) undercounts the identity proof and offers an insufficient remedy (§5). Folded into C11 (k). It does not block a development merge of an unmounted family. |
| A15-I1 | Info | A finite `REQUEST_TIME_FLOAT` or `LARAVEL_START` outside Carbon's range (about \|x\| ≥ 1e15) makes `capture()` throw `InvalidFormatException`, instead of the documented "anything else falls back to now". `LARAVEL_START` is not tried.<br>This is pre-existing: round 21's `receivedAt()` throws the same way (`55976099/out-of-range-preexisting.txt`). It fails closed: probe P5 shows the generic 503 before the identity proof, with nothing recorded. It is unreachable from clients, because the SAPI sets the value.<br>Optional fix: a magnitude guard before `createFromTimestamp()`, or catch and fall back. |
| A15-I2 | Info | `PaidGrantRequestInstant` lacks the family's serialization guard: `__serialize`/`__unserialize` that throw, as in `PaidGrantReadReceipt`, `PaidGrantProjectionRead` and `PaidGrantConsumerCommitAdmission`. So its docblock claim ("the only way to obtain one is `capture()`") does not hold against `unserialize()`. No untrusted path reaches it (§1).<br>Recommend adding the refusals for consistency. A private `__clone` is optional, since a clone keeps the validated value. |
| A15-I3 | Info | The author's `hardening/codex-23/README.md` lists `docs/ops/paid-delivery-runtime.md` under "Not changed", but `27a151b6` changes it (C11 (k) and the quoting). This is a record inconsistency only. |
| A15-I4 | Info (positive) | The nginx regex quoting fixes a reference block that did not parse (§5). |

## 7. The integrated commit `b99b5dd`

- **Parents.** `b99b5dd^1 = 27a151b6` and `b99b5dd^2 = d4b11262`.
- **Paid paths.** `git diff 27a151b6 b99b5dd` over `app/Domain/Grants/Paid`, `PaidGrantController.php`,
  `app/Http/Middleware`, `routes/paid-grants.php`, `config/paid-grants.php`, `database`, `scripts/render-paid-grant.php`,
  `resources/contracts`, `tests/Feature/PaidGrant*` and `tests/Support` is **0 bytes**.
- **The rest of the merge.**
  - **The only `app/` change:** `app/Console/Commands/ManageRightsScopes.php`, a new operator command wrapping the existing
    `ManageRightsScope` service. The paid domain does not reference rights scopes.
  - **No change** to `config/`, `database/`, `routes/`, `bootstrap/`, `resources/`, `composer.json`/`composer.lock`,
    `package*.json`, `phpunit.xml` or `scripts/ci`.
  - **Everything else:** the staging ops kit (`ops/staging/`, including a paid-delivery FPM pool and nginx locations
    documented as scaffolding for unmounted routes), the test-commerce scripts and units, rights-scope command tests,
    docs and `CLAUDE.md`.
  - **None of it touches** the production identity, order source V1, storage or migration ordering that the paid family
    depends on.
- **Still unmounted.** On `b99b5dd`, `grep -rn 'paid-grants.php\|PaidGrant'` over `bootstrap`, `routes/web.php`,
  `routes/console.php` and `app/Providers` finds nothing (rc 1). The family remains development-only, default-off and
  unmounted.
- **Tests.** `PaidGrantRequestInstantTest` (7) plus my probe (6) pass on `b99b5dd`: 13/13, 241 assertions.
- **Ruling.** The original-range decision carries to `b99b5dd`, which is source-equivalent for the paid family. The full
  25-file paid family on `b99b5dd` is the integrator's run, not mine.

## Runs

PHP 8.4.26 through the worktree runner, with SQLite defaults and `public/build` absent. I used one worktree,
`/home/user/rv56-a15`, moved between `27a151b6`, `55976099` and `b99b5dd`, with `composer dump-autoload` each time. Its
`vendor/` is a physical copy without `.git` directories (1.2 GB). `composer.lock` is identical across all three commits.
Evidence is in `review-evidence/addendum15/<sha8>/`.

| # | Source | Command | Result | rc | Raw output |
| --- | --- | --- | --- | --- | --- |
| 1 | `27a151b6` | `git diff --stat 55976099 27a151b6` (all, and the schema/config/route/lockfile paths) | 15 files; paths empty | 0 | `27a151b6/range-stat.txt` |
| 2 | `27a151b6` | `tests/Feature/PaidGrantRequestInstantTest.php` | **7/7, 129 assertions**, 66 s | 0 | `27a151b6/request-instant-file.txt` |
| 3 | `27a151b6` | `php capture-edges.php.txt <wt> [−10\|−61\|+0.5\|+2]` | 120 as documented. 15 DIFF = the 3 out-of-range cases × 5 runs (A15-I1, intended to show it) | 1 ×5 (intended) | `27a151b6/capture-edges.txt` |
| 4 | — | Real `php-fpm8.4` pool + raw FastCGI client | `REQUEST_TIME_FLOAT` is a `double` and overrides the FastCGI param. Headers arrive only as `HTTP_*`. | 0 | `27a151b6/fpm-request-time-float.txt` (+ `fpm-probe*.txt`) |
| 5 | `27a151b6` | Untracked probe `ReviewProbeAddendum15Test` (P1–P5) | **6/6, 112 assertions** | 0 | `27a151b6/review-probe.txt`; source `ReviewProbeAddendum15Test.php.txt` |
| 6 | `27a151b6` + M1 | The second age check reintroduced in `redeem()`; the file | 7 tests: 1 error, 1 failure (the two new behaviour tests). Restored: `git diff --exit-code` rc 0. | 2 | `27a151b6/mutation-M1.txt` |
| 7 | `27a151b6` + M2 | Public constructor; the structural and rule tests | 2 tests: 1 failure (structural). Restored: rc 0. | 1 | `27a151b6/mutation-M2.txt` |
| 8 | `27a151b6` | `vendor/bin/pint --test` on the 4 changed PHP files | passed | 0 | `27a151b6/pint.txt` |
| 9 | `27a151b6` | Static greps: creators and consumers of the type, serialization sinks, identity call chain and `FOR UPDATE`, mounting | as §1, §5 | 0/1 as labelled | `27a151b6/static-reachability.txt` |
| 10 | `27a151b6` | `nginx -t` on the reference block extracted from the ops doc at each commit | `55976099`: unknown directive, rc 1. `27a151b6` and `b99b5dd`: ok, rc 0. | 1/0/0 | `27a151b6/nginx-quoting.txt` (+ `nginx-extract.py.txt`) |
| 11 | `55976099` | Retained red: product unmodified + the two new behaviour tests | **6 tests, 107 assertions, 1 error (domain 410 from `live()`) + 1 failure (HTTP 410 ≠ 200)**. Matches the author's record exactly. | 2 | `55976099/red-request-instant.txt`; file `PaidGrantRequestInstantTest-red.php.txt` |
| 12 | `55976099` | `receivedAt()` on 1e20, `PHP_INT_MAX`, −1e15, NaN | the same throws (A15-I1 is pre-existing) | 0 | `55976099/out-of-range-preexisting.txt` |
| 13 | `b99b5dd` | Parents, ancestry and paid-path diff, delta, mounting grep | paid diff 0 bytes; unmounted | 0 | `b99b5dd/integration-delta.txt` |
| 14 | `b99b5dd` | `--filter 'PaidGrantRequestInstantTest\|ReviewProbeAddendum15Test' tests/Feature` | **13/13, 241 assertions**, 139 s | 0 | `b99b5dd/request-instant-and-probe.txt` |

**Not recorded as runs:**
- **The first probe run (row 5).** It ran at `27a151b6` before I redirected output to a file. It gave the same result
  (6/6, rc 0), and the recorded run replaced it.
- **The first edge run (row 3).** It held one wrong expectation of my own: I expected `+1.0000004 s` to round down to
  +1.000000. The float lands at +1.000001 and is correctly rejected. I fixed the expectation, and the recorded run
  replaced it.
- **The edge run under the old name.** Row 3 was then re-run after I renamed the probe from `.php` to `.php.txt`, so
  nothing executable sits under `docs/`. The table shows that re-run.
- **Setup.** `rsync` is not installed, so the vendor copy used `tar --exclude=.git`.
- **nginx.** nginx 1.24.0 was already present (`apt-get install` reported it as the newest version). `nginx -t` created
  `/var/lib/nginx/paid-delivery-temp`, which I removed afterwards.

## Conditions

| ID | Status |
| --- | --- |
| C1, C3, C9, C10 | Resolved or met (unchanged). |
| C2 | Open, as extended in Addenda 9, 11 and 12. |
| C4–C8 | Open, unchanged. |
| C11 | Met as a document for items (a)–(j). **Item (k) is added** (the identity proof before `redeem()` against the proxy first-byte timeout). It is **open for Sean and the pre-mount host verification**, with the A15-L1 corrections: 4 × (9 + k) locking reads; `lock_wait_timeout` as well as `innodb_lock_wait_timeout`; and an explicit budget from request start, checked before anything is recorded. Codex thread `PRRT_kwDOU5febs6qfw0X` stays open under C11 pre-mount verification. |
| C12, C13 | Met. |

## Not tested

- **A real slow identity proof:** InnoDB row-lock or metadata-lock waits on native MySQL, through a real FPM worker and
  proxy.
- **The whole Laravel app under a real FPM request.** Row 4 tests the SAPI variable only.
- **Persistent-worker runtimes (Octane)** (C11 (j)).
- **The full 25-file paid family** on either commit (the integrator is running it on `b99b5dd`), and the full suite.
- **nginx beyond `nginx -t`**, and a real browser.

## Files added by this review (untracked; nothing committed)

- This file.
- `review-evidence/addendum15/27a151b6/`, `review-evidence/addendum15/55976099/` and
  `review-evidence/addendum15/b99b5dd/`.
- The PHP probe and the edge probe are kept only as `.txt` sources under evidence. `tests/Feature/ReviewProbeAddendum15Test.php`
  does not exist in the worktree, and `tests/Feature/PaidGrantRequestInstantTest.php` was restored after the red run.
