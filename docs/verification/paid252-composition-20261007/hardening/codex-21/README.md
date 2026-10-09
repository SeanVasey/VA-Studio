# Paid252 round 21: request-start admission and spool crash recovery, plus review addendum 12 constraints

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance.

**Source.** The work is uncommitted on `harness/paid252-composition`. It was started on `36be2a33`. The coordinator then
committed review addendum 12 as `703674d7` (docs and the reviewer's frontend probe). The paid product files are
identical at both commits, so results say "703674d7 + working tree".

**Not changed:**
- no schema, migration, guard, receipt, fence, raw proof or post-commit proof;
- no pinned file (`evidence/pinned-check.txt`);
- `docs/ops/paid-delivery-runtime.md`, which the coordinator edits in this tree.

## A. Admission at the server's request start (Codex P2 4224514947; addendum 12 clamp)

**Finding.** `PaidGrantController::redeem()` ran `identity()` (`ProductionCustomerSessions::principal()`, a
current-identity database proof) before `redeem()` took its admitted instant. A slow proof near expiry still refused a
token that was valid on arrival.

**Fix.**

- **Controller.** `PaidGrantController::redeem()` first takes
  `$receivedAt = PaidGrantDownloads::receivedAt($request->server('REQUEST_TIME_FLOAT'))`, before `identity()`, and passes
  it to `redeem()` as an explicit parameter.
- **Where the time comes from.** `REQUEST_TIME_FLOAT` is set by the SAPI, never by a client header. `LARAVEL_START`
  (defined at the top of `public/index.php`, also before any identity work) is the only fallback.
- **Clamp (addendum 12).** A candidate is accepted only if all of these hold:
  - it is a PHP `float` or `int`;
  - it is finite;
  - it is no more than 1 s after now (clock granularity), and is clamped to now;
  - it is not earlier than now − `ADMISSION_MAX_AGE_SECONDS` (60 s).

  Otherwise the controller's own now is used, which can only make admission stricter.
- **Numeric strings are rejected.** The SAPI always gives a float, so a string is treated as broken. This is stricter
  than "numeric", and safe.
- **Why 60 s.** Between request start and the controller there is only bootstrap, middleware (the session lock waits at
  most 5 s) and parsing; the identity proof runs after the capture. 60 s is one observation budget. The earlier
  "whole-request bound" (thousands of seconds) would let a broken value backdate admission far more.
- **Inside `redeem()`.** The passed instant is checked again by the same rule (`admissible()`); null means now. Frames 1
  and 2 and the post-frame `deadline()` use it exactly as in round 20.

## B. Spool slot recovery after a worker crash (Codex P2 4224514955; addendum 12 constraints)

**Finding.** A worker killed mid-copy leaves its named `slot-N.snapshot`, because its `finally` never runs. `slot()`
skipped any slot holding a snapshot, so after three crashes every redemption failed with `target_unavailable`.

**Fix (`PaidGrantPrepareStream::slot()` plus the new `recovered()`).**

- **When recovery runs.** Only after this process has taken the slot's exclusive `flock` (`LOCK_EX|LOCK_NB`). A holder
  keeps that lock until its stream closes, and the kernel drops it when the process dies, so holding it proves no live
  process owns the slot.
- **Sidecars first.** The reservation and holder sidecars are checked exactly as before. A malformed sidecar still skips
  the slot.
- **What is recovered.** The leftover snapshot is removed only if it is all of these:
  - a regular file (not a symlink, checked with `lstat`), with `realpath` equal to its path;
  - nlink 1;
  - uid equal to this process's euid (the spool owner `slot()` already uses);
  - mode 0600 or 0400;
  - size ≤ `DeliveryAssetFiles::MAX_BYTES` (1 GiB).

  Mode 0400 is included because the spool seals a snapshot to 0400 before its read-back; a worker killed during a long
  read-back leaves exactly that.
- **How it is removed.** The leftover is re-`lstat`ed to the same dev and inode, unlinked through the existing
  `unlinkSpool()`, and both sidecars are reset to zeros with the existing `reservation()` and `write()` writers. All of
  this happens under the admission lock that `admit()` already holds.
- **Anything else** is skipped and never touched.
- **Operator report.** `Log::warning('Paid delivery spool slot recovered after a worker stopped mid-snapshot.', ['slot' =>
  N])` with the slot index only. The paid code has no operator log or audit channel of its own, so this uses the default
  application log. It carries no path, hash or customer data.

**Do `pending()` and `holders()` need the sidecars cleared? No.** Both probe each other slot's lock with `LOCK_NB` and
skip any slot whose lock is free, so a crashed holder's sidecars are already ignored. `admit()` also overwrites both as
soon as it takes the slot. The reset in `recovered()` is therefore belt and braces, so that no stale value lingers.

## A12-I4: focus after Stop

"Stop preparing" removes its own button, so focus fell to `body`. The progress line (`role="status"`) now has
`tabIndex={-1}`, and `stopPreparing()` focuses it. The reviewer's committed probe P4 in
`tests/frontend/paid-grant-review-addendum12.test.tsx` asserted the old behaviour. It now asserts the fix and is retitled
"fixed in round 21".

## Tests

| File | Case |
| --- | --- |
| `PaidGrantRequestInstantTest` (new, 4, HTTP) | **Slow identity:** received 2 s before expiry, while the identity proof takes 5 s of wall time (until 3 s after expiry). Admitted (200, exact bytes, 1 redemption). |
| | **Late arrival:** received after expiry, so refused 410 with nothing recorded. |
| | **Each fallback case:** the controller runs 3 s after expiry. Each of these falls back to now and is refused 410 with nothing recorded: missing (`null`), `'abc'`, a numeric string, NaN, INF, `true`, `[1]`, 120 s in the future, and 61 s old. The control case, a valid value 8 s earlier (before expiry), is admitted. |
| | **The rule itself:** −5 s accepted, −60 s accepted, +0.5 s clamped to now; +1.5 s, −61 s, NaN, ±INF, null, strings, `true` and arrays all give now. |
| `PaidGrantSpoolRecoveryTest` (new, 6) | **Crash leftovers:** a leftover at mode 0600, then one at 0400, each with live-looking sidecars, is recovered; the snapshot succeeds; the reservation is zero; one operator warning per recovery, `['slot' => 0]`. |
| | **All three slots:** three leftovers, then three nested snapshots holding all three slots, all succeed. |
| | **Bad shapes:** a symlinked, 0644 or two-link leftover is skipped; the inode, mode, nlink and size of every slot file are unchanged; the symlink target is untouched; `target_unavailable`. |
| | **Too large:** a leftover over 1 GiB (sparse) is skipped untouched, with no warning. |
| | **Malformed sidecar:** a leftover with a malformed sidecar is skipped untouched, and the next slot is used. |
| | **Live holder:** a slot whose `flock` a live child process (Python `fcntl.flock`) holds is never recovered. Once the child exits, it is recovered. |
| Probe (not committed; `evidence/probe/`) | **Another owner:** a leftover `chown`ed to uid 65534 is skipped untouched. This needs root, so it is not a committed test (that would add a skip in non-root CI). It was run as root here: 1 test, 6 assertions, rc 0. |
| `tests/frontend/paid-grant-continuation.test.tsx` (+1) | Focus moves from Stop to the progress line, not `body`. |

No SQLite skips were added.

## Results (`evidence/`)

PHPUnit runs from the worktree root with `public/build` absent:
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <args>`.
Frontend runs use Node 24.21.0 with a temporary `node_modules` symlink, since removed.

| Run | Source | Result |
| --- | --- | --- |
| Red, first test versions | `36be2a33` product + new tests | 9 tests, 3 errors, 3 failures, rc 2 (`red-on-36be2a33.txt`) |
| Red, final tests | `703674d7` product (scratch worktree, removed) + final tests | 10 tests, 3 errors, 3 failures, rc 2 (`red-final-tests-on-703674d7.txt`) |
| Paid family `--filter PaidGrant tests/Feature` | `703674d7` + final working tree | 133 tests (123 + 10), 4,662 assertions, 1 skipped, rc 0 (`family-green-final.txt`) |
| A1: controller passes no request time | final tree + mutation | slow-identity and fallback-control tests fail, rc 1 |
| A2: no age bound | final tree + mutation | fallback and rule tests fail, rc 1 |
| B1: no recovery | final tree + mutation | crash, three-slot and live-holder-then-free tests fail, rc 2 |
| B2: recovery without shape checks | final tree + mutation | bad-shape and too-large tests fail, rc 1 |
| B3: no size bound | final tree + mutation | too-large test fails, rc 1 |
| B4: no operator warning | final tree + mutation | crash test fails (warning expected), rc 2 |
| Client red (A12-I4) | `703674d7` client + new test | 1 failed (focus on `body`), rc 1 (`client-red-a12-i4.txt`) |
| Client mutation CM-I4: no focus call | final tree + mutation (restored) | 2 failed, rc 1 |
| All paid frontend files (12) | final working tree | 68 tests passed, rc 0 (`frontend-paid-all.txt`) |
| `tsc --noEmit` | final working tree | rc 0 |
| Pint `--test`, 5 PHP files | final working tree | passed |
| Census self-test | final working tree | OK, rc 0 |

**Red runs that pass by design.** Several cases pass on the old code because they guard behaviour that must not change:
- refusal after expiry;
- the bad-shape, too-large and malformed-sidecar skips;
- the live-holder half of its test.

**Earlier family run.** Before the addendum 12 constraints (size bound, warning, focus), the family run was 132 tests,
4,636 assertions, 1 skipped, rc 0 (`family-green.txt`). The final run is in `family-green-final.txt`.

## Not tested

- **A real FPM `REQUEST_TIME_FLOAT`.** The value is injected through the test request's server bag.
- **A real worker kill mid-copy.** The leftover is fabricated in exactly the shape the spool leaves.
- **Native MySQL.**

## For Sean

- **Downloads.** A download that reaches the server before its authorization expires is no longer refused because the
  sign-in check that follows was slow.
- **Crashed workers.** A server worker that crashes while preparing a download no longer blocks one of the three download
  slots for good. The next download reuses the slot, and the event is logged for operators.
