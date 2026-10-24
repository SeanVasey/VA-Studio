# Independent re-review: lane MIG after fixes for conditions C1 to C5

- Worktree `/home/user/wt/mig`, branch `harness/migration-tooling-20261024`.
- Tested source `41681c840d3d72466e19126a52ce9e2015204b55`. Its application code is identical to `8b66d788`.
- HEAD `50056efb` adds only the packet and evidence. main is unchanged at `707649a6`.
- Prior record: `scratchpad/mig-review-d456ee3b.md`.
- I did not commit, push, comment or dispatch anything. The probe test files lived in the worktree only while running and are now in the scratchpad. `git status --short` is empty and `public/build` is absent.

## Verdict: APPROVE WITH CONDITIONS

There are no P0 or P1 findings.

C2, C4 and C5 are closed. C3 is closed. C1 is closed for the exact failure path I reported (probe A).

Probing beyond that path found one residual separation-of-duties gap of the same class (R-1, P2). It is now narrower than P2-1, and it only affects `verified_grant`, which still grants nothing. Under the freeze-and-batch rule it can be deferred to the lane follow-up batch. It must be closed before anything consumes `verified_grant` (that is, before historical entitlement activation, which needs Sean's authorization).

### Condition

**C1′ (R-1).** Include the creator and the segment actors of the newest batch, the one whose member list decides eligibility, in the reviewer exclusion set. Alternatively, require that newest batch's delta from its predecessor to be reconciled (with dispositions) before eligibility can rely on an absence. Add a regression test equivalent to probe D.

## Findings

### R-1 (P2): the creator of the newest export can manufacture eligibility by omission, then review alone

Eligibility now depends on the newest fully archived batch's `members`. A competing unrefunded exclusive sale, or another customer sharing the buyer's address, only blocks review if it is a member of that batch.

The separation-of-duties set holds only the inserting actor and the batch creator of the four rows the review relies on (line, order, customer, contract). It does not include the creator of the newest batch whose omissions established the absence. `import` does not require a reconciled delta.

**Failure path (probe D, SQLite, passes on `41681c84`):**

1. Operator A imports a baseline in which `synthetic-track-03` was sold exclusive twice. Line 1002 shows `duplicate_exclusive_sale`.
2. Operator B attests and imports a "later export" with a newer watermark. It is the baseline minus the competing sale `synthetic-ord-1009`. Every member is already archived, so B inserts 0 rows, and the batch counts as complete.
3. B reviews line 1002. The outcome is `recorded` and `classification()` returns `verified_grant`.

The delta would have flagged the removal as blocking, but nothing makes the review depend on that delta.

The same works for `ambiguous_customer_identity`: omit the other customer who shares the address.

Impact is bounded. No entitlement exists, and the act leaves an audit trail (B's attestation, batch and review). But one person can turn ineligible evidence into `verified_grant`, which is exactly what the reviewer separation exists to prevent.

The docs over-claim here. `docs/handoff/2026-10-07/codex-deferred-20261011.md` and the packet describe P2-1 as fixed. They should say "fixed for inserted and batch-created rows" until C1′ lands.

### P3 (non-blocking)

1. **Watermark is unbounded.** `currentBatch()` orders by the operator-declared `source_as_of`, which is checked only to be at or before `acquired_at`, never against the import time. A future-dated export would stay "newest" for good, so every review would be evaluated against it. This fails closed for removals, but it is another lever for R-1. Consider bounding `acquired_at` by the batch's `created_at`.
2. **A changed review can never be redone.** `reviewed_evidence_changed` is permanent for that line version: the unique `line_id` stops a new review even after an owner disposition. This fails closed. Note it for the operator guide.
3. **Equivalent mutation.** Removing `$current['eligible']` from `classification()` (RR-M2) survives. It is equivalent, because `evidence_sha256` is null whenever there are blockers, so `hash_equals` already fails. Nothing to fix.
4. **Deferred items accepted.** The earlier deferred P3s (P3-2, P3-3, P3-8, P3-9) are recorded in the deferred file. I accept that.

## Verification of each condition

- **C1 (segment actor).** Closed for probe A. `blockers=["reviewer_imported_evidence"]` for operator B, who finished A's batch. The rows now carry `actor_id` and `attestation_audit_id`. RR-M1, which reverts to a batch-creator-only rule, was killed. The residual gap is R-1.
- **C3 (staleness).** Closed for probe B. When the later export drops order 1002 and changes buyer 002's address, the result is `blockers=["evidence_removed"]` and `archive_only`. The author's test also covers a partial newest export (`latest_export_not_archived`) and `reviewed_evidence_changed`. The delta now blocks a changed normalized address; RR-M6, which made it automatic again, was killed.
- **C2 (no PII in output).** Closed. Probe C now prints only `"column":"file_sha256:43efe2…"` and `source_id_sha256`. Delta dispositions keep only `reason_sha256`. The earlier surviving mutation (an e-mail in the `reconcile` output, RR-MR5b) is now killed by `test_c2`, which covers every subcommand and both authority commands. RR-M5, which printed the file name, was killed.
- **C4 (MFA and attestation).** Closed. `import`, `review` and `transition` take a `StaffWriteAuthority`. Inside the transaction they:
  - check the Gate under lock and re-lock the actor row;
  - call `StaffWriteAuthority::require` (MFA enrollment wherever the panel requires it, i.e. `TestEnvironment::STAFF_MFA` = production and staging);
  - call `requireFor` (binds operator, action, scope and reference; record must exist; ≤ 900 s; single use, via a consumption audit row in the same transaction; refused unless `admitsTestCommerce()` = local, testing or staging).

  So:
  - **local/testing:** attestation is required and MFA is optional.
  - **staging:** requires both MFA enrollment and the attestation. The author's test covers this: an unenrolled actor is refused, an enrolled one is admitted.
  - **production:** every write is refused even with MFA and `production` named in config. Probe E, using a non-synthetic package with an enrolled admin, got `staff_authority_refused` for both `import` and `declare`, with zero rows written. This matches the description.

  RR-M3 (no `requireFor`) and RR-M4 (no `require`) were both killed. The services also call `LegacyArchivePolicy::admit()`.

  Lock order is safe:
  - Transitions take user → ledger head → user. A same-actor race serializes on the user row; different actors meet at the head.
  - Import takes user → batch.
- **C5 (launch gate).** Closed. There is an explicit launch-gate section in `docs/live-payment-and-production-preparation-queue.md` naming `ActivateExclusiveOffer` (`app/Domain/Catalog/ActivateExclusiveOffer.php` exists) and every exclusive checkout path, with a native race requirement. The T39 and T40 rows are updated, and so are the FP-101 and FP-099 notes (unlinked track ids). Nothing claims the wiring exists.
- **Writes outside the archive.** None. I re-grepped every write. Inserts go only to the `legacy_*` tables, `AuditEvent::create` (now also the attestation consumption rows, written via `StaffWriteAuthority`) and the private disk. The `users` row is locked but never modified.
- **Migration delta.**
  - `legacy_history_batches` gains `members` (longText: record keys and hashes only, no PII) and `members_sha256`.
  - The four record tables gain `actor_id` (an FK to `users`, restrict on delete) and a nullable `attestation_audit_id`.
  - The migration is still additive and forward-only, and it alters no existing table. The UPDATE, DELETE and REPLACE triggers and the value guards are unchanged and still cover each whole table. The prefix is still `264000`.
  - Lane A5 has renamed its migration to `2026_10_24_264100_refund_dispute_lifecycle` (seen in `/home/user/wt/a5` at `0e9520aa`), so the duplicate-prefix note from the earlier record is resolved.
- **Census and selection.** The SQLite census is 293 and byte-identical to `d456ee3b`. `LegacyHistoryReviewConditionsTest` was added to `include_files`. The selection proves **2524 selected tests in 244 files (of 9663 in 708)**, matching both workflow comments. I deleted the generated files.

## Commands and results

SQLite runs used the direct PHPUnit command from the coordinator: `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `PAO_DISABLE=1`, `--fail-on-phpunit-warning`. The wrapper is `scratchpad/mig-review/run.sh`.

- **SQLite, the 8 MIG files on HEAD:**

  | File | Result |
  | --- | --- |
  | `HistoryNormalizerTest` | 32 tests, 276 assertions |
  | `HistoryDeltaTest` | 6 tests, 57 assertions |
  | `LegacyHistoryArchiveTest` | 8 tests, 69 assertions |
  | `LegacyHistoryCommandTest` | 5 tests, 66 assertions |
  | `ExclusivityAuthorityTest` | 6 tests, 96 assertions |
  | `LegacyHistoryArchiveMigrationTest` | 4 tests, 60 assertions |
  | `ExclusivityAuthorityConcurrencyTest` | 2 skipped |
  | `LegacyHistoryReviewConditionsTest` | 5 tests, 170 assertions |

  Total: 68 tests, 794 assertions, matching the packet.
- **Safeguards:** 52, 27, 14 and 53 OK.
- **Native MySQL 8.4.11:** a fresh private `/opt/mysql-8.4.11/bin/mysqld --no-defaults` with its own datadir in the scratchpad, port 3521 (free per `ss -ltnp`; other lanes were on 3391, 3407 and 3489 and I did not touch them), socket via a `/tmp/mrv3521` symlink, database `vaseyaudio_migrr`, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_migrr`.
  - `LegacyHistoryReviewConditionsTest` + `LegacyHistoryArchiveMigrationTest`: OK (9 tests, 230 assertions), 4m05s
  - `ExclusivityAuthorityConcurrencyTest` (two-process races): OK (2 tests, 168 assertions; the count varies with barrier polling), 7m34s
  - Shutdown: `mysqladmin shutdown` exit 0, then the datadir and the symlink were removed.

## Probes (`scratchpad/mig-review/ZzReReviewProbeTest.php`, SQLite)

| Probe | Result | Status |
| --- | --- | --- |
| A, segment actor reviews | blocked (`reviewer_imported_evidence`) | closed |
| B, removal plus address change | `evidence_removed`, `archive_only` | closed |
| C, file name in output | only a hash is printed | closed |
| D, newest-batch creator omits the competitor, then reviews | `recorded`, `verified_grant` | **R-1 open** |
| E, production named in config, MFA-enrolled admin | `import` and `declare` both refused with `staff_authority_refused`, 0 rows | as described |

## Mutations (one at a time, reverted with `git checkout`; `scratchpad/mig-review/mut2/results.jsonl`)

| Id | Mutation | Result |
| --- | --- | --- |
| RR-MR5b | E-mail in `reconcile` output (survivor last round) | killed (`test_c2`) |
| RR-M1 | Separation of duties by batch creators only | killed |
| RR-M2 | `classification()` ignores `eligible` | survived, **equivalent** (P3-3) |
| RR-M3 | Drop `requireFor` | killed |
| RR-M4 | Drop `require` (MFA) | killed (staging test) |
| RR-M5 | Print the unreferenced file name | killed |
| RR-M6 | Address change automatic in delta | killed |

## Untested

- No real export (S-9).
- Not run: the full native and SQLite suites, Foundation CI, browser specs.
- I did not repeat the 51-file migration-pattern SQLite run.
- Native runs covered the review-conditions, schema and race files only. The author reports the archive, authority and command files natively at 28 tests / 461 assertions; I did not repeat that.
- Concurrent grant reviews were not raced natively (they map to `review_changed_concurrently` by code reading only).
