# Stopped private content copy upgrade

This is the explicit upgrade preparation path for the durable local installation in [persistent content onboarding](persistent-content-onboarding.md). It copies a stopped, retained SQLite workspace into a **new** private directory, applies reviewed forward migrations to that copy, and only then makes the copy available to the existing loopback launcher. It never updates, deletes, rekeys or migrates the original workspace. This is local authoring preparation, not a production backup, server restore, deployment or commerce acceptance result.

## Operator inputs and prerequisites

- Retain the old reviewed checkout at the canonical path recorded in the old workspace's protected identity. Its exact Git source, migration files, Composer lock and built storefront manifest must still match. Updating that checkout in place, moving the workspace or discarding its key is not repaired by this command.
- Prepare a separate reviewed new checkout with its own locked Composer autoload metadata and a genuine locked storefront build. Supply the reviewed old and new 40-character Git commits explicitly; do not substitute an unreviewed current branch head. Local Git replacement refs cannot redefine those commits, and tracked source bytes, executable modes and untracked executable/configuration source are checked.
- Stop the old launcher, operator command and every other database writer. Port `127.0.0.1:8175` must be free. This command takes the original workspace's OS lease using a read-only descriptor without changing its retained lease token. An unrelated SQLite client that ignores this application's lease must also be stopped by its operator.
- Use Linux/Unix with effective-UID inspection, PHP 8.4 with SQLite and the application extensions, Node with no-follow file opens, and local OS file locking. Missing effective-UID inspection fails closed. This selection has been exercised on Linux; Windows, network filesystems and production/non-root server installation have not been accepted by it.
- Choose a nonexisting canonical destination outside both checkouts and the original workspace. Its immediate parent must be owned and protected from group/world writes; unsafe writable ancestors, symlinks, descendant hard links and overwritten destinations are refused. The narrow root-owned sticky temporary-directory exception is inherited from the onboarding guards. The operator supplies the durable location; no server, provider account or deployment location is invented.

Run the command from the **new** reviewed checkout, replacing all example paths and SHA placeholders with the retained reviewed inputs:

```sh
node scripts/ops/persistent-content-upgrade.mjs \
  --source-checkout /absolute/retained-old-checkout \
  --source-directory /absolute/private/authoring-v1 \
  --directory /absolute/private/authoring-v2 \
  --expected-source-sha REVIEWED_OLD_40_CHARACTER_SHA \
  --expected-target-sha REVIEWED_NEW_40_CHARACTER_SHA
```

The default complete source-inventory budget is 10 GiB and 100,000 entries. `--max-bytes INTEGER` may explicitly select a positive byte budget up to 1 TiB. Each copied file is streamed through private exclusive no-follow file descriptors and checked against its original identity, size and digest. Each database snapshot/migration phase has a 60-second process limit. These bounds are admission and failure limits, not a large-catalog performance claim.

After the command reports success, use the existing launcher from the same new checkout:

```sh
node scripts/dev/persistent-content.mjs start --directory /absolute/private/authoring-v2
```

The original operator, key, installation ID and cookie identity remain usable. Payments, customer enrollment, mail, media processing, workers and scheduler remain disabled. No user, recording, rights clearance, price, license term, purchase or live provider setting is fabricated by this path.

## Capture, migration and availability boundaries

The source workspace is fully inventoried while its exclusive lease is held. The main database and any crash-retained `-wal` and `-shm` files are copied **before any PDO connection opens a database**. Only the new copy is subsequently opened, checked, checkpointed or migrated. Source file paths, inode/device identities and byte digests, including its identity and lease, are compared again after capture and after migration. File access times are not claimed unchanged by reading them.

The new workspace retains all original `app/` bytes, opaque file sessions and logs, together with the original application key and session-cookie name. Application/configuration caches, compiled views, old public build assets and temporary files are regenerated or discarded in the copy; fresh admin/storefront assets come from the new checkout. The target has its own directory identity and independent OS lease and remains `initializing` during the complete operation.

The old migration source must be an exact prefix of the new migration source. Rewritten historical migrations, removed migrations, reordered predecessors and downgrades are refused. The copied migration ledger must match that reviewed old source before Laravel's actual forward `migrate` command runs. No seeder or rollback is invoked. This strict path accepts additive changes; a version that needs to transform existing rows requires a separate reviewed preservation contract.

Verification compares every old table's original column metadata, including generated columns, and every old cell using typed serialization, each column's actual SQLite storage class and a digest of deterministically ordered rows. Existing timestamps, authorship, binary values, nulls, audit rows, foreign-key definitions, views, index/trigger definitions and business SQLite sequences must remain identical. The full old `CREATE TABLE` definition must remain byte-identical or retain its exact header, original body prefix and table options while appending actual new columns. Rebuilds that remove or rewrite an old CHECK, collation, generated expression or other original definition refuse; some otherwise valid additive migrations that rearrange table definitions therefore require a separate reviewed contract. Merely preserving row counts or PDO string values is insufficient. New migration-ledger rows and its exact sequence advance are the explicit exception; the expected pending migrations must appear in the next batch. Original private file/session/log digests are checked again before availability.

The command records protected `upgrade-plan.json`, `upgrade-baseline.json` and `upgrade-result.json` evidence in the destination. They contain structural metadata and digests, not an export of row values or a plaintext key. `upgrade-provenance.json` binds the reviewed old/new commits, raw database capture, preservation result and exact ready identity digest with HMAC-SHA256 using the retained private application key. This provenance is produced and checked by the focused suite at upgrade creation; the unchanged onboarding launcher continues to enforce its protected identity/path/schema/lease checks and does not independently validate the extra HMAC on each later start.

The ready state is written only after release source, lease, copied data and preservation checks pass. Interrupted or failed copies are retained unavailable for inspection; the command does not delete them, overwrite them, resume them or reset the old workspace. An already existing destination always refuses. Keep the original unchanged and choose another new protected destination after diagnosing the failure. This command does not establish an independently stored production backup or permission to discard the old workspace.

## Focused evidence and deferred acceptance

The selected subprocess suite uses real PHP/SQLite, actual audited operator creation, actual Laravel migrations and the signed Livewire login transport. Its technical fixtures are isolated temporary source/checkouts and workspaces with no production prices, rights or purchases. It proves an additive copied upgrade, a real killed-writer WAL/SHM capture, identical old cells/audits/private bytes/sequences/key/session, and a real authenticated browser cookie retained through the upgrade and two new loopback restarts. It also reaches the genuine disabled checkout response with CSRF, verifies that private database/provenance/configuration paths are unavailable over HTTP, and rejects destructive same-count row/timestamp updates, sequence changes, thrown migrations, held leases, concurrent targets, SHA/source/migration drift, Git replacements, linked descendants, unsafe ancestors, existing destinations and a too-small byte budget.

```sh
PERSISTENT_UPGRADE_REQUIRE_PHP=1 node --test scripts/ops/persistent-content-upgrade.test.mjs
php -l scripts/ops/persistent-content-upgrade.php
php vendor/bin/pint --test scripts/ops/persistent-content-upgrade.php
node --check scripts/ops/persistent-content-upgrade.mjs
node --check scripts/ops/persistent-content-upgrade.test.mjs
git diff --check
```

An earlier expanded HTTP fixture expected `404` from disabled checkout. The actual route's explicit `COMMERCE_NOT_ENABLED` response is `503`; a request without CSRF first returned `419`. The retained negative receipts and corrected request/assertion are part of the review evidence. No application gate was weakened. The final focused result must be recorded against its exact frozen reviewed source by the integrator, with an independent sensitive migration review before composition.

Independent review of predecessor `1f9a352` also reproduced two actual preservation gaps without changing original source bytes: removing an old inline CHECK after a table rebuild, and changing a retained BLOB into TEXT with identical bytes. Both incorrectly made the copied workspace ready. The guard correction retains the predecessor evidence and adds actual unavailable-target regressions for both cases, together with full table-definition and SQLite storage-class proof. Predecessor focused success is not approval of the corrected candidate; its exact source requires fresh focused evidence and independent review.

Production host/ownership/permissions, independent backup and restore, workers/scheduler/scanner/media processing, selected commercial policies/accounts and live payment/delivery remain separate work. MySQL, production commerce, genuine browser rendering and the full final integrated acceptance matrix are not established by these local SQLite/HTTP checks. Follow the October 6 CI policy: focused development evidence and independent review now, manual full verification for the final reviewed candidate.
