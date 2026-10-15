# Tax255 NEW-write commit admission — 2026-10-14/15

Operative application/test source `d4b331039336450e65fd0263820e60ae6a7ad071`, based on actual PR #119 merge `cd73b3226531abb6f7a79cc779becb61ea5d166a` and its receipt-only successor. NEW order/request/binding/reviewed-payment writes now register original-frame admission after ordinary committing delegates. Configuration, authenticated fixed raw rows, exact singleton/global family selectors and original temporal bounds must remain valid before physical commit. A refusal rolls back only the owned transaction, clears its pending callbacks and preserves already committed provider-family evidence.

Order/request admission uses current buyer/catalog/license authority. Binding/reviewed admission uses historical ownership and retained request/provider context; current password, account-active/access-version or current catalog expiry cannot invalidate historical evidence. Owner-key withdrawal still refuses. Retained observedAt and on_time/paid_exception remain original. Unbound NEW requests and the inclusive provider-lifetime creation boundary remain admitted. Transport identity is captured before provider GETs. [Design](design.md) and the original [independent design checkpoint](independent-design-review.md) describe these distinctions.

MySQL raw capture/terminal proof now uses qualified captured-PDO FOR UPDATE reads instead of ordinary RR snapshot reads. Row-lock waits are capped at min(original,5) seconds and restored on the same PDO; native 1205/1213 map to controlled contention503. Before each NEW insert, all four permanent owned Tax tables are pinned and a separate identified same-server/schema autocommit observer proves structured BASE TABLE/InnoDB metadata. SQLite requires permanent unshadowed tables and a rollback-capable journal. New metadata waits also preserve the caller’s session setting. These guards do not interrupt arbitrary SQL or undo a commit already performed outside the owned anchor.

## Completed current-source checks

Tests/assertions and skips are recorded separately. Every PHP/recorder result below is an observed original exit; final Linux recorder PID/start-tick/UID witnesses independently reconcile those exits. All declared source, HEAD, status, runner and 13 loader hashes remained unchanged during each test invocation.

| Selection | Actual source | Tests/assertions | Skips | PHP / recorder / observed recorder OS exit |
| --- | --- | --- | --- | --- |
| Whole12 affected Tax255 files, SQLite | `d4b33103` | 176/1273 | 23 | 0/0/0 |
| Native peer-commit snapshot and raw row-wait regressions | `d4b33103` | 2/31 | 0 | 0/0/0 |
| Fresh native commit/config/history/temporal/transport selection | `d4b33103` | 11/130 | 0 | 0/0/0 |
| Native damaged-engine refusal, all four NEW phases | `38dea396` | 4/45 | 0 | 0/0/0 |

SQLite’s176 reported cases are153 executed plus23 exact reviewed native-only skips: six expanded cases in the new file (four engine cases and two native read/wait methods),14 native installer cases and three native schema cases. The exact case identities reconcile against the current skip policy. The two historical-owner and two global-provider-reuse datasets are deliberately SQLite-only and registered in the reciprocal MySQL skip policy; the targeted native selections exclude them and have zero skips. Inventory allowances are not executed tests.

The engine result remains an earlier-source receipt. Its carry is narrow: the damaged target operation’s unchanged TaxCheckoutRecords pre-DML refusal path executes before its repaired Tax raw helper is invoked. The engine assertion and fixture method bodies are byte-identical, but healthy setup operations themselves can invoke RawPlans. The complete four-case engine fixture was not re-executed at d4; this is target-refusal-path carry, not whole-invocation or whole-application equality. The earlier native11/130 at 9b2 also remains its original receipt; it is superseded for current behavior by the fresh d4 selection, because the MySQL helper changed. Focused selections overlap; they are not full Foundation acceptance.

The native snapshot regression records the actual active transaction as REPEATABLE READ through performance_schema. A distinct identified same-server/schema peer commits a valid sealed synthetic binding before global capture, after the owned frame seeded an earlier empty snapshot. The pre-operation damaged fixture removes global uniqueness. Old source commits the target incorrectly; repaired source refuses and preserves the independently committed peer. This demonstrates currentness at capture, not a post-capture phantom or two complete application writers racing. The wait regression is a raw-helper primitive in a real owned CommandTransaction with staged unrelated work and an afterCommit callback; it proves bounded1205 refusal, timeout117 restoration, peer transaction survival, owned rollback/callback cleanup and a positive read after release. It is not a complete NEW-service-write contention case, and1213 deadlock behavior is source-reviewed rather than executed here.

Real monotonic live-license tests sleep5.1 seconds inside an ordinary committing delegate while test wall time stays fixed. Order and request run on SQLite; the native selection includes order. They prove expiry refusal, rollback and callback cleanup with a new valid admission retry. Other late paid_exception/boundary fixtures deliberately move synthetic wall time; they do not prove host elapsed-time or provider behavior.

## Exact commands and original failures

The archive contains each exact launcher argv, PHP argv/environment scope, command record, log, JUnit, process.exit and recorder result. The following are the actual current-source recorder commands, with the documented toolchain activated; existing names refuse overwrite. They are historical invocations, not instructions to replace receipts.

```sh
source /workspace/.va-studio-toolchain/activate.sh
python3 /workspace/runtime/tax-write-admission-evidence-20261014/run-focused.py --name sqlite-tax-write-affected-current-raw-proof --driver sqlite --timeout 2400 tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutCommitAdmissionTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutFrozenV1Test.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutGuardTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutHttpBoundaryTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutJourneyTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutMigrationTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutNativeInstallerTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutNativeSchemaTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutPolicyTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxPaidLineAdapterV2Test.php tests/Feature/ProductionTaxCheckout/ProductionTaxPaidSourceAdmissionTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxSourceV2Test.php
python3 /workspace/runtime/tax-write-admission-evidence-20261014/run-focused.py --name mysql-tax-write-current-raw-proof-fixed --driver mysql --timeout 1800 --filter 'test_native_global_binding_capture|test_native_raw_plan_row_wait' tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutCommitAdmissionTest.php
python3 /workspace/runtime/tax-write-admission-evidence-20261014/run-focused.py --name mysql-tax-write-commit-and-history-current-raw-proof --driver mysql --timeout 3600 --filter 'test_late_configuration|test_historical_provider.*reviewed|test_late_paid_exception|test_new_request_preserves|test_live_current_license_cap.*order|test_paid_review_retains|test_current_credential.*(order catalog|request credential)' tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutCommitAdmissionTest.php
```

All original invocation outcomes follow; short source labels resolve to exact40-character SHAs in the index and command records. Assertions after a stop-on-defect failure were not executed.

| Original invocation | Actual head | Tests/assertions | Failures / errors / skips | PHP / recorder |
| --- | --- | --- | --- | --- |
| `mysql-tax-write-commit-and-history-current-raw-proof` | `d4b33103` | 11/130 | 0/0/0 | 0/0 |
| `mysql-tax-write-commit-and-history-final` | `9b2f2e2e` | 11/130 | 0/0/0 | 0/0 |
| `mysql-tax-write-current-raw-proof-fixed` | `d4b33103` | 2/31 | 0/0/0 | 0/0 |
| `mysql-tax-write-damaged-engine-correct-status` | `38dea396` | 4/45 | 0/0/0 | 0/0 |
| `mysql-tax-write-damaged-engine` | `868b1a34` | 1/5 | 1/0/0 | 1/1 |
| `mysql-tax-write-global-snapshot-old-raw-source` | `fea23dea` | 1/9 | 1/0/0 | 1/1 |
| `mysql-tax-write-row-wait-old-raw-source` | `fea23dea` | 1/2 | 1/0/0 | 1/1 |
| `sqlite-binding-owner-before-review-repair-transactional-guard` | `52d637ed` | 1/4 | 1/0/0 | 1/1 |
| `sqlite-boundary-before-review-repair-corrected` | `88649ca2` | 1/2 | 1/0/0 | 1/1 |
| `sqlite-boundary-before-review-repair` | `3d85b23d` | 1/1 | 0/1/0 | 2/1 |
| `sqlite-four-new-write-config-first-fix` | `c2652593` | 4/60 | 0/0/0 | 0/0 |
| `sqlite-new-binding-config-old-source` | `e1968ced` | 1/4 | 1/0/0 | 1/1 |
| `sqlite-new-order-config-first-fix` | `c2652593` | 1/14 | 0/0/0 | 0/0 |
| `sqlite-new-order-config-old-source` | `e1968ced` | 1/3 | 1/0/0 | 1/1 |
| `sqlite-new-request-config-old-source` | `e1968ced` | 1/3 | 1/0/0 | 1/1 |
| `sqlite-new-reviewed-config-old-source` | `e1968ced` | 1/3 | 1/0/0 | 1/1 |
| `sqlite-owner-and-boundary-after-review-repair-correct-status` | `335c1d46` | 3/30 | 0/0/0 | 0/0 |
| `sqlite-owner-and-boundary-after-review-repair` | `454314b4` | 1/6 | 1/0/0 | 1/1 |
| `sqlite-owner-and-boundary-before-review-repair` | `b9b36745` | 1/2 | 0/1/0 | 2/1 |
| `sqlite-owner-before-review-repair-restored-fixture` | `3d85b23d` | 1/3 | 1/0/0 | 1/1 |
| `sqlite-reviewed-owner-before-review-repair-transactional-guard` | `52d637ed` | 1/3 | 1/0/0 | 1/1 |
| `sqlite-reviewed-owner-before-review-repair` | `88649ca2` | 1/2 | 1/0/0 | 1/1 |
| `sqlite-tax-write-affected-current-raw-proof` | `d4b33103` | 176/1273 | 0/0/23 | 0/0 |
| `sqlite-tax-write-affected-final` | `9b2f2e2e` | 174/1273 | 0/0/21 | 0/0 |
| `sqlite-tax-write-global-and-live-cap-final` | `9b2f2e2e` | 4/57 | 0/0/0 | 0/0 |
| `sqlite-tax-write-historical-first-source` | `1262a717` | 3/28 | 0/0/0 | 0/0 |
| `sqlite-tax-write-live-license-cap` | `38dea396` | 1/13 | 1/0/0 | 1/1 |
| `sqlite-tax-write-withdrawal-and-transport` | `868b1a34` | 5/53 | 0/0/0 | 0/0 |

Nine original application-gap oracles fail with PHP/recorder1/1, one failure each and no errors/skips: four Tax-config phase gaps on e196, two historical-owner gaps on52d, request-boundary refusal on886, plus snapshot1/9 and unbounded-wait1/2 at fea. The fea application bytes equal9b2; only native test methods/skip pairs were added. These are genuine behavior failures, rather than API/type errors.

Seven fixture/assertion failures remain separate: an intact immutable owner guard prevented mutation (PHP2/recorder1); two removed-guard setups refused before the committing callback; a malformed assertion treated prose as a database connection (PHP2/recorder1); two status assertions expected503 instead of the established raw/schema409; and a duplicate-email retry fixture timed out waiting for SMTP after the live expiry/rollback assertions. Their correction did not overwrite any original record, and they are not presented as application-gap proofs. Corrected fixture/source runs and valid retries are distinct rows above.

## Private runtime and execution-error reconciliation

PHP 8.4.26 / PHPUnit 12.5.34; private MySQL 8.4.11 with observed UTC+00:00 and strict SQL mode. Owned container `va-studio-private-mysql-tax-write-20261014`, private named datadir `va-studio-private-mysql-tax-write-data-20261014`, loopback port 33516, socket `/workspace/runtime/mysql-tax-write-run/mysql.sock`, schema `vaseyaudio_tax_write_admission`. Native recorders run sequentially on this schema. The server was stopped after all native results with `docker stop --timeout 10`, observed exit0; its datadir/evidence remain and older servers were untouched. SQL initialization stdout/exit was saved before the fallible host socket probe; only the owned socket directory’s mode was corrected before probing. Version, launch, readiness and stop receipts are archived.

Two independent read-only process-creation attempts were rejected with an exec-server transport disconnect before either shell was created; a later observation succeeded while the original recorder continued. No account/session limit or cybersecurity-classifier cause was inferred. Separately, this worktree’s vendor/bin symlink selected another worktree’s Composer loader and broke the standard discovery CLI with a duplicate initializer. Exact shared proxy bytes were copied into this worktree’s owned ignored vendor/bin directory; shared packages and 13 own loaders remained unchanged. The original failure, temporary own-autoload discovery adapter, proxy repair and later normal-CLI success remain distinct receipts. A first runtime-only skip comparison used JUnit’s dotted namespace directly and refused before writing a record; corrected namespace normalization proves the exact skip pairs. That preparation error is preserved separately from tests.

Six changed PHP files pass original `vendor/bin/pint --test` with exit0 and unchanged before/after bytes at d4. The database census 52 policy self-tests passed at 9b2 and again on the current d4 registry/source. Atd4, the normal unmodified `python3 scripts/ci/phpunit-shards.py --shards=2 --prefix=phpunit-ci-mysql --mysql-native-selection` succeeds: 8,765 discovered cases/650 files,2,225 selected/220 files, disjoint1,113+1,112 cases in110+110 complete files, source groups preserved. This is discovery/partition only, not database execution. The earlier adapter generation had8,763/650 and2,223/220. Both six-output generations are archived by exact recorded hash and only their owned ignored root outputs were removed. Workflow census comments are refreshed to the new observed counts without changing gates or dispatch behavior.

## Portable evidence and review boundary

[Archive](recorded-evidence.tar.gz) SHA256 `b35525aebbd244c18ba91bfba0a1f648808bacf8850510dbb4b8a52cb35e27f0`; 9,040,998 bytes, 2646 members. [Index](recorded-evidence-index.json) binds 28 actual PHPUnit invocations, 18 Git-bound source/runtime inventories and 2307 deduplicated exact Git blob byte objects. Inventory manifests map each path to `source-blobs/<sha256>`; these are portable source bytes, not merely metadata called a Git bundle. All 2,344 current declared inputs include 16 routes;13 loader hashes and every archive member hash reconcile. Original review checkpoints and packager/finalizer/startup/census helper bytes are retained.

Separate non-author checkpoints requested the owner/equality and native current-read repairs; their original REVISE/PARTIAL notes remain unchanged in the archive. The repaired-source checkpoint establishes no open P0/P1 or additional P2, but is not a final decision. Final sealed-packet DECISION on the actual candidate, exact-head receipt carry and cheap published-head preflight/protections remain merge gates; the lead never self-approves.

Current locking reads do not independently prove effective transaction isolation. Gap exclusion through physical commit requires actual RR/Serializable next-key behavior without one-transaction isolation overrides, or verified intact canonical uniqueness. No RC phantom-safety, complete damaged-global restore, arbitrary external-trigger rollback or no-overlapping-DDL guarantee is claimed. The 1205 test does not establish a 1213 deadlock execution, general SQL interruption or whole-operation wall-time budget. Unexpected-trigger/index inventory, existing-request pre-create READ/final-I/O admission, V2 committed-source/terminal consumer proof, forward Paid252 schema/consumer wiring and root registration remain open reviewed work. The next coherent dependency is V2 committed-source/Paid252 consumer integration, including its terminal after-use proof and remaining I/O admission.

Tax255 remains default-off, unmounted/unregistered and test-funds only, with the refusing transport unchanged. Frozen V1/common capsules, migrations, profile members, SDK/API pins, logo and root routes/flags are unchanged. No Foundation/GitLab dispatch, whole-suite acceptance, live credentials/payment, provider/merchant/tax/host verification, active customer import, production deploy/DNS/cutover or activation is authorized or claimed. The adjacent ordinary membership afterCommit outcome P2 stays frozen for its related batch with an owned positive physical-commit witness and genuine throwing-callback test.

Local comment-refresh verification at `8cf6c60ca8d26cd515369dcc36122ec22a10945e`: workflow cadence14 and CI routing27 Python checks pass with observed exit0 and unchanged CI/script hashes. The original [receipt](ci-policy-self-tests.json) is separate from the already sealed test archive; no database or hosted workflow was executed by these checks.

Separate non-author final APPROVE WITH CONDITIONS at exact clean `ad4bf751a3302a816eda49dd7951000610fe736c`, with no open P0/P1 or required product/packet correction. The unchanged [DECISION](independent-review-DECISION.md) and [verification report](independent-packet-verification.json) bind the complete source/evidence checks, original failures and conditions. Exact publication-head carry and cheap preflight/protections remain before merge; all release and next-integration limits remain open.
