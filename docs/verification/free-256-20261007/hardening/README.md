# Free256 hardening lane: findings F-1, F-2, F-3, F-5 and F-8

Branch `harness/free-256-hardening`, from `51c9fecf` (head of PR #53). Findings are those of
`../independent-review/DECISION.md` (F-8 also raised by Codex on PR #53). SQLite only; no native MySQL was started.
Each finding has `F-n/red.txt` and `F-n/green.txt` (full PHPUnit output, `rc=` on its own line after the run).

Invocation: `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <file>`

## How the red runs were taken

Red is the new regression run against the pre-fix behavior, not against a missing class where avoidable:

- F-1 red: the profile-root seam (below) is present but the read-side fix is not.
- F-2 red: `ProductionFreeGrantSpool` exists but `ProductionFreeGrantDownloads` does not use it yet.
- F-3, F-5: the test files alone against `51c9fecf` code.
- F-8 red: `ProductionFreeGrantRecords` at `51c9fecf` and the request-key lookup at its old form.

Tests that call an API the fix introduces (`validateStored`, `RELEASED`, `ProductionFreeGrantRecords::keys()`) error in
red for that reason; the behavioral tests fail on the actual defect.

## Results

| Finding | Test file | Red | Green |
| --- | --- | --- | --- |
| F-1 | `ProductionFreeGrantProfileRevisionTest` | 6 tests: 5 errors, 1 failure (rc=2) | OK 6 tests, 75 assertions (rc=0) |
| F-2 | `ProductionFreeGrantSpoolTest` | 5 tests: 5 failures (rc=1) | OK 5 tests, 39 assertions (rc=0) |
| F-3 | `ProductionFreeGrantStaffMfaTest` | 3 tests: 2 failures (rc=1) | OK 3 tests, 21 assertions (rc=0) |
| F-5 | `ProductionFreeGrantFilesTest` | 3 tests: 1 failure (rc=1) | OK 3 tests, 20 assertions (rc=0) |
| F-8 | `ProductionFreeGrantKeyRotationTest` | 3 tests: 2 errors (rc=2) | OK 3 tests, 31 assertions (rc=0) |

Whole directory on SQLite (`sqlite-directory.txt`): `tests/Feature/ProductionFreeGrants` = **113 tests, 747 assertions,
2 skipped, rc=0**. The 2 skips are the two native-only schema cases already in the SQLite census (93 tests before this
lane's 20 new ones; before F-8 the run was 110 tests, 716 assertions). No new test skips on SQLite, so
`scripts/ci/database-sqlite-skips.json` is unchanged; `python3 -I scripts/ci/test-database-receipts.py` still passes
(`database-receipts.txt`: 34 tests OK). `vendor/bin/pint --test` on every changed or new PHP file: passed (`pint.txt`).

## F-1: historical grants no longer depend on the current renderer runtime

Change (`ProductionFreeGrantRenderProfile`, `Definitions`, `Grants`, `Documents`):

- `RELEASED` registry: `CanonicalJson::hash` of the full sealed profile document, by revision and provenance (r1 holds
  the two current hashes). I chose a hash registry over a profile-version field because the shipped `version` string
  does not change when the implementation manifest does, so only a hash identifies a revision. Entries are
  append-only; a test fails when the current runtime profile is not listed.
- `validateStored()`: read-side check against the registry, never the runtime. `Definitions::graph()` now uses it
  (reason `profile_unregistered`) and also requires the profile's provenance to equal the definition's. The row seal and
  `profile_hash` column already bound the stored profile to its row. `originGraph()` additionally checks the origin's
  `profile_hash` column against its profile.
- `requireCurrent()`: runtime equality, kept for `render` (checked before a claim is appended, so a refused render burns
  no attempt), `recover`, and, judgement call below, `review`/`accept` and `open`.
- Seam: the default project root is the container's `path.base` binding when bound, else `dirname(__DIR__, 4)`
  (identical in the application; the bounded child renderer has no container and is unchanged). The tests rebind
  `path.base` to a temp root holding a manifest with a trailing newline, i.e. mutation U3, so `current()` refuses.

Regression: an existing rendered origin stays in the library, authorizes and redeems the contract and master after the
revision, staff can read and revoke; render of a pending origin and recover are refused with `profile_changed` and no
claim row; after the runtime is restored the pending origin renders. A forged but correctly sealed definition row
carrying an unreleased profile, or a released profile of the wrong provenance, reads as `profile_unregistered`. Unit
cases refuse changed implementation hash, limits, template, missing/extra keys and empty profiles.

Left: a revision where `current()` returns a different but valid profile (mutation U1) is not simulated in-process
(`MANIFEST_HASH` is a constant); it is covered by construction, since reads never call `current()`.

## F-2: bounded delivery spool

Change: new `ProductionFreeGrantSpool` (non-final; `freeBytes`, `flush`, `unlinkSpool` are the test seams, as for
`PrepareTestDeliveryStream`), used by `ProductionFreeGrantDownloads::snapshot` through the container. Held `flock`
slots on fixed `slot-N.lock` files, free space of at least size plus reserve, `0600` write, `chmod 0400`, read-only
reopen with identity check, read-back SHA-256 from the spool, unlink, lease kept by `PreparedDeliveryStream` until
closed. A slot whose snapshot path already exists (crash residue) is skipped, never repaired. Config
`spool_slots` (default 3, 1 to 16) and `spool_reserve_bytes` (default 16 MiB, minimum 16 MiB) are validated by the
policy. At most `slots` x 1 GiB can be held. Reasons: `spool_busy`, `spool_space`, `artifact_drift`.

Regression: three snapshots held, the fourth refused `spool_busy` with no redemption row and no residue, then accepted
after one is closed; slot count follows config; free space of size plus reserve minus one is refused before any byte
is written, exact size plus reserve passes, `false`/NAN/INF refused; a same-size byte change after the write fails
read-back as `artifact_drift` and releases the slot; the prepared stream is nlink 0, mode 0400.

## F-3: MFA enrollment required unconditionally for 256 staff

Change: `ProductionFreeGrantStaff::proveCurrent` keeps `AdminMultiFactor::satisfiedBy` and additionally requires an
enabled provider on the locked current user row (`mfa_required`), independent of the panel's production-only rule.
`AdminMultiFactor` is untouched (frozen-bytes test). Applies to every command through `staffCommand`: propose,
approve, open, close, revoke and staff read.

Regression (`freeSetup(requireMfa: false)`, the shipped non-production rule): an admin without MFA is refused for each
action and writes nothing; enrolled staff succeed; enrollment removed after the actor was loaded is refused.

Left: the session MFA challenge remains the Filament mount's job (no mount exists yet).

## F-5: dangling-symlink store

Change: `ProductionFreeGrantFiles::store` writes a fresh random `.original-<hex>.tmp` inside the claim directory with
`x+b`, verifies and seals it, then `link()`s it to `original.pdf` and unlinks the temp name. `link()` neither follows a
destination symlink nor replaces an entry; failure is `storage_failed`. Only this call's own temp name is removed on
failure (identity-checked).

Regression: a dangling `original.pdf` symlink leaves the outside target absent and the store refuses; a live symlink
leaves the outside file unchanged; a successful store leaves only a 0400, nlink 1 `original.pdf`; a second store for the
same claim is refused and the first bytes are unchanged.

## F-8: previous application keys

Change: `ProductionFreeGrantRecords::keys()` returns the current `app.key` first, then well-formed (32+ characters)
distinct `app.previous_keys`. `seal()` always writes with the current key; `verify()` accepts a seal matching any
candidate, compared with `hash_equals` for all candidates without short-circuiting. Request-key hashes are derived for
lookup, so `accept` now looks the replay up with `request_key_hash IN (current, previous...)` and writes new rows with
the current key. A key in neither list never verifies. Payload ciphertext already decrypts through Laravel's
previous-key support.

Regression: after rotating key A to B with A listed, the definition (staff read and revoke), origin graph, recover and
authorization row read; the stored request-key hash is among the candidates; new rows seal with B and old rows still
carry A's seal. With A absent, or with only an unrelated key listed, definition, origin, recover and authorization
reads are `tampered`.

Not testable here (outside Free256 scope): the main-resident customer identity layer
(`ProductionCustomerAccess`/`IdentityPolicy`, `CustomerAccess` and others) also derives from the current `app.key`
only. After a rotation, customer-facing 256 commands (library, authorize, redeem, accept) fail with `identity_refused`
before 256 rows are read, regardless of this fix. The customer-side regression therefore reads at the domain level
(origin graph, authorization row, request-key candidates) instead of through the identity-gated commands. Whoever owns
identity must give it the same previous-key handling before a rotation is safe.

## Judgement calls

1. F-1 asked for runtime equality only for `render` and `recover`. I also require it for customer `review`/`accept`
   and for staff `open`: otherwise a customer could assent under a definition whose profile this runtime cannot render,
   leaving an origin that can never get its original. Reads, delivery, revoke, approve and close do not need it.
2. F-1 reason for an unreleased stored profile is the new `profile_unregistered`, distinct from the runtime's
   `profile_changed`.
3. F-5 removes its own random temp name on failure although the file's header said nothing is ever deleted; published
   and pre-existing files are still never touched.
4. F-2 settings live in the policy array, so the existing equality re-proof (`changed_policy`) covers them too.

## Files changed

`app/Domain/Grants/ProductionFree/`: `ProductionFreeGrantRenderProfile`, `Definitions`, `Grants`, `Documents`,
`Downloads`, `Files`, `Policy`, `Records`, `Staff` (modified), `ProductionFreeGrantSpool` (new); `config/production-free-grants.php`;
tests `ProductionFreeGrant{ProfileRevision,Spool,StaffMfa,Files,KeyRotation}Test.php` (new). Pinned renderer files, the
manifest, `MANIFEST_HASH` and every main-resident file are untouched, so the sealed profile hashes are unchanged.

## Still open

F-4 (MySQL TEMPORARY-table parent shadow), F-6 (renderer child sandbox width), F-7 (delivery does not re-prove current
source readiness) stay open and untouched. Native MySQL was not run for this lane's changes; the shipped native runs
are the reviewer's.

## Codex review round (four P2 findings on PR #53)

Evidence under `codex-1/` to `codex-4/` (`red.txt`, `green.txt`), plus `codex-sqlite-directory.txt`,
`codex-database-receipts.txt` and `codex-pint.txt`. Each item was reproduced red first. The directory run on SQLite is
**120 tests, 857 assertions, 2 skipped (the same two native-only schema cases), rc=0**; `test-database-receipts.py` OK
(34 tests); Pint passes on every changed or new PHP file. No new test skips on SQLite.

| Item | Test file | Red | Green |
| --- | --- | --- | --- |
| 1 abandoned spool snapshots | `ProductionFreeGrantSpoolResidualTest` | 2 tests: 1 error (`spool_busy`), the non-regular-file control passes (rc=2) | OK 2 tests, 17 assertions |
| 2 aggregate disk reservation | `ProductionFreeGrantSpoolReservationTest` | 2 tests: 2 failures, the second concurrent snapshot was admitted (rc=1) | OK 2 tests, 10 assertions |
| 3 render attempt count | `ProductionFreeGrantAttemptLimitTest` | 2 tests: 2 failures, attempt 17 got `attempts_exhausted` (rc=1) | OK 2 tests, 77 assertions |
| 4 library newest rows | `ProductionFreeGrantLibraryWindowTest` | 1 test: 1 error, the page held filler rows (rc=2) | OK 1 test, 6 assertions |

1. **Residual snapshots.** When a slot's flock is acquired its previous holder is gone, so `ProductionFreeGrantSpool::slot`
   unlinks a residual at exactly `slot-N.snapshot` for that slot, and only if it is a regular, single-link file owned
   by the process user. A directory, symlink or hard-linked file leaves the slot skipped (never repaired), as before.
   Regression: residuals in all three slots, three concurrent downloads succeed and every residual is gone; the
   directory, symlink (target untouched) and hard-link cases are refused `spool_busy` and left in place.
2. **Aggregate reservation.** Admission (slot choice, free-space probe, reservation) now runs under a short blocking
   `flock` on `admission.lock`. Each slot has a fixed 20-digit sidecar `slot-N.reserve`, written only while its slot
   lock is held. Admission requires `free - sum(reservations of other held slots) - size >= reserve`. A reservation is
   set to zero when the snapshot is complete (the disk then reports those bytes itself, so they are not counted twice)
   and also when the fill or any later step fails. Slots are found by globbing `slot-*.lock`, so lowering `spool_slots`
   later does not hide an active higher slot. Regression drives `ProductionFreeGrantSpool::prepare` directly with a
   simulated free-space value: a nested second reservation that fits alone but not together is `spool_space`, then
   succeeds after the first completes; the exact-fit boundary admits both; a failed fill releases its reservation.
   The cross-process `flock` behavior is exercised only in one process here.
3. **Attempt count.** The allowance counts `claimed` rows (`< 32`). The work-ordinal CHECK is now `0..63`, and
   `originGraph` reads up to 64 work rows (it read 32, which would have truncated a full chain by `id`). Migration
   `2026_10_07_256000` has never been applied anywhere, so its guard bound was changed in place and no new migration
   was added. The CHECK text is shared by both drivers, and the SQLite schema tests pass; the native MySQL schema cases
   were not run here, and no test pins this literal text.
4. **Library window.** New `ProductionFreeGrantRows::newest()` selects the ids ordered by `created_at DESC, id DESC`
   with `LIMIT` in SQL (MySQL `FOR UPDATE` like the reader); the rows are then fetched by `id IN (...)`, so `total`
   stays the exact count. `ProductionFreeGrantLibrary` takes an optional page size (default 50, 1 to 1000). The
   regression holds 1,001 older filler origins that sort below every real UUID, so the old "first 1,000 by id" window
   contained only fillers; with a page size of 3 the three real origins are returned newest first and `total` is 1004.
   The fillers need the append-only guards removed and restored in the test (SQLite triggers re-created from
   `sqlite_master`, foreign keys toggled off and on); that is test-only and reads of the fillers never happen.
   `created_at` has one-second resolution, so ties fall back to `id`, as before.

Judgement calls: the optional library page size exists so the cap can be exercised without 50 real origins; it is not
a customer-facing input. Reservation accounting counts only not-yet-written bytes (released at completion) rather than
holding the full size for the stream's lifetime, because the finished unlinked snapshot already reduces free space.

## Bounded chain reads (Codex P2 5) and the reviewer addenda A2-1 and A1-6

Evidence: `codex-5/` (`red.txt`, `green.txt`, `sqlite-directory.txt`, `database-receipts.txt`, `pint.txt`) and `review-a2/`
(`a1-6-red.txt`, `a1-6-green.txt`, `a2-1-green.txt`). The directory run on SQLite is **129 tests, 925 assertions, 2 skipped
(the same two native-only schema cases), rc=0**; `test-database-receipts.py` OK (34 tests); Pint passes on the changed and
new files only. No new test skips on SQLite, so the census is unchanged. Migration `2026_10_07_256000` has never been
applied anywhere, so the CHECK bounds below were changed in place and no migration was added. No MySQL was run; the
native check of everything below is pending the independent reviewer.

### Audit of every bounded read

Root cause: a read capped at N rows but ordered by UUID `id` (the reader's fixed order), against a schema bound that did
not equal N. Every `rows->all`/`one` call in the namespace was checked:

| Read | Before | Decision |
| --- | --- | --- |
| Availability events (`Definitions::graph`) | window 1,000, CHECK `0..9999` | **(b) cap writes at the bound.** CHECK is now `0..999` (`ProductionFreeGrantSchema::MAX_AVAILABILITY_EVENTS = 1000`); the read is complete; the 1,001st command is refused `availability_exhausted` before the insert. Reading 10,000 sealed rows (decrypt and HMAC each) on every staff command is not worth it for a toggle that has no legitimate reason to flip a thousand times. A definition at the bound is always `closed` (ordinal 999 is odd), so it is never stuck open. |
| Work rows (`originGraph`) | window 64, CHECK `0..63` (fixed in round 4) | **(a) read the full chain.** 64 rows is the schema's maximum, so it is complete. Bound is now the shared constant `MAX_WORK_ROWS`. |
| Library origins | fixed in round 4 | SQL `ORDER BY created_at DESC, id DESC LIMIT n`, then fetch by id. |
| Reviews, originals, revocations, authorizations (by id), origins (by id or request-key hash) | `one()` (limit 2) | Unique by constraint, one row; two rows read as `tampered`. No change. |
| Authorizations and redemptions | `count()` only (rate limit, one-use check) | Counted in SQL, never windowed. No change. |

New `ProductionFreeGrantRows::chain($logical, $where, $bindings, $max)` is used for both ordinal chains. It reads `max + 1`
rows through the same id-ordered reader (so MySQL locking and committed-read behavior of `CurrentRows` are unchanged), sorts by
ordinal, and treats a row beyond the schema bound as `tampered` instead of silently dropping it. Because the read is
complete by construction, SQL ordering is immaterial; a schema bound and a read bound can no longer drift because both
use the same constants. `expectedOrdinal` input is now capped at the same bound.

Regression `ProductionFreeGrantAvailabilityBoundTest` (red: 1 failure, the command at ordinal 1000 committed then
reloaded as `tampered`; green: OK 1 test, 13 assertions): 997 events are appended through the same sealed insert the
command uses, then the events at ordinals 998 (open) and 999 (closed) are accepted and projected correctly, the next
open is refused `availability_exhausted` with the table unchanged, and `read` still reports the closed state at ordinal
999. A beyond-bound row cannot be forged on SQLite because the CHECK refuses it, so the `chain()` overflow branch is
covered by that constraint rather than a separate test.

### A2-1: library window test on both drivers

`ProductionFreeGrantLibraryWindowTest` now has a driver-neutral test that runs everywhere (three real origins ten seconds
apart whose UUIDs sort opposite to their age, forced with `Str::createUuidsUsing`, page size 2, plus the default page) and the
1,001-filler case, which needs the append-only guards removed and restored through `sqlite_master`. I could not write a
MySQL form I can run here (it would need `SHOW CREATE TRIGGER` round trips I cannot verify), and a MySQL skip would break
the zero-skip MySQL shards, so the filler test is not skipped: on a non-SQLite driver it asserts the driver and returns, and
the neutral test carries the regression. No `sqlite_master` read happens off SQLite. Green on SQLite: 2 tests, 15 assertions
(`review-a2/a2-1-green.txt`). A red for the original MySQL error cannot be produced here. **The native MySQL run is
pending the reviewer.** The SQLite census is unchanged because nothing skips.

### A1-6: contention on native races

Choice: map, do not retry. New `ProductionFreeGrantTransactions::run()` replaces every `DB::transaction` in the Free256
commands (staff, customer, render, recover, failure append). SQLSTATE `40001`, MySQL 1213 and 1205 (also Laravel's
`DeadlockException`, anywhere in the exception chain) roll the transaction back and refuse with `contention`. `Rows::insert`
also stops labelling a deadlocked insert `refused_by_guard`. Retrying inside was rejected: the closures carry external
effects (leased render claims, staged files, the one-use redemption row), and the customer commands are idempotent by
request key, so the caller retrying the same command is observable and safe; a bounded in-library retry would re-run those
closures invisibly.

Regression `ProductionFreeGrantContentionTest` (red: 6 raw `PDOException` errors and 1 classification failure, rc=2;
green: OK 7 tests, 44 assertions): the driver exception is thrown from inside the transaction, after the command has
written its definition or origin row (the hook is the policy re-proof every command runs last). For 1213, 1205 and a bare
`40001`, staff `propose` and customer `accept` refuse `contention`, the hook fires exactly once (no retry), no rows
remain, and the same request key then succeeds exactly once. A classification test accepts deadlock and lock-wait
errors, including wrapped in `QueryException` or `DeadlockException`, and rejects duplicate-key, access-denied and plain
exceptions.

## Stalled source reads (Codex P2 6, A3-1) and unrenderable text (Codex P2 7, A3-2)

Evidence: `codex-6/` and `codex-7/` (`red.txt`, `green.txt`, `pint.txt`, `sqlite-directory.txt`, `database-receipts.txt`),
`codex-6/red-hung-read.txt`, `codex-7/glyph-equivalence.txt`. SQLite directory run: **139 tests, 1016 assertions,
2 skipped (the same native-only cases), rc=0**; census self-test OK; Pint passes on
the changed and new files. No MySQL was run.

### P2 6 and A3-1: no read loop can spin or hang

| Test file | Red | Green |
| --- | --- | --- |
| `ProductionFreeGrantStalledSourceTest` | 3 failures: the old loops spun to the stream's 20,000-read safety cap (`artifact_drift`, `expired` not reached, 20,001 reads) | OK 4 tests, 28 assertions (the 4th, a hung blocking socket, hangs on the old code: `red-hung-read.txt`, killed by a 40 s guard, rc=124) |

Change (`ProductionFreeGrantDownloads::snapshot`, `ProductionFreeGrantTransfer::writeTo`):

- The authorization deadline is checked before every read of the source, not only when a chunk is written.
- The source stream is set blocking and given a read timeout of `max(1, min(5, remaining seconds))`, so a read that never returns
  is bounded by the authorization; a timed-out read (`stream_get_meta_data()['timed_out']`) or an empty read before EOF is
  refused immediately as `artifact_unavailable`. Refusing at once (rather than allowing a few empties) is correct because a
  blocking read returns an empty string only at EOF or after its timeout; an adapter that wants to wait must block inside
  its own stream. The spool slot and reservation are released through the existing failure path (tests assert the single slot
  is reusable and the reservation file is zero).
- `Transfer::writeTo` already checked the deadline per iteration; it now also refuses an empty read before EOF instead of
  spinning until the deadline.

Class audit of every read, copy and stream loop in the namespace:

| Loop | Verdict |
| --- | --- |
| Downloads source fill | fixed (above) |
| Downloads write closure | each chunk checks the deadline and the byte bound; a short or zero `fwrite` throws |
| Spool read-back | already checks the deadline each iteration and refuses an empty read before EOF |
| Spool fill/flush, slot search, `pending()` | bounded by slot count and the glob of slot files |
| Files store and verify | writes are bounded by the rendered size (zero-length `fwrite` throws); reads are one `stream_get_contents` capped at `MAX_BYTES` on a local regular file |
| Renderer child I/O | Symfony `Process` with a 60 s timeout, 24 MiB stdout cap, 8 KiB stderr cap |
| `Transfer::writeTo` | fixed (empty read) |
| Library, chains, definitions | bounded SQL reads, no stream loops |

### P2 7 and A3-2: renderable text only

| Test file | Red | Green |
| --- | --- | --- |
| `ProductionFreeGrantRenderableTest` | 4 failures (Arabic title, decomposed name, no glyph accepted; disagreement with the real renderer) | OK 6 tests, 61 assertions |

New `ProductionFreeGrantRenderable` runs the renderer's own code on a probe built by the renderer's own input builder
(`RenderInput::fromOrigin`): `Text::build` applies the script, mark and control repertoire (`supportedText`) to every
label and value, and the finished text is then checked for font glyphs exactly as `PdfRenderer` does. It is called, before
any write, at definition propose (title, terms reference, terms, assent text), at approve, open and customer review/accept
re-checks of the stored definition, and for the buyer's declared name at review and accept. Refusal reason: `unsupported_text`.

- **Normalization:** none; unrenderable input is refused as typed. NFC-normalizing first would seal a value different from what the
  person entered, and a decomposed accent is a combining mark, which the repertoire already excludes, so refusing is
  both simpler and exact. Precomposed accents, Greek and Cyrillic are accepted.
- **Glyph coverage was a real gap, beyond the scripts.** About 6,500 code points pass the script/mark filter but have no glyph in the
  retained DejaVu fonts (for example U+02EF); the real renderer refuses them with `unsupported_input`. The check covers them.
- **One source of truth, and its limit.** The repertoire is the renderer's own `Text::build`. The glyph test lives in the pinned
  child renderer (`PdfRenderer`), which cannot be called from the web process without defining the process-wide
  `K_PATH_FONTS` constant that the other renderers in the same process define to their own font folders. So the
  glyph test reads the same font definition and CIDToGID files with the same rule. It was compared with the Tcpdf API the renderer uses over
  every Unicode scalar value (1,112,032 code points, 0 differences; `glyph-equivalence.txt`), and a test checks
  agreement with the real isolated renderer on 13 sample characters. A drift guard (`VERIFIED_REVISION`) fails the suite when
  a new renderer revision is released until that comparison is repeated. Sharing the function itself would mean moving it
  into the pinned renderer files, i.e. a new profile revision (r2); I did not make that change in this round.
- **Other inputs feeding the renderer or paths:** asset source ids (ASCII-only pattern), asset file names (generated from roles),
  UUIDs, hashes and the account public id are system generated or pattern bound; the revoke reason and source manifest are
  stored but not rendered. Nothing else needed a change.

Pre-activation consideration (A3-2): definitions and origins sealed before this check could hold text that cannot render. No
sealed production origin exists, so no operator recovery path is added; before any activation, confirm that no earlier
rehearsal definition or origin holds unrenderable text, because a sealed origin is immutable and can only stay `failed`.

## Codex review round 8: partial reservation sidecars (`codex-8/`)

Codex P2 on `792af167`: a worker that died while creating `slot-N.reserve` left a short file at the fixed path; every
later admission picked that slot and refused on the size check, so one crash blocked all delivery. `reservation()`
runs under both the slot lock and the admission lock, so no live holder is writing the sidecar. It now reclaims a
wrong-size residual under the same rule as a residual snapshot (regular, single-link, owned by this process user)
and recreates it, and it always creates a sidecar under a fresh random name that is renamed into place, so a crash
can no longer leave a partial file at the fixed path. A sidecar that is not an own regular file (for example a
symlink) is never reclaimed and keeps refusing (`artifact_unavailable`), with the outside target untouched.

Regressions in `ProductionFreeGrantSpoolResidualTest`: empty and 7-byte sidecars in both slots are reclaimed, both
downloads succeed and no temp names remain; a symlinked sidecar is refused and left alone, and the slot works once it
is removed. Red before the fix: 1 error (`red.txt`); green after: 4 tests, 30 assertions (`green.txt`). Directory
SQLite 141 / 1029, 2 native-only skips (`sqlite-directory.txt`); Pint passed (`pint.txt`); census self-test OK.

## Codex review round 9: render lease versus renderer timeout (`codex-9/`)

Codex P2 on `817dd34c`: the policy accepted any `render_lease_seconds` from 30, below the pinned renderer child's
60-second timeout, so a slow but successful render could store an immutable original whose publication is refused
`lease_expired`, and repeated claims would exhaust the origin. `ProductionFreeGrantPolicy` now requires the lease to be
at least `RENDERER_TIMEOUT_SECONDS` (60) plus `RENDER_LEASE_MARGIN_SECONDS` (60) for storing and publishing; the default
300 is unchanged. The renderer process file is hash-pinned in the render profile, so it is not edited: the timeout is
mirrored as a constant, and the regression asserts the pinned file still says `max_execution_time=60` and
`setTimeout(60)`, so the two cannot drift silently. Regression
`ProductionFreeGrantApprovalTest::test_render_lease_must_outlive_the_renderer_timeout_plus_margin`: leases 30, 60 and
119 refused `changed_policy` with nothing written; 120 accepted. Red (the old `>= 30` bound applied to this code): 1
failure; green: 1 test, 10 assertions. Directory SQLite 142 / 1039, 2 native-only skips; Pint passed; pinned renderer
files untouched.
