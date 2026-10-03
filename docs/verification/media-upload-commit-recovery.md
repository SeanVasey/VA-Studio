# Private media upload commit recovery

This WP-03 / T13 safety child starts from GitHub handoff candidate `6c55b4637ec858716c40055b2646e69da69230e1`. It does not complete resumable intake, object storage, content onboarding, or the T13 parent.

## Finding and change

Source review found that `IngestMediaUpload` deleted the newly copied private source after every exception from its database transaction. Laravel can report an error after the root PDO commit has already persisted the asset and audit. A failed nested rollback can also leave those rows pending in an outer transaction. In either case, unconditional cleanup destroys bytes referenced by durable or still-committable rows.

The intake callback now marks its commit boundary immediately after recording the audit, matching the conservative boundary in `MediaProcessor`. Cleanup is allowed only before that boundary, when the connection has returned to the transaction level observed before intake, and when Laravel has not reported a `DeadlockException`. The level check retains bytes if callback failure is followed by a failed nested rollback. The exception check covers Laravel's nested concurrency handler, which decrements the depth without rolling back; a lock-wait timeout can leave earlier statements pending. Reaching the boundary is not proof of a durable commit; uncertainty intentionally retains the unique private quarantine path. No lookup for an absent row authorizes deletion.

The upload role, limits, authorization locks, integrity checks, quarantine status, audit payload and exception propagation remain in place. Retained uncertain sources do not become public or ready. Existing quiescent-maintenance requirements for investigating orphans still apply; this change introduces no deletion sweep.

The transaction paths were reviewed against the locked [Laravel transaction implementation](https://github.com/laravel/framework/blob/91188a17ceaa3dbace6e8a5f7abd0d042e466359/src/Illuminate/Database/Concerns/ManagesTransactions.php). MySQL documents [statement-only rollback for a lock-wait timeout by default](https://dev.mysql.com/doc/refman/8.4/en/innodb-error-handling.html). The guard covers the current intake callback path; future transaction callbacks that replace framework exceptions need renewed review.

## Regression coverage

`tests/Feature/MediaUploadCommitRecoveryTest.php` uses isolated fake private storage and `FinalizationDatabaseMigrations`, so its first case observes a real root commit rather than a `RefreshDatabase` savepoint.

- An exception from the root `TransactionCommitted` listener must preserve the persisted quarantined asset, exact source bytes and audit. The source remains unavailable through the public media route.
- A controlled savepoint rollback after callback completion makes the new rows invisible, then throws. The uncertain source must remain intact; a healthy retry must use a different path. This is fault injection, not evidence of a real lost server acknowledgement.
- An audit listener failure before callback completion followed by successful rollback must remove only that attempted upload, at both root and nested transaction levels. Earlier source bytes, asset and audit must survive.
- Releasing the nested savepoint before throwing from the audit listener makes rollback fail. The still-pending asset and audit must retain their source bytes and survive a subsequent root commit.
- An injected lock-wait error from the audit listener exercises Laravel's real nested concurrency handler. It restores the entry depth without rollback; the pending asset and audit must retain their bytes and survive a root commit. This does not claim to reproduce a live lock race.

The new regression file is registered additively in the focused `media` suite, for both SQLite and MySQL workflow dispatches. Existing suite membership is unchanged.

## Evidence and remaining gate

The defect and expected pre-fix failures were established by source review; a red PHP run has not been executed in the editing environment because PHP and Composer are unavailable. Local structural checks and independent review are recorded in the branch/PR handoff. They do not substitute for runtime tests.

Required runtime evidence is the focused `media` suite on SQLite and MySQL at the published candidate SHA, including all six new cases. The existing `MediaUploadTest` should also pass when full CI runs. No live storage, catalog content or credentials are involved. Do not merge until those checks and independent review pass on the exact candidate.

Reverting the application change restores the unconditional-deletion risk. There is no schema migration and no reason to delete retained files during rollback.
