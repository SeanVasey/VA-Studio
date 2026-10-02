# UUID column-conversion regressions

This test-only correction distinguishes the bytes supplied to a SQL statement from the bytes that a fixed-width MySQL column presents to a `BEFORE` trigger. Migration `2026_10_01_000030_byte_exact_uuid_guards.php` is unchanged.

The [MySQL diagnostic run](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36806946452) established that the existing 36-character columns can discard a trailing newline, CRLF or space before the trigger observes `NEW`. A raw predicate probe still rejects the unconverted overlength input. The stored column value is the canonical 36-byte prefix. Expecting every such write to throw therefore tests an incorrect assumption about the database conversion order. Warning capture through the test connection was not reliable enough to use as the acceptance condition.

`UuidByteGuardMigrationTest` now checks these two boundaries separately:

- The raw predicate matrix continues to check length-only and canonical UUID policies, NUL tails, embedded NUL, whitespace, multibyte text, nullable values and SQLite BLOB values.
- Labelled write cases reject NUL suffixes and terminators, ordinary overflow, multibyte text and short values. Canonical fields additionally reject embedded NUL, uppercase, non-hex characters and wrong separators, including malformed values that occupy exactly 36 bytes. SQLite BLOB writes remain covered. The receipt claim retains its existing length-only policy.
- Fifteen transactional probes cover newline, CRLF and space suffixes on request public/document identifiers, render claims, receipt claims and delivery-control identifiers. SQLite must reject each write. MySQL must accept the converted value and read back the exact expected text, its exact uppercase `HEX` representation and `OCTET_LENGTH = 36`.
- Every whitespace probe rolls back and compares all rows of its target table with the snapshot taken before the probes. The populated-upgrade test also compares retained delivery history after the delivery-control probes. Nullable claim states, populated upgrades, preflight refusal without rewriting, partial-install retries, trigger collisions and original lifecycle guards retain their previous coverage.

The database checks stored identifiers. They do not establish byte-for-byte acceptance of every submitted SQL parameter before MySQL performs column conversion. Application validation remains responsible for validating external input before persistence. This correction neither normalizes retained evidence nor changes a column, trigger, lifecycle policy or migration rollout boundary.

Fresh verification of the reconstructed source on PHP 8.4.26:

```sh
php -l tests/Feature/UuidByteGuardMigrationTest.php
php vendor/bin/phpunit tests/Feature/UuidByteGuardMigrationTest.php
git diff --check
```

Syntax and whitespace checks passed. SQLite passed **8 tests / 134 assertions** with no errors, failures or skips in **6.502 seconds**. This is fresh evidence for the reconstructed correction; the discarded local snapshot's source identity is not reused. Real MySQL execution of this exact correction remains required in the integrating CI run. The diagnostic run establishes the conversion behavior, not acceptance of the final candidate.

The [UUID integrity follow-up](uuid-integrity-followup.md) and [migration runbook boundary](media-integrity-followups.md#migration-000029-runbook-boundary) still apply. These tests do not establish production migration or launch readiness.
