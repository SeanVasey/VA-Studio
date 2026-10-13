# Reservation native selection policy and discovery

Selection-only commit **a01bdc3673a4b0fd25f25958075201b1290ce888**, prepared and verified on integrated source **565f19d1**, changes exactly `scripts/ci/database-mysql-selection.json` and `scripts/ci/database-sqlite-skips.json`. The delta adds five explicit native include files, six genuine native-only SQLite method skips, and the native reservation worker to the exact driver-branch helper census. Both skip-owner files are automatically selected in full. The three new pure unit files remain in the full SQLite inventory without unnecessary native duplication.

No timing estimates, shard count, test outcomes, workflow triggers, runtime skips, or cost policy were changed. This lane dispatched no Foundation/GitLab jobs and performed no native database test execution.

From `/workspace/VA-Studio-membership-ledger`:

```sh
python3 scripts/ci/test-phpunit-shards.py
python3 scripts/ci/test-database-receipts.py
python3 scripts/ci/test-gitlab-database-receipts.py
```

All pass: **52 tests**, **61 tests**, and **32 tests** respectively, zero failures/errors/skips, exit **0**. The third command validates offline fixture receipts; it is not a GitLab dispatch or hosted verification.

Exact discovery command/environment is retained in `discovery.sh`, invoked as:

```sh
bash /workspace/runtime/membership-reservation-selection-evidence/discovery.sh
```

It executes PHPUnit test listing for the source and each existing native shard, not the database tests themselves. Initial discovery at **565f19d1** proves **1,971 selected native cases / 187 files**, from **8,045 total cases / 587 files**, across **24 nonempty shards**. All **2,091** bound source files match after execution. The reviewed skip census is **231 SQLite-skipped native methods**, **16 MySQL-skipped SQLite methods**; six of the SQLite entries are new.

The existing timing inputs have **24 selected files without recorded timings**, which use the existing fallback weight. The warning is retained. The estimates are not observed runtime and this lane does not manufacture replacement timing data.

`initial-565f19d1/` preserves this complete initial discovery source/log/exit/manifest/census and generated configuration/listing files. `discovery-generated-artifacts.json` records their SHA-256 and byte lengths; generated files were verified against those hashes before moving to the archive so a later exact-source census can write fresh files without overwriting the initial evidence.

Final eligibility follow-up adds methods to an already-selected file. The selection remains correct but this initial census is not relabeled as final discovery; a later final source-bound census must record the actual resulting count. No passing database runtime result is claimed by this selection proof.


## Refreshed integrated-source census

The same exact `discovery.sh` command was rerun on **defe95802e7d09e7df12c61a98c1ebf1caee99c0**, containing integrated selection commit `397a339f` and final eligibility follow-up `a54ffb6a`. It passed with **1,973 selected native cases / 187 files**, from **8,047 total cases / 587 files**, across the unchanged **24 nonempty shards**, exit **0**. MembershipEligibleLicenseProofTest contributes **16** cases; its two new methods explain the +2 source/native difference. The skip census remains **231 SQLite native-only methods / 16 MySQL SQLite-only methods**. The **24 untimed selected files** warning remains explicit; existing fallback weights were preserved.

All **2,091** inventoried source files match before/after. The selection configs, supporting policy code and policy test files are byte-identical across all **nine** bound policy files, and a fresh driver-branch coverage check returned no errors (`policy-carry-verification.json`). The prior **52/61/32** policy-suite passes carry on those exact bytes; they were not rerun or relabeled as new executions. No expectation or workflow file changed.

Final compact manifest, file-by-file new-test mapping and generated-artifact digests are top-level `discovery-manifest.json`, `discovery-census.json`, and `discovery-generated-artifacts.json`. The full final configuration/listing artifacts and their bound source receipts are retained in `final-defe9580/`, separately from the initial census. This is authoritative PHPUnit discovery/partition verification only, not evidence that 1,973 native database cases executed.


## Final test-source census at 45d12994

The latest authoritative discovery is retained separately under `final-45d12994/`, bound to **45d1299406821627f4ba02af186e324a70c6ade0**. The earlier `final-defe9580/` remains historical evidence. Exact commands:

```sh
bash /workspace/runtime/membership-reservation-selection-evidence/final-45d12994/discovery.sh
python3 /workspace/runtime/membership-reservation-selection-evidence/final-45d12994/finalize.py
```

Both exit **0**. Discovery remains **1,973 native cases / 187 files**, from **8,047 total cases / 587 files**, across **24 nonempty shards**. The skip policy is unchanged at **231 SQLite native-only methods / 16 MySQL SQLite-only methods**. All **2,091** bound source files match before/after; exactly the two expected test-only corrections differ from defe9580. Nine policy/script/policy-test files remain byte-identical to the earlier passing policy receipts, and a fresh static driver-branch census has zero errors. Prior **52/61/32** policy passes carry on those exact bytes; no policy suite was rerun.

The existing **24 untimed selected files** warning is retained, without changing estimates or fallback weights. All **50 generated artifacts** have retained hashes and copies. The discovery script uses only `--list-tests-xml`, with an unreachable MySQL port and no socket; no test bodies, native database workloads, hosted workflows, or provider calls were executed. See `final-45d12994/README.md`, `policy-carry-verification.json`, `source-delta.json`, and exact script/log/exit receipts.
