# Production identity dependency admission repair — October 7, 2026

The independently retained source `4b3d47860373aad181c382899b9ab9677023eca0` accepted an incompatible permanent `users` table before installing identity migration 247. The SQLite probe replaced the real parent with an `id TEXT` primary-key-only table; the native probe kept the canonical unsigned BIGINT primary key, InnoDB engine and collation but removed the credential columns. Both original probes failed their refusal assertion with zero harness errors. Their exact source, raw red results and physical catalog snapshots were frozen by the independent reviewer in docs-only `d7631a43ba608d0cce384b1af015b87f6ffffdde` and are copied without modification under `production-identity-dependency-floor-20261007/historical-4b/`.

The narrow repair is source `3c2158e726a3bd3fdd57597adcb5756c49dff2ce`, parent `0638b7026b018762430e39602b62125f600a9854`. It changes only `IdentityMigrationOwnership.php` and adds `ProductionIdentityDependencyAdmissionTest.php`. Migration 247 and all other runtime, routes, transport, session and UI source remain byte-identical to that parent. No parent table, prior migration, historical evidence or old account is rewritten or adopted.

## Permanent dependency contract

Before any owned DDL, the direct captured PDO inspection now requires:

- Supported actual PDO driver, enabled foreign-key enforcement and exact permanent parent object identities; temporary objects and dictionary aliases are refused.
- The real `users` ID, name, email, verified-address timestamp, password, remember-token, timestamps, raw staff flag and MFA columns with the retained types, nullability and defaults; the primary identity and unique email index must remain canonical.
- The real eight-column `customer_accounts` contract, its primary key, three complete unique indexes and the user/owner restricted foreign keys.
- The real retained `quote_owners.owner_key` type and primary key.
- Native InnoDB tables, canonical charset/collation, supported index properties and exact foreign-key actions, with no unrecognized constraint or trigger additions.
- Exact existing customer-account insert/update/delete and immutable quote-owner guard bytes. In an installation containing the approved discovery epoch class, its actual users epoch guard definitions and AFTER timing are also required. The author base predates that separate source dependency; the root-composed installation must be checked independently.

The existing migration already performs `inspect()` before its first DDL and again after its last DDL. The strengthened floor therefore applies at both ends, including when resuming an exact empty owned table/guard prefix. It also applies to runtime callers of this same inspection helper. An incompatible dependency is refused, never repaired as part of migration 247. Stop application writers and competing migrators for MySQL's implicit-commit DDL; this does not make an online migration claim.

## Focused checks and provenance

Nine new cases physically replace required parent metadata, withdraw or change real SQL guards, remove the unique email key, disable foreign keys, introduce a temporary parent shadow, and damage a dependency while an exact empty owned prefix remains. Each refusal compares the actual catalog before and after, then restores the original parent DDL and verifies valid migration installation can resume. The existing migration selection still checks both valid prefix phases, immutable retained history, refused rollback, shadow/foreign-view admission, raw replacement protection and the native dictionary alias case.

Final artifacts are in `production-identity-dependency-floor-20261007/`; `receipts.json` binds counters and digests to the frozen source. The SQLite and native canary copies are byte-identical to the independently frozen originals, respectively SHA-256 `161571fc869801381a5905b30a30901c0fd6d798e87e1d59f182bb5aeb63b4d6` and `4ad5a40000a13a65716bbfa26bb53e1062eaca280605e3e2c03386c188463b23`. Their final snapshots confirm refusal with no owned DDL and record the exact author source. Earlier probe reds remain attached to their original source; original test assertions were not changed.

| Exact repair source check | Recorded / executed | Assertions | Result |
| --- | --- | --- | --- |
| Native migration, dependency floor and operative identity journeys | 22 / 22 | 97 | No failures, errors or skips |
| SQLite migration, dependency floor and operative identity journeys | 22 / 21 | 115 | No failures/errors; native alias case skipped |
| Unchanged independent SQLite dependency probe | 1 / 1 | 4 | Refusal and unchanged actual catalog |
| Unchanged independent native dependency probe | 1 / 1 | 4 | Refusal and unchanged actual catalog |
| Unchanged temporary-user and unknown-role runtime probes | 2 / 2 | 9 | No failures, errors or skips |

The SQLite-only skip is the full method tuple `Tests\Feature\ProductionIdentity\ProductionIdentityMigrationTest::test_native_dictionary_unicode_guard_alias_on_foreign_table_is_refused`, with reason `MySQL dictionary collation alias requires native MySQL.` The same method executes successfully with two recorded assertions in the final native selection. PHP formatting also passes for the two owned repair files; unchanged original probes deliberately preserve the reviewer's formatting bytes.

The selected author command is `vendor/bin/phpunit` on `ProductionIdentityMigrationTest.php`, `ProductionIdentityDependencyAdmissionTest.php` and `ProductionIdentityJourneyTest.php`, with source-specific JUnit output. The last file exercises actual loopback SMTP enrollment, authentication, recovery, historical observation preservation and current authority revocation against the strengthened valid floor. Additional unchanged temporary-user and unknown-role runtime probes are retained separately. Native checks use only the isolated author schema/account on actual `8.0.46-0ubuntu0.24.04.4 (Ubuntu)`, not hosted MySQL 8.4. All private connection facts remain outside Git.

One initial native development invocation exposed an author test-fixture error: native SHOW CREATE includes a column collation between the password type and nullability, so the intended fixture replacement was a no-op and cleanup masked that assertion. The corrected test uses the actual type prefix and restores with DROP IF EXISTS. This eight-error development JUnit file is retained under `development/`; it is not a runtime finding or passing receipt. Only later frozen-source runs support final claims.

Prior `eaaa55be` runtime and `af4870b7` UI evidence remains historical evidence of those exact bytes. This helper change adds a runtime dependency check, so older passing results do not approve the new boundary. Independent root review and checks on the final composed source are still required. All mail and feature activation remains default-off. There were no external SMTP sends, live credentials, provider writes, dependencies, pushes, merges or hosted CI; the full 46-case identity suite was not repeated for this narrow repair.
