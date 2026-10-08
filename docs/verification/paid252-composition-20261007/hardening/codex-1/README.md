# Paid252 Codex round 1: transfer deadline and per-operation client timeouts

Development evidence from the Claude Code harness for PR #56. This is not Foundation or final acceptance. The code is in
commit `cae5eeae` on `harness/paid252-composition` (parent `70a99960`). No schema, migration, guard, receipt or
authorization lifetime changed, and no file pinned in `resources/contracts/*/profile-assets.json` was touched.

## Finding 1: the transfer was bounded by the authorization expiry (`PaidGrantTransfer.php`)

The per-chunk check compared the clock with the authorization's expiry. A large file or a slow client that kept streaming
past it was cut off with a truncated 200 after `redeem()` had already committed the `paid_redemptions` row, so an
attempt was consumed without delivering the file. This is the independent review's M1 / condition C1.

The fix follows the design already merged for Free256 (`ProductionFreeGrantDownloads::redeem`, `hardening/codex-14` in
`docs/verification/free-256-20261007`).

- **Valid-to-start:** the first redeem frame's expiry check is unchanged (the current time against `expires_at`, 410).
  The second frame now re-checks the instant the first frame admitted instead of the current time.
- **Kept:** the existing post-frame check (time remaining, at most 600 s) and the 60 s `PaidGrantDeadline` budget over
  locate, both frames, the snapshot and the check before the first byte. That budget is never extended.
- **Removed:** the step that shortened that budget to the authorization expiry, which is what cut transfers off.
- **Transfer deadline:** `min(transfer_max_seconds, transfer_base_seconds + ceil(bytes / transfer_min_bytes_per_second))`
  from the verified snapshot size. It is computed before the commit frame, so a refused policy records nothing. It is
  counted from the redemption commit and checked before the first chunk and before every chunk, refusing with the
  existing 410.
- **Defaults** (`config/paid-grants.php`): 262144 B/s, 30 s base, 7200 s maximum; a 1 GiB file gets 4,126 s.
- **Validation:** `PaidGrantPolicy::capture()` and `transferSeconds()` return 503 unless every value is an integer
  within Free256's bounds (rate 16384–1073741824, base 0–600, maximum 60–14400).
- **Clock:** injectable as `?Closure(): int`, as in Free256, so no test sleeps.
- **Redemption-row guard:** not applicable here. Free256 stamps its redemption with the request-start time because its
  insert guard compares that time with the expiry. The `paid_redemptions` insert guard checks only id, uniqueness and
  integer range and compares no time, so `created_at` keeps the real commit time. A test shows a row committed after
  expiry, for a redemption admitted before it, is accepted.

## Finding 2: every client request aborted at 20 s (`PaidGrantJourney.tsx`)

Recorded native timings show document preparation at 65–76 s and authorization up to about 20 s, so the single 20 s
abort could discard a committed authorization's only token. The abort is replaced by the exported
`paidRequestTimeouts`. Mutating requests wait past the server's own budget, plus the 5 s session-lock wait and a 15 s
margin. The abort-on-unmount, pagehide/visibilitychange and generation logic are unchanged.

| Operation | Server budget | Measured native time | Client abort |
| --- | --- | --- | --- |
| read (index, show, download status) | 60 s | not measured directly; similar frames 12–20 s | 30 s (raised to 80 s in round 2, `../codex-2/`) |
| finalize | 60 s | 18 s | 80 s |
| authorize | 60 s | 11.9–20.4 s | 80 s |
| document | 300 s render lease | 65–76 s | 320 s |
| redeem | — | — | none (native form POST into an iframe, unchanged) |

**Authorize replay after a client timeout:** the pending request keeps the same `requestKey`, nonce, kind and origin
hash, and after the required fresh read the retry sends a byte-identical body. The server treats authorize as
idempotent per `(account_id, request_key)`: a matching request hash returns the same authorization id, token and
expiry, and a different body under the same key gets 409 (`PaidGrantDownloadJourneyTest` and the review's adversarial
case 4). The client waits past the server budget, so a retry never races the original. A replay after the 60 s lifetime
gets 410 and creates nothing.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Baseline, `--filter PaidGrant tests/Feature`, SQLite | `70a99960` | 69 tests, 3,798 assertions, 1 skipped, rc 0 (`baseline.*`) |
| Red A: new `PaidGrantTransferDeadlineTest`, original source | `70a99960` | 4 errors, 1 failure, rc 2 (`backend-red.*`) |
| Red B: same, original source plus only the config keys | `70a99960` + config | 2 errors, 3 failures, rc 2 (`backend-red2-config-only.*`) |
| Green: `PaidGrantTransferDeadlineTest` | `cae5eeae` | 6 tests, 133 assertions, rc 0 (`backend-green-file.*`) |
| Green: paid family, SQLite | `cae5eeae` | 75 tests (69 + 6), 4,219 assertions, 1 skipped, rc 0 (`family-green.*`) |
| Vitest, red | `70a99960` | 3 failed, 9 passed, rc 1 (`frontend-red.txt`) |
| Vitest, green | `cae5eeae` | 12 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | `cae5eeae` | rc 0 (`tsc.txt`) |
| Pint `--test`, the 5 changed PHP files | `cae5eeae` | passed (`pint.txt`) |
| `test-database-receipts.py`, shard and focused-test self-tests | `cae5eeae` | OK (`receipts.txt`, `test-*.txt`) |

Red B is the behavioural red. The transfer clock is never read (0 calls against 4), a stream past the transfer bound is
not refused, the second frame returns 410 for a redemption admitted in time, and `transferSeconds()` does not exist. Two
cases cannot go red on the old code by design: the "outlasts the authorization" case fails there only on its clock-read
check, because the old code uses the real clock, and "redeem after expiry is refused" is a regression guard that passes
before and after. The cap case was corrected after the red run, so it has only been run green.

## Not tested

- Native MySQL: the transfer tests use an injected clock, and no changed path is MySQL-only.
- A MySQL lock wait (50 s default) inside a frame can exceed the client's 15 s margin. The user then sees "could not be
  confirmed" and can retry idempotently.
- A pending authorize whose replay is refused as expired keeps its retry banner until the page is left.
- The redeem cost on MySQL (about 18.7k statements within the 60 s budget, review M2/C2) is unchanged.

## For Sean

The authorization lifetime now limits when a redemption may start, not when it must commit: a redemption admitted
before expiry can commit up to the 60 s budget later. The transfer defaults copy Free256's and would satisfy review
condition C1 unless the authored delivery policy needs other values.
