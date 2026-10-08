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
