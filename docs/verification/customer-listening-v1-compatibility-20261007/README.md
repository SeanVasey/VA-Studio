# Preserve ordinary V1 listening writes and gate V2 note promotion

Executable source `49b9154756b96b2ae11de1c63d75efc274e2f326`, tree `bf8f16e84273818937cc9ceb0c2f83b09e7e746c`, based on exact root notes registration `ff4602cc849577232a6ab532d96edd772cc1b428`. This child corrects PR31 review 4204862600: ordinary favorite/playlist changes unnecessarily rewrote encrypted V1 payloads as V2, making the real pre-notes reader return 503 even when no note had been used.

All eight ordinary favorite/playlist commands now preserve the stored V1 meaning/shape. New ordinary rows and explicit clears also remain V1. Effective mutations still advance the owned revision; empty clears remain monotonic, fencing old/stale writers. Reads, export and no-ops preserve exact raw encrypted row bytes. Ordinary writes remain V1 even when note promotion is enabled. Only an effective `set-track-note` on retained V1 can promote it, with both a strict boolean opt-in and a bounded nonblank plain-text rollout review reference. Flag-only/string-flag/invalid-reference configurations refuse with 503 before SQL writes.

The new configuration is default-false/unset:

```
customer-listening.v2_promotion_enabled = false
customer-listening.v2_rollout_review_reference = null
```

`ListeningRollout` captures this policy before framework work and the existing primary evidence proof rechecks it after the final query, storage and container callbacks. A late flag/reference change rolls back promotion. Existing account/current credential, physical row/revision and public-availability proofs remain intact. No migration, historical table rewrite, root route/controller/request/privacy/session/page registration or dependency change is included.

A V1 row projects the existing closed `listeningSchema:1` variant while promotion is disabled. The existing component hides note authoring for that variant; export/clear controls were moved outside the note section so those feature-data controls remain useful. With safe promotion configured, the existing schema2 projection advertises note authoring without rewriting the V1 row. Existing stored V2 always uses schema2, remains readable/editable/exportable/clearable when promotion is disabled, and is never silently downgraded. The 60000-byte actual encrypted-envelope guard, original native TEXT capacity, unavailable-reference privacy, note pruning and export/version-fenced clear behavior remain.

## Safe rollout and rollback boundary

The [stopped-copy upgrade contract](../persistent-content-copy-upgrade.md) requires retaining the reviewed old checkout and protected original workspace/key, stopping every old launcher/operator/database writer, taking a protected independent copy, applying reviewed forward source/migrations to that copy and proving original data/private-byte preservation before availability. No deployment or copy operation was performed by this repair.

Enable note promotion only after an owner-reviewed stopped/backup upgrade establishes that every reader/writer of the target data supports V2. The reference is an explicit operator declaration, not automatic proof that other hosts are stopped. Do not enable it during a mixed old/new-reader rollout. Once any useful V2 row exists, disabling promotion does not make an old reader work; application rollback requires retaining the V2-capable reader or a separately reviewed stopped preservation/restore plan. Do not rewrite/downgrade notes or discard revisions to obtain an old-reader rollback. The original protected source workspace is not changed by the documented copy path.

Synthetic test-only opt-in for root-owned HTTP/capacity fixtures:

```php
config([
    'customer-listening.v2_promotion_enabled' => true,
    'customer-listening.v2_rollout_review_reference' => 'SYNTHETIC stopped-upgrade test fixture',
]);
// Tests\Support\ListeningNotesFixtures::enablePromotion() uses precisely these values.
```

This test reference is neither a production approval nor legal copy. No environment setting or production/customer capability was enabled.

## Actual predecessor reader and verification

`tests/Fixtures/customer-listening-v1/ListeningLibrary.php.txt` contains exact pre-notes domain source from independently approved `ca3b1fa7eb60edd2228b73742da33179228a277e`, SHA256 `30aca52bd3d15e314bdfa6bad146870991a1269a4178cd64d9c27b7e376aa2a7`. The support loader checks that digest and renames only the class identifier so the real old domain reader/writer can run alongside the new one on current locked Laravel/crypt/database support. It is pinned trusted test source; no product/customer input is evaluated. This proves actual old payload-reader compatibility on the installed runtime, not a separately installed historical Laravel runtime or a real rolling deployment.

The 34 new cases exercise every effective ordinary action (including clear) with promotion configured/off, fresh rows, old/new alternating writers, exact encrypted no-ops/export, invalid rollout declarations, four real final-query/container callback changes and retained V2 continuity. They explicitly execute the old reader against V2 and assert its 503 refusal; old-reader V2 support is not invented.

PHP 8.4.26, isolated SQLite in-memory databases, Node 24.19.0 and original locked dependencies with independent generated autoload metadata; synthetic key/accounts/public tracks/notes only. Reflection confirms each owned source/test resolves to this worktree. `source-runtime.json` binds exact source/tree/file blobs and fixture hash.

- `successor-focused.txt` / `.xml`: 149 PHP cases/937 assertions, no errors/failures/skips. This selection covers the new compatibility cases, original library/freshness/primary proof/migration, notes/boundary/capacity and account access.
- `exact-source-focused.txt` / `.xml`: frozen49b9154 independently rerun after final formatting/source freeze; 48 PHP cases/357 assertions, all passed (compatibility 34, boundary 11, capacity 3).
- `successor-frontend.txt`: 51 cases passed, including V1 export/clear with no note authoring. TypeScript and frozen Pint passed; whitespace check passed before executable commit.
- `original-compatibility-red.txt` / `.xml`: unchanged originalff4602 application via its own generated autoloader, same new 34 cases loaded by absolute path; 32 actual failures, two already-safe cases pass, zero errors. The ordinary-write failures specifically report actual pinned old-reader 503, not only a schema-field comparison.
- `original-notes-reader-red.*` preserves the first run's genuine uncaught old-reader 503 (one product error), before the assertion was refined to report that expected compatibility failure explicitly. `first-focused.*` preserves one author saved-callback fixture lacking the new required synthetic promotion opt-in; only that owned fixture was corrected, leaving the guard intact. `invalid-selection-file-name.txt` records a nonexistent test-path selection and no executed tests; the corrected selection follows.

Original independent native overflow receipts remain byte-identical in `cloud-listening-notes-independent-20261007`, including actual intended 86184-byte encrypted envelope against TEXT 65535 causing predecessor SQLSTATE22001/MySQL1406 and corrected retained-row/no-write refusal. `preserved-native-capacity-artifacts.json` records their unchanged SHA256 digests. They are prior independent execution evidence, not reruns on 49b9154. This author has not executed native MySQL on this child. Root's composed HTTP/mounted fixtures need the explicit synthetic promotion declaration; exact composed independent review/native acceptance remains required before the PR31 update. Suppression251 remains a separate paused child until this compatibility repair is handed off.
