# T01-RECEIPTS-01: database receipts and full-only shadow decisions

Status: isolated integration candidate prepared October 1, 2026, based on `85f90250d4b8984560aa66520462e6941495303d` (tree `c1d0bba7e63e5d5c7e85a9a14448f3a714728f93`, protected as `839b3e45219124501b86b44a59b2de00e4a290c2`). The original seven-path child was independently reviewed at `09e8421d8e2e46beb838742435b0540434e7e43a` (tree `104fc93646360f00bbbeee704dd8a8877bd0f306`). Independent review of this integrated candidate and actual cloud acceptance remain outstanding. This child prepares the evidence needed for the [deferred post-merge reuse decision](ci-post-merge-reuse-decision.md). It does not complete T01 or reduce the existing full verification requirements.

Sean requested less frequent repeated full CI/MySQL checks and authorized continued reversible development. This bounded increment adds current-run evidence without enabling reuse. Every existing full MySQL, SQLite, frontend, browser, build and audit gate still runs under its existing trigger and mode conditions. Stable job names, matrices, concurrency, timeouts and required-check behavior remain intact.

## Child acceptance and remaining work

| Child | Scope | Status | Acceptance |
| --- | --- | --- | --- |
| T01-RECEIPTS-01 | Six database source/runtime/result receipts, current-attempt collector and permanent full-only shadow output | Source candidate; cloud verification outstanding | Independent review of the frozen source; successful existing full gates; inspect receipts from an ordinary PR and corresponding main push against actual Git/API/job/artifact facts |

No prior-run consumer, reuse output, job-skipping condition, protection change, privileged PR trigger or new Action dependency is included. Existing pinned checkout, PHP setup and artifact-upload Actions are retained. Historical runs lack these receipts and are ineligible for future reuse; historical result parsing is compatibility evidence only.

## What a database receipt establishes

`scripts/ci/database-receipts.py start` runs after installation and key generation, before partition discovery and tests. `finish` runs after the original test command and accepts only a successful test-step outcome. Each of the four MySQL and two SQLite jobs records:

- The actual clean tracked Git commit/tree and ordered raw commit parents, including shallow-checkout parents; absence of nonignored untracked paths; repository identity; exact event ref and PR/base/head or main-push provenance; explicit run ID/attempt; executed workflow SHA/ref bound to the same checkout/event; committed workflow, policy, configuration, lock and timing hashes.
- Selected public PHP/extension, tool, runner and database identities before and after testing. MySQL includes the actual service image content ID and selected server settings. SQLite includes the actual engine version. Selected tool binaries/version outputs and the operating-system release file are hashed. No environment dump, DSN, password or SQL binding is retained.
- Exact installed Composer package versions/source/dist references equal to the committed lock, with count and canonical identity digest. The collector independently recomputes that identity from its own committed lock. This establishes declared installed references, not hashes of all installed dependency contents.
- Complete source and shard test inventories, group membership, manifest and file hashes; the selected shard's exact JUnit case identities, assertion/result counters and result digest. Every expanded source case, owning file and group must partition exactly once. The collector additionally checks the inventories actually executed by all jobs collectively, rejecting individually valid but inconsistent partitions.

Clean Git source excludes ignored generated/dependency/runtime outputs such as installed packages, `.env`, framework caches and test evidence. These outputs are not thereby certified as identical to a previous run. No complete reusable runtime proof or prior-runtime comparison is claimed. The initial fingerprints deliberately make runtime drift visible while floating hosted-runner, PHP, Composer, MySQL and apt inputs remain unpinned.

MySQL must execute every discovered case with zero skips. SQLite's exact skipped identities must match the committed reviewed class/method policy in `scripts/ci/database-sqlite-skips.json`, expanded against the current source inventory; every policy method must exist. Every skipped SQLite counterpart must execute on MySQL. Unexpected platform-conditional skips and all-skipped shards fail. The policy is not inferred from filenames or group labels.

PHPUnit JUnit does not represent warnings, risky tests, notices or deprecations, and it cannot distinguish skipped from incomplete cases. Receipts state that limitation. The original `--fail-on-phpunit-warning --display-warnings` test command and successful step/job requirements are preserved; the collector does not invent evidence for fields the format omits.

## Collector boundary

Only the existing isolated `backend` aggregate gets job-local `contents: read` and `actions: read`. Its checkout and both database checkouts disable persisted credentials. An Actions API token is exposed through an explicit environment variable only in the collector step. Application and database-test commands receive no such token; checkout still uses its ordinary read-only content access internally. The aggregate first runs the existing mode gate, requiring all applicable quality, database, frontend and browser jobs to succeed.

The collector reads only the current explicit run and attempt from the trusted Foundation workflow ID/path. API PR head SHA is checked against the event's PR head; the separately recorded actual checkout remains the synthetic merge commit. It requires all six exact database job names, successful source/partition/test/receipt/upload steps, exact artifact names incorporating run and attempt, repository/head-repository provenance, future expiry and a hard SHA-256 archive digest match. Complete bounded pagination and a final unchanged run-attempt check are required. A partial rerun that does not supply all six successful receipts in the current attempt fails closed; run the entire workflow to establish a new complete attempt.

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

The receipt suite currently has 23 tests with adverse subcases for dirty/untracked/wrong Git and workflow provenance, shallow parent extraction, unknown PR repository identity, installed dependency drift, missing/duplicate/pending/failed/mixed-attempt jobs, missing/expired/wrong-run/digest-mismatched artifacts, incomplete pagination, incompatible actual partitions, dataset mapping, inherited-method ownership, malformed census/counters/skip policy, unsafe JSON/XML/ZIP/redirects, token separation and shadow output that cannot authorize skipping. Existing scope, focused-selection and partition suites have 22, 20 and 35 tests respectively.

Original PHP 8.4.26 / PHPUnit 12.5.34 discovery against the `50e5b7c`-based child found 136 tracked owning files and 2,057 expanded cases. Its 53 reviewed SQLite policy methods existed and expanded to 97 cases. The copied installed Composer dependencies matched all 158 committed references. These were discovery/reference checks, not test execution or hosted runtime acceptance. A replay of six historical corrected artifacts established parser compatibility with their 1,903-case census; those artifacts do not establish acceptance for this source and cannot become reusable receipts.

### Latest-source integration evidence

The seven owned paths were merged three ways onto `85f90250`, preserving all other source and every non-T01 task-register row. T03, T05, T06, T07 and T08 retain their accepted PR #88 status. Receipt implementation and adversarial-test bytes remain identical to the reviewed original child. The Foundation delta remains exactly the original receipt delta: reversing it reproduces the latest-base workflow byte for byte. Full gates, trigger policy, warning flags, matrices and runtime commands remain unchanged.

The integrated source contains the approved bulk-tag child and its later native-selection repair. Reading the actual `BulkTrackTagsConcurrencyTest` confirmed its explicit non-MySQL skip guard, independent-process locking assertions and two method definitions. Exactly those two class/method pairs were added to the existing policy; no class-wide or filename-based allowance was added. They expand to `test_overlapping_batches_with_separate_actors_wait_on_the_shared_track_and_the_loser_has_no_partial_changes`, and `test_authority_withdrawal_and_batch_serialize_on_the_persisted_actor_row` with named datasets `batch locks first` and `withdrawal locks first`.

Fresh PHP 8.4.26 / PHPUnit 12.5.34 discovery now finds 138 tracked owning files and 2,073 expanded cases. All 55 policy pairs exist and expand to exactly 100 expected SQLite skips, including those three bulk cases. Installed Composer references still match all 158 committed references. Receipt, scope, focused-selection and partition safeguards pass 23, 22, 20 and 35 tests respectively; Python compilation and whitespace checks pass. Discovery and dependency-reference checks do not prove application execution, MySQL races or hosted receipt collection.

The separately reviewed T19 seven-line operator-browser media-prerequisite stage from `8c9bb1bad3162a0c1d26275fdbccc610b1e642cc` is deliberately outside this seven-path receipt candidate. A read-only composition check applies that exact stage cleanly to the receipt workflow, then reverses both deltas to reproduce the current base byte for byte. Later coherent integration must merge both scoped changes rather than replace the whole workflow with either candidate's blob. Actual full PR/main event, source/runtime, six-artifact and completed outer-acceptance evidence remains pending.

The local environment does not provide the hosted-runner identity, all required tools or the actual MySQL service used by production CI. No local fallback weakens the production receipt contract. The next full cloud batch must prove actual start/finish probes, artifact digest/download behavior, six-receipt aggregation, and PR/main event bindings. Actual branch-protection requirements remain unknown following the earlier 403 response; this child does not change or infer them. Receipt collection is a prerequisite only. Reuse activation remains a separate reviewed increment after complete runtime, final acceptance, freshness, newest-run and platform-check contracts are resolved.

Primary platform references: [workflow contexts](https://docs.github.com/en/actions/reference/workflows-and-actions/contexts), [workflow events](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows), [run/attempt API](https://docs.github.com/en/rest/actions/workflow-runs), [attempt jobs](https://docs.github.com/en/rest/actions/workflow-jobs), [artifacts API](https://docs.github.com/en/rest/actions/artifacts), [workflow permissions](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax), and [artifact integrity](https://docs.github.com/en/actions/tutorials/store-and-share-data).
