# Two main guards reconciled with reviewed changes (2026-11-07)

These five cases were red on `main` after #196 and #197. All five failed identically on main's unchanged lock (see
`docs/verification/f2-composer-minors-20261107.md`). Only test files change.

| Test | Cause | Change |
| --- | --- | --- |
| `IdentityHistoricalConfigurationAdmissionTest` (4 cases) | DEF-ID (`2bed2004`, #197) deliberately runs the raw metadata admission before configuration admission. Reordering the code back breaks 3 DEF-ID cases in `ProductionCheckoutMetadataAdmissionTest`. | The test accepts either refusal (`unavailable` or `historical_plain_configuration_required`) and keeps the zero-parent-callback assertion. It adds a positive control: the same capture succeeds with plain configuration. |
| `ProductionTaxCheckoutFrozenV1Test` (1 case) | REP (`abc9ecba`, #196) changed `CheckoutSchema.php` and `CheckoutSchemaInstaller.php`, which are on the frozen V1 list. | `REVIEWED_SUCCESSORS` pins the exact current hashes of those two files, with their provenance. `FROZEN`, the composition record and its record-match test are unchanged. A successor must name a frozen file. |

## Verification (Claude harness, PHP 8.4.27, SQLite, main's lock)

- `ProductionTaxCheckoutFrozenV1Test`: OK, 2 tests, 28 assertions.
- `IdentityHistoricalConfigurationAdmissionTest`: OK, 4 tests, 28 assertions.
- Mutations:
  - one byte appended to `CheckoutSchema.php` (a successor file) fails;
  - one byte appended to `OrderEvidence.php` (still frozen) fails;
  - reordering the witness admission fails three DEF-ID cases, which shows the ordering is deliberate.
- Successor hashes: equal `sha256sum` of the current files. The parent `abc9ecba^` hashes equal `FROZEN` and the record.

## Independent review of `d530ceed`: APPROVE WITH CONDITIONS, no P0 or P1

- **P2-1, fixed in the next commit:** the provenance comment cited the eb5ee434 repair review. The abc9ecba review is the one
  that covered these bytes.
- **P3-1, fixed:** the comment now states that REP added a migration-only upgrade preflight, and that strict admission now also
  requires the two indexes.
- **P3-2, recorded as a coverage gap:** `configuration()`'s ArrayAccess refusal in `IdentityOriginalCommitWitness` can no
  longer be reached, because metadata admission refuses first in both the constructor and `context()`. It remains as defence
  in depth, but no test exercises it.

The reviewer confirmed the following:

- The metadata admission refuses all four parent cases before any `offsetGet`, through raw reflection.
- The positive control shares the fixture, transaction and deadline with the negative case.
- REP's diff to the two files is additive: two nonunique single-column KEYs.
