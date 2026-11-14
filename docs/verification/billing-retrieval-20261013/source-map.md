# C3 source and invocation map

Frozen root native reference: `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`. The two final root MySQL invocations pass **11 tests / 215 assertions**, with zero errors, failures or skips. Component runs retain their own source identities; they are not promoted into one final-source suite.

The archive holds **35 JUnit invocations**, **11 separately parsed non-JUnit commands**, and **42 source inventories**: 34 exactly match their stated Git source or explicit retained overlay, and eight historical inventories only partially match the later comparison reference. “Exact” is limited to captured files. The [run registry](evidence/runs.json) names exact command/source receipts; [JUnit summary](evidence/junit-summary.json) preserves every leaf, count, status and exit.

## Invocation results

Counts are tests / assertions. Historical comparison references are not claims that those earlier invocations executed the later source. Invocations overlap.

| Invocation | Driver | Tests / assertions | Errors | Failures | Skips | Exit | Source comparison |
| --- | --- | ---: | ---: | ---: | ---: | ---: | --- |
| [records/helpers-initial](evidence/records/helpers-initial.xml) | sqlite | 32 / 54 | 0 | 0 | 0 | 0 | a8facf90; 5/8 historical partial |
| [records/helpers-final](evidence/records/helpers-final.xml) | sqlite | 34 / 57 | 0 | 0 | 0 | 0 | a8facf90; 8/8 exact |
| [records/issuer-canary](evidence/records/issuer-canary.xml) | sqlite | 1 / 14 | 0 | 0 | 0 | 0 | 7ed9ef47; 1202/1204 historical partial |
| [records/issuer-full](evidence/records/issuer-full.xml) | sqlite | 43 / 124 | 0 | 0 | 0 | 0 | 7ed9ef47; 1202/1204 historical partial |
| [records/issuer-final](evidence/records/issuer-final.xml) | sqlite | 10 / 67 | 0 | 0 | 1 | 0 | 7ed9ef47; 1204/1204 exact |
| [records/legacy-transition](evidence/records/legacy-transition.xml) | sqlite | 2 / 88 | 0 | 0 | 0 | 0 | 5dc11691; 1203/1203 exact |
| [records/native-producer-canary](evidence/records/native-producer-canary.xml) | mysql | 1 / 14 | 0 | 0 | 0 | 0 | 5dc11691; 2105/2105 exact |
| [records/native-producer-remainder](evidence/records/native-producer-remainder.xml) | mysql | 11 / 150 | 0 | 0 | 0 | 0 | 5dc11691; 2105/2105 exact |
| [records/baseline](evidence/records/baseline/phpunit.xml) | sqlite | 4 / 53 | 0 | 4 | 0 | 1 | 45d12994; 10288/10288 exact |
| [records/award-upgrade-old](evidence/records/award-upgrade/old-phpunit.xml) | sqlite | 1 / 25 | 0 | 0 | 0 | 0 | 45d12994; 10288/10288 exact |
| [records/award-upgrade-new](evidence/records/award-upgrade/new-phpunit.xml) | sqlite | 1 / 103 | 0 | 0 | 0 | 0 | 5dc11691; 10631/10631 exact |
| [schema/sqlite-upgrade-initial](evidence/schema/sqlite-upgrade-initial.xml) | sqlite | 23 / 698 | 0 | 0 | 0 | 0 | 57c9bf9f; 1909/1912 historical partial |
| [schema/sqlite-identity-initial](evidence/schema/sqlite-identity-initial.xml) | sqlite | 7 / 54 | 0 | 0 | 1 | 0 | 57c9bf9f; 1910/1913 historical partial |
| [schema/mysql-canary](evidence/schema/mysql-canary.xml) | mysql | 1 / 1 | 1 | 0 | 0 | 2 | 57c9bf9f; 1910/1913 historical partial |
| [schema/mysql-canary-diagnostic](evidence/schema/mysql-canary-diagnostic.xml) | mysql | 1 / 1 | 1 | 0 | 0 | 2 | 57c9bf9f; 1911/1913 historical partial |
| [schema/mysql-canary-fixed](evidence/schema/mysql-canary-fixed.xml) | mysql | 1 / 63 | 0 | 0 | 0 | 0 | 57c9bf9f; 1912/1913 historical partial |
| [schema/sqlite-final](evidence/schema/sqlite-final.xml) | sqlite | 33 / 758 | 0 | 0 | 3 | 0 | 57c9bf9f; 1913/1913 exact |
| [schema/mysql-identity-final](evidence/schema/mysql-identity-final.xml) | mysql | 9 / 81 | 0 | 0 | 0 | 0 | 57c9bf9f; 1913/1913 exact |
| [schema/mysql-currentness-red](evidence/schema/mysql-currentness-red.xml) | mysql | 2 / 28 | 0 | 2 | 0 | 1 | 57c9bf9f + retained new test; 1914/1914 exact |
| [schema/mysql-currentness-fixed](evidence/schema/mysql-currentness-fixed.xml) | mysql | 2 / 34 | 0 | 0 | 0 | 0 | 71f545bd; 1914/1914 exact |
| [schema/sqlite-currentness-final](evidence/schema/sqlite-currentness-final.xml) | sqlite | 35 / 758 | 0 | 0 | 5 | 0 | 71f545bd; 1914/1914 exact |
| [schema/mysql-upgrade-final](evidence/schema/mysql-upgrade-final.xml) | mysql | 11 / 275 | 0 | 0 | 0 | 0 | 71f545bd; 1914/1914 exact |
| [fixture/sqlite-fixtures-initial](evidence/fixture/sqlite-fixtures-initial.xml) | sqlite | 47 / 429 | 0 | 0 | 0 | 0 | a063e380; 1956/1956 exact |
| [fixture/sqlite-schema-preparation](evidence/fixture/sqlite-schema-preparation.xml) | sqlite | 21 / 142 | 0 | 0 | 0 | 0 | 822408d2; 1956/1956 exact |
| [fixture/sqlite-cursor](evidence/fixture/sqlite-cursor.xml) | sqlite | 8 / 123 | 0 | 0 | 0 | 0 | a4c68a50; 1956/1956 exact |
| [root/sqlite-historical-migrations](evidence/root/sqlite-historical-migrations.xml) | sqlite | 5 / 46 | 0 | 0 | 0 | 0 | 67fa03a7; 1275/1275 exact |
| [root/sqlite-award-proof-integration](evidence/root/sqlite-award-proof-integration.xml) | sqlite | 32 / 320 | 0 | 0 | 0 | 0 | bea13e9c; 1278/1278 exact |
| [root/sqlite-billing-api-integration](evidence/root/sqlite-billing-api-integration.xml) | sqlite | 51 / 316 | 0 | 1 | 0 | 1 | 3bcf22ca; 1280/1280 exact |
| [root/sqlite-hint-job-integration](evidence/root/sqlite-hint-job-integration.xml) | sqlite | 54 / 353 | 0 | 1 | 0 | 1 | 86312796; 1280/1280 exact |
| [root/sqlite-membership-admission-integration](evidence/root/sqlite-membership-admission-integration.xml) | sqlite | 16 / 169 | 0 | 0 | 1 | 0 | e050e720; 1276/1276 exact |
| [root/native-canaries-final](evidence/root/native-canaries-final.xml) | mysql | 3 / 63 | 0 | 0 | 0 | 0 | 0d2f06c7; 1276/1276 exact |
| [consumer/unit](evidence/consumer/unit.xml) | sqlite | 9 / 17 | 0 | 0 | 0 | 0 | cd58ada9; 11/11 exact |
| [consumer/terminal-omission-red](evidence/consumer/terminal-omission-red.xml) | sqlite | 4 / 56 | 0 | 4 | 0 | 1 | bea13e9c + retained omission patch; 1243/1243 exact |
| [consumer/terminal-restored-green](evidence/consumer/terminal-restored-green.xml) | sqlite | 4 / 80 | 0 | 0 | 0 | 0 | bea13e9c; 1243/1243 exact |

| [root/native-races-final](evidence/root/native-races-final.xml) | mysql | 8 / 152 | 0 | 0 | 0 | 0 | 0d2f06c7; 1278/1278 exact |

## Source verification and final carry

[Component binding verification](evidence/source-binding-verification.json) hashes actual Git blobs, with an isolated two-statement omission overlay or retained new-test capsule only where explicitly recorded. [Final-source comparison](evidence/final-source-verification.json) compares every captured hash in the successful-coverage selection against `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`. Its differences remain visible; matching a selected file does not prove omitted dependencies or rerun a component on final source.

| Successful invocation | Captured files | Differences from frozen root |
| --- | ---: | --- |
| `records/helpers-final` | 8 | All captured hashes match |
| `records/issuer-final` | 1204 | 8 application, 4 test support |
| `records/legacy-transition` | 1203 | 1 application |
| `records/award-upgrade-new` | 10631 | 1 application, 6 other test, 1 other tracked input, 4 tooling |
| `fixture/sqlite-fixtures-initial` | 1956 | 1 application, 6 other test |
| `fixture/sqlite-schema-preparation` | 1956 | 1 application, 4 other test |
| `fixture/sqlite-cursor` | 1956 | 1 application, 3 other test |
| `root/sqlite-historical-migrations` | 1275 | 4 application |
| `root/sqlite-award-proof-integration` | 1278 | 1 application |
| `root/sqlite-membership-admission-integration` | 1276 | 1 application |
| `consumer/unit` | 11 | All captured hashes match |
| `consumer/terminal-restored-green` | 1243 | 1 application, 5 other test |
| `schema/sqlite-currentness-final` | 1914 | 11 application, 20 other test, 4 test support |
| `schema/mysql-identity-final` | 1913 | 12 application, 20 other test, 4 test support |
| `records/native-producer-canary` | 2105 | 1 application, 6 other test |
| `records/native-producer-remainder` | 2105 | 1 application, 6 other test |
| `schema/mysql-currentness-fixed` | 1914 | 11 application, 20 other test, 4 test support |
| `schema/mysql-upgrade-final` | 1914 | 11 application, 20 other test, 4 test support |
| `root/native-canaries-final` | 1276 | All captured hashes match |
| `root/native-races-final` | 1278 | All captured hashes match |

Most integrated SQLite and producer receipts precede the final `BillingSchema.php` current-metadata repair. The schema component runs include that repair but precede several producer/consumer integrations in their isolated branch. The final root native 11-case selection exercises the integrated application, while the component-only cases remain source-limited. Helper and arithmetic inventories are intentionally narrow (8 and 11 files).

The root billing API receipt at `3bcf22ca` also predates the final style-only removal of an unused `BillingSchema` import in `BillingRetrievalAttemptsTest` (`51b6d59f`). That removal changed no method, assertion or application behavior. Original quality and corrected style receipts retain the exact one-line delta. The observation/clock/interval/attempt passing leaves remain attributed to their original nonzero invocation.

## Historical partial bindings

These eight earlier inventories do not completely match the later component reference. No missing historical bytes are reconstructed or relabelled as final-source execution. Every differing digest is retained; all eight have zero absent paths.

- `helpers-initial`: 5/8 match `a8facf90`; differing paths: `app/Domain/Memberships/Billing/BillingRetrievalInterval.php`; `tests/Unit/BillingDatabaseClockTest.php`; `tests/Unit/BillingRetrievalRecordsTest.php`.
- `issuer-canary`: 1202/1204 match `7ed9ef47`; differing paths: `app/Domain/Memberships/Billing/BillingLedger.php`; `tests/Feature/ProductionMembershipBilling/BillingIssuedRetrievalTest.php`.
- `issuer-full`: 1202/1204 match `7ed9ef47`; differing paths: `app/Domain/Memberships/Billing/BillingLedger.php`; `tests/Feature/ProductionMembershipBilling/BillingIssuedRetrievalTest.php`.
- `sqlite-upgrade-initial`: 1909/1912 match `57c9bf9f`; differing paths: `app/Domain/Memberships/Billing/BillingRetrievalSchema.php`; `app/Domain/Memberships/Billing/BillingSchema.php`; `tests/Feature/ProductionMembershipBilling/BillingRetrievalSchemaUpgradeTest.php`.
- `sqlite-identity-initial`: 1910/1913 match `57c9bf9f`; differing paths: `app/Domain/Memberships/Billing/BillingSchema.php`; `tests/Feature/ProductionMembershipBilling/BillingRetrievalIdentitySchemaTest.php`; `tests/Feature/ProductionMembershipBilling/BillingRetrievalSchemaUpgradeTest.php`.
- `mysql-canary`: 1910/1913 match `57c9bf9f`; differing paths: `app/Domain/Memberships/Billing/BillingSchema.php`; `tests/Feature/ProductionMembershipBilling/BillingRetrievalIdentitySchemaTest.php`; `tests/Feature/ProductionMembershipBilling/BillingRetrievalSchemaUpgradeTest.php`.
- `mysql-canary-diagnostic`: 1911/1913 match `57c9bf9f`; differing paths: `app/Domain/Memberships/Billing/BillingSchema.php`; `tests/Feature/ProductionMembershipBilling/BillingRetrievalSchemaUpgradeTest.php`.
- `mysql-canary-fixed`: 1912/1913 match `57c9bf9f`; differing paths: `tests/Feature/ProductionMembershipBilling/BillingRetrievalSchemaUpgradeTest.php`.

[Source stability](evidence/source-stability-verification.json) distinguishes independently comparable before/after maps from runner sidecars reporting unchanged source without a separately archived after-map. The initial helper run has neither retained stability form; its pass alone does not prove unchanged source. External baseline and award-upgrade programs have separate [before/after byte verification](evidence/external-program-verification.json); native runner bytes have [captured-hash verification](evidence/runner-source-verification.json).

## Original failures and corrections

- Original source `45d12994`: four intended baseline assertion failures (4 / 53), demonstrating allocation replacement and worker-clock defects through the original APIs.
- Initial native schema canary and diagnostic canary: one setup/schema-acceptance error each (1 / 1, exit 2). MySQL changed known enum-literal character-set formatting after ADD COLUMN; the later narrow normalization and passing receipts are separate. The diagnostic test source was temporary.
- Native currentness comparison: unchanged application `57c9bf9f` plus the exact retained new test gives 2 / 28 with two expected failures. Missing/replaced guards and all four table-shape changes were incorrectly admitted through an older metadata snapshot. Corrected `71f545bd` gives 2 / 34 with zero errors/failures/skips.
- Root API selection `3bcf22ca`: 51 / 316 with one assertion failure. Four complete files passed 47 / 300 inside that failed invocation. Test-only `86312796` replaces an advancing-second assumption with actual start < hint < end and covering start > hint ordering.
- Root hint/job selection `86312796`: 54 / 353 with one cursor assertion failure. Six complete files passed 50 / 320 inside that failed invocation. The corrected whole cursor file passes 8 / 123 at `a4c68a50`, using controlled synthetic same-second pagination timestamps on SQLite.
- Deliberate Context terminal-call omission: 4 / 56 with four expected failures; restored source gives 4 / 80. These assertions detect loss of the direct Context terminal refusal reason. Other public checks still refused the mutant operations; this is not evidence of an unauthorized durable effect.

The native producer's initial exit 255 is a host-versus-container instance-identity setup refusal before PHPUnit. The initial discovery exits before bodies on an autoloader redeclaration. The original Pint failure is one unused import. All are retained separately from corrected results. A schema-lane unrecorded early duplicate-autoloader runner error is disclosed in component notes; no receipt is reconstructed for it.

## Retained skips

JUnit stores empty skip elements for these native-only cases; the case identities and invocation provenance are retained rather than inventing messages. All seven distinct identities later have native passing evidence, but their SQLite invocations remain skipped.

- `Tests\Feature\ProductionMembershipBilling\BillingIssuedRetrievalTest::test_native_duplicate_first_identity_uses_a_current_read_after_its_earlier_snapshot` — `records/issuer-final`.
- `Tests\Feature\ProductionMembershipBilling\BillingRetrievalIdentitySchemaTest::test_native_database_utc_is_independent_of_session_timezone` — `schema/sqlite-identity-initial`, `schema/sqlite-final`, `schema/sqlite-currentness-final`.
- `Tests\Feature\ProductionMembershipBilling\BillingRetrievalIdentitySchemaTest::test_native_old_snapshot_cannot_hide_a_competing_committed_interval_claim` — `schema/sqlite-final`, `schema/sqlite-currentness-final`.
- `Tests\Feature\ProductionMembershipBilling\BillingRetrievalIdentitySchemaTest::test_native_endpoint_lookup_uses_current_allocation_after_an_older_snapshot` — `schema/sqlite-final`, `schema/sqlite-currentness-final`.
- `Tests\Feature\ProductionMembershipBilling\MembershipReservationClockClosureTest::test_native_committed_clock_callback_owner_withdrawal_refuses_receipt_without_undoing_reservation` — `root/sqlite-membership-admission-integration`.
- `Tests\Feature\ProductionMembershipBilling\BillingRetrievalGuardDefinitionTest::test_old_dictionary_snapshot_cannot_admit_a_missing_or_replaced_required_interval_guard` — `schema/sqlite-currentness-final`.
- `Tests\Feature\ProductionMembershipBilling\BillingRetrievalGuardDefinitionTest::test_old_dictionary_snapshot_cannot_admit_changed_current_endpoint_table_shape` — `schema/sqlite-currentness-final`.

## Discovery and quality source boundaries

Body-free discovery at `67d85d6e` initially failed because a shared `vendor/bin` symlink loaded another worktree's autoloader. A private proxy directory corrected that; proxy bytes and repository source stayed unchanged. The canonical explicit-autoloader focused runner was unaffected. Successful discovery on the same source reports 2,068 selected cases / 199 files, from 8,151 / 599, across 24 nonempty shards. Both 2,111-file maps match Git. The deliberately unreachable `127.0.0.1:1` endpoint confirms this was enumeration, not database-body execution.

Local quality at `874d57fe` passes four Python policy suites (52 + 61 + 27 + 14 = 154 tests) and initially fails Pint on one unused import. The corrected 56-file Pint run passes at `51b6d59f`. Both 2,283-file maps match their commits and remain unchanged. [Policy carry](evidence/policy-carry-verification.json) proves 39 unchanged inputs and the original results digest across that style correction; it is not a policy rerun at `0d2f06c7`. The later GitLab timing-comment change qualifies historical estimates only. Preliminary `e050e720` policy receipts (52 / 61 / 27) remain separately recorded.

Final review, exact-head preflight and any merge decision are root/reviewer responsibilities. This map makes no hosted CI, production, provider or C4 activation claim.
