# Lane MIG — BeatStars history archive, delta capture and exclusivity authority (2026-10-24)

Branch `harness/migration-tooling-20261024` from main `786fb7c7` (PR #167 merge). Development lane under
Sean's 2026-10-06 CI cost policy: focused local checks only, not Foundation CI and not acceptance. Every
input is a clearly labelled synthetic fixture; no real BeatStars export (input S-9) exists or was read.

## Work package

Parity rows (`docs/remaining-parity-coverage.csv`) and tasks (`docs/remaining-development-tasks.csv`):

| Row | Task | Scope in this lane |
| --- | --- | --- |
| FP-099 Historic orders and contract preservation | T25/T35 | Archive historical orders, lines, customers and original contract files as immutable private records. Every line is `archive_only`; a separately reviewed `verified_grant` classification exists only where the archived source proves it (paid, not refunded, exact buyer, verified original contract). No contract text is generated, rendered or substituted. No entitlement, grant, download or customer account is created. |
| FP-098 Dry-run idempotent import and reconciliation | T34 | Extend the synthetic tooling to the order/contract/customer cohorts: mapping-driven normalizer, read-only dry run (default), restartable idempotent archive import, reconciliation summary (counts, hashes, money in minor units per currency; any unexplained delta fails). |
| FP-101 Delta capture and exclusivity authority | T39 | Delta between a baseline and a later export (new/changed/removed customers, orders, lines, contracts, refunds and exclusive sales) with operator dispositions; an append-only exclusivity-authority ledger (`beatstars` → `frozen` → `vasey_store`) that admits exactly one selling authority and records the handover only after the post-freeze delta is archived and reconciled. |

## Files owned

New only, except the shared records listed last:

- `database/migrations/2026_10_24_264000_legacy_history_archive.php` — **additive, forward-only schema migration** (SQLite and MySQL parity, immutable guards). Needs independent review.
- `config/legacy-archive.php` (no `env()` key; non-local admission is a reviewed code change).
- `app/Domain/Migration/History/*` (mapping, source files, normalizer, reconciliation, delta, policy, archive importer, grant review, exclusivity authority).
- `app/Console/Commands/LegacyHistory.php`, `app/Console/Commands/ExclusivityAuthorityCommand.php`.
- `tests/Fixtures/migration/history-synthetic/**`, `tests/Unit/Migration/History*Test.php`, `tests/Feature/Migration/Legacy*Test.php`, `tests/Feature/Migration/ExclusivityAuthority*Test.php`, `tests/Support/exclusivity-authority-race-worker.php`.
- `docs/migration/legacy-history-archive.md`, this packet.
- Shared records touched only as each lane must: `CHANGELOG.md`, `docs/migration/README.md`, `docs/remaining-parity-coverage.csv` (three rows), `scripts/ci/database-sqlite-skips.json` (append only), `scripts/ci/database-mysql-selection.json` (pattern file list), count comments in `.github/workflows/final-verification.yml` and `.gitlab-ci.yml`.

Not touched: `routes/*`, `bootstrap/app.php`, membership/identity/C4e/G1/T36 files, `docs/legal/*`, the decision register, checkout, inventory, grant and entitlement code.

## Assumptions

1. The real BeatStars order/customer/contract export shape is unknown (S-9). The normalizer is mapping-driven like the catalog normalizer: the operator declares every column, status and license name; an undeclared column is a finding. A real export may need a new mapping or adapter.
2. Contract originals arrive as files alongside a contracts sheet. Missing originals are recorded exceptions (`contract_original_missing`), never regenerated.
3. Historical orders are USD only (as the catalog normalizer); other currencies are refused rather than given an invented exponent.
4. `verified_grant` is an archive classification, not a download right. Activating historical entitlements needs Sean's authorization (release boundary) and is out of scope.
5. Wiring the store's exclusive checkout to consult the authority ledger touches checkout/inventory files owned by later lanes (PCC/activation); this lane provides the ledger, the guard query and the handover evidence, not that wiring.

## Acceptance criteria

- A1 Dry run is the default for every action, writes nothing (zero SQL writes, no files outside an explicit private output) and refuses non-local environments unless `config('legacy-archive.non_local_environments')` names the running environment (empty by default); production also refuses synthetic sources.
- A2 Write mode needs `--write`, the dry run's snapshot SHA-256, a staff actor whose password is confirmed at a hidden prompt in an interactive console, and `--attest` (recorded console attestation).
- A3 Source IDs, row hashes, record hashes, file hashes and the mapping hash are preserved in the snapshot and the archive.
- A4 Findings (duplicate IDs, unknown status/license, bad money, unknown references, unmapped columns, tampered contract hash, unreferenced files, path-unsafe names) block the import; nothing is defaulted.
- A5 Re-running an import is idempotent; an interrupted segment rolls back and the next run resumes without duplicates.
- A6 Marketing consent is always `unknown` in the snapshot and the archive (schema-guarded); consent columns can only be ignored.
- A7 The importer and the grant review write only `legacy_*` tables and the audit log; no grant, entitlement, order, customer or download table changes.
- A8 `verified_grant` requires a different staff reviewer than the importer, paid unrefunded status, an unambiguous buyer and a re-verified private original contract.
- A9 Reconciliation reports per-cohort expected/actual/missing/unexpected/changed, artifact re-hash and money totals; any unexplained delta fails.
- A10 Delta capture classifies new/changed/removed records; removals, money/track/license changes and changed contract bytes need an explicit disposition; any unexplained item fails.
- A11 The authority ledger refuses a second authority (declare twice, handover before freeze, handover twice), requires the post-freeze delta to be archived and reconciled, and serializes concurrent transitions (native MySQL race).
- A12 Archive rows and authority events are immutable in SQL on both engines.

## Tests planned

Unit (no database): normalizer findings and exceptions, consent, hashes, determinism; delta categories and dispositions; reconciliation arithmetic. Feature (SQLite, and the schema file natively on MySQL): dry run writes nothing, environment refusal, write-mode gating, idempotent rerun, restart after an injected interruption, tampered source and tampered stored artifact, duplicate IDs, missing contract, archive-only never grants, grant review separation, delta with removed/changed records, authority transitions. Native MySQL only: two independent processes racing an authority transition.

## Results

**Tested source:** `fed4571e5df8813a329a5fdb37e09c2ab5646b08` (application, migration, test and CI-comment
bytes). Later commits on this branch change documentation and evidence only. The branch merged origin/main
`e5d0d3f2` (PR #168 G1, PR #169 legal drafts) at `ba68a187` before the verification runs. PHP 8.4.26,
PHPUnit 12.5.34, SQLite in memory; native runs on a private MySQL 8.4.11 (`/opt/mysql-8.4.11/bin/mysqld`, own
datadir in the session scratchpad, port 3463, database `vaseyaudio_mig`, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_mig`),
shut down cleanly afterwards (`mysqladmin shutdown` exit 0); no other lane's server was touched.

### What was built

- `app/Domain/Migration/History/`: `HistoryMapping` (mapping admission), `HistorySourceFiles` (private package
  admission through `PrivateSourceFiles`; synthetic fixtures only from `tests/Fixtures/`), `HistoryNormalizer`
  (pure, deterministic `vasey-legacy-history-v1` snapshot with findings, exceptions and review eligibility),
  `HistoryReconciliation` and `HistoryDelta` (pure), `LegacyArchivePolicy`, `LegacyHistoryArchive` (plan, segmented
  import, observation), `LegacyGrantReview` (`verified_grant` path), `ExclusivityAuthority` (ledger),
  `LegacyArchiveRefusal`.
- `app/Console/Commands/LegacyHistory.php` (`vasey:legacy-history dry-run|import|reconcile|delta|review-grant`) and
  `app/Console/Commands/ExclusivityAuthorityCommand.php` (`vasey:exclusivity-authority status|declare|freeze|handover|unfreeze|revert`).
- **Schema (needs independent review):** `database/migrations/2026_10_24_264000_legacy_history_archive.php`, additive and
  forward-only (`down()` throws before any query; `up()` refuses a retained table): `legacy_history_batches`,
  `legacy_history_customers`, `legacy_history_orders`, `legacy_history_lines`, `legacy_history_contracts`,
  `legacy_history_grant_reviews`, `legacy_exclusivity_authority_events`. Every table has UPDATE/DELETE abort triggers
  (and SQLite REPLACE guards); byte-exact insert guards admit only `marketing_consent = unknown`,
  `classification = archive_only` (lines), `classification = verified_grant` (reviews) and the authority/transition
  vocabulary. Foreign keys only to `users` and within the archive. No existing table changes.
- `config/legacy-archive.php` (`non_local_environments => []`, no environment variable).
- Fixtures `tests/Fixtures/migration/history-synthetic/{baseline,later}/` (labelled synthetic; ids `synthetic-*`,
  addresses `@example.invalid`, contract files stating they are not agreements).
- Tests: `tests/Unit/Migration/HistoryNormalizerTest.php`, `HistoryDeltaTest.php`;
  `tests/Feature/Migration/LegacyHistoryArchiveTest.php`, `LegacyHistoryCommandTest.php`, `ExclusivityAuthorityTest.php`,
  `LegacyHistoryArchiveMigrationTest.php`, `ExclusivityAuthorityConcurrencyTest.php` (native only); support
  `tests/Support/LegacyHistoryFixtures.php`, `ExclusivityAuthorityRace.php`, `exclusivity-authority-race-worker.php`.
- Docs: `docs/migration/legacy-history-archive.md`, `docs/migration/README.md` row, `CHANGELOG.md`, parity rows
  FP-098/FP-099/FP-101 set to `implemented_partial`.

### Acceptance criteria

| Criterion | Result | Evidence |
| --- | --- | --- |
| A1 dry run default, no writes, environment refusal | met for tested paths | `LegacyHistoryCommandTest`: dry run issues only `SELECT` statements; `staging`, `production`, `local-eu` refused with zero statements; admitted only when `legacy-archive.non_local_environments` names the environment; ambiguous configured/running environment refused; synthetic import refused in production. Mutations M5, M6 killed. |
| A2 write gating | met | `--write` plus `--snapshot` (dry-run hash), interactive console, hidden password, staff gate and `--attest` (attestation audit row before the write); each missing piece refused with nothing written. Mutation M7 killed. |
| A3 identities and hashes preserved | met | Normalizer test: source ids, row hashes, record hashes, sheet/mapping hashes, original SHA-256 and size; stable record keys across snapshots. |
| A4 findings block, nothing defaulted | met | 24-case finding provider plus tampered-hash, unreferenced-file and mapping refusals; command refuses import with findings. Mutation M2a killed. |
| A5 idempotent and restartable | met | Segments `5/12, 5/7, 5/2, 2/0` then a rerun inserting 0; injected failure at the 7th record rolls the segment back and the next run inserts exactly the remaining 11. Mutation M9 killed. |
| A6 consent stays unknown | met | Opt-in column ignored and its values absent from the snapshot; mapping refuses a consent field; SQL guard refuses `granted`, `UNKNOWN`, padded values. Mutations M3a, M3b killed. |
| A7 no write outside the archive | met for tested paths | Every non-`legacy_*`, non-audit table is byte-compared before and after import, rerun, grant review and handover. Mutations M1a, M1b killed. |
| A8 `verified_grant` separation and evidence | met | The importer is refused (`reviewer_imported_evidence`); a different reviewer records one immutable review, idempotent on repeat; refunded, unpaid, missing-contract, ambiguous-buyer, duplicate-exclusive and tampered-original lines are refused. Mutations M8, M11 killed. |
| A9 reconciliation fails on any unexplained difference | met | Missing, changed, unexpected, altered-ciphertext, tampered and missing originals, money-column drift. Mutation M2c killed. |
| A10 delta classification and dispositions | met | Refund, new exclusive sale, restated rows captured; removed records, money/license changes, exclusive changes and changed contract bytes blocking until dispositions bound to both hashes; stale or mismatched dispositions refused. Mutation M10 killed. |
| A11 single authority | met | Second declaration, handover before freeze, second handover, freeze from the store, unfreeze after a store period refused; handover needs a post-freeze export, the latest prior baseline, both archived and reconciled and a reconciled delta. Native race: two processes, observed InnoDB wait on the ledger, exactly one event. Mutations M4a to M4d killed. |
| A12 immutability on both engines | met | Schema test on SQLite and natively on MySQL: UPDATE, DELETE and REPLACE refused on all seven tables; unique identity indexes asserted. Mutation M4e killed after the index assertion was added. |

### Commands and exact results

Direct PHPUnit, SQLite (the lane's standard environment: `APP_ENV=testing … DB_CONNECTION=sqlite DB_DATABASE=:memory: …
PAO_DISABLE=1 php -d memory_limit=512M -r '…require "vendor/phpunit/phpunit/phpunit";' -- --colors=never
--fail-on-phpunit-warning <files>`):

| Run | Source | Files | Result |
| --- | --- | --- | --- |
| New tests | `fed4571e` | 7 new test files | 63 tests, 542 assertions, 2 skipped (the native-only race), exit 0 (`evidence/final-sqlite-new.txt`) |
| Existing migration/import/normalizer/dry-run and adjacent | `fed4571e` | `BeatStarsExportNormalizerTest`, `BeatStarsDryRunCommandTest`, `CatalogDryRunTest`, `CatalogDryRunCommandTest`, `NormalizedCatalogSourceTest`, `ProtectedCatalogReportTest`, `PersistentCatalogDraftImportTest`, `CustomerConsentAdmissionTest`, `ProductionEnvironmentTemplateTest`, `InstallationReportTest`, `ProductionBuyerAssentObservationsTest` | 360 tests, 3,833 assertions, exit 0 (`evidence/final-sqlite-existing.txt`) |
| Every migration-pattern test file (51 files, the new schema test included) | `de11fdb4` | `pattern_files` of `scripts/ci/database-mysql-selection.json` | 600 tests, 6,933 assertions, 20 skipped (reviewed SQLite census), exit 0, 46 m 54 s (`evidence/de11fdb4-sqlite-migration-pattern-files.txt`) |
| Original failure, retained | `de11fdb4` | the existing set | 1 failure: `ProductionEnvironmentTemplateTest` read `env()` in a comment of `config/legacy-archive.php`; fixed in `ebc9025b` (comment wording only) and passing at `fed4571e` (`evidence/de11fdb4-sqlite-existing-FAILED-env-comment.txt`) |

Native MySQL 8.4.11 (same command with `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3463 DB_DATABASE=vaseyaudio_mig
VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_mig`):

| Run | Source | Result |
| --- | --- | --- |
| `LegacyHistoryArchiveMigrationTest`, `LegacyHistoryArchiveTest`, `ExclusivityAuthorityTest`, `LegacyHistoryCommandTest` | `fed4571e` | 23 tests, 291 assertions, exit 0 (`evidence/final-mysql.txt`); also 22/284 at `de11fdb4` |
| `ExclusivityAuthorityConcurrencyTest` (2 native-only races) | `fed4571e` | 2 tests, 358 assertions (barrier polling varies), exit 0, 11 m 43 s (`evidence/final-mysql-race.txt`); also passed at `de11fdb4` and before commit |

Other checks at `fed4571e`: `python3 scripts/ci/test-phpunit-shards.py` 52 OK, `test-ci-scope.py` 27 OK,
`test-workflow-cadence.py` 14 OK, `test-focused-tests.py` 53 OK; `composer validate --strict` valid; Pint `--test`
passed on every new PHP file.

### Census and native selection

- `scripts/ci/database-sqlite-skips.json`: two methods appended (291 → 293), first 150 entries unchanged:
  `ExclusivityAuthorityConcurrencyTest::test_native_concurrent_freezes_from_one_head_record_exactly_one_event` and
  `::test_native_concurrent_declarations_on_an_empty_ledger_record_exactly_one_authority`.
- `scripts/ci/database-mysql-selection.json`: `LegacyHistoryArchiveMigrationTest` added to `pattern_files` (it matches the
  migration pattern); `ExclusivityAuthorityTest` and `LegacyHistoryArchiveTest` added to the reviewed `include_files`;
  the race worker added to `branching_helper_files`. MySQL skip census unchanged.
- `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`:
  **2519 selected MySQL-native tests in 243 files (of 9617 discovered tests in 703 files)**, up from main's 2499 in 239
  (of 9554 in 696). Count comments updated in `.github/workflows/final-verification.yml` and `.gitlab-ci.yml`; generated
  `phpunit-ci-mysql-*` files deleted. Timings for the new files use the fallback weight.

### Mutations

`evidence/mutations-5562a372.jsonl`: one mutation at a time on the source, focused tests on SQLite, restored with
`git checkout`. 19 run, 18 killed on the first pass; the survivor M4e (the ledger's unique `sequence` replaced by a plain
index) survived on SQLite because the REPLACE guard trigger also refuses a duplicate sequence. `fed4571e` adds a unique-index
assertion; M4e is then killed (4 tests, 1 failure). Final: 19/19 killed.

| Guard | Mutations (all killed) |
| --- | --- |
| No write outside the archive | M1a importer writes `cache`; M1b grant review writes `cache` |
| Hash checks | M2a declared-hash mismatch ignored; M2b importer stores changed bytes; M2c reconciliation stops re-hashing originals |
| Consent unknown | M3a normalizer emits `granted`; M3b consent insert guard removed |
| Single exclusivity authority | M4a handover from the store; M4b handover without freeze; M4c pre-freeze export accepted; M4d unreconciled delta accepted; M4e unique sequence removed |
| Dry run writes nothing | M5 plan issues a DELETE |
| Environment refusal | M6 admission always passes |
| Attestation | M7 write without `--attest` |
| Separation of duties | M8 importer reviews its own evidence |
| Idempotency | M9 already-archived records re-inserted |
| Delta | M10 removed record not blocking |
| Archive-only | M11 lines classified `verified_grant` at import |

The restated-row delta rule (`fed4571e`'s new unit test) fails against the pre-fix rule (1 failure), confirming it
pins the bug found by the command test.

### Untested conditions and limits

- **No real BeatStars export has been run (S-9).** Every result is on hand-made synthetic packages. The real order,
  customer and contract export format, column names, status and license vocabularies, row volume (more than 1,000 rows
  per sheet must be split), date formats (only `YYYY-MM-DDTHH:MM:SSZ` is accepted) and whether contract originals are
  exportable at all are unknown; the real run may need a new mapping or an adapter.
- The store's exclusive activation and checkout do **not** consult `ExclusivityAuthority::storeMaySellExclusives()`.
  Until that wiring lands (production-checkout/activation lane files), the ledger records and rehearses the cutover
  authority but does not itself stop a concurrent store sale.
- No provider (Stripe/PayPal) refund or payment records are reconciled; order status and refunds come only from the
  export. Download events (M-036), consent/suppression evidence from an ESP (M-037), memberships, services, merch and
  sound kits (product-family adapters) are not covered. Legacy `track_source_id` values are not linked to store tracks.
- No customer is linked to a store account; the e-mail HMAC only enables a later exact-match review.
  `verified_grant` creates no entitlement; activating historical entitlements needs Sean's authorization.
- The dry run reports to standard output only (counts, codes, keys and hashes); it writes no private report file.
- Native MySQL covers the four selected files and the races; the full native suite, browser, host, Foundation CI and
  GitLab were not run. The 51-file migration-pattern SQLite run was at `de11fdb4` (code identical to `fed4571e` except
  the config comment and the two test additions, which were re-run at `fed4571e`).
- Independent review of the schema migration and the sensitive services has not happened.

### Post-merge check (origin/main `707649a6`, PR #170 T36)

Main moved after the tested source. Merge `b9ae0606` resolves the `CHANGELOG.md` and workflow count-comment conflicts
(T36 added tests, no migration and no census change). On `b9ae0606`: native selection proof 2519 selected in 243 files
of 9658 discovered in 707 (comment updated); the four safeguards 52/27/14/53 OK; a focused SQLite run of the seven new
test files plus `ProductionEnvironmentTemplateTest`, `InstallationReportTest` and `BeatStarsExportNormalizerTest`:
194 tests, 3,083 assertions, 2 skipped, exit 0 (`evidence/postmerge-b9ae0606-sqlite.txt`). The native MySQL and
51-file runs were not repeated on the merge; T36's application files do not intersect this lane's.

### Independent review round (`d456ee3b`, APPROVE WITH CONDITIONS, no P0/P1)

The record is outside the repository (session scratchpad `mig-review-d456ee3b.md`). Fixed in `86a51c6e` and `8b66d788`,
each with a regression test in `tests/Feature/Migration/LegacyHistoryReviewConditionsTest.php` (and one assertion in
`HistoryNormalizerTest`):

| Condition | Fix | Regression proof |
| --- | --- | --- |
| C1 / P2-1 separation of duties | Each customer, order, line and contract row records its inserting `actor_id` (and attestation id). The reviewer must differ from every inserting actor and every batch creator of the rows the review relies on. | `test_c1_…`: A creates the batch, B imports 16 rows; neither A nor B can review, C can. Mutation R1 (batch creators only, the old rule) killed. |
| C3 / P2-2 stale `verified_grant` | Batches record their snapshot members (`members`, `members_sha256`). Eligibility and `classification()` re-derive against the newest fully archived export of the source: `latest_export_not_archived`, `evidence_removed`, `reviewed_evidence_changed`. Delta capture now blocks a changed customer address (P3-6). | `test_c3_…`: partial later export, removed exclusive, changed buyer address. Mutations R2 (evaluate against the line's own batch, the old behaviour), R3a, R3b, R8 killed. |
| C2 / P2-3 personal data in output | Findings carry `source_id_sha256` instead of the raw id; an unreferenced file appears only as `file_sha256:<hash>`; delta dispositions keep `reason_sha256`. | `test_c2_…` asserts no address, name, payment reference, file name or path on every action: dry-run, import (dry and write), reconcile, delta (with and without dispositions), review-grant (dry and write), authority status, declare (dry and write) and handover (dry). Mutations R4, R5, R5b killed (R5b survived until the normalizer test assertion was added). |
| C4 / P2-4 attestation and MFA | `import`, `review` and `transition` take a `StaffWriteAuthority` and, inside the write transaction, lock the actor, apply `StaffWriteAuthority::require` (panel staff MFA rule) and `requireFor` (bound action/scope/reference, recorded, fresh within 900 s, spent once). The services also apply `LegacyArchivePolicy::admit()` (P3-7). | `test_c4_…`: wrong snapshot, other actor, wrong action, stale and reused attestations refused; missing attestation refused; in admitted `staging` an unenrolled admin is refused and an enrolled one admitted. Mutations R6, R7 killed. |
| C5 / P3-4 launch gate | Explicit gate in `docs/live-payment-and-production-preparation-queue.md`, T39/T40 `done_when`, FP-101 note; FP-099 note on unlinked track ids (P3-5); handover guide note on operator-declared watermarks (P3-1). | Documentation. |

Also: a review that loses a race on the unique line id is refused as `review_changed_concurrently`. The unmerged
migration gains columns only (still additive, forward-only, prefix `264000` kept; lane A5 renames its file to
`264100`). Run against the `d456ee3b` sources, the new regression file fails 5 of 5 (4 errors from the changed service
signatures, 1 assertion failure for C2); the mutations above show each fix's semantic failure on the new code.
Deferred P3s (P3-2, P3-3, P3-8, P3-9) are in `docs/handoff/2026-10-07/codex-deferred-20261011.md`.

**Tested source for this round: `41681c840d3d72466e19126a52ce9e2015204b55`** (application code identical to `8b66d788`;
`41681c84` fixes two native-only test defects found on MySQL at `8b66d788`: a missing import in the native race test,
which SQLite skips, and a DISTINCT with ORDER BY that MySQL refuses with 3065; both original failure logs are kept).

| Run | Source | Result |
| --- | --- | --- |
| SQLite, the 8 MIG test files | `41681c84` | 68 tests, 794 assertions, 2 skipped (native races), exit 0 (`evidence/review-41681c84-sqlite-new.txt`) |
| SQLite, existing migration/import and adjacent files (the earlier 11 plus `MigrationRecompilationTest`, `CustomerConsentMigrationTest`) | `8b66d788` (same application code) | 381 tests, 4,037 assertions, exit 0 (`evidence/review-8b66d788-sqlite-existing.txt`) |
| Native MySQL 8.4.11, schema, archive, authority, command and review-conditions files | `41681c84` | 28 tests, 461 assertions, exit 0 (`evidence/review-41681c84-mysql.txt`) |
| Native MySQL, two-process races | `41681c84` | 2 tests, 262 assertions, exit 0 (`evidence/review-41681c84-mysql-race.txt`) |
| Original native failures, retained | `8b66d788` | 28 tests, 1 error (C1 test DISTINCT/ORDER BY); races 1 error (missing import), the other race passed |
| Safeguards and `composer validate --strict` | `8b66d788` | 52 / 27 / 14 / 53 OK; valid |
| Review-fix mutations | `86a51c6e` + `8b66d788` | 10/10 killed (R5b after the added assertion) (`evidence/review-mutations-86a51c6e.jsonl`) |

Fresh private `mysqld` (`/opt/mysql-8.4.11`, new datadir in the scratchpad, port 3463 after `ss -ltnp`; other lanes
were on 3391 and 3407 and were not touched), database `vaseyaudio_mig`; shut down with exit 0 and its datadir removed.
Census: SQLite skip census unchanged (293); `LegacyHistoryReviewConditionsTest` added to the reviewed `include_files`;
native selection **2524 selected tests in 244 files of 9663 discovered in 708**; workflow and GitLab comments updated.
The 51-file migration-pattern SQLite run was not repeated (last at `de11fdb4`); the amended migration is exercised by
every test above on both engines.

### Next dependencies

1. Independent review of the migration and `app/Domain/Migration/History/*` at the exact SHA.
2. Sean supplies S-9 (order/customer/contract exports and originals); write the real mapping, run `dry-run` locally,
   then `reconcile`, with private counts/hashes recorded outside Git.
3. Wire `storeMaySellExclusives()` into exclusive activation and checkout in the owning lane, with a native race against
   a handover.
4. T39 cutover rehearsal using `freeze` → post-freeze export → `import` → `delta` → `handover` → `revert`.
