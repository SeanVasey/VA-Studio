# Independent review: lane MIG (history archive, delta capture, exclusivity authority)

- Branch `harness/migration-tooling-20261024`, worktree `/home/user/wt/mig`
- Reviewed HEAD `d456ee3bf37cedd237fc628c85ed8b2338b30046`. This is the tested source `fed4571e` plus the merges of origin/main `e5d0d3f2` and `707649a6`, and docs/evidence commits. `git diff fed4571e HEAD` on app/database/tests/config/scripts contains only T36 files from main. No MIG code changed after `fed4571e`.
- Reviewer: independent Claude reviewer, 2026-10-24. I did not push, commit, comment or dispatch anything. I ran probes and mutations, then reverted each one. At the end, `git status --short` is empty and `public/build` is absent.

## Verdict: APPROVE WITH CONDITIONS

There are no P0 or P1 findings. The migration is additive and forward-only. It has immutable guards on both engines. Every write path stays inside the `legacy_*` tables, the audit log and the private disk. No path creates a grant, entitlement, download, customer account or consent record. The authority ledger serializes correctly on native MySQL.

I found four P2s. Two are real defects in sensitive invariants: separation of duties and the PII-free output claim. One is a semantic gap in `verified_grant` staleness. One is a latent MFA/attestation gap that only matters once a non-local environment is admitted. Under the 2026-10-11 freeze-and-batch rule, these P2s can go to the lane's follow-up batch. They do not block this merge.

C2 and C4 have to be fixed before the specific next steps they name: the real S-9 run, and any non-local admission.

### Conditions

1. **C1 (P2-1).** Close the segment-actor separation-of-duties gap. Either refuse an import segment whose actor is not the batch creator, or compute "importers" from the segment audit actors. Add a regression test in which operator B finishes A's batch and then reviews.
2. **C2 (P2-3).** Before the real S-9 dry run:
   - Stop printing contract file names (and any other raw cell text) in findings.
   - Assert the no-PII output on every action: reconcile, delta, review-grant and both authority commands. Today only dry-run is asserted.
3. **C3 (P2-2).** Before anything consumes `verified_grant` (historical entitlement activation, which needs Sean's authorization):
   - Make eligibility and `classification()` evaluate against the latest reconciled snapshot of the source, so that removals count.
   - Re-verify the evidence hash in `classification()`.
4. **C4 (P2-4).** Before any environment is added to `config/legacy-archive.php`, enforce staff MFA and attestation inside the write transaction. That means `StaffWriteAuthority::requireFor` or an equivalent that binds and consumes the attestation and requires MFA enrollment where the panel requires it. Production also needs a design decision, because `requireFor` refuses outside `admitsTestCommerce()`.
5. **C5 (P3).** Record "exclusive activation/checkout must consult `ExclusivityAuthority::storeMaySellExclusives()`" as an explicit launch gate. Put it in `docs/live-payment-and-production-preparation-queue.md` and/or the T39/T40 rows. It is currently recorded honestly in FP-101, CHANGELOG, the operator guide and the packet, but not in the launch queue.

## Findings

### P2-1: Separation of duties checks only the batch creator, not who inserted the evidence

`LegacyGrantReview::evaluate()` derives "importers" from `legacy_history_batches.actor_id` of the batches that own the line, order, customer and contract rows. `LegacyHistoryArchive::import()` → `batch()` reuses an existing batch row for any authorized actor and never compares actors. The batch row keeps the creator's id, and the segment audit event records the real inserter.

The packet says "Whoever archived any of this evidence cannot also review it" (A8). That claim is not true when segments are split between operators.

**Failure path, proven by probe A on SQLite:**

1. Operator A runs `import(limit=1)`. This creates the batch and inserts one customer.
2. Operator B runs `import(limit=1000)` and inserts the other 16 records, including the order, line and contract for `synthetic-ord-1002`. Two distinct segment actors are recorded in the audit log.
3. B calls `review(line 1002, B, write=true)`. The outcome is `recorded`.

Impact is bounded: B cannot alter the evidence, because the snapshot hash binds it and A attested it. Still, the stated control is bypassable, and the audit trail would show B as both importer and reviewer.

### P2-2: `verified_grant` eligibility and classification ignore later removals and buyer changes

`evaluate()` takes the latest stored version of each record across all batches (the union of the archive). `classification()` only checks whether the latest line version carries a review.

The archive has no tombstones. A later export that drops the order, line or contract leaves the earlier versions in place as if they were current. A buyer e-mail change is classified as an automatic, non-blocking delta (`customer_details_changed`). `import` does not require a reconciled delta.

**Failure path, proven by probe B:**

1. Import the baseline. A different reviewer records `verified_grant` on line 1002.
2. Import a later export that removes order 1002, its line and its contract, and changes buyer 002's e-mail.
3. `inspect(line)` returns `blockers=[]` and `classification()` returns `verified_grant`.

A status-only change (paid → unpaid) is handled correctly. The line's `row_sha256` covers the status cell, so a new line version appears and the classification falls back to `archive_only`. I checked this.

No entitlement depends on this today, so it is P2. It must be fixed before any activation consumer exists (C3).

### P2-3: Output can contain PII, and the no-PII check covers dry-run only

Findings print `CellText::detail()` of unreferenced contract file names verbatim. The names are only screened for control characters and `/`.

Probe C added a file named `Exclusive - Jane Q Buyer - jane.buyer@example.invalid.pdf`. The dry-run JSON printed it in full:

`"column":"Exclusive - Jane Q Buyer - jane.buyer@example.invalid.pdf"`

Real BeatStars contract file names commonly carry buyer names. This contradicts the command's own docblock ("never an e-mail address, a name …") and the packet's claim that output contains only counts, codes, keys and hashes.

Mutation MR5b, which adds a buyer e-mail to the `reconcile` output, **survived**. Only dry-run output is asserted to be free of `@` and names.

### P2-4: Write-time staff authority records an attestation but never verifies or consumes it, and MFA is not enforced

Both commands call `StaffWriteAuthority::attest()` and then pass the audit id into the domain services. The services re-check only `Gate administer-catalog` under lock: `is_admin` and a verified e-mail. They accept any `attestationAuditId`, including null. They never call `StaffWriteAuthority::requireFor()` or `require()`.

As a result:

- The attestation is not bound to the operation.
- It is not consumed (it is not single-use).
- MFA enrollment is recorded (`mfa_enrolled`) but never required.

In `local` and `testing` the panel does not require MFA, so this is latent. If staging or production is ever named in `config/legacy-archive.php`, writes would bypass the repo's rule that production administration needs MFA. Admission requires a reviewed code change, so this is P2 with condition C4.

### P3 (non-blocking)

1. **Handover's "export taken at or after the freeze" rests on operator declarations.** `handoverEvidence()` compares the operator-declared `source_as_of` from `mapping.json` with the freeze time. The normalizer only enforces `ordered_at <= source_as_of <= acquired_at`, and both timestamps are declared by the operator. The guide should state that the handover reference must evidence the actual export time.
2. **SQL guards on the ledger are vocabulary-only.** They do not constrain authority/transition pairs or sequence contiguity. A direct insert of `(sequence=7, vasey_store, declare)` is accepted. The domain enforces these rules. TRUNCATE and DROP are DDL and are not trigger-guarded. On MySQL, TRUNCATE of `legacy_history_customers`, `legacy_history_orders`, `legacy_history_contracts` or `legacy_exclusivity_authority_events` would succeed, because nothing references them by FK. This matches the repo's other immutable tables.
3. **The FOR UPDATE mutation (MR3) is killed only by the race harness's barrier.** The worker arms on the SQL text containing `for update`. The actual single-event guarantee is the unique `sequence` index, which the schema test pins (M4e). The lock contention is real and observed in `performance_schema` with the lock in place. Acceptable as is.
4. **Checkout gap disclosure.** The disclosure is accurate everywhere I looked: CHANGELOG ("does not consult the authority ledger yet"), guide §Exclusivity, FP-101 note, and the packet's untested conditions and next dependencies. Nothing claims enforcement. It is not yet an explicit line in the launch queue (C5).
5. **The FP-099 note omits the asset-link gap.** Legacy `track_source_id` is not linked to store assets, and the row's acceptance includes "exact … asset links proven". The packet's untested list does say this. Suggest adding it to the CSV note.
6. **Customer e-mail changes are automatic in the delta.** That matters for any later exact-match buyer linking (see P2-2). Consider making e-mail changes blocking, or at least flagging them.
7. **The domain services do not call `LegacyArchivePolicy::admit()` themselves.** Only the commands do. Today the commands are the only callers.
8. **Orphaned contract files after a rolled-back segment.** A rolled-back segment leaves its stored original on disk. The files are content-addressed and reused on resume, so this is harmless.
9. **`ProductionBuyerAssentObservationsTest` uses `rollback(step=1)`.** That call targets whichever migration sorts last, which is now MIG's archive (its `down()` throws, so the test still passes). The test no longer exercises the migration it names. This fragility predates MIG.
10. **Batch attestation is first-segment only.** `legacy_history_batches.attestation_audit_id` records only the first segment's attestation. Later segments' attestations are only in the segment audit events.

## Assessment by item

1. **Migration.**
   - Additive. `up()` refuses if any of the 7 tables is retained. `down()` throws before any query (tested).
   - No existing table is altered. FKs go only to `users`, `legacy_history_batches` and `legacy_history_lines`. Every table is InnoDB.
   - Uniques are correct: `snapshot_sha256`; `(record_key, record_sha256)` ×4; `line_id`; `sequence`. All are asserted on both engines.
   - UPDATE and DELETE abort triggers on both engines. A SQLite BEFORE INSERT replace guard covers REPLACE and upsert (SQLite REPLACE does not fire DELETE triggers). On MySQL, REPLACE fires DELETE and ON DUPLICATE fires UPDATE.
   - Byte-exact value guards: `CAST(... AS BINARY)` on MySQL; `typeof='text'` plus `IN` on SQLite.
   - PII is only in `record_ciphertext` (Crypt). In clear: source ids, keys, hashes, `email_hmac`, consent=`unknown`, status, money, currency, `ordered_at`, license label, track source id. Contract originals live on the private `local` disk (`storage/app/private`), addressed by SHA-256.
   - No interaction with the census or with other domains' ownership guards: those scan only their owned objects. I ran `NativeSchemaIsolationTest` natively, which the author had not; it copies every table and trigger, including the new ones, into a peer schema and re-migrates. It passed (34/357).
2. **Archive correctness.**
   - The `(record_key, record_sha256)` identity and immutable versioning are correct.
   - Each segment runs in one transaction, re-checks the Gate under lock, and takes the batch row FOR UPDATE.
   - Originals are re-verified after the write, and on `reconcile` and review.
   - I grepped every write in `app/Domain/Migration/History/*` and both commands. They go only to `legacy_*` inserts, `AuditEvent::create` and `Storage::disk('local')->put`. No grant, entitlement, download, customer account or consent write exists.
   - MR7 (skip the locked authority re-check) was killed.
3. **`verified_grant`.**
   - It is a separate table, guarded to `verified_grant`, with a unique `line_id`.
   - Lines are SQL-guarded to `archive_only`.
   - All conditions are enforced: paid, both refunded amounts zero, payment reference, unambiguous e-mail HMAC, exactly one contract with verified bytes, no competing unrefunded exclusive, reviewer not the batch importer.
   - No entitlement is created (`nonArchiveState` stays byte-equal).
   - MR2 was killed.
   - The gaps are P2-1 and P2-2.
4. **Delta.**
   - Automatic (non-blocking) changes: new records, forward status with non-decreasing refund, line refund increase, restated `row`/`row_sha256`, customer contact changes, a contract `declared_sha256` going from null to a value.
   - Blocking changes: removals, other order/line/customer changes, exclusive line changes, contract byte or link changes.
   - Dispositions are bound to both snapshot hashes. Stale or unknown items are refused.
   - The "row number only" contract fix (`$only($restated)` added in `d9b74cd4`) is pinned by `test_rows_that_only_move_within_a_sheet_are_restated_not_blocking`. Mutation MR6 restored the pre-fix rule from `4e3f7ddf` and was killed (1 failure).
5. **Authority ledger.**
   - The state machine matches its spec. `unfreeze` requires head transition = `freeze` and previous authority = `beatstars`.
   - Handover preconditions:
     - post-freeze watermark (MR8 killed)
     - both snapshots archived and reconciled
     - baseline is the latest prior export (MR9 killed)
     - reconciled delta
   - The ledger head is read FOR UPDATE, `sequence` is unique, and 23000/40001 errors map to `authority_changed_concurrently`.
   - The native two-process races passed on HEAD. MR3 was killed.
   - The checkout gap is disclosed and not overclaimed (C5).
6. **Commands.**
   - Dry run is the default.
   - A write requires `--write`, the snapshot hash (import) or a reference, an interactive console, a hidden password, the Gate, and `--attest`. The attestation audit row is written before the write.
   - Environment admission is `local`/`testing` plus the config list, with no `env()` key. An ambiguous environment is refused.
   - Synthetic sources are refused in production (MR4 killed).
   - Output is not reliably free of PII (P2-3).
7. **Census and selection.**
   - SQLite census 291 → 293. It is append-only, and the first 150 entries and the full prior prefix are unchanged (checked against origin/main).
   - The `pattern_files`, `include_files` and `branching_helper_files` additions are appropriate.
   - The regenerated selection proves **2519 of 9658 in 243 files (of 707)**, matching both workflow comments. Generated files were deleted.
   - The parity CSV edits (FP-098/099/101 → `implemented_partial`) are accurate. FP-099 is slightly incomplete (P3-5).
   - **Shared `264000` prefix with lane A5** (`2026_10_24_264000_refund_dispute_lifecycle`, unmerged, `/home/user/wt/a5` at `f30ae1ae`):
     - Laravel's migrator keys and orders migrations by full file name. Duplicate prefixes are legal, and the order is deterministic: `…legacy_history_archive` sorts before `…refund_dispute_lifecycle`.
     - Every repo guard I found keys on full names: `MigrationDefinitions::load`, `require database_path('migrations/<full name>')`, own-row checks in `migrations`, owned-object dictionary scans, and `FinalizationLifecycleFixture`'s file-count check. None requires a unique numeric prefix.
     - The two migrations are independent. MIG references only `users`. A5 forward-only alters commerce children, and its tests drop and rebuild order tables MIG never references. Neither `down()` is reachable in normal operation; both throw.
     - **So it does not break this repo's migration guards.** It does break the de facto unique six-digit serial (…252200, 259000, 260000 … 263000), and the "last migration" test from P3-9 would then target A5's file.
     - Recommendation: whichever lane merges second renumbers its unmerged migration (for example `264100` or `265000`) and updates its own references. No environment has run either migration, so renaming is safe.

## Commands and results (all on HEAD `d456ee3b` unless noted)

The SQLite runs used the coordinator-specified direct PHPUnit command: `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `PAO_DISABLE=1`, `--fail-on-phpunit-warning`. The wrapper is `scratchpad/mig-review/run.sh`.

### New test files on SQLite

| File | Result |
| --- | --- |
| HistoryNormalizerTest | 32/194 |
| HistoryDeltaTest | 6/57 |
| LegacyHistoryArchiveTest | 8/69 |
| LegacyHistoryCommandTest | 5/66 |
| ExclusivityAuthorityTest | 6/96 |
| LegacyHistoryArchiveMigrationTest | 4/60 |
| ExclusivityAuthorityConcurrencyTest | 2 skipped (native-only) |
| **Total** | **63 tests, 542 assertions**, matches the packet |

### Existing tests on SQLite

| File | Result |
| --- | --- |
| MigrationRecompilationTest | 1/5 |
| ProductionEnvironmentTemplateTest | 6/1657 |
| BeatStarsExportNormalizerTest | 91/731 |
| BeatStarsDryRunCommandTest | 28/208 |
| CustomerConsentAdmissionTest | 22/48 |
| InstallationReportTest | 34/153 |
| CustomerConsentMigrationTest | 20/198 |
| TrackPublicationGuardMigrationTest | 10/31 |
| ProductionTrackCapabilitiesMigrationOwnershipTest | 23/606 |

All passed.

### Native MySQL 8.4.11

Private `/opt/mysql-8.4.11/bin/mysqld --no-defaults`: own datadir in the scratchpad, port 3517 (free per `ss -ltnp`; other lanes were on 3391/3407/3489 and I did not touch them), database `vaseyaudio_migrev`, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_migrev`.

| Run | Result | Time |
| --- | --- | --- |
| LegacyHistoryArchiveMigrationTest + LegacyHistoryArchiveTest + ExclusivityAuthorityTest + LegacyHistoryCommandTest | OK (23 tests, 291 assertions) | 6m34s |
| ExclusivityAuthorityConcurrencyTest | OK (2 tests, 324 assertions) | 11m48s |
| NativeSchemaIsolationTest (not run by the author) | OK (34 tests, 357 assertions) | 28m44s |

The container then restarted, and the server was gone afterwards. All native runs had already completed. The datadir and the socket symlink were removed, so no server of mine remains.

### Safeguards and selection

- Safeguards: `test-phpunit-shards.py` 52 OK, `test-ci-scope.py` 27 OK, `test-workflow-cadence.py` 14 OK, `test-focused-tests.py` 53 OK.
- `phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` proved 2519 selected tests in 243 files (of 9658 in 707). The generated files were deleted.

## Probes (temporary test file, kept at `scratchpad/mig-review/ZzReviewProbeTest.php`, never in the worktree at the end)

- **A: segment-actor review.** Confirmed (P2-1).
- **B1: paid → unpaid in a later export.** Classification correctly reverts to `archive_only`.
- **B2: removal plus e-mail change in a later export.** `blockers=[]` and `verified_grant` persists (P2-2).
- **C: unreferenced contract file name.** Printed verbatim in dry-run output (P2-3).

## Mutations (one at a time, reverted with `git checkout`; results in `scratchpad/mig-review/mut/results.jsonl`)

| Id | Mutation | Tests | Result |
| --- | --- | --- | --- |
| MR1 | Normalizer emits `granted` consent (inferred) | normalizer + archive | killed (8 errors, 1 failure) |
| MR2 | Drop reviewer ≠ importer blocker | archive + command | killed (2 failures) |
| MR3 | Remove FOR UPDATE on ledger head (native MySQL) | concurrency (freeze) | killed (barrier; see P3-3) |
| MR4 | Accept synthetic source in production | command | killed |
| MR5 | Print buyer e-mail in dry-run output | command | killed |
| MR5b | Print buyer e-mail in reconcile output | command | **survived** (P2-3) |
| MR6 | Restore pre-fix contract restated rule | delta unit | killed |
| MR7 | Skip locked staff re-check in import | archive | killed |
| MR8 | Accept pre-freeze later export at handover | authority | killed |
| MR9 | Ignore "baseline is latest" at handover | authority | killed |

## Untested conditions

- No real BeatStars export (S-9) was available.
- Not run: the full native selection, the full SQLite suite, Foundation CI, browser specs, GitLab.
- I did not repeat the author's 51-file migration-pattern SQLite run on HEAD. HEAD's MIG code equals `fed4571e`, and T36 adds no migration.
- MySQL under READ COMMITTED: reasoned only. The unique sequence still serializes, but the worker pins REPEATABLE READ.
- I did not test the combined schema with lane A5 merged. I only read A5's migration and its references.
- Concurrent grant reviews of one line: reasoned only (the unique `line_id` rejects the loser with a raw QueryException).
- Behaviour after `APP_KEY` rotation (`email_hmac` and `reference_hmac` would no longer match; Crypt previous keys apply to the ciphertext only): not tested.
