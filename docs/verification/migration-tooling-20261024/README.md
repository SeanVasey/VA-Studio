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

Recorded below after implementation.
