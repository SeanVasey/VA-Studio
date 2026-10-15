# Paid252 typed Tax delivery — focused development evidence

This batch adds typed Tax authorization, bounded status history, single committed redemption attempts and private prepared transfers on retained PaidTax V2 originals. A separately owned two-table forward migration admits exact resumable prefixes without adding dependencies to the original five Tax tables. Delivery commands authenticate the real current buyer, historical buyer, original SourceV2, all original lines and whole-order fulfillment; tokens and request idempotency are bound to those retained originals.

The domain remains **default-off and unmounted**: `downloads_enabled=false`, original rehearsal/operative/HTTP flags remain false, and no controller, endpoint or root registration is added. Fixtures are synthetic enrolled buyers, retained synthetic paid producers and actual private local bytes. This is focused development evidence, with no host/provider/live payment/active import/activation/release or complete Foundation claim. Stripe SDK 21.3.2/API 2026-08-26.dahlia and the deferred Stripe22 boundary remain unchanged.

Root owns implementation/tests/CI metadata and this packet; a separate non-author reviewer owns original review artifacts. The [design](design.md) and initial decisions remain historical proposals, not execution claims. The original source REVISE and four-repair approval are preserved without rewriting their scope.

## Final affected selections

Production application/config/migration source is unchanged since `d6367b3f632e576be65af8e08b0a15ffc05a1f6f`. Final application/test/helper inputs are frozen at `3ea857df3d98ce38680a455163711b230f97d1bc`; its only input changes from the last executed V1 selection at a12 are canonical sorting of three CI policy JSON lists. The archive binds every historical source separately. No test is relabeled as executed on a later head.

- Whole new SQLite delivery/schema/native files at be6: **26 tests, 2364 assertions, 3 native-only skips, no failures/errors; PHP/recorder/observed OS exits 0/0/0**.
- Whole existing affected Tax files at294: **32/371, zero skips/failures/errors; exits 0/0/0**.
- Whole affected V1 paid source/document/download journeys at a12: **21/398, zero skips/failures/errors; exits 0/0/0**.
- Whole private MySQL delivery schema file at f4: **5/2893, zero skips/failures/errors; exits 0/0/0**. Exact committed-prefix recovery, collisions/drift/temporary refusals, populated old V1/Tax SQL-boundary rows, retention and no pre-DDL mutation. Those populated boundary rows are synthetic SQL fixtures, not authenticated paid grants.
- Whole private MySQL delivery race file at ddb: **3/76, zero skips/failures/errors; exits 0/0/0**. Independent native connections produce exactly one duplicate-authorization winner, one duplicate-redemption winner and one final-attempt winner across two authorizations. Original wait settings are restored and transaction frames close. The native Schema and race selections are narrow source/test carries, not whole-current-head native reruns.

Exact final application commands, after activating the recorded PHP toolchain, are below. The archived launchers/recorders are read-only originals; replay requires a new owned output directory/run name, not overwriting these receipts. Every `<selection>/command.json` retains expanded PHP argv, environment, paths/filter and timeout; its `result.json`, `log.txt`, `junit.xml` and launch-bound OS witness retain exact outcomes.

```sh
source /workspace/.va-studio-toolchain/activate.sh
python3 launch-durable.py --name mysql-whole-forward-delivery-schema --driver mysql tests/Feature/PaidTaxDeliverySchemaRecoveryTest.php
python3 launch-durable.py --name mysql-three-real-delivery-races-first --driver mysql tests/Feature/PaidTaxDownloadNativeTest.php
python3 launch-durable.py --name sqlite-final-download-and-schema-files --driver sqlite tests/Feature/PaidTaxDownloadJourneyTest.php tests/Feature/PaidTaxDeliverySchemaRecoveryTest.php tests/Feature/PaidTaxDownloadNativeTest.php
python3 launch-durable.py --name sqlite-affected-existing-tax-family --driver sqlite tests/Feature/PaidTaxGrantJourneyTest.php tests/Feature/PaidTaxAdversarialJourneyTest.php tests/Feature/PaidTaxFamilyCompatibilityTest.php tests/Unit/PaidTaxCapabilityTest.php
python3 launch-durable.py --name sqlite-affected-v1-paid-journeys --driver sqlite tests/Feature/PaidGrantSourceJourneyTest.php tests/Feature/PaidGrantDocumentJourneyTest.php tests/Feature/PaidGrantDownloadJourneyTest.php
```

The three exact SQLite skips are `Tests\Feature\PaidTaxDownloadNativeTest::test_native_duplicate_authorizations_join_one_immutable_result_after_current_buyer_serialization` and the two recorded data sets of `Tests\Feature\PaidTaxDownloadNativeTest::test_native_redemption_duplicate_and_last_attempt_races_keep_one_spent_attempt` (each case is enumerated in the archived original JUnit and skip receipt). They genuinely skipped on be6 before their CI skip declaration was added at294; declaration is not backdated. No new-schema case skipped in the native5-case run. Partial earlier stop-on-defect selections did not execute remaining cases.

## Repairs and original failures

Independent review identified four material source P2s: missing terminal capture of recent authorization/history selectors; a scalar-only snapshot deadline overlooking the live request budget inside adapter callbacks; delayed later-chunk clock observations permitting a handoff after the bound; and public new-table access while delivery admission was pending. Original corrected fixtures on d349 run **5/27, four failures, no errors/skips, exits1/1/1**, with production bytes equal to the original reviewed f2 source. The same five selected cases on d636 run **5/39, zero failures/errors/skips, exits0/0/0**. The fifth is a passing held-key/owner control. These are actual behavioral regressions and repairs, not missing-API assertions.

Earlier nonzero runs are preserved separately: the decrypt fixture originally fired in an outside-transaction source locator, so its rollback assertion targeted the wrong phase; a diagnostic recorded that actual frame. The mixed initial five-case attempt also used an ineffective cached-instance resolver hook, a wrong expected key-refusal status and replacement media without the required unpublish. Fixtures were corrected to real Tax commit dispatch, resolver factory and unpublish/publish paths. None is claimed as an independently proven product rollback defect or a complete four-case oracle. One patch-preparation lookup failed after Pint shortened an imported name, changed no file, and was corrected before any application run.

Private preparation, first-chunk authority, per-chunk transfer time and cleanup preserve the original live min-only budget. Transfer start is sampled before the second commit command, never renewed after callbacks; first nonempty bytes require the original consumer/source/owner proof after callback-producing operations. Failure or never-started disposal abandons the original observer/descriptor/lease while retaining a durable committed attempt. A throwing afterCommit is a spent attempt and refusal, not success inferred from idle PDO. Tests cover actual private PDF/master/stems/multiline journeys, idempotency/history/limits, wrong ownership/token/targets, late key/owner/source/policy mutations, live clock/snapshot shortening, physical and committed-attempt failures, retry and destructor cleanup.

## Every original PHP invocation

Thirteen actual PHP invocations: nine passing originals and four nonzero originals. No preparation failure is counted as an application test. All thirteen have original launch-bound PID/UID/startticks OS observations and source/status/runner/autoload unchanged checks.

| Selection | Actual tested head | Tests / assertions | Failures / errors / skips | PHP / recorder / OS exit |
| --- | --- | --- | --- | --- |
| `mysql-delivery-pre-ddl-temporary-shadow-before` | `3f67a6506784f982248a72a3c20b440203b11b77` | 1 / 5 | 0 / 0 / 0 | 0 / 0 / 0 |
| `mysql-three-real-delivery-races-first` | `ddb85c2f0d2917bcb59efef8139f642792e28a71` | 3 / 76 | 0 / 0 / 0 | 0 / 0 / 0 |
| `mysql-whole-forward-delivery-schema` | `f4f9d5f87e64150cae7951d7db84d73436c108d7` | 5 / 2893 | 0 / 0 / 0 | 0 / 0 / 0 |
| `sqlite-affected-existing-tax-family` | `294110a22c17430fae783a773dece5b0c6da09a4` | 32 / 371 | 0 / 0 / 0 | 0 / 0 / 0 |
| `sqlite-affected-v1-paid-journeys` | `a12fe5effd31670fcdc78b6382b241554bf4c653` | 21 / 398 | 0 / 0 / 0 | 0 / 0 / 0 |
| `sqlite-download-adversarial-first` | `50dd22d7d2f1c851a4e0c7cc498c0658ac7f5fcf` | 6 / 89 | 1 / 0 / 0 | 1 / 1 / 1 |
| `sqlite-final-download-and-schema-files` | `be6c483e44712a6c60971bf4343d993e8a55d28a` | 26 / 2364 | 0 / 0 / 3 | 0 / 0 / 0 |
| `sqlite-first-real-download-journey` | `f2cefff70473349c4273ff4dd1e1aab9880d48ff` | 1 / 28 | 0 / 0 / 0 | 0 / 0 / 0 |
| `sqlite-four-real-source-oracles-before-repair` | `ab09d94dfef3fc859d700412145e4f4d20d20628` | 5 / 13 | 4 / 1 / 0 | 2 / 1 / 1 |
| `sqlite-four-source-oracles-after-repair` | `d6367b3f632e576be65af8e08b0a15ffc05a1f6f` | 5 / 39 | 0 / 0 / 0 | 0 / 0 / 0 |
| `sqlite-four-source-oracles-corrected-fixtures` | `d349c182d13df907b69afd1a65338a53265f860a` | 5 / 27 | 4 / 0 / 0 | 1 / 1 / 1 |
| `sqlite-key-owner-mutation-diagnostic` | `92f15d155886c9c2387877a804ccf2be360f4b0c` | 1 / 11 | 1 / 0 / 0 | 1 / 1 / 1 |
| `sqlite-whole-download-domain-first` | `c805a0b152aebd45c2e0a8740295b22f39a11082` | 15 / 160 | 0 / 0 / 0 | 0 / 0 / 0 |

The exploratory native temporary-shadow selection passed already on its earlier source; it is neither a red regression nor a source repair. The complete native race invocation took 927.968104315s including fixture/schema lifecycle; individual duplicate winners and bounded competing503s are recorded in the original worker traces. Actual exact redemption bytes/hash and subsequent409/spent counts are checked. Native serialization error codes are not exported; no forced uniqueness/deadlock stress claim is made. `begun_dictionary_read=true` includes the earlier identity frame, not proof that the blocked Tax delegate executed.

## Static checks, isolation and provenance

At clean3ea, Pint passed all18 changed PHP files. CI partition52, workflow14 and scope27 checks passed. The cheap discovery generator proved **2333 selected native cases in229 files of8880 discovered cases in662 files**, across8 nonempty shards; discovery is not test execution. Its66 fallback weights and roughly351-minute shard estimates are unmeasured estimates; the existing210-minute workflow bound is unchanged. Earlier be6 discovery used uncommitted policy edits and remains discovery-only with that dirty-overlay scope. Clean final discovery supersedes its publication binding without repeating application tests.

```sh
vendor/bin/pint --test <18 exact paths in pint-final.json>
python3 scripts/ci/test-phpunit-shards.py
python3 scripts/ci/test-workflow-cadence.py
python3 scripts/ci/test-ci-scope.py
python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection
```

Private MySQL **8.4.11** used its own datadir volume `va-studio-private-mysql-paid252-tax-delivery-data-20261015`, port33519, socket `/workspace/runtime/mysql-paid252-tax-delivery-run/mysql.sock` and schema `vaseyaudio_paid252_tax_delivery`. Actual launch/version/ready/identity/stop receipts are archived. Its owned server was stopped with exit0; volume retained and unrelated server untouched. No credentials or private fixture artifacts are committed. Frozen contract directories, original V1 Paid source/migrations and original PaidTaxSchema are byte unchanged against actual PR122 merge; no repin occurred.

The [index](recorded-evidence-index.json) and [archive](recorded-evidence.tar.gz) seal13 original run inventories,2372 deduplicated source blobs and2610 archive members. Archive SHA256 `0b06e6561f4d41d27affe7e68eb5b221dec6f2dfae5159baec47edcf2889f205`, 8851729 bytes; source candidate `f636b4b0814c4664afde7ca71e157c417ea93b97`. Later review/publication records are adjacent documentation-only successors. The index/archive bind each original input inventory to exact deduplicated Git source blobs,16 routes and13 runtime loader hashes. Current candidate inventory is bound separately, with explicit three-policy-only changes from a12; it is not falsely equated to the whole historical input manifest. Historical launcher bytes for the first three runs are reconstructed from an authentically retained predecessor using the recorded transform, match the original launch SHA, and are explicitly labeled reconstruction. Launch commands, runner bytes, outcomes and OS witnesses remain original. A missing `/proc/.../children` metadata probe failed, was retained, and recovered using identity/stat observations without rerunning tests.

## Remaining boundaries and next dependency

Independent full-source/narrow-carry and final sealed evidence decisions must precede exact-head cheap preflight and expected-SHA development merge. No P0/P1 may remain open. Actual merge and both ledgers are recorded afterward; review/source approval alone is not a merge claim.

Next: independently designed default-false typed Tax HTTP/root composition using this domain seam. SQL/isolation/deadlock/trigger/DDL races outside the selected cases, invisible direct-PDO reopen, damaged restores, distributed caches/all-family capacity, uninterruptible helper I/O, representative performance/FPM and real provider/commercial/account/host/activation/final Foundation evidence remain untested. Bounded helpers do not prove immediate in-call cancellation; authority is start-redemption/first-byte, not perpetual reauthorization. Shared V1/Identity descriptor P2 and ordinary membership throwing-afterCommit P2 remain frozen. No Foundation or GitLab dispatch occurred.
