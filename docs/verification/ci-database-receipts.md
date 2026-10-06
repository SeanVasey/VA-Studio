# T01-RECEIPTS-01: database receipts and full-only shadow decisions

## October 6, 2026: eight MySQL and two SQLite receipts

The separate parallelization candidate expands only GitHub's MySQL matrix from four to eight whole-file shards. The stable `backend` aggregate now requires **ten current-attempt database receipts: eight MySQL and two SQLite**, plus all existing quality, frontend and browser gates. Every expanded case, owning file and group still appears exactly once on each engine. All MySQL cases execute; SQLite skips must match the same reviewed policy. Receipt schema, parsing bounds, source/runtime checks, warning flags, job deadlines and full-only/no-reuse behavior remain unchanged.

The shared evidence helpers require the calling provider's explicit committed shard count. They never derive that count from an artifact or environment variable. GitLab retains its own fixed four-MySQL/two-SQLite policy and six-receipt collector; changing GitHub's count cannot silently change the legacy provider's matrix contract. Tests reject four-shard GitHub evidence, missing or duplicate late shards, wrong job denominators, mismatched manifest cardinality and unknown shard nine.

This is prepared source, not hosted acceptance. Independent review and the next ordinary complete Foundation run must establish ten actual source-bound receipts, all applicable successful jobs and terminal outer-run success. The following October 1 run identities, counts and preparation outcomes are historical. See [throughput evidence](ci-throughput.md) for current estimates and their limitations.

## Completed old-candidate PR proof — October 1, 2026

[Foundation run `36835120458`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36835120458), attempt 1, completed successfully at 09:07:21 UTC. Its PR #89 head was `bb41dbd5b6fba22f4f213b93e041ba322276f14b`; the actual checkout was merge `33cc70475a4bdb610a6aa04700f3cdb674e4e209`, tree `8ceb8393d66b0ea4c2c2f46e429b0106ac4f5402`, with ordered parents `cc591daa001f774626e574885cbc7b4904f6b2d2` and that PR head. Final read-only API inspection found all twelve applicable jobs successful, including aggregate `backend` job `110298641493`; the documentation-only job was intentionally skipped in full runtime mode.

All six actual current-attempt database artifacts were downloaded by numeric ID and independently verified against their published SHA-256 digests, exact source/runtime/start/finish identities, locked dependency references, original discovery/partition inventories, JUnit results and producing job intervals. The unchanged strict collector independently reproduced the actual hosted collection byte-for-byte, using the completed run's actual API snapshots and original artifact bytes as transport inputs. Its implementation SHA-256 was `95e47929e01746d2fa560a95910b93108bc7a48aca59701115306fcb029aca3f`; no production source or validation was replaced.

The complete source census was **146 owning files / 2,218 unique expanded cases**, identity SHA-256 `bdd60b9899429a6adc7723e3012fc26cf6eb14a8c66b25255ce36b8f8ca7ee00`, group SHA-256 `912b97805683f9b92294d1cb1f90f7ec638964ef37aac9de0f851f45c8193ca5`. Every case/file/group appeared exactly once per engine's collective partition. MySQL executed all 2,218 cases with **30,166 assertions**, zero failures/errors/skips. SQLite executed 2,116 cases with **25,522 assertions**, plus exactly 102 reviewed MySQL-only skips; all skipped counterparts executed on MySQL. PHP `8.4.26`, SQLite `3.45.1` and MySQL `8.4.11` were recorded. The existing JUnit warning/incomplete limitations remain unchanged.

| Engine/shard | Reported | Executed | Skipped | Assertions | Artifact |
| --- | ---: | ---: | ---: | ---: | --- |
| MySQL 1/4 | 552 | 552 | 0 | 8,095 | `11149798772` |
| MySQL 2/4 | 457 | 457 | 0 | 7,295 | `11150478349` |
| MySQL 3/4 | 552 | 552 | 0 | 5,326 | `11149753875` |
| MySQL 4/4 | 657 | 657 | 0 | 9,450 | `11150666640` |
| SQLite 1/2 | 1,090 | 1,048 | 42 | 15,007 | `11149466863` |
| SQLite 2/2 | 1,128 | 1,068 | 60 | 10,515 | `11149905431` |

| Complete archive | SHA-256 |
| --- | --- |
| MySQL 1/4 | `8448d51828a4cfc4786bdb3368ea3965a2e686e4eb11c7c3550d34624eb3b6d1` |
| MySQL 2/4 | `b61a88fceb418fd668d1ced4cb61d8f4f9ee3b150f6a26603a712bdc3130645f` |
| MySQL 3/4 | `56d966784cb46d0401dbe3c34c94bcf7dc88c5644ebce4ebdd06c38c10090cb7` |
| MySQL 4/4 | `1653a8e09a946a661fbcdf365b0f2e76065848dd3d2404c56e0f810bf1672f0f` |
| SQLite 1/2 | `da67f07a38861975f3c929d5a4ba180ffa2abb9cb69206e7b7f6a00e3ac91848` |
| SQLite 2/2 | `6802d21337bd18a66757bd2f9451a9a0612e80fa6b3911e29479cdc4398d6aa4` |

The actual aggregate logged: six current-run database receipts verified, full shadow decision, reuse disabled. [Shadow artifact `11151102895`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36835120458/artifacts/11151102895) contains only `phpunit-ci-shadow-receipt.json`; its complete ZIP SHA-256 is `aef211a8d0455cf4134d17484c6dd93f592ccf11bbf28c2d9ce1f43d2632a507`, and its member SHA-256 is `262ed4d380e5f335ac7dd845adc05d9e3b204a10ed1655c5049a50548c52188f`. The member equals the independently replayed strict output exactly. It retains `execution_mode: full`, `reuse_enabled: false`, unknown prior acceptance/protection requirements and comparisons not performed. Its own `outer_acceptance` remains pending at production time; the subsequent completed aggregate and overall success were established separately from final API/job facts.

The same old source passed 336 frontend cases, build/client scan/audits, 67 operator-browser cases with one existing intentional WebKit keyboard skip, and both genuine related-track journeys without failures, flaky cases or retries. The native source/run/artifact census was independently checked. These facts resolve that run's runtime gate, while the earlier assertion, pagination, timeout and genuine-fixture failures remain historical evidence.

**This old green head is not the current promotion candidate.** Independent P2 review found interrupted inquiry-schema and related-image guard recovery defects; the [migration repair](mysql-migration-recovery.md) and separately measured T02 timing data are composed into newer checkpoint `b41f8f58845f25e686f8f11ca70b147a362af9ec`, tree `3b5aa741c0c9a1407514390ca7d740488704e814`. That changed source still requires fresh full Foundation/receipt acceptance and independent integration review before promotion. No old receipt certifies it, no parent task is declared complete, and reuse stays disabled. A corresponding ordinary main-push receipt/outer-acceptance proof also remains outstanding.

## Original preparation record

Status: isolated integration candidate prepared October 1, 2026, on the frozen PR #89 application source `6a1915dd7190a1f35408ac108f23d8ddc41a6790` (tree `d98b2c5e127ba0b9b20cfd6d920a28f788b5f7af`, protected as `1deaf86549cc99a668bc878b19a1ca2e303e035e`). PR #89 full acceptance remains pending. The original seven-path child was independently reviewed at `09e8421d8e2e46beb838742435b0540434e7e43a` (tree `104fc93646360f00bbbeee704dd8a8877bd0f306`); its earlier integration was independently approved at `5348b8a3d5817e19724ae1d5741c321f5a84c7d6` (tree `f9dd618f86eba06bbd1d5b723372d5ee609daefd`, protected as `8f306614e4cf1162860105bc9146f94d4d70442a`). Independent review of this latest composition and actual cloud receipt acceptance remain outstanding. This child prepares the evidence needed for the [deferred post-merge reuse decision](ci-post-merge-reuse-decision.md). It does not complete T01 or reduce the existing full verification requirements.

Sean requested less frequent repeated full CI/MySQL checks and authorized continued reversible development. This bounded increment adds current-run evidence without enabling reuse. Every existing full MySQL, SQLite, frontend, browser, build and audit gate still runs under its existing trigger and mode conditions. Stable job names, matrices, concurrency, timeouts and required-check behavior remain intact.

## Child acceptance and remaining work

| Child | Scope | Status | Acceptance |
| --- | --- | --- | --- |
| T01-RECEIPTS-01 | Six database source/runtime/result receipts, current-attempt collector and permanent full-only shadow output | Old-candidate ordinary PR proof verified; repaired integration and main-push proof outstanding | Preserve the completed PR evidence above; require independent final-source review, fresh full gates and corresponding main-push receipts before completing the broader acceptance boundary |

No prior-run consumer, reuse output, job-skipping condition, protection change, privileged PR trigger or new Action dependency is included. Existing pinned checkout, PHP setup and artifact-upload Actions are retained. Historical runs lack these receipts and are ineligible for future reuse; historical result parsing is compatibility evidence only.

## What a database receipt establishes

`scripts/ci/database-receipts.py start` runs after installation and key generation, before partition discovery and tests. `finish` runs after the original test command and accepts only a successful test-step outcome. Each of the eight MySQL and two SQLite GitHub jobs records:

- The actual clean tracked Git commit/tree and ordered raw commit parents, including shallow-checkout parents; absence of nonignored untracked paths; repository identity; exact event ref and PR/base/head or main-push provenance; explicit run ID/attempt; executed workflow SHA/ref bound to the same checkout/event; committed workflow, policy, configuration, lock and timing hashes.
- Selected public PHP/extension, tool, runner and database identities before and after testing. MySQL includes the actual service image content ID and selected server settings. SQLite includes the actual engine version. Selected tool binaries/version outputs and the operating-system release file are hashed. No environment dump, DSN, password or SQL binding is retained.
- Exact installed Composer package versions/source/dist references equal to the committed lock, with count and canonical identity digest. The collector independently recomputes that identity from its own committed lock. This establishes declared installed references, not hashes of all installed dependency contents.
- Complete source and shard test inventories, group membership, manifest and file hashes; the selected shard's exact JUnit case identities, assertion/result counters and result digest. Every expanded source case, owning file and group must partition exactly once. The collector additionally checks the inventories actually executed by all jobs collectively, rejecting individually valid but inconsistent partitions.

Clean Git source excludes ignored generated/dependency/runtime outputs such as installed packages, `.env`, framework caches and test evidence. These outputs are not thereby certified as identical to a previous run. No complete reusable runtime proof or prior-runtime comparison is claimed. The initial fingerprints deliberately make runtime drift visible while floating hosted-runner, PHP, Composer, MySQL and apt inputs remain unpinned.

MySQL must execute every discovered case with zero skips. SQLite's exact skipped identities must match the committed reviewed class/method policy in `scripts/ci/database-sqlite-skips.json`, expanded against the current source inventory; every policy method must exist. Every skipped SQLite counterpart must execute on MySQL. Unexpected platform-conditional skips and all-skipped shards fail. The policy is not inferred from filenames or group labels.

PHPUnit JUnit does not represent warnings, risky tests, notices or deprecations, and it cannot distinguish skipped from incomplete cases. Receipts state that limitation. The original `--fail-on-phpunit-warning --display-warnings` test command and successful step/job requirements are preserved; the collector does not invent evidence for fields the format omits.

## Collector boundary

Only the existing isolated `backend` aggregate gets job-local `contents: read` and `actions: read`. Its checkout and both database checkouts disable persisted credentials. An Actions API token is exposed through an explicit environment variable only in the collector step. Application and database-test commands receive no such token; checkout still uses its ordinary read-only content access internally. The aggregate first runs the existing mode gate, requiring all applicable quality, database, frontend and browser jobs to succeed.

The collector reads only the current explicit run and attempt from the trusted Foundation workflow ID/path. API PR head SHA is checked against the event's PR head; the separately recorded actual checkout remains the synthetic merge commit. It requires all ten exact GitHub database job names, successful source/partition/test/receipt/upload steps, exact artifact names incorporating run and attempt, repository/head-repository provenance, future expiry and a hard SHA-256 archive digest match. Complete bounded pagination and a final unchanged run-attempt check are required. A partial rerun that does not supply all ten successful GitHub receipts in the current attempt fails closed; run the entire workflow to establish a new complete attempt.

Artifacts are downloaded by exact numeric ID. Authentication is sent only to the fixed same-repository GitHub API origin. A separate unauthenticated request follows the signed HTTPS artifact-host redirect; further redirects, credentials, invalid ports and untrusted hosts reject. Downloads, entries and expanded bytes are bounded. ZIPs are parsed without extraction or execution and reject links, traversal, duplicate/extra entries, encryption and unknown compression. JSON rejects duplicate/nonfinite fields and unknown receipt schemas. XML rejects DTD/entities, invalid encoding, excessive depth/node count, unknown result shapes, nested output/result nodes and incomplete/duplicate case evidence. Tokens, signed URLs and subprocess/provider output are never printed on rejection.

Uploads explicitly disable overwrite. Name preemption or missing evidence must fail rather than replace another artifact. Artifact metadata identifies a run, not a producing job; matching successful trusted upload steps and source-bound receipt contents provide the additional current-run contract.

The bounded `database-shadow-{run_id}-{attempt}` artifact contains `phpunit-ci-shadow-receipt.json`, whose purpose is `database-receipt-collection-shadow-only`. It always records `execution_mode: full`, `reuse_enabled: false`, prior acceptance/protection requirements as `unknown`, and source/runtime comparison as `not performed`. It never writes a workflow mode/output or selects a skip path. Its producing aggregate and workflow have not finished when it is uploaded, so `outer_acceptance` explicitly remains pending; the artifact cannot prove its own aggregate or outer-run success. Final API/job/artifact checks must be made after the run ends before treating any future evidence as completed acceptance.

## Local verification

Commands executed in the original isolated author checkout and rerun successfully in the integration checkout:

```sh
python3 scripts/ci/test-database-receipts.py
python3 scripts/ci/test-ci-scope.py
python3 scripts/ci/test-focused-tests.py
python3 scripts/ci/test-phpunit-shards.py
python3 -m py_compile scripts/ci/database-receipts.py scripts/ci/test-database-receipts.py
git diff --check
```

The receipt suite currently has 24 tests with adverse subcases for dirty/untracked/wrong Git and workflow provenance, shallow parent extraction, unknown PR repository identity, installed dependency drift, missing/duplicate/pending/failed/mixed-attempt jobs, missing/expired/wrong-run/digest-mismatched artifacts, incomplete pagination, incompatible actual partitions, dataset mapping, inherited-method ownership, malformed census/counters/skip policy, unsafe JSON/XML/ZIP/redirects, token separation and shadow output that cannot authorize skipping. Existing scope, focused-selection and partition suites have 22, 21 and 35 tests respectively; the completed run executed all 102 safeguards successfully.

Original PHP 8.4.26 / PHPUnit 12.5.34 discovery against the `50e5b7c`-based child found 136 tracked owning files and 2,057 expanded cases. Its 53 reviewed SQLite policy methods existed and expanded to 97 cases. The copied installed Composer dependencies matched all 158 committed references. These were discovery/reference checks, not test execution or hosted runtime acceptance. A replay of six historical corrected artifacts established parser compatibility with their 1,903-case census; those artifacts do not establish acceptance for this source and cannot become reusable receipts.

### Latest-source integration evidence

The seven owned paths were first merged three ways onto `85f90250` and independently approved as `5348b8a3`, then merged three ways onto the frozen PR #89 source `6a1915dd`, preserving all other source and every non-T01 task-register row. T03, T05, T06, T07 and T08 retain their accepted PR #88 status. Receipt implementation and adversarial-test bytes remain identical to the reviewed original child. The Foundation delta remains exactly the original receipt delta: reversing it reproduces the latest-base workflow byte for byte. Full gates, trigger policy, warning flags, matrices and runtime commands remain unchanged.

The integrated source contains the approved bulk-tag child and its later native-selection repair. Reading the actual `BulkTrackTagsConcurrencyTest` confirmed its explicit non-MySQL skip guard, independent-process locking assertions and two method definitions. Exactly those two class/method pairs were added to the existing policy; no class-wide or filename-based allowance was added. They expand to `test_overlapping_batches_with_separate_actors_wait_on_the_shared_track_and_the_loser_has_no_partial_changes`, and `test_authority_withdrawal_and_batch_serialize_on_the_persisted_actor_row` with named datasets `batch locks first` and `withdrawal locks first`.

The earlier independently approved integration found 138 tracked owning files and 2,073 expanded cases. Fresh PHP 8.4.26 / PHPUnit 12.5.34 discovery on the latest composition finds 139 owning files and 2,114 expanded cases, including the 41 inspection cases. Its canonical case and group inventory SHA-256 fingerprints are `fff35c080fe85a04dcd8caf5051fb116ca3805e3014b20a2d2c0183a1d9312a6` and `5df0897638c3bfde94df86c03fc4abbe5635392b177f3738a41f53e1a32da43f`. All 55 policy pairs exist and expand to exactly 100 expected SQLite skips, including those three bulk cases; inspection adds no skip allowance. Installed Composer references still match all 158 committed references. Receipt, scope, focused-selection and partition safeguards pass 23, 22, 20 and 35 tests respectively; Python compilation and whitespace checks pass. Discovery and dependency-reference checks do not prove application execution, MySQL races or hosted receipt collection.

The current base already includes the independently approved T19 seven-line operator-browser media-prerequisite stage from `810e965dfde766d9f2c9fc78489c3bd5500f0599`. Three-way composition preserves it exactly. Comparing this workflow with the earlier receipt integration `5348b8a3` adds only those seven lines; reversing the receipt delta reproduces the frozen PR #89 workflow byte for byte. No application or browser fixture changes enter the seven-path receipt child. Actual full PR/main event, source/runtime, six-artifact and completed outer-acceptance evidence remains pending.

### Prepared related-track composition

The next isolated feature composition additionally contains the independently approved manual related-track source `bee2412a7465e8cda8a2a0d88316c2fb6b4e518e` and the separately reviewed native pagination repair. Fresh discovery at composition parent `8fec8e7cd7c5e3917d2b3028c33eabc6cae8ddc7` finds 145 owning files and 2,209 cases. The explicit non-MySQL guard in `SiteContentConcurrencyTest` also covers its new `test_v4_publish_and_rollback_preserve_exact_winner_and_retained_loser_in_both_lock_orders` method. Exactly that class/method pair is added to the policy; its two named cases are `publish first` and `rollback first`. The policy now has 56 pairs and 102 expected SQLite skips, with no broader class allowance. Receipt, scope, selector and partition safeguards still pass all 100 tests. The new feature's actual MySQL races, genuine isolated browser fixtures, final composition review and full Foundation/receipt acceptance remain pending; this preparation does not change the full-only execution contract.

The local environment does not provide the hosted-runner identity, all required tools or the actual MySQL service used by production CI. No local fallback weakens the production receipt contract. The next full cloud batch must prove actual start/finish probes, artifact digest/download behavior, six-receipt aggregation, and PR/main event bindings. Actual branch-protection requirements remain unknown following the earlier 403 response; this child does not change or infer them. Receipt collection is a prerequisite only. Reuse activation remains a separate reviewed increment after complete runtime, final acceptance, freshness, newest-run and platform-check contracts are resolved.

Primary platform references: [workflow contexts](https://docs.github.com/en/actions/reference/workflows-and-actions/contexts), [workflow events](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows), [run/attempt API](https://docs.github.com/en/rest/actions/workflow-runs), [attempt jobs](https://docs.github.com/en/rest/actions/workflow-jobs), [artifacts API](https://docs.github.com/en/rest/actions/artifacts), [workflow permissions](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax), and [artifact integrity](https://docs.github.com/en/actions/tutorials/store-and-share-data).
