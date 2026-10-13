# Commands, source identities and retained outcomes

This is an **interim packet**, not final acceptance. The native first-award commit/replay canary now passes at source matching application `bf498055` and selected test/support bytes in `904f08d5`: **1 test, 15 assertions, zero failures/errors/skips**. Its broad source check separately failed for an unrelated deadline-test cleanup; the exact failure and subsequent relevant-source verification are retained below. Native schema selection and race completion, fresh final-source review and exact-head preflight remain pending at this append. Earlier deadline errors remain visible. The full review of `307699b6` says **REQUEST CHANGES**; the later `9ec09073` review approves only the four-file entry/gate correction.

`source-map.json` records completed JUnit selections, every observed case status, shell exit files, source identities and their limits. `evidence/artifact-digests.json` pins the archived bytes. Earlier failures, fixture corrections and incomplete setup attempts remain visible. An output filename alone is not used to establish a tested checkout.

## Invocation records

The original command notes for subscription work and CI selection discovery are retained as [subscription-commands.txt](evidence/subscription-commands.txt) and [selection-commands.txt](evidence/selection-commands.txt). The completed native retention selection has its original runner [native-retained-upgrades-approval.sh](evidence/native-retained-upgrades-approval.sh). These are distinguishable from the **reconstructed reproducible commands** below: root's other original complete shell invocations were not archived. Their source/selection records come from the development lead's checkpoint, corroborated by retained JUnit case identities; unrecorded flags or invocation spelling are not invented.

Known common toolchain: `source /workspace/.va-studio-toolchain/activate.sh`, PHP **8.4.26**, PHPUnit **12.5.34**. Working directory: `/workspace/VA-Studio-membership-award`.

```sh
php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' --
```

Common environment from the lead's checkpoint:

```sh
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false \
DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
```

SQLite adds `DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_SOCKET=`. Native work adds:

```sh
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33413 DB_USERNAME=root DB_PASSWORD= \
DB_DATABASE=vaseyaudio_membership_award DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock
```

This database is private local synthetic test storage. Its recorded server is **MySQL 8.4.11**, shown independently in [the metadata probe output](evidence/native-trigger-metadata-probe.txt). Container: `va-studio-private-mysql-policy-20261013`; private datadir `/workspace/runtime/mysql-policy-data`; host loopback port `33413`; private host socket above. Creation recipe and pinned image are retained in the earlier [PR111 packet](../membership-policy-20261013/commands.md#private-mysql). This packet does not claim host or provider verification.

For the reconstructed commands, append the table's selection arguments to the PHP entry point with the appropriate environment and `--log-junit /workspace/runtime/membership-award-evidence/NAME.xml`, directing output to the corresponding NAME.txt. This reproduces the recorded selection; it is not a claim that this precise shell string or argument order was originally executed. Actual selected case identities remain in JUnit and `source-map.json`.

In the table, `B/` denotes `tests/Feature/ProductionMembershipBilling/`. Backslashes preceding `|` only escape Markdown table separators; the regex contains ordinary alternatives.

| NAME | Recorded source | Reconstructed selection arguments | Recorded result: tests / assertions; skips; failures/errors; exit |
| --- | --- | --- | --- |
| paid-time-red | Test `84642d05`, unchanged PR111 application | `B/BillingPaidTimeTest.php` | 11 / 13; 0; 11 failures; 1 |
| paid-time-green | `8eba3c77` | `B/BillingPaidTimeTest.php B/BillingSettlementTest.php B/StripeSdkBillingGatewayTest.php B/BillingProviderPinTest.php` | 63 / 209; 0; 0; 0 |
| invoice-canary | Precommit blobs reported to become `307699b6`; complete precommit source manifest unavailable | `B/MembershipPaidInvoiceProofTest.php --filter test_real_retained_billing_proves_original_buyer_and_provider_paid_time` | 1 / 9; 0; 0; 0 |
| award-canary-307699b6 | `307699b6` | `B/MembershipFirstAwardTest.php --filter test_owned_award_commits_one_period_and_one_event_and_replays_the_original_result` | 1 / 15; 0; 0; 0 |
| integrated-sqlite-307699b6 | `307699b6` | `B/MembershipPaidInvoiceProofTest.php B/MembershipFirstAwardTest.php` | 16 / 120; 0; 1 failure; 1 |
| after-commit-fixture-correction | Test `04b2699a`, application `307699b6` | `B/MembershipFirstAwardTest.php --filter test_after_commit_owner_change_refuses_success_but_does_not_claim_rollback` | 1 / 10; 0; 0; 0 |
| native-fence-canary-307699b6 | Application/test source `307699b6` | `B/BillingAwardFenceTest.php --filter test_exact_retained_six_table_upgrade_is_idempotent_and_keeps_every_row` | 1 / 4; 0; 0; 0 |
| native-fence-definition-red | Test `04b2699a`, application `307699b6` | `B/BillingAwardFenceDefinitionTest.php --filter test_old_catalog_snapshot_cannot_admit_a_fence_moved_to_myisam_before_its_open` | 1 / 4; 0; 1 failure; 1 |
| native-fence-guard-red | Test `aa85e782`, application `307699b6` | `B/BillingAwardFenceDefinitionTest.php --filter test_old_trigger_snapshot_cannot_admit_a_removed_current_hint_guard` | 1 / 3; 0; 1 failure; 1 |
| native-fence-guard-red-v2 | Test `3d2197c0`, application `307699b6` | Same guard method | 1 / 5; 0; 1 failure; 1 |
| observation-tail-red | Test `0523aa79`, application `307699b6` | `B/MembershipPaidInvoiceProofTest.php --filter test_existing_proof_refuses_a_new_observation_even_with_identical_facts_and_second` | 1 / 3; 0; 1 failure; 1 |
| integrated-sqlite-9302e1c0 | `9302e1c0` | `B/MembershipPaidInvoiceProofTest.php B/MembershipFirstAwardTest.php B/BillingSubscriptionApprovalTest.php B/BillingAwardFenceTest.php` | 35 / 231; 0; 0; 0 |
| native-current-and-award-9302e1c0 | `9302e1c0` | `B/BillingAwardFenceDefinitionTest.php B/MembershipFirstAwardTest.php --filter 'test_old_.*snapshot\|test_owned_award' --stop-on-error --stop-on-failure` | 3 / 14; 0; 1 error; 2 |
| native-retained-upgrades-approval | Relevant source unchanged from `a1b91e13`, application `9302e1c0` | Exact retained runner; three retention/approval cases | 3 / 25; 0; 0; 0 |
| billing-sqlite-a1b91e13 | Checkout `a1b91e13`, application `9302e1c0` | `tests/Feature/ProductionMembershipBilling` | 255 / 1354; 12; 0; 0 |

The initial guard test failed on its assumption that `SHOW TRIGGERS` is current. It is **not valid red evidence of application admission**. The corrected guard test uses named `SHOW CREATE TRIGGER` to establish current absence and then reaches the application's incorrect admission. The engine and observation-tail red runs reach their intended application assertions. The initial integrated after-commit failure was a test fixture missing the retained account guard's required access-version increment; the correction changed that fixture only.

## Precommit and intermediate results

The subscription command record preserves exact commands and both test-only assertion corrections. Its first run failed because canonical JSON reorders associative keys; its later guard-removal test initially expected `schema` instead of the existing `schema_prefix`. The compatibility attempt during an incomplete shared schema edit failed in migration setup because `BillingAwardFence` did not exist yet. None of those failures is suppressed or described as a passing production regression test.

| NAME | Tests / assertions | Failures / errors / skips | Exit | Source limit |
| --- | --- | --- | --- | --- |
| subscription-canary | 1 / 3 | 1 / 0 / 0 | 1 | Precommit; no complete working source snapshot |
| subscription-sqlite-first | 10 / 66 | 0 / 0 / 0 | 0 | Partial file hashes; predates final schema reproof and eleventh test |
| subscription-compatibility | 25 / 0 | 0 / 25 / 0 | 2 | Concurrent incomplete schema edit; no application assertions ran |
| subscription-final-canary | 1 / 9 | 0 / 0 / 0 | 0 | Precommit; complete source identity unavailable |
| subscription-final | 10 / 64 | 1 / 0 / 0 | 1 | Partial file hashes; fail-fast test-only reason mismatch |
| subscription-final-v2 | 26 / 130 | 0 / 0 / 0 | 0 | Partial file hashes; eleven approval and fifteen existing observation cases |
| fence-sqlite-1 | 7 / 34 | 0 / 0 / 0 | 0 | Four recorded file hashes, not a complete checkout identity |

The fence selection's exact original invocation was not retained. Its reproducible selection is `B/BillingAwardFenceTest.php`; its four file hashes are preserved in [fence-source-sha256.txt](evidence/fence-source-sha256.txt). Passing precommit cases support intermediate development only. Their incomplete source identities are not silently promoted into exact-head final verification.

## Diagnostic and policy-only commands

[The native metadata probe](evidence/native-trigger-metadata-probe.php) and its output are retained. A reproducible invocation is `php /workspace/runtime/membership-award-evidence/native-trigger-metadata-probe.php` under the activated PHP toolchain, against its disposable `vaseyaudio_award_ddl_probe` schema. The original complete shell invocation and shell exit were not archived. It records an old trigger inventory from ordinary dictionary reads and `SHOW TRIGGERS`; named `SHOW CREATE TRIGGER` returns the current definition and reports 1360 for a dropped known trigger. The probe is not a PHPUnit pass or a provider claim.

```sh
python3 scripts/ci/test-focused-tests.py
python3 scripts/ci/test-phpunit-shards.py
DB_CONNECTION=mysql python3 scripts/ci/phpunit-shards.py --shards 8 \
  --prefix phpunit-ci-mysql --mysql-native-selection \
  --timings scripts/ci/phpunit-timings-mysql.json
```

Exact discovery environment/commands are in `evidence/selection-commands.txt`. The first two commands passed **50** and **52** Python tests. Discovery proved **1921 selected cases / 178 files**, from **7891 cases / 575 files**, using PHPUnit `--list-tests-xml` only. Local discovery used eight shards; the hosted workflow remains 24 shards. Fifteen untimed files retained existing fallback weights. These counts apply to the recorded selection source and can change with subsequent tests.

The large derivative source/shard listings remain in runtime storage; their exact hashes and sizes are archived in `evidence/selection-listing-digests.json`. The discovery manifest, policy hashes and compact source summary are included. All completed functional-test logs/XML/exits, including failed attempts, are archived without truncation.

## Pending evidence

`native-retained-upgrades-approval` has completed: **3 tests, 25 assertions, zero failures/errors/skips, exit 0**. Its exact runner and completed receipts are retained. The lead confirms the selected retention/approval source remained unchanged from `a1b91e13` while unrelated award-entry files were being edited; this is not a clean-worktree claim for the entire running interval. The current-definition selection independently contains five guard assertions and four engine assertions in its two passing cases; its erroring award case contributes the remaining five assertions. Ownership red/green evidence is now retained below. Native deadline resolution, native races, fresh independent review and exact-head preflight remain pending. No Foundation or GitLab workflow was dispatched by this packet preparation.


## Ownership entry and complete registration baseline

The exact invocation and source notes are retained in [ownership-commands.md](evidence/ownership-commands.md). All four runs used the stated SQLite environment and direct PHPUnit entry point. Source capsules under each `evidence/ownership-*-source/` directory match their original `.sources` SHA256 receipts. Earlier intermediate files were reconstructed by the implementation agent, then verified against the hashes captured before each run; packet assembly independently verified every archived source file again.

| NAME | Recorded source | Tests / assertions | Failures / errors / skips | Observed exit |
| --- | --- | --- | --- | --- |
| ownership-entry-red | Unchanged `a1b91e13` application plus the initial two-test source capsule | 2 / 10 | 2 / 0 / 0 | 1 |
| ownership-gate-red | Base `a1b91e13` plus uncommitted permit-only intermediate identified by source hashes | 1 / 8 | 1 / 0 / 0 | 1 |
| ownership-transient-red | Base `a1b91e13` plus uncommitted second-`current()` intermediate identified by source hashes | 1 / 9 | 1 / 0 / 0 | 1 |
| ownership-entry-green | Four application/test override hashes independently verified equal to commit `9ec090737e66e7f89075600cca96a6174ef2076d` | 15 / 141 | 0 / 0 / 0 | 0 |

The first red shows direct context construction and a second context created by an invoice callback could commit effects outside the intended owned invocation. The subsequent two reds distinguish incomplete gate-order repairs: removing a capability before capture, then temporarily adding/removing it around repeated gate checks. Neither uncommitted intermediate is assigned a fictitious commit. The green selection covers all six final ownership cases and the nine existing first-award cases. It does not establish native performance or race behavior; the ten-second deadline remains unchanged.

## Native deadline diagnostic

The isolated profile's exact commands, limits and interpretation are retained in [native-award-profile-a1b91e13-commands.md](evidence/native-award-profile-a1b91e13-commands.md). It ran the archived diagnostic test against a separate clean `a1b91e13` worktree and private schema `vaseyaudio_membership_award_profile`. Packet assembly independently compared all **362** before/after application source hashes to that commit, and verified the diagnostic test hash.

Observed result: **1 test, 8 assertions, 1 error, zero failures/skips, exit 2**. The unchanged owned award refused with `operation_deadline` after **15.430449810 seconds**, before effects; durable periods and award events were both zero, and the connection was outside a transaction. This remains a failing normal award attempt measured by a diagnostic, never an acceptance pass.

The award-window native thread reported 11,180 prepares and 11,590 prepared executions. Its separate count of 66 SQL event-name records represents transaction controls, not the total database queries. Long statement history was disabled, so no call-site digest history is claimed. The fallback digest TSV covers the entire process, including migration and fixture setup, and cannot be treated as award-only attribution. A subsequent optimization must retain current metadata/shape checks and the original deadline; no cache or larger budget is approved by this measurement.


## Uncommitted metadata batching variants

Both native canaries use application base `9ec090737e66e7f89075600cca96a6174ef2076d` plus frozen changes to `MembershipSchema.php`, `BillingSchema.php` and new `MembershipSchemaCatalog.php`. The exact original patch and pre-run SHA256 inventory for each variant are retained. Packet assembly applied each patch to the committed base in a disposable directory and independently matched all three reconstructed files to their receipts; results are recorded in [native-batch-patch-verification.json](evidence/native-batch-patch-verification.json). These variants are uncommitted source identities, not new commit SHAs.

| NAME | Variant / exact command record | Tests / assertions | Failures / errors / skips | Observed exit |
| --- | --- | --- | --- | --- |
| sqlite-schema-batch | `native-batch-1`; [retained runner](evidence/sqlite-schema-batch.sh) and post-run source check | 49 / 298 | 0 / 0 / 3 | 0 |
| native-batch-1 | First patch and hash receipt; [native command record](evidence/native-batch-commands.md) | 1 / 0 | 0 / 1 / 0 | 2 |
| native-batch-2 | Second patch and hash receipt; same native command record | 1 / 5 | 0 / 1 / 0 | 2 |

The SQLite run covers `MembershipSchemaPreparationTest`, `BillingSchemaPreparationTest`, `MembershipPaidInvoiceProofTest`, `MembershipFirstAwardTest` and `MembershipAwardOwnershipTest`. Its three skipped cases are the native foreign-check reservation, foreign table-local unique namespace and changed-enum-check methods in `MembershipSchemaPreparationTest`. The runner records all three metadata source hashes before execution and verifies them afterwards. Those hashes match **batch 1**, so this pass is not transferred to batch 2 or any later optimization.

Native batch 1 errors during migration setup: `array_all` passes both value and key, while the supplied built-in `is_string` accepts one argument. No award runs, and no performance improvement follows from that attempt. Batch 2 uses the explicit callback correction and positive native table opens before batched metadata inspection. Setup completes, but the owned-award canary still errors with **`operation_deadline`**, before first inserts. Its 133.638-second elapsed test time includes migrations and fixture preparation; it is not an award-duration measurement. Both runs retain their original errors and exit 2. There is still no successful native first-award acceptance.

[final-pint-command.json](evidence/final-pint-command.json) retains the exact `php vendor/bin/pint --test` argument array and SHA256 map for **33 PHP files**. The check returned exit 0. Packet assembly verified every file against the committed base plus the batch-2 patch. The run label `final-pint` names this formatting receipt; it is not a final-candidate or runtime acceptance claim.

The [entry correction review](evidence/review-entry-9ec09073.md) records **APPROVE the entry/gate correction only, with no remaining P0/P1 in that four-file delta**, at exact commit `9ec090737e66e7f89075600cca96a6174ef2076d`. The reviewer explicitly leaves metadata/performance edits, native completion and final evidence review unapproved. Root's corresponding [independent entry review](independent-entry-review.md) remains unchanged.

The additional isolated function-timing profile has completed and is retained below. Source limits for all earlier precommit runs remain unchanged. No product code, test code, CI policy, README or ledger was edited while assembling this evidence.


## Native function timing diagnostic at the original deadline

The [exact diagnostic command and interpretation](evidence/native-function-profile-commands.md), [original release receipt](evidence/native-function-profile-evidence-sha256.txt), external test, full report, log, XML and exit are retained. The separate profile worktree was based on `36fc22d0d94585ba13724e14093ceabc725c762a`, with the exact uncommitted `native-batch-2` metadata patch and three diagnostic timing wrappers. Packet assembly reconstructed that source in a disposable directory and independently matched **all 363 recorded app/tests/Support hashes** to both before/after maps. All three original unwrapped files and instrumented pairs match their receipts; the [source verification record](evidence/native-function-profile-source-verification.json) records those checks. This identity includes diagnostic instrumentation and does not claim an unmodified commit or complete environment/vendor inventory.

On private MySQL **8.4.11**, schema `vaseyaudio_membership_award_profile`, the observed result was **1 test, 8 assertions, 1 error, zero failures/skips, exit 2**. The original **ten-second bound** remained unchanged. The public award took **13.841690479 seconds** and refused `operation_deadline` in `MembershipAwardContext::proveSources`, before award effects. Durable periods and award events were both **zero**; Laravel transaction level was zero and PDO reported no transaction. The log's **119.296-second** total includes migrations and fixture setup. This is a failing diagnostic, not a native award acceptance pass.

| Function | Calls | Inclusive seconds |
| --- | ---: | ---: |
| `IdentityMigrationOwnership::inspect` | 64 | 11.096746386 |
| `MembershipSchema::up` | 35 | 8.096291572 |
| `BillingSchema::inspect` | 5 | 1.574834307 |

These function timers are **inclusive and nested; never sum them**. Identity inspection accounts for approximately 80.17% of the measured public interval, which supports investigating repeated identity checks. The measurement does not attribute individual SQL call sites or establish performance acceptance.

The thread deltas record **9,110 prepares**, **9,110 prepared executions** and **9,110 close-statement events**. Their server times are 6.039507858, 6.716369495 and 0.204876944 seconds respectively. The separate 66 SQL events are transaction/savepoint controls, not a total query count. Protocol and nested categories can overlap; completion of the summary baseline can appear in the deltas. Long statement history was disabled, so no complete digest history is claimed. The read-only [object census](evidence/native-function-profile-object-census.tsv) did not support the proposed explanation of many stale application schemas amplifying inspection cost.

At this diagnostic checkpoint, positive native award acceptance and the concurrency race proofs remained untested. No later source or budget revision inherits this measurement as a pass.


## Admission deadline correction and preserved failed attempts

The [exact command/source record](evidence/admission-budget-commands.md) and all three source capsules, logs, XML and exits are retained. The production operation now captures one **sixty-second admission deadline** before connection callbacks, source resolution, waits, writes, commit delegates and receipt verification. A newer producer lease cannot renew it. Blocking calls are not interrupted merely by this deadline; subsequent checks refuse admission after elapsed time. Synthetic offsets in these tests establish deadline behavior, not host timing or native performance.

| Run | Tests / assertions | Failures / errors / skips | Observed exit |
| --- | --- | --- | --- |
| `admission-budget-red` | 1 / 6 | 1 / 0 / 0 | 1 |
| `admission-budget-green` | 18 / 176 | 0 / 1 / 0 | 2 |
| `admission-budget-green-v2` | 18 / 176 | 0 / 0 / 0 | 0 |

The red retains the unchanged ten-second `MembershipAwards.php` from `36fc22d0` and the initial new test/shim; twenty synthetic seconds correctly reaches the intended refusal assertion. Its three-file receipt is not a complete working-tree inventory. The first green run completes the new assertions but errors during teardown because a test wrapper retained a `MembershipRows`/SQLite pin and VACUUM reports `SQL statements in progress`. That failed attempt is preserved. Only test cleanup changed for v2; its application and clock shim are identical to the first green. All five v2 receipt/source-capsule hashes match `904f08d55fe832aacb37b76cbd04bd0d8a02d314`.

The final 18 cases comprise three deadline checks, six ownership checks and nine existing first-award checks. They cover admission after twenty synthetic seconds, expiry at forty plus twenty-one seconds despite a newer valid invoice lease, and a refused receipt after a sixty-one-second post-commit advance while the durable period/event remain present. The deadline test/helper are test-only and introduce no production clock hook. These SQLite cases do not establish native race behavior.

## Billing parent-floor compatibility probes

The [retained notes](evidence/sqlite-billing-parent-floor-notes.md), [runner](evidence/sqlite-billing-parent-floor.sh), original [initial runner](evidence/sqlite-billing-parent-floor-initial.sh), both runtime-only test sources and source checks are archived. The initial runner was copied before the output files were renamed with `-initial`; its literal paths use the original unsuffixed basename. The initial runtime fixture removed an interior identity guard and reached the earlier `LogicException`, resulting in **3 tests, 12 assertions, 1 error, zero failures/skips, exit 2**. Product bytes did not change before correcting that fixture to remove the final empty identity guard.

The corrected SQLite run passes **3 tests, 15 assertions, zero failures/errors/skips, exit 0**: two external runtime probes and the existing fresh-schema retry case. It confirms the delegated missing-parent refusal remains `BillingException(parent_floor)` without repair or writes, while the unrelated temporary-membership-table refusal retains `MembershipException(shadowed_schema)` and its marker. The three product file hashes match both `bf498055` and `904f08d5`; the fourth receipt binds the external probe. These two external cases are excluded from the committed-test census and are not native verification.

## Native first-award canary and source-check exception

The [exact runner](evidence/native-final-canary.sh), [pre-run 1,841-file inventory](evidence/native-final-canary.sources.json), tracked diff, logs, XML and captured PHPUnit exit are retained. The command selects only `MembershipFirstAwardTest::test_owned_award_commits_one_period_and_one_event_and_replays_the_original_result` on the private MySQL server described above. It passes **1 test, 15 assertions, zero failures/errors/skips, PHPUnit exit 0**. Its 161.391-second whole-test time includes migrations, fixtures, first award and replay; it is not a single-operation duration.

The original broad post-run check **failed**: [its retained result](evidence/native-final-canary.source-check.json) records a change to `MembershipAwardDeadlineTest.php`, and the runner's Python `assert not changed` raised `AssertionError`. This failure is not relabeled green. No separate exit file for that Python check was retained; the runner has no `errexit` and returns the previously captured PHPUnit result after the check. The PHPUnit exit and source verification outcome are distinct.

[Independent source comparison](evidence/native-final-canary-relevant-source-verification.json) verifies all **1,840 other inventoried files** against `904f08d5`, including **1,177** application, migration, support, base-test and selected-test files. Of that broader relevant set, **1,176** also match application commit `bf498055`; the sole additional clock helper was uncommitted there and matches `904f08d5`. The changed deadline test is outside the explicit PHPUnit file selection and is not loaded by that selected test or its shared fixtures; it alone loads the synthetic clock shim. This conclusion follows from the retained command and static source references, not an invented runtime include trace. No functional rerun or whole-worktree immutability is claimed.

This is positive acceptance of the selected commit/replay path. The two timing profiles remain **erroring diagnostics**, each **1 test / 8 assertions / 1 error / exit 2**, and are not counted as acceptance. Earlier setup errors, native red regressions and the two native metadata passes remain recorded at their own source identities. The still-unfinished final native schema selection and the pending race cases contribute no passing counts here.

## Final deadline-case discovery and formatting receipts

The [selection command record](evidence/selection-deadline-commands.md) retains the exact commands and environment. Policy suites pass **50** and **52** Python tests. Final PHPUnit listing discovers **1,930 selected cases / 180 files** from **7,900 total cases / 577 files**, with eight nonempty local listing shards and the three deadline methods selected exactly once. All recorded commands return exit 0. These are `--list-tests-xml` discovery results; no functional test or migration ran during discovery. Hosted configuration remains 24 shards, with unchanged triggers and cost policy; 17 untimed files retain existing fallback weights.

The first census also returned exit 0, but its post-listing source check detected the deadline-test cleanup. Its [superseded status](evidence/selection-deadline-initial/status.json), source receipt, log, exit and compact manifest remain archived. Final discovery repeated the same command on the frozen final inputs; all five policy/test/helper hashes match `904f08d5`. Large derivative listing/configuration files remain in runtime storage with [exact digest and size receipts](evidence/selection-deadline-listing-digests.json); both compact manifests are in this packet.

[The final formatting runner](evidence/final-pint-v2.sh) and [35-file command/hash receipt](evidence/final-pint-v2-command.json) record `vendor/bin/pint --test` returning **exit 0**. Every recorded source hash independently matches `904f08d5`. This is formatting evidence, not runtime acceptance.

## Unique completed SQLite case census

The [case-by-case census](evidence/sqlite-unique-completed-case-census.json) contains **277 unique committed test identities: 262 passes and 15 native-only skips**, with **1,478 assertions** from each identity's latest applicable completed passing selection. It starts with the 255-case whole Billing run at `a1b91e13`, then updates overlapping identities from the ownership green, 49-case schema batch, corrected parent-floor selection and final 18-case admission selection. All earlier completed green SQLite selections are subsets of those identities. The two external runtime probes are excluded; together they contribute seven additional assertions in their own three-case selection.

These totals deduplicate full class and JUnit case names, including dataset suffixes. Each identity retains its originating run and source limit. They are **historical coverage across multiple sources**, not a single 277-case execution or final-head verification. Skips are never counted as passes. Native functional results and failed diagnostics remain separate.
