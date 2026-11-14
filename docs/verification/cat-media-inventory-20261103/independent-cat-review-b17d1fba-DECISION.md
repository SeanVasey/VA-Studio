# CAT-A independent review: bounded read-only staff media inventory

- Reviewed source: `b17d1fba` (commits `90ae5923`, then `b17d1fba` with Pint changes only), base `b5d1467b`.
- Worktree: `/home/user/wt/cat`, branch `claude/cat-inventory-20261104`. The head, `16e726d8`, is docs only. `git diff --quiet b17d1fba HEAD -- app tests` confirmed that `app/` and `tests/` are identical at the head.
- Files: `app/Domain/Media/ReadStaffMediaInventory.php` (91 lines), `tests/Feature/StaffMediaInventoryTest.php` (218 lines), `docs/verification/cat-media-inventory-20261103/README.md`.
- Reviewer: independent Claude subagent, 2026-11-04. I made no edits, commits or branch switches in the worktree. I did not start MySQL or run Composer.

## Decision: APPROVE

There are no P0 or P1 findings. The service code is correct for every claim I checked. All findings are about test strength or the docs. They fit the per-lane follow-up batch under the freeze-and-batch rule. The P2 is a one-assertion fix on the slice's central invariant, so land it before any UI caller consumes the report.

## Evidence I produced

1. SQLite run of the exact source (the brief's command, PHP 8.4.27, PHPUnit 12.5.34): `OK (10 tests, 48 assertions)`, 35 s.
2. Mutation probe. I wrote mutant copies of the service class in the scratchpad and preloaded each one ahead of the Composer autoloader. A `ReflectionClass` check confirmed that the mutant file was the one loaded. The worktree was untouched.

| Mutant | Result |
| --- | --- |
| m1: per-asset `reference_coverage` becomes `'complete'` when the asset has no observed reference and every window is exhausted | **survives** (10/10 pass) |
| m2: sum-overflow guard removed, so an overflowing group total becomes a float | **survives** |
| m3: `sha256` added to the SELECT and to each asset row | **survives** |
| m4: missing size counted as 0 in the group total | killed by `test_missing_recorded_size_does_not_become_zero_bytes` |
| m5: `reference_state` becomes `'unreferenced'` when every window is exhausted | killed by `test_even_an_empty_reference_prefix_cannot_establish_safe_deletion` |

3. The native MySQL result (10/48) was recorded on `27e416ac`, which is `b17d1fba` plus `a2144d4a`. I confirmed that `a2144d4a` changes only `CatalogSocketBinding.php`, its test and its packet, so it does not overlap the CAT files. I did not rerun MySQL.

## Assessment by question

### 1. Authorization: sound

- `ReadStaffMediaInventory.php:29-31` refuses any enclosing transaction (`transactionLevel() !== 0`) before opening its own.
- Lines 35-38 call `MediaWriterActor::authorize` inside the transaction. That method (`MediaWriterActor.php:17-21`) does the following:
  - Treats an unsaved user as not existing.
  - Re-reads the user row with `lockForUpdate`.
  - Calls `Gate::authorize('administer-catalog', [true])`. The gate (`AppServiceProvider.php:81-91`) re-reads the user again with a lock and requires `is_admin` and `email_verified_at`.
- Then `AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)` re-reads enrollment from the database. A retained secret on a stale model is not trusted.
- Neither `Gate::before` nor `Gate::after` is defined anywhere in `app/`, `bootstrap/`, `config/` or `routes/`.
- Bounds are validated only after authorization (line 39), so an unauthorized caller learns nothing from validation messages.
- The first non-locking read comes after the locking authority reads. Under MySQL REPEATABLE READ, the media snapshot therefore starts after authorization.
- Revoked admins, customers, unsaved users, removed MFA enrollment and an enclosing transaction are all refused. The first four cases are tested (tests 2 and 3), and so is the transaction case (test 10).
- This is the same MFA meaning as `ReadPrivateTrackReview`: enrollment, not proof that this session completed a challenge. That policy is unchanged, so it is not a finding here.

### 2. Privacy: sound

- Page SELECT (line 44): `id, role, status, size_bytes`. Reference SELECT (line 52): `id` plus the fixed foreign-key columns.
- Output is built field by field (lines 72-74 and 84-88). It contains asset id, role, status, recorded bytes, fixed `table.column` labels, prefix row counts and has-more flags, and constant strings.
- It returns no path, filename, hash, mime type, technical metadata, URL, user or customer id, ciphertext or contract body. It returns no source row ids either.
- The service queries only `pending_entitlements(id, media_asset_id)` from commerce, which carries no customer identifier.

### 3. Bounds: sound

- Keyset page: `id > afterId ORDER BY id LIMIT limit+1`, with limit 1-100.
- Five reference queries, each `ORDER BY id LIMIT 201`.
- The worst case is 1,106 rows. The packet's arithmetic is correct.
- There is no `IN` list, JSON function, count or aggregate.
- Invalid input is refused with `ValidationException`, not clamped (tested with -1/1, 0/0 and 0/101).
- Every listed table has an `id` primary key, including `media_upload_sessions` (`$table->id()`).
- The reference map covers every declared foreign key to `media_assets` in `database/migrations`: `parent_asset_id`, `source_asset_id`, the four `stems_recordings` columns, `media_upload_sessions.asset_id` and `pending_entitlements.media_asset_id`. The sound-kit migrations declare none.

### 4. Semantics: sound

- Every asset carries `reference_coverage => 'incomplete'` (line 74), and the top level does too (line 86).
- `reference_state` is only `unknown` or `known_reference`. Nothing returns "unreferenced" or "safe to delete".
- `recorded_bytes`:
  - `filter_var(..., FILTER_VALIDATE_INT, min_range 0)` turns a NULL size, an invalid value, or a MySQL unsigned value above `PHP_INT_MAX` (returned as a string) into `null`.
  - A group total becomes `null`, and stays `null`, if any member is `null`.
  - The overflow test `$bytes > PHP_INT_MAX - $sum` (line 80) has nonnegative operands, so the check itself cannot overflow.

### 5. Tests: mostly sound, with gaps

Tests 2, 3, 4, 7, 8, 9 and 10 kill the regressions they name. Three plausible regressions survive (m1-m3). See findings F1-F3.

## Findings

### F1. P2. The per-asset coverage invariant is not asserted for an `unknown` asset

- **Where:** `tests/Feature/StaffMediaInventoryTest.php:186-195` (test 8). The value it should cover is set at `ReadStaffMediaInventory.php:74`.
- **Failure path:** a future change makes per-asset `reference_coverage` conditional, for example `'complete'` when no reference is observed and every prefix is exhausted. Mutant m1 does exactly this and passes all 10 tests.
  - Test 5 asserts per-asset coverage only for a `known_reference` row.
  - Tests 6 and 7 assert only the top-level value.
  - A UI that reads per-asset `unknown` plus `complete` would effectively present the asset as unreferenced. That breaks the AGENTS.md rule that nothing is called unreferenced or safe to delete.
- **Fix:** in test 8, add `assertSame('incomplete', $report['assets'][0]['reference_coverage'])` and `assertSame('incomplete', $report['reference_coverage'])`. Also assert that every window has `has_more === false`, so the test proves the exhausted-prefix case it is named after.

### F2. P3. Privacy test uses a denylist of strings, so a new private field passes

- **Where:** `tests/Feature/StaffMediaInventoryTest.php:55-58`.
- **Failure path:** mutant m3 adds `sha256` to the SELECT and the output row and passes. The fixture leaves `sha256` null, and `'sha256'` is not in the denylist. The same applies to `mime_type`, `disk`, `verified_by` or `track_id`.
- **Fix:** assert the exact key set:
  - Each asset row: `['id','role','status','recorded_bytes','reference_state','known_reference_kinds','reference_coverage']`.
  - Each group: `['role','status','asset_count','recorded_bytes']`.
  - Top level: `['scope','assets','bytes_by_role_status','has_more','next_after_id','reference_windows','reference_coverage','unknown_reasons','observation']`.
  - Each window: `['rows_observed','has_more']`.

  Also give the fixture a synthetic `sha256` and add it to the denylist.

### F3. P3. Overflow and unrepresentable-size handling is untested

- **Where:** `ReadStaffMediaInventory.php:68-69` and `79-80`. The packet claims both behaviors at `README.md:41`.
- **Failure path:** mutant m2 removes the overflow guard and passes. PHP would then promote the sum to a float and return a rounded total, which the packet promises never happens.
- **Fix:** add a test with two same-group assets whose `size_bytes` is `PHP_INT_MAX`. SQLite stores this and MySQL unsigned BIGINT accepts it. Assert that each asset's `recorded_bytes` is `PHP_INT_MAX` and the group total is `null`. Also assert that a mixed group (one null size, one valid size) gives a `null` total with `asset_count` 2.

### F4. P3. The MFA refusal test does not prove that no media read happened first

- **Where:** `tests/Feature/StaffMediaInventoryTest.php:82-104`.
- **Failure path:** a refactor that runs the MFA check after the `media_assets` query still throws and still passes. The transaction rolls back and nothing is returned, so data does not leak, but the fence-ordering claim is unproven.
- **Fix:** reuse test 2's `DB::listen` collector around the first denied call and assert that no media SQL ran.

### F5. P3. The packet contradicts itself about runs (docs only, head `16e726d8`)

- **Where:** `docs/verification/cat-media-inventory-20261103/README.md:61` says "All PHP lint, Pint, PHPUnit, SQLite/MySQL ... are UNRUN". Lines 72-84 then record lint, Pint, SQLite and MySQL runs.
- **Failure path:** a later reader cannot tell which statement is current.
- **Fix:** scope line 61 explicitly to the author's source-only preparation, or point it at the harness section.

### F6. P3 note for future callers, not a defect in this slice

- No HTTP or Filament caller exists.
- A future caller must do the following:
  - Validate `afterId` and `limit` as integers. In coercive mode, `"1.5"` becomes 1 with only a deprecation warning, which is silent clamping at the boundary.
  - Rate-limit the endpoint. Each call holds a `FOR UPDATE` lock on the actor's user row and reads up to 1,106 rows.
  - Send a private no-store response, as the packet already says.
- The reference windows are always the oldest 200 rows of each table, whatever the cursor is. On a production-sized catalog, almost every newer asset will read `unknown`. That is the safe direction and it is disclosed, but a UI should not present it as a coverage signal.

## What I did not check

- I did not run anything on MySQL. I did not verify the `27e416ac` MySQL result or lock and concurrency behavior. On SQLite, `lockForUpdate` does nothing.
- I did not check behavior when an HTTP request has a panel that differs from `Filament::getPanel('admin')`. There is no caller.
- I did not audit the implementations of `LicenseFixtures::admin`, `ContractFixtures::paid` and `ContractFixtures::retained`. I relied on their names and on the tests passing.
- I did not run the full PHP suite, Pint, `php -l`, browser specs or Foundation CI. The packet's Pint and lint claims are taken as recorded.
- I did not check whether `media_assets.role` or `status` values could themselves carry sensitive text. They are enumerated strings set by domain code.
- I did not audit references from JSON, encrypted or offer-snapshot data. The packet declares them uninspected, and the service reports them as `unknown_reasons`.
