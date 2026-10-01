# UUID byte-integrity follow-up

T04-UUID-01 is implemented for integrated verification. Migration `2026_10_01_000030_byte_exact_uuid_guards.php` closes the verified SQLite NUL-tail and BLOB bypass on 13 previously guarded identifier fields across 11 tables. Four fields retain their previous length-only policy; nine retain canonical lowercase UUID syntax. Nullable claim-token states and original lifecycle/immutability checks remain unchanged.

The migration checks all retained fields before DDL, then installs 14 supplemental triggers. It never normalizes or repairs identifiers. Partial-install retries verify existing definitions before keeping them; a collision stops without replacement. Rollback removes only the supplemental checks.

Use the complete [migration 000029 runbook boundary](media-integrity-followups.md#migration-000029-runbook-boundary) for **both 000029 and 000030**: verified restorable backup, restored-copy rehearsal on the actual engine, all affected writers paused before preflight through verification, and recorded original/supplemental definitions. MySQL DDL commits separately. Verify 000030's 14 guards as well as all original guards before releasing writers. Rollback removes protection and requires the same isolation and rehearsal.

Local candidate `0d1c29f7063cb468e5278f76b5711e95888e6bd1` passed PHP 8.4.26 syntax checks and eight new SQLite tests / 82 assertions. A broader run covering the new UUID suite, hash suite and migrations 000019–000023 passed 78 tests / 984 assertions with no errors, failures or skips. An independent reviewer checked field policies, preflight/retry/rollback and reran the eight new tests successfully; source hashes remained unchanged. These local snapshot IDs do not imply remote ancestry or merge acceptance.

Real MySQL trigger installation, definition comparison and stored-value behavior remain required in the integrating full CI run. Record the final tested commit/tree, run and actual results in that PR. This closes the identified implementation gap only after those gates; it does not establish production migration or launch readiness.
