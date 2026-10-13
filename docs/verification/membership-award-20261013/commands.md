# Commands, source identities and retained outcomes

This is an **interim packet**, not final acceptance. Application correction `9302e1c0` has green SQLite evidence, but its three-case native selection contains **two passes and one `operation_deadline` error**. The ownership correction has separate green SQLite evidence at `9ec09073`; native metadata performance work is still ongoing. The separate review of `307699b6` says **REQUEST CHANGES**; no later approval is implied.

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
