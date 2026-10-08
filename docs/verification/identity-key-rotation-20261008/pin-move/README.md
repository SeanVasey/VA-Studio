# Identity key rotation: review conditions applied

Applies the independent review's conditions (`../independent-review/DECISION.md`) on top of `f59f9b82`.

- **C1, Free256 frozen bytes.** The review's item-5 ruling found Free256's lock, `proveCurrent`, `durableBinding` and
  principal-match contract unchanged, and moved the pins in
  `tests/Feature/ProductionFreeGrants/ProductionFreeGrantFrozenBytesTest.php` to the bytes at `f59f9b82`
  (`sha256sum` re-checked before editing): `ProductionCustomerAccess.php` `f0fc0b70…20f7`,
  `ProductionCustomerPrincipal.php` `d49b587d…457b`. No other pinned file changed.
- **C2.** F1 and F3 are recorded as open production conditions in `../README.md`.
- The reviewer's adversarial tests are committed as written, except that Pint removed one unused import and added one
  blank line in `ReviewIdentityKeyRotationAdversarialTest.php`. The three native race methods in
  `ReviewIdentityKeyRotationNativeRaceTest` skip on SQLite and are added to `scripts/ci/database-sqlite-skips.json`.

| Run (SQLite unless noted) | Result | File |
| --- | --- | --- |
| `tests/Feature/ProductionFreeGrants` (frozen-bytes test included; was 1 failure at `f59f9b82`) | 161 tests, 1154 assertions, 2 skipped, rc 0 | `sqlite-ProductionFreeGrants.txt` |
| `ReviewIdentityKeyRotationAdversarialTest` (after Pint) | 8 tests, 47 assertions, rc 0 | `sqlite-ReviewIdentityKeyRotationAdversarialTest.txt` |
| `ReviewSuppressionKeyRotationTest` | 1 test, 15 assertions, rc 0 | `sqlite-ReviewSuppressionKeyRotationTest.txt` |
| `ReviewIdentityKeyRotationNativeRaceTest` | 3 skipped on SQLite (MySQL-only), rc 0; MySQL 3/3 is the reviewer's `mysql-0*` evidence | `sqlite-ReviewIdentityKeyRotationNativeRaceTest.txt` |
| Pint `--test` on the six touched test files | passed | `pint.txt` |
| `python3 -I scripts/ci/test-database-receipts.py` | 34 tests OK | `database-receipts-selftest.txt` |

The native race tests were not re-run here; the reviewer's private MySQL 8.4.11 runs are the native evidence.
`ProductionIdentityNativeRaceTest` is not in the SQLite skip census on main either; that belongs to the census
normalization step before Foundation, not this PR.
