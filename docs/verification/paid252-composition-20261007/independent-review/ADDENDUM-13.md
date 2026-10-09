# Independent review: Paid252 composition, Addendum 13 (round 21: request-start admission, spool recovery, Stop focus; main merge)

**Range:** `36be2a33..94d325185d57eea57011d7466934a9b854c9d448`, fetched from `origin/harness/paid252-composition`; the
worktree is detached at `94d32518`.

| Commit | Content |
| --- | --- |
| `703674d7` | Commits my Addendum 12 record. My 14 untracked copies were byte-identical. My A12 probe matched `703674d7` and was updated in `c7342238`. |
| `c7342238` | Round 21:<br>• `PaidGrantDownloads::receivedAt()`, taken in the controller before `identity()`, and `redeem(..., $receivedAt)`.<br>• Spool leftover recovery in `PaidGrantPrepareStream::slot()`/`recovered()`.<br>• Focus moves to the progress line after Stop (A12-I4).<br>• New tests: `PaidGrantRequestInstantTest` (4) and `PaidGrantSpoolRecoveryTest` (6). |
| `caf61122` | `hardening/codex-21/`. C11 (i) and (j) become requirements 8 and 9 in `docs/ops/paid-delivery-runtime.md`, and the A12-I3 note is added to the verification bullets. |
| `94d32518` | Merge of main `89e3e61f` (#54 membership billing lane 2, #58). Conflicts in `CHANGELOG.md` and `scripts/ci/database-sqlite-skips.json`. |

I wrote none of the code under review. Nothing was committed or pushed, no product code was modified, and no MySQL daemon
was started. **Reviewer:** Claude (independent review lane). **Date:** 2026-10-08.

## Verdict

**APPROVE WITH CONDITIONS (unchanged), for a development merge.**

- Codex 4224514947 and 4224514955 are resolved as I recommended in Addendum 12 §7. A12-I4 is resolved.
- The merge is clean for the paid family and the census is exactly the union.
- No new finding at Low or above.
- C11 (i) and (j) are added, so C11 is met as a document with only its pre-mount verification open.

## 1. The request-time clamp

- **The source cannot be influenced by a client.**
  - The controller reads `$request->server('REQUEST_TIME_FLOAT')`, which comes from `$_SERVER`. Under PHP-FPM, PHP
    registers that key itself, overriding any FastCGI parameter of the same name.
  - Request headers arrive only as `HTTP_*` keys, and trusted-proxy handling does not touch the server bag.
  - Probe P1: a `Request-Time-Float` header and `HTTP_REQUEST_TIME_FLOAT`/`HTTP_X_REQUEST_TIME_FLOAT` server entries leave
    `REQUEST_TIME_FLOAT` as the real request time.
- **The rule.** Only a finite `float` or `int` is accepted. It must be at most 1 s ahead of now (clamped to now) and no
  older than now − 60 s. Otherwise the next candidate, `LARAVEL_START`, is tried, and failing that, now.
  - `redeem()` applies `admissible()` again against its own `now()`.
  - So a value can only make admission stricter, or earlier by at most 60 s.
- **Probe P1 results** (`LARAVEL_START` is not defined under PHPUnit):

  | Input | Result |
  | --- | --- |
  | NAN, ±INF, a numeric string, `true`, `null` | Fall back to now |
  | 0.5 s ahead | Clamped to now |
  | 2 s ahead | Falls back to now |
  | 59.5 s old | Admitted as given |
  | 60.5 s or 61 s old | Fall back to now |
  | Integer 30 s old | 30.25 s (the integer drops the sub-second part) |

- **Fallbacks.** `LARAVEL_START` is set at the top of `public/index.php`, also before identity work. Under a
  persistent-worker runtime it would be stale and is then rejected by the same 60 s bound.
- **Worst case.** Admission can be at most 60 s before the real start. That is when a persistent-worker runtime reports a
  value that is stale but under 60 s old, which C11 requirement 9 asks to be verified. The latest commit is therefore
  bounded by `expires_at + 60 + 60 + snapshot_seconds + 60`.
- **Authority is unchanged.** `$receivedAt` is data only: `identity()`, `locate()` and both frames still run every token,
  owner and identity check. `PaidGrantRequestInstantTest` covers admission across a slow identity proof, a request
  received after expiry (410, nothing recorded), implausible values and the rule itself.

## 2. Spool recovery

Recovery runs inside `slot()`, which runs inside `admit()` under the exclusive `admission.lock`. It touches a slot only
after taking that slot's `flock` with `LOCK_EX | LOCK_NB`.

- **A live holder's sealed 0400 snapshot cannot be removed.**
  - A holder takes its slot lock in `slot()` before creating `slot-N.snapshot` (`x+b`). It seals it to 0400, reads it
    back, and unlinks it before `close()` releases the lease.
  - Locks belong to open file descriptions, so even a nested admission in the same process cannot take a held slot.
  - Probe P2: the holder is paused in `unlinkSpool()`, with its snapshot already sealed 0400 and still named. A nested
    admission takes another slot and delivers; the outer snapshot keeps its inode and its 0400 mode, and the outer
    redemption then completes.
  - `PaidGrantSpoolRecoveryTest` covers a lock held by another process, and recovery once that process has gone.
- **TOCTOU between `lstat` and unlink.** Before unlinking, it requires all of the following:
  - a regular file, `nlink` 1, owned by the process euid;
  - mode 0600 or 0400;
  - size at most `DeliveryAssetFiles::MAX_BYTES`;
  - `realpath($snapshot) === $snapshot`;
  - a second `lstat` with the same dev and inode.

  The only window left is between that second `lstat` and `unlink`, inside the 0700 spool that only the same user can
  write. That is outside the threat model. The `realpath` check cannot strand slots, because `DeliveryAssetFiles::root()`
  already requires a canonical private root (`realpath($path) === $path`).
- **Interaction with `pending()` and `holders()`.** Both read only slots that are held by others, excluding the
  admitter's own number. A leftover in an unheld slot never affected them, and the recovering slot is the admitter's own.
  - Recovery runs before the free-space probe in `admit()`, so the freed bytes count. A dead process holds no descriptor.
  - Sidecars are reset to zeros and then overwritten by this admission's holder and reservation. If the admission is
    then refused (same-buyer holder, or space), the lease closes and the zeroed sidecars are correct.
  - The no-repair rule still applies to malformed sidecars, symlinks, loose modes, multiple links and oversize files
    (tests cover all of these).
- **The log line holds no personal data.** It is a fixed message with `['slot' => $slot]` only: no path, hash, account or
  holder digest.
- **Dependency.** Everything above relies on coherent `flock` on a host-local spool, which C11 requirement 8 now states.

## 3. Stop focus (A12-I4)

`stopPreparing()` now focuses the progress line (`tabIndex={-1}`, a ref). The committed A12 probe, flipped in round 21,
and a new continuation case assert it. Nothing else changed in the client.

## 4. The merge of main `89e3e61f`

- **Paid code is untouched.**
  - Outside `docs/`, main changed only these app files: `app/Domain/Memberships/Billing/*`,
    `Memberships/Production/MembershipRows.php`, `app/Jobs/RetrieveMembershipInvoice.php` and one console command. It
    also changed tests, the browser specs, one frontend test and the census.
  - No `database/`, `config/`, `routes/` or `bootstrap/` file changed, and no lockfile changed, so the worktree autoload
    still applies.
  - The only `paid_` string in the merged app code is `amount_paid_off_stripe` in `BillingSettlement`, which is unrelated.
  - Billing adds no table or foreign key that touches the paid schema's dependency checks.
  - I found no semantic interaction.
- **The census is exactly the union.**
  - `scripts/ci/database-sqlite-skips.json` at `94d32518` has 184 unique pairs. That is main's 183 at `89e3e61f` plus the
    branch's single extra pair (`PaidGrantSchemaRecoveryTest::test_native_schema_global_exact_case_accent_fk_and_routine_identities_refuse_before_first_owned_ddl`).
    `head == branch ∪ main` holds.
  - All 184 methods exist in the merged tree (`census-methods-exist.txt`).
  - The paid family on the merged tree skips exactly that one pair. The census self-test passes.
  - The census is checked for exactness only when receipts are collected against a full JUnit run. I did not run the full
    suite, so the 183 pairs from main stand on main's own evidence.
- **The paid family on the merged tree:** 133 tests, 1 skipped, rc 0. The new tests pass 10/10.

## 5. Conditions

| ID | Status |
| --- | --- |
| C1, C3, C9, C10 | Resolved or met (unchanged). |
| C2 | Open, as extended in Addenda 9, 11 and 12. |
| C4–C8 | Open, unchanged. |
| C11 | **Met as a document, all items (a)–(j) present** (requirements 1–10 in `docs/ops/paid-delivery-runtime.md`). **Open only for the pre-mount verification** recorded in that document. |
| C12, C13 | Met. |

No new condition.

## Runs

PHP 8.4.26 through the worktree runner, with SQLite defaults and `public/build` absent. Every run was at head `94d32518`,
the merged tree. Evidence is in `review-evidence/addendum13/94d32518/`.

| # | Command | Result | rc | Wall | Raw output |
| --- | --- | --- | --- | --- | --- |
| 1 | `--filter PaidGrant tests/Feature` (25 files) | **133 tests, 132 passed, 1 skipped** (the census pair), 4,698 assertions. RequestInstant 4, SpoolRecovery 6. | 0 | 622 s | `sqlite-paid-family.{txt,xml}` |
| 2 | `--filter 'PaidGrantRequestInstantTest\|PaidGrantSpoolRecoveryTest' tests/Feature` | 10 tests, 111 assertions | 0 | 27 s | `sqlite-new-tests.{txt,xml}` |
| 3 | Untracked probe `ReviewProbeAddendum13Test`:<br>• P1: `receivedAt()` edges and header isolation.<br>• P2: a live holder's sealed 0400 snapshot survives a nested admission. | 2/2, 24 assertions | 0 | 1 s | `sqlite-review-probe.{txt,xml}`; source `ReviewProbeAddendum13Test.php.txt` |
| 4 | Static census check (all 184 methods exist; union equality) | no missing methods; `head == branch ∪ main` | 0 | | `census-methods-exist.txt` (union output in §4) |
| 5 | `/home/user/VA-Studio/vendor/bin/pint --test` on the 5 changed PHP files | passed | 0 | | `pint.txt` |
| 6 | `python3 -I scripts/ci/test-database-receipts.py` | 34 tests OK | 0 | | `census-self-test.txt` |
| 7 | `npx vitest run` on all 12 tracked `tests/frontend/paid-grant-*.test.tsx`, including my A10–A12 probes as committed (vitest 5.0.1, Node 24.21.0) | 12 files, 68/68 | 0 | | `frontend-vitest.txt` |
| 8 | `npx tsc --noEmit` | clean | 0 | | `tsc.txt` |

**Notes on the runs:**
- **Not recorded as a run.** My first probe run failed (rc 1) on my own boundary arithmetic. I built the "60 s old"
  value from an integer timestamp while the frozen `now` carried 0.755 s, so it was 60.755 s old and correctly rejected.
  I switched to 59.5 s and 60.5 s from the precise timestamp. P2 had passed in that run, and the recorded run replaced its
  output.
- I wrote no new frontend probe: the only client change (Stop focus) is covered by the committed tests.
- **No pinned renderer file changed** in the range.
- **Symlink.** `node_modules` was symlinked for rows 7–8 only and then removed (`unlink`).

## Not tested

- **Native MySQL.**
- **Real infrastructure.** Real worker kills leaving spool files, multi-host or NFS spools, and `REQUEST_TIME_FLOAT`
  under a persistent-worker runtime.
- **The full suite on the merged tree** (cost policy). Census exactness for main's pairs rests on main's evidence.
- **A real browser.**

## Files added by this review (untracked; nothing committed)

- This file.
- `review-evidence/addendum13/94d32518/`. It includes the PHP probe source as `.txt`; `tests/Feature/ReviewProbe*` does
  not exist.

**Worktree housekeeping.** My untracked Addendum 12 copies were moved, not deleted, to
`/tmp/claude-0/-home-user-VA-Studio/8839656b-df19-5c03-a1af-24c989093d81/scratchpad/rv56a13-parked-addendum12/`.
