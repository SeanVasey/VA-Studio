# Tax255 hardening of independent-review findings R-1 to R-6

Branch `harness/tax-255-hardening`, from `0e313aa6` (head of PR #52). Development evidence only. This changes nothing about activation: the family is still default-off, unregistered, unmounted and unbound to a provider, and every blocker in `../README.md` and `../independent-review/DECISION.md` stands (V2 `CheckoutCommitAdmission`, reviewed A4 own-account transport, unknown-outcome retention, paid-family wiring, MySQL race proof, Foundation CI on the exact integrated SHA, Sean's merchant and tax facts). Nothing was committed or pushed. No frozen V1 file was edited (`ProductionTaxCheckoutFrozenV1Test` is green).

Every red/green file below holds the PHPUnit output and an `rc=` line after each run. Red runs use the unfixed code (a snapshot of the tree, or a one-expression mutation); green runs use the hardened tree. SQLite runs are in this worktree. Native runs use a private MySQL 8.4.11 on 127.0.0.1:3721 (`native-runner.sh`, `native-instance-lifecycle.txt`); no other daemon and not port 3306 was touched.

| Finding | Red | Green |
| --- | --- | --- |
| R-1 | `R-1/red.txt`: 2 of 5 fail (SQLite) | `R-1/green.txt`: SourceV2 5/5 (52 assertions), adapter 20/20 |
| R-2 | `R-2/red.txt`: 4 mutations, each fails its own case (1 of 4) | `R-2/green.txt`: 4/4 (20 assertions) |
| R-3 | `R-3/red-native.txt`: 1 of 1 fails, 9 identifiers stored (native) | `R-3/green-native.txt` 1/1 (5 assertions); `R-3/green-sqlite.txt` Guard 7/7, Migration 10/10; whole classes native: see below |
| R-4 | `R-4/red-M*.txt`: 3 installer mutations, each fails its own native case | `R-4/green-native.txt`: whole class, see below; `R-4/green-sqlite-census.txt` |
| R-5 | `R-5/red.txt`: 2 of 2 fail | `R-5/green.txt`: 2/2, and the whole Journey class 21/21 |
| R-6 | `R-6/red-native.txt`: 2 of 2 fail (native) | `R-6/green-native.txt`: 2/2 |

## R-1 (Medium): paid-line adapter authenticated nothing

Change (`ProductionTaxPaidLineAdapterV2.php`). `accept` is now `accept(ProductionTaxPaidOrderSourceV2 $source, int $position, array $line, string $provenance)`. In order it:

1. refuses any provenance other than `synthetic_rehearsal` with `live_unsupported` (409), so `verified_production` / `live` / `own_account_sdk` lines are refused outright until a reviewed live producer exists;
2. refuses a non-V2 or V1-shaped line with `source_version`;
3. requires the line to be byte-identical (`CanonicalJson::encode`) to `$source->line($position)`, else `source_binding` (409); a position the held source does not hold refuses with the source's own `changed`;
4. then runs the previous consistency checks (shape, hashes, arithmetic, execution context, ceiling) as defence in depth.

Why the held source rather than an HMAC. The held source is the only authenticated producer: it reads rows sealed with the APP_KEY, under the held transaction, with the T23 identity prelock and `proveRetainedCurrent`. Comparing to `$source->line($p)` therefore proves the order, the line, the reviewed session and the tax all exist and agree, which a keyed hash would not (an HMAC proves only that some holder of the key sealed the array, and a genuine old line would stay valid outside the frame). It adds no key material and no line-format change, and it matches how V1's `PaidGrantPolicy::source` already consumes `lockedRead()->line()`. Cost: the adapter can no longer be called without a held frame, which is the intended seam. The caller reads the source and calls `accept` inside the same held transaction and keeps its own `proveRetainedCurrent`.

`assertSelfConsistent(array $line, string $provenance): void` is the old check set, public only so the DB-free unit tests keep driving every shape and arithmetic refusal. It returns nothing and its docblock says it is not authentication; `accept` is the only acceptance path and a test pins its signature.

Regression (`ProductionTaxSourceV2Test`): (a) a genuine line moved to a new order id with tax zeroed and `source_hash` resealed is refused `source_binding`, as is a single changed byte and position 2; (b) a resealed `verified_production`/`live`/`own_account_sdk` line with `cs_live_FORGED` is refused `live_unsupported`, and as a rehearsal it is refused `source_binding`; the genuine line is accepted and returned unchanged. Red: both forgeries were accepted by the old adapter. The old adapter test cases moved to `assertSelfConsistent` unchanged (`ProductionTaxPaidLineAdapterV2Test`, 19 to 20 with the signature test).

Stays open: no consumer exists. Wiring into Paid252 remains the paid lane's change, and a live producer (and with it `verified_production`) needs its own review.

## R-2 (Low): PaymentIntent and currency money cases

Added `RecordingTaxCheckoutTransport::$mutatePayment` (test seam) and `ProductionTaxCheckoutJourneyTest::test_a_paid_session_with_a_mismatched_money_fact_is_refused_and_nothing_is_retained` with four cases. Each alters one fact of an otherwise valid paid session, expects `provider_uncertain` / 503 and no reviewed row, and then shows the unaltered session is retained. Red was shown with the reviewer's own mutations (`../independent-review/review-evidence/mutations/R1..R4.diff`, reapplied by `R-2/mutate_money.py`), each failing exactly its case:

| Mutation | Case that failed |
| --- | --- |
| R1 drop `amount_received === total` | PaymentIntent amount_received is short of the session total |
| R2 drop PaymentIntent `amount === total` | PaymentIntent amount differs from the session total |
| R3 drop PaymentIntent currency | PaymentIntent currency is not usd |
| R4 drop session currency | session currency is not usd |

Other reviewer money cases (PI status, `on_behalf_of`, metadata, line currency, livemode) were not ported and no mutation was run for them.

## R-3 (Low): MySQL provider-id guards accepted a trailing line feed

Change (`TaxCheckoutSchema::providerId` and `::sessionId`, MySQL branch only). The `REGEXP '^...$'` form is replaced by the form SQLite already used: `CHAR_LENGTH` bounds, a `SUBSTR` prefix equality, and `SUBSTR(tail) NOT REGEXP '[^A-Za-z0-9]'`. There is no end anchor, so LF, CR, CR LF and any other tail byte are refused. The SQLite text is unchanged. Other `$`-anchored MySQL guards (uuid, hash, timestamp) are unchanged: they have an exact `CHAR_LENGTH` or a `DATE_FORMAT` round trip that already refuses a trailing terminator (`trailing newline timestamp` case exists).

Explicit note: the migration `2026_10_07_255000` exists on no production database, so changing its generated guard text in place is acceptable. Any database that already ran the earlier text (a dev or CI database) fails the installer's guard-text comparison and must be recreated, not adopted.

Regression: `ProductionTaxCheckoutGuardTest::test_provider_identifiers_with_a_trailing_line_terminator_are_refused`, 9 attempts (`acct_SYNTHETIC`, `cs_test_SYNTHETICTAX`, `pi_SYNTHETICTAX`, each plus LF, CR, CR LF), each rolled back so a failure lists every admitted case, plus the terminator-free rows accepted. SQLite already refused; the red is native: all 9 stored on the unfixed schema (`R-3/red-native.txt`). The NativeSchema dictionary test compares generated text to the dictionary and needed no edit.

## R-4 (Low): native installer refusal class

New `ProductionTaxCheckoutNativeInstallerTest`, ported from the reviewer's probe: 11 refusal cases (drifted guard body, interior gap, populated incomplete installation, temporary shadow, foreign view, foreign trigger, foreign routine, foreign key consumer, additional owned index, additional owned guard, column collation drift), each asserting the exact refusal reason, the dictionary unchanged before and after, and that `assertComplete` also refuses. Plus the two R-6 cases below, plus `test_native_frozen_v1_installer_reruns_with_tax255_present_and_tables_are_inert`. Both method names are in `scripts/ci/database-sqlite-skips.json`. On SQLite the class skips 14 of 14, and the skipped method names equal the census (checked from the JUnit of the whole directory run); `python3 -I scripts/ci/test-database-receipts.py` passes (34 tests).

Red for a test-gap finding is mutation sensitivity: three one-statement removals from the installer (`R-4/mutate_installer.py`), each failing exactly its own native case: remove `foreign key consumer`, remove `additional owned guard`, remove `foreign view`. The other eight cases were not mutation-tested.

## R-5 (Low): retrieved session id not compared

Change (`ProductionTaxCheckout.php`). `initiate()` and `reconcile()` now require `$safe['session_id']` to equal the created/bound id and refuse `session_mismatch` (409) before binding or returning a URL; the reason is passed through the generic `provider_uncertain` mapping alongside `tax_ceiling`. Regression in `ProductionTaxCheckoutJourneyTest`: a transport returning `cs_test_OTHERSESSION` is refused at initiate (request retained, no binding, and the same order then proceeds once the real session is returned) and at reconcile (no reviewed row). Red: initiate bound it and returned the other URL; reconcile refused only late, with the generic `changed` from `retain()`.

## R-6 (scope addition, from Codex on PR #52): installer ignored trigger DEFINER and SQL_MODE

Change (`TaxCheckoutSchemaInstaller`, MySQL branch). Mirrors `CheckoutSchemaInstaller` exactly: one `SELECT CURRENT_USER() AS definer, @@SESSION.sql_mode AS sql_mode` per trigger comparison, and `guard definition` unless `DEFINER` and `SQL_MODE` equal it. V1 is untouched.

Regression (native, in the R-4 class): a guard recreated from its generated statement as `DEFINER='nobody'@'localhost'`, and one recreated under a session `sql_mode` with `ALLOW_INVALID_DATES` appended (then restored), each refused `guard definition` before any DDL with the dictionary unchanged. Red: the unfixed installer reported "Damaged installation was adopted" for both.

## Directory and whole-class results

`sqlite-directory.txt`: `tests/Feature/ProductionTaxCheckout` on SQLite, 142 tests, 995 assertions, 17 skipped (the 3 NativeSchema and 14 NativeInstaller native-only cases), rc 0. Baseline at `0e313aa6` was 118 tests, 3 skipped. `vendor/bin/pint --test` over all 10 changed or new PHP files: `{"tool":"pint","result":"passed"}`.

Native MySQL 8.4.11 (private instance 127.0.0.1:3721, since shut down and its datadir deleted; `native-instance-lifecycle.txt`), hardened tree, each rc 0:

| Class | Tests | Assertions | Skipped | File |
| --- | --- | --- | --- | --- |
| NativeInstaller (new, whole class) | 14 | 46 | 0 | `R-4/green-native.txt` |
| Guard (including the new trailing-line-terminator test) | 7 | 123 | 0 | `R-3/green-native-guard-class.txt` |
| NativeSchema | 3 | 22 | 0 | `R-3/green-native-schema-class.txt` |
| Migration | 10 | 191 | 8 (SQLite-only cases, by design) | `R-3/green-native-migration-class.txt` |
| R-3 new test alone / R-6 two cases alone | 1 / 2 | 5 / 6 | 0 | `R-3/green-native.txt`, `R-6/green-native.txt` |

Not run natively: Journey, SourceV2, adapter and Policy (the files I changed there are driver-independent PHP; they ran on SQLite) and HttpBoundary. No MySQL concurrency proof was attempted.

Evidence note: the shared scratchpad `native.sh` I first used was overwritten by another lane at 06:42Z. Its runs after that failed instantly with "Test file ... not found" before connecting to any database, so no result was produced and nothing was touched; I reran them with a private copy (`native-runner.sh` here). The R-4/R-6/R-3 evidence files cited above all carry my runner's header.


## Judgement calls

- New refusal reasons: `live_unsupported`, `source_binding`, `session_mismatch`.
- `assertSelfConsistent` is public so the DB-free adapter tests keep their coverage; the alternative, a real source per case, would need a paid order per case.
- R-3 uses an unanchored negated-class form instead of `\z`, to avoid escape differences between PHP, the SQL literal and the dictionary text, and so MySQL and SQLite share one shape.
- `../README.md` still describes `accept($line, $provenance)` and says `source_hash` authenticity comes only from `lockedRead`; the integration owner should update those two sentences. I left that file alone as outside the hardening directory.
- I-2 to I-5 were not addressed.
