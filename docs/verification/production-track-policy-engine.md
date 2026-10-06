# Production track-policy storage engine

This forward repair follows the independently reviewed authored-policy checkpoint `18365c56d1854aea6bf7b09d2df5cc1695012d66`. That checkpoint and its focused receipts remain intact. Its native 86-case result used the normal InnoDB default and did not prove safety under an altered session default.

The three tables in `2026_10_06_233000_production_track_policy_drafts.php` now explicitly select InnoDB. Their foreign keys and transaction/locking invariants cannot depend on an unspecified connection or session engine. Existing parent tables must support those foreign keys; an incompatible installation remains a migration failure rather than an unguarded policy store. SQLite ignores the MySQL engine declaration and retains its existing schema and trigger behavior.

The narrow native regression first creates the existing disposable schema normally, removes the empty owned policy schema, sets `@@SESSION.default_storage_engine` to MyISAM and recreates only the owned migration. It queries actual `information_schema` engine/foreign-key metadata, tests missing-author rejection and transaction rollback, and proves that a late source-acknowledgment audit callback failure preserves the complete policy/audit graph. It restores the session default afterward.

Before repair, the genuine MySQL 8.4.11 case failed after three assertions: all three tables were physically MyISAM. With the explicit engine declarations, the identical native case passed eight assertions, including all three InnoDB engines and all five foreign keys, with no errors, failures or skips. Both runs used fresh private disposable MySQL with normal durability. Their actual negative and positive receipts are retained separately; no earlier result is backdated.

The affected SQLite selection passed ten cases and 36 assertions, with one intentional native-engine skip. It covers encrypted authored creation, all six SQL replacement collisions with recursive triggers disabled, and the three late callback rollback cases. Scoped formatting, syntax, diff integrity and the eight workflow cadence guards passed. No full 86-case rerun, hosted workflow, complete matrix, provider operation or deployment was performed for this repair. Complete integrated acceptance remains deferred to the final exact candidate.

Run the narrow native regression with:

```sh
php vendor/bin/phpunit tests/Feature/ProductionTrackPolicyEngineTest.php
```

The integration owner registers the single native-only method and reconciles the shared CI policy before publication. This child changes only the owned migration, its engine regression and this evidence document; it adds no active policy or production transport.
