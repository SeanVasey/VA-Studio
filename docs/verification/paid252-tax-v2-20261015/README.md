# Paid252 Tax V2 origins and original preparation — focused development evidence

Base: actual PR #121 merge `7567c52a3246ab2625196952a448c8cd7a890dcb`, plus its receipt-only carry `8297b8c4`. This is a separate typed consumer/schema/original-preparation batch. Final independent evidence approval, exact-head Development preflight and expected-head merge remain publication gates; this packet does not claim release acceptance.

## Implemented boundary

`PaidTax` authenticates the original Tax SourceV2 and current buyer, retains one immutable batch/per-line origin, prepares actual private first-original PDFs with bounded claims and exact purchased-file checks, and records whole-order physical completion. Five separately owned `paid_tax_*` tables and a forward resumable migration preserve V1 data and guards. Source/consumer commit admission and one-use projection receipts close callbacks under the original budgets. No HTTP/download endpoint, authorization/redemption family, root mount or active entitlement is supplied by this PR. Those are the next related step.

Literal flags remain default-off; this evidence uses retained synthetic paid producers, genuine local SMTP enrollment, private fixture files and the actual pinned renderer child. No real provider payment, customer import, host, live credential, deployment, DNS, activation or Foundation/GitLab verification is claimed. SDK 21.3.2 / API 2026-08-26.dahlia and the deferred Stripe 22 boundary remain unchanged.

The [original design](design.md) and its original decisions remain historical proposals/reviews, not current execution claims. The related FreshTaxCheckoutPolicy descriptor P2 is fixed with actual array admission before offsets. Shared V1/generic/identity descriptor P2s and ordinary membership afterCommit P2 remain frozen outside this batch.

## Final focused selections

Application and test source at `26c0c472ac6b19060e42064e3e9315aae80e6eb5`. The archive binds each invocation separately; later publication-only documentation does not relabel historical runs.

| Selection | Tested head | Tests / assertions | Skips | PHP / recorder / observed OS exit |
| --- | --- | --- | --- | --- |
| Schema component of `mysql-buyer-serialization-and-native-recovery` (whole invocation failed later) | `94a2c49b3236a68d204c86014e80a96e8cf877e8` | 11 / 1305 | 1 | 1 / 1 / 1 for whole invocation |
| `mysql-bounded-origin-race-and-private-original` | `26c0c472ac6b19060e42064e3e9315aae80e6eb5` | 2 / 100 | 0 | 0 / 0 / 0 |
| `sqlite-whole-paid-tax-family` | `26c0c472ac6b19060e42064e3e9315aae80e6eb5` | 91 / 2660 | 2 | 0 / 0 / 0 |
| `sqlite-affected-v1-paid-journeys` | `26c0c472ac6b19060e42064e3e9315aae80e6eb5` | 21 / 398 | 0 | 0 / 0 / 0 |

These are focused development selections, not the repository-wide matrices. The whole new-family SQLite selection also includes the affected Tax policy/metadata files; the V1 selection runs the three whole affected journey files. Native selection covers schema prefix recovery/refusal, actual cold independently authenticated consumer processes, exact buyer-row waits, duplicate-origin replay, byte-distinct provider identities, temporary/nontransactional refusal, restored bounded owner waits and actual private original/preparation.

Declared skips:

- `sqlite-whole-paid-tax-family`: `Tests\Feature\PaidTaxSchemaRecoveryTest::test_native_schema_global_exact_case_accent_fk_and_routine_identities_refuse_before_first_owned_ddl` (driver-specific case, exact committed skip policy).
- `sqlite-whole-paid-tax-family`: `Tests\Feature\PaidTaxNativeBoundaryTest::test_native_provider_replays_serialize_one_origin_and_refuse_temporary_or_nontransactional_owned_objects` (driver-specific case, exact committed skip policy).
- `mysql-buyer-serialization-and-native-recovery`: `Tests\Feature\PaidTaxSchemaRecoveryTest::test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target` (driver-specific case, exact committed skip policy).

Native Schema source is unchanged from 94a to the final tested source; its already passing component is explicitly carried without duplicating the selection just to obtain a green overall exit. The worker trace maps native 1205/1213 to controlled 503; the exact underlying native error code is not exported. The observed exact row waits, before-delegate failure, five-second duration, restored settings and closed frames establish the selected bounded contention behavior.

Exact human commands (from this directory's archived launcher/runner; actual expanded PHP argv, environment, selected files, filter, runtime versions and JUnit live in each `<selection>/command.json`, `result.json`, `log.txt`, `junit.xml`):

```sh
source /workspace/.va-studio-toolchain/activate.sh
python3 launch-durable.py --name mysql-bounded-origin-race-and-private-original --driver mysql --filter 'PaidTaxNativeBoundaryTest|test_actual_tax_original_is_private_deterministic_idempotent_and_restore_only' tests/Feature/PaidTaxNativeBoundaryTest.php tests/Feature/PaidTaxGrantJourneyTest.php
python3 launch-durable.py --name sqlite-whole-paid-tax-family --driver sqlite tests/Feature/PaidTaxGrantJourneyTest.php tests/Feature/PaidTaxAdversarialJourneyTest.php tests/Feature/PaidTaxSchemaRecoveryTest.php tests/Feature/PaidTaxFamilyCompatibilityTest.php tests/Feature/PaidTaxNativeBoundaryTest.php tests/Feature/PaidTaxTransportFixtureTest.php tests/Unit/PaidTaxCapabilityTest.php tests/Feature/ProductionTaxCheckout/FreshTaxCheckoutPolicyMetadataTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutPolicyTest.php
python3 launch-durable.py --name sqlite-affected-v1-paid-journeys --driver sqlite tests/Feature/PaidGrantSourceJourneyTest.php tests/Feature/PaidGrantDocumentJourneyTest.php tests/Feature/PaidGrantDownloadJourneyTest.php
```

The original native Schema component came from the following command. It ended nonzero after the later race assertion; the component carry does not change that exit:

```sh
python3 launch-durable.py --name mysql-buyer-serialization-and-native-recovery --driver mysql --filter 'PaidTaxSchemaRecoveryTest|PaidTaxNativeBoundaryTest|test_actual_tax_original_is_private_deterministic_idempotent_and_restore_only' tests/Feature/PaidTaxSchemaRecoveryTest.php tests/Feature/PaidTaxNativeBoundaryTest.php tests/Feature/PaidTaxGrantJourneyTest.php
```

Current formatting passed for 45 PHP files (`pint-26c0c472.json` contains every selected path/hash/argv). Current partition 52, workflow 14 and scope 27 policy tests passed; the census command validates inventory only:

```sh
python3 scripts/ci/test-phpunit-shards.py
python3 scripts/ci/test-workflow-cadence.py
python3 scripts/ci/test-ci-scope.py
python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection
```

The launcher refuses reused run names and dirty source. Do not overwrite original receipts to repeat a command. Every recorded selection binds all committed application/bootstrap/config/database/contracts/routes/scripts/tests and dependency/runtime inputs, with exact Git bytes and thirteen Composer loader hashes. Original three foreground selections have no portable launch/PID/startticks OS witness; their missing witness has not been reconstructed or described as observed.

## Required repairs and failure provenance

- The Fresh Tax environment descriptor, pre-source physical reopen, deserialized budget, delayed claim-clock, begun delegate cleanup, pre-start displacement, before-bytes refusal cleanup and cold schema-grammar regressions each have original failing source-bound executions and later focused corrections. Native generated-ID lookup failed after MySQL resets `lastInsertId` on SET/SELECT; the fix captures it immediately after DML. Byte-distinct provider identifiers use ASCII binary collation in the new schema.
- The native `04f45bc6` run retained a winning origin and a second worker's status 403 at `PaidTaxReadReceipt.php:54`, after 13.442 s. A separate raw PDO probe on private MySQL 8.4.11 REPEATABLE-READ established the mechanism: information_schema reads create an old view; later FOR UPDATE sees `1` while a plain read sees `0`. SHOW CREATE/no-query/LIMIT0 controls see `1`. The scratch object was removed. This is a genuine same-owner replay defect, not budget expiry.
- The repair captures and retains the exact original frame, then locks the admitted buyer's qualified permanent InnoDB `users.id` before begun delegates or dictionary reads. Both native wait settings are min(original,5) and restored. Full current buyer/schema/source authority still follows before owned DML; the early lock grants no authority. Final comparisons remain plain reads with no new terminal row locks. Isolation and all original numerical caps are unchanged.
- The revised native test starts both commands behind the exact buyer row after independent authentication, includes an ordinary begun listener reading dictionary metadata, requires at least one saved origin, the same ID from every successful worker and only exact pre-delegate controlled 503 for a refused waiter, and proves a separate real blocked worker refuses with 503, restores settings, closes its transaction, skips delegates and permits fresh exact replay.
- Earlier `160ff441` and `81e6af2d` native failures omitted diagnostics. Their causes remain unresolved for those original invocations; the later diagnosed snapshot defect is not retroactively assigned. Their selected race/schema assertions were not completed.
- Original transport/open-URL/invalid synthetic-ID, temporary-table alias, deliberate FK-damage setup, wrong different-buyer/status and users-shadow expectation failures remain explicit. In `a051ac2e`, the new users shadow correctly hit the existing producer 409 before consumer birth; the wrong 503 test expectation stopped later race assertions. `94a2c49b` changes only that precise expectation. The original 94a run also retained a genuine winner and a 5.0267 s controlled 503 waiter; its unconditional two-saved oracle was stronger than the preserved five-second wait cap. It remained failed overall, with the Schema component independently reconciled 11/1305/S1 and the later private-original unexecuted. The corrected oracle still rejects 403, 410, unrelated 503 and all-refused outcomes and requires fresh exact replay. No failure is deleted or relabelled a pass.

All original outcomes, including failed/errored invocations and exact source heads:

| Invocation | Source | Tests/assertions; failures/errors/skips | PHP/recorder/OS |
| --- | --- | --- | --- |
| `mysql-bounded-origin-race-and-private-original` | `26c0c472ac6b19060e42064e3e9315aae80e6eb5` | 2/100; F0 E0 S0 | 0/0/0 |
| `mysql-buyer-serialization-and-native-recovery` | `94a2c49b3236a68d204c86014e80a96e8cf877e8` | 12/1350; F1 E0 S1 | 1/1/1 |
| `mysql-cold-consumers-origin-race-and-recovery` | `81e6af2ddaae6e8355bd6d7f2b7c41d21748a6cf` | 1/39; F1 E0 S0 | 1/1/1 |
| `mysql-origin-race-and-objects-first` | `64cfd5101a3484687c97cdf94c57ef0738c07ef0` | 1/3; F0 E1 S0 | 2/1/1 |
| `mysql-origin-race-gated-and-recovery` | `fee317f1ab46a4350d41be630911d5b0edf3fd85` | 1/33; F1 E0 S0 | 1/1/1 |
| `mysql-origin-race-objects-and-recovery-corrected` | `160ff441f3bcc997d0f9bd636a7d784119e15c13` | 1/625; F1 E0 S0 | 1/1/1 |
| `mysql-origin-race-retained-diagnostics` | `04f45bc6b8e0f8a69d96255271281e23836c1e42` | 1/39; F1 E0 S0 | 1/1/1 |
| `mysql-original-buyer-before-snapshot-fixed` | `a051ac2e5044269e415ee147ca7bc3063ca54c5d` | 1/4; F1 E0 S0 | 1/1/1 |
| `mysql-provider-case-corrected-fixture-old-source` | `f3aa1f1fa6565b38e676a160e2aba9d1840f9856` | 1/3; F0 E1 S0 | 2/1/1 |
| `mysql-provider-case-fixed` | `8ae2903be6c259e1465830f0e1ca792308b00c58` | 1/12; F0 E0 S0 | 0/0/0 |
| `mysql-provider-case-old-source` | `16a1a8f15ded4256a90a87d819bae5902cfe1c17` | 1/2; F0 E1 S0 | 2/1/1 |
| `pure-capabilities-fixed` | `51257e9675af14d794e4e2ac16182dd98966ee3f` | 3/4; F0 E0 S0 | 0/0/0 |
| `pure-deadline-deserialization-old-source` | `ba6596285f1d0ecac7ef1d5cc92542fce6e16b65` | 1/1; F1 E0 S0 | 1/1/1 |
| `pure-distinct-transport-protocol` | `7f9f9ac6d53430f8e504ed6c33946bbfd45ed12e` | 1/4; F0 E0 S0 | 0/0/0 |
| `sqlite-adversarial-boundaries-corrected-fixture` | `c917abab04852252523dbcb370494e8f4df24074` | 15/2172; F0 E1 S0 | 2/1/1 |
| `sqlite-adversarial-boundaries-first` | `e38ece6ef267b697c2d67d8dbf41af053a4e3d0d` | 3/19; F0 E1 S0 | 2/1/1 |
| `sqlite-affected-v1-paid-journeys` | `26c0c472ac6b19060e42064e3e9315aae80e6eb5` | 21/398; F0 E0 S0 | 0/0/0 |
| `sqlite-before-bytes-cleanup-old-source` | `250295bea54c7b6ba2f3dfa5776d0cba46ad09a2` | 1/8; F1 E0 S0 | 1/1/1 |
| `sqlite-begin-birth-fixed` | `8ae2903be6c259e1465830f0e1ca792308b00c58` | 4/54; F0 E0 S0 | 0/0/0 |
| `sqlite-begin-throw-old-source` | `ba89be684e81408e1fd8286fd0bc9880b97e86a9` | 1/6; F1 E0 S0 | 1/1/1 |
| `sqlite-cold-schema-fixed` | `5ed4f8d01b54eb9ee23d6856d23c5866a42020ca` | 1/6; F0 E0 S0 | 0/0/0 |
| `sqlite-cold-schema-old-source` | `2f181f55796e4e51fc754461b0095c23887a5c36` | 1/3; F1 E0 S0 | 1/1/1 |
| `sqlite-commit-boundaries-first` | `7ef0fd778e3c5a52463789918765a3f5ac6eb3f2` | 4/33; F1 E0 S0 | 1/1/1 |
| `sqlite-consumer-family-and-recovery` | `8a35c90f84052531f4f1189fcd806d6afdda4e43` | 8/74; F1 E0 S0 | 1/1/1 |
| `sqlite-delayed-claim-clock-old-source` | `f3aa1f1fa6565b38e676a160e2aba9d1840f9856` | 1/8; F1 E0 S0 | 1/1/1 |
| `sqlite-family-and-recovery-corrected-refusal` | `b77819229546b88bfa76c0b9049eabe41b852d7b` | 19/2230; F0 E0 S1 | 0/0/0 |
| `sqlite-fresh-environment-fixed` | `1e6e103101865eff0dfe6cfd7ac8a3fa1d9ace7e` | 3/5; F0 E0 S0 | 0/0/no portable witness |
| `sqlite-fresh-environment-old-source` | `c2c1d16a9660820153dc11f6e3a7c489aa6e8130` | 1/1; F1 E0 S0 | 1/1/no portable witness |
| `sqlite-fresh-policy-compatibility` | `1e6e103101865eff0dfe6cfd7ac8a3fa1d9ace7e` | 46/152; F0 E0 S0 | 0/0/no portable witness |
| `sqlite-origin-amounts` | `42774b742b9536413c753bc40297646db0886207` | 3/66; F0 E0 S0 | 0/0/0 |
| `sqlite-origin-first` | `42774b742b9536413c753bc40297646db0886207` | 1/22; F0 E0 S0 | 0/0/0 |
| `sqlite-original-first` | `b57d1f1642d4b437cf0727ad032cca63fb39670a` | 1/23; F0 E0 S0 | 0/0/0 |
| `sqlite-owned-frame-and-clock-repair` | `51257e9675af14d794e4e2ac16182dd98966ee3f` | 8/72; F0 E0 S0 | 0/0/0 |
| `sqlite-pre-source-reopen-old-source` | `ba6596285f1d0ecac7ef1d5cc92542fce6e16b65` | 1/4; F1 E0 S0 | 1/1/1 |
| `sqlite-prestart-and-begin-fixed` | `035e7cd885e29754af81a549061b6ae00d4e8823` | 7/86; F0 E0 S0 | 0/0/0 |
| `sqlite-prestart-displacement-old-source` | `5a3e1b794eec254a24649a164027114ac5d54767` | 1/6; F1 E0 S0 | 1/1/1 |
| `sqlite-two-line-recovery-first` | `b57d1f1642d4b437cf0727ad032cca63fb39670a` | 1/17; F0 E0 S0 | 0/0/0 |
| `sqlite-whole-paid-tax-family` | `26c0c472ac6b19060e42064e3e9315aae80e6eb5` | 91/2660; F0 E0 S2 | 0/0/0 |

## Independent review and reproducibility

Original independent design/source REVISE decisions remain beside their later approvals. Full source approval at 8a35c90f, cold-grammar approval at 5ed4f8d0, early-owner approval at a051ac2e and fixture-only carry to 94a2c49b are separately authored originals; final packet review must reconcile this sealed evidence and the publication head. The implementation lead does not approve its own change.

[Recorded evidence index](recorded-evidence-index.json) binds `38` PHP invocations, `32` immutable source inventories, `2395` current inputs, `16` route inputs and `13` loaders. [Archive](recorded-evidence.tar.gz): SHA256 `8ef68cc4b8ed180541141fa2fb24565474ed8e8a8484455de2dff18c2e5e7b76`, 11732248 bytes. It includes exact deduplicated source blobs, original launch/OS observations, all JUnit/logs/exits, private-server preparation/version/stop receipts, policy/census/format records, profile audit and reviewer originals. The index distinguishes preparation failures from launched PHP cases.

Private native server: MySQL 8.4.11, owned datadir volume `va-studio-private-mysql-paid252-tax-v2-data-20261015`, port 33518, socket `/workspace/runtime/mysql-paid252-tax-v2-run/mysql.sock`, schema `vaseyaudio_paid252_tax_v2`; exact original version/session and stopped-server receipts are archived. Only that owned server is stopped; prior datadirs and unrelated services remain untouched.

Pint, source/profile audit and CI selection/trigger/scope policy records retain their exact heads. All six old profiles and their 28 unique manifest/member paths and V1 paid source are byte-unchanged. New V2 manifest/six members are unchanged since their first 42774b implementation; no repin occurred. Census 2,307 of 8,854 cases in 226 selected files (659 total files) validates inventory only, not test execution. Timing fallbacks for 63 unmeasured files are not measured runtime evidence. Hosted workflow triggers are unchanged.

## Limits and next dependency

This repairs same-owner replay view ordering; it does not prove every unrelated mutable/media set globally current. General isolation, deadlock/trigger/DDL races, damaged restore/failover, invisible direct-PDO reopen, cross-host physical timing/storage, representative latency/throughput, cache capacity, FPM verification for the new family, production legal/commercial/account/provider/host inputs and final integrated Foundation remain open. Original 60/300-second and 5-attempt bounds are unchanged; no cap was extended to obtain a pass. Physical evidence is a bounded observation, not permanent/atomic filesystem validity. Later delivery must recheck exact retained bytes.

After reviewed development merge, continue the versioned Tax delivery/authorization/redemption/HTTP composition with the same current-owner, source-receipt, original-file and budget boundaries. Keep the existing frozen profiles and deferred unrelated P2s intact.

## Final independent development decision

A separate non-author reviewer [APPROVES WITH CONDITIONS](independent-final-DECISION.md) exact `562147dac0b8dea1f9902a5e6b0be8b8bdbdd7ba` for focused development, with no open P0/P1 or required source/test/evidence correction. The [report](independent-final-report.json) independently reconciles all 2,994 archive members, 32 inventories, 2,397 reachable Git objects and 76,445 path bindings; all 38 invocations (17 passing originals, 21 preserved nonzero originals), current selections and narrow Schema carry remain distinct. The reviewer-only legacy-profile parser error and corrected static verifier are both preserved beside the final decision; no application test was rerun for that parser correction.

Recording these originals changes documentation only. Explicit successor carry, cheap exact-publication-head preflight, complete review/protection inventory and expected-head merge remain before publication completes. Default-off/unmounted and all recorded activation/release conditions remain. Actual merge must be recorded separately, not inferred from this approval.
