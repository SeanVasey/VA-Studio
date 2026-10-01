# Synthetic private restore rehearsal

This executable T25 preparation check restores a newly generated **SQLite** database snapshot and copies of purchased private artifacts into separate temporary paths. It uses existing synthetic commerce/media fixtures and a synthetic PDF renderer, with fake payment transport and no fulfillment activation. It is not a backup command for an existing installation.

Run from the repository after installing its locked PHP dependencies and normal media-test prerequisites:

```sh
php vendor/bin/phpunit tests/Feature/SyntheticPrivateRestoreTest.php --fail-on-phpunit-warning --display-warnings
```

The suite requires the `testing` environment. It creates its own random private workspace and named SQLite connections, ignores ambient database URLs/credentials, and never migrates or restores the configured application database. Even when the surrounding CI job uses MySQL, these five cases exercise SQLite. There are no input paths or production restore entry points. Cleanup removes only the generated workspace.

## What the rehearsal verifies

1. Create a synthetic paid mixed cart with exclusive and nonexclusive purchases, issue two fixture originals, and retain pending entitlements. No real provider request, customer data, download authorization or activation is involved.
2. Record the full schema digest and each table's row count and digest, including SQLite sequence state. Create an actual `VACUUM INTO` database snapshot. Copy the exact purchased media through `DeliveryAssetFiles::copyVerified`, and copy original PDF bytes returned by `ContractFiles::verify`, into a private backup tree.
3. Copy that database and those objects into a separate restore tree, compare their hashes, close the source connection and delete the synthetic source database and source object tree. Reopen the restored database through a new connection with `PRAGMA query_only = ON`. Check database integrity, foreign keys and the complete schema/row inventory.
4. Re-read encrypted purchase evidence and frozen purchased revision descriptors through the existing historical readers. Verify original manifests and exact original/media bytes. The recorded owner can read the purchase; another owner receives the existing unavailable-order response.
5. Independently remove or corrupt a restored original PDF or purchased media file, including corruption with unchanged byte length. Existing private-file verifiers must reject it. Copying the archived original back restores verification. Throughout these checks the entire database, renderer/provider call history and backup bytes remain unchanged; entitlements stay pending and delivery tables remain empty.

The random test encryption key stays in process memory. It is not written into backup evidence. The rehearsal assumes that same key remains available across the local reopen; it does **not** test a separate-host key recovery procedure. Synthetic fixture PDFs establish orchestration/byte preservation, not legal or PDF-standard approval.

## Recorded local evidence and remaining gates

On October 1, 2026, the focused command passed under PHP 8.4.26 with SQLite: **5 tests, 532 assertions**, no skips, errors or failures. Runtime was approximately 22 seconds. Source identity and independent review belong in the integrating candidate's verification record.

This is bounded offline recovery evidence, not completion of T25 or WP-13. Production MySQL backup/restore, encrypted off-host storage, object-store versions/permissions, atomic database/object capture under concurrent writes, retained key recovery, complete historical-source ingestion, approved retention/legal exceptions, named operators and observed RPO/RTO remain separate acceptance gates after hosting and policy decisions. These tests neither establish an archive of real BeatStars history nor authorize production activation or cutover.
