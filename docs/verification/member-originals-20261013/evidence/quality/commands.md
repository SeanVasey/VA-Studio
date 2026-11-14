# C4b focused quality and discovery — completed

Exact root source: `78ef16be7626c81de49d103d581551b707201eda` in `/workspace/VA-Studio-member-originals`. Diff base is actual C4a main merge `990020c17fa3f5f7b446b1d41b32b1a1bb48249d`. Root was frozen during these checks. This packet contains policy self-tests, read-only style validation and PHPUnit test discovery only; no application/database test body, native workload, hosted dispatch or production action.

## Scope/CLI inspection before execution

`git diff --name-only --diff-filter=ACM 990020c1 -- '*.php'` found110 PHP paths:78 operative paths under app/bootstrap/config/database/scripts/tests and32 archived historical PHP evidence capsules. Before Pint execution the lead explicitly selected operative paths only, preserving historical capsules. `pint-selection.json` records exact include/exclude lists.

The actual shard CLI requires `--prefix=phpunit-ci-mysql` with native selection and rejects an alternate prefix. This was found by reading the script, before attempting any rejected invocation. The lead approved that required prefix after an exact50-name absence/untracked check. No CLI source was changed. Generated files were archived under this unique runtime packet, hashes compared, then only those50 exact created root filenames were removed. `generated-before.json`, `generated-artifacts.json` and `generated-cleanup.json` record the full lists/proof.

## Actual commands

Toolchain activation: `source /workspace/.va-studio-toolchain/activate.sh`. The retained `run-check.py` supplies `PYTHONDONTWRITEBYTECODE=1`, `APP_ENV=testing`, database host127.0.0.1/port1, empty socket/URL and an explicitly synthetic discovery-only database/user. Exact nonsecret environment and actual argv are retained for every invocation in its `.command.json`. Each invocation verifies the same2,254 captured application/test/configuration/contract/script/workflow/root metadata files before and after, plus the exact HEAD. Style additionally verifies all78 selected PHP hashes.

The four policy suites executed sequentially, using these exact commands:

```bash
python3 /workspace/runtime/member-originals-quality-evidence/run-check.py --name policy-phpunit-shards -- python3 scripts/ci/test-phpunit-shards.py
python3 /workspace/runtime/member-originals-quality-evidence/run-check.py --name policy-database-receipts -- python3 scripts/ci/test-database-receipts.py
python3 /workspace/runtime/member-originals-quality-evidence/run-check.py --name policy-gitlab-database-receipts -- python3 scripts/ci/test-gitlab-database-receipts.py
python3 /workspace/runtime/member-originals-quality-evidence/run-check.py --name policy-workflow-cadence -- python3 scripts/ci/test-workflow-cadence.py
python3 /workspace/runtime/member-originals-quality-evidence/run-style-discovery.py
```

The last retained runner reads the frozen changed-file list and invokes `vendor/bin/pint --test` with the exact78 operative paths, then invokes:

```bash
python3 scripts/ci/phpunit-shards.py --shards 24 --prefix phpunit-ci-mysql --timings scripts/ci/phpunit-timings-mysql.json --mysql-native-selection
```

Its actual inner commands go through the same source-bound run-check recorder. The root's private local vendor/bin/phpunit proxy uses its own vendor/autoload.php. The generator's only PHPUnit mode is `--list-tests-xml`; it is not an application test execution. `versions.json` records PHP/Python/PHPUnit version commands plus proxy/autoload hashes and private metadata paths.

## Results and limitations

- `test-phpunit-shards.py`:52 tests, exit0,0.520seconds outer elapsed.
- `test-database-receipts.py`:61 tests, exit0,51.198seconds outer elapsed.
- `test-gitlab-database-receipts.py`:32 tests, exit0,39.441seconds outer elapsed.
- `test-workflow-cadence.py`:14 tests, exit0,0.166seconds outer elapsed.
- Policy total:159 actual tests, all four logs end OK. This is this exact named selection; no assertion about a prior batch's similarly named scripts/counts.
- Pint:78 operative PHP files, exit0,4.730seconds; no formatting changes.
- Native selection discovery:2,143 selected cases/210 files from8,475 discovered cases/625 files,24 nonempty shards, exit0,6.241seconds.

The generator preserved its47 untimed-file warning: those files use fallback partition weights, not measured durations. Shard time estimates are historical/fallback estimates, not executed runtime. The reviewed skip policies contain17 MySQL methods and247 SQLite methods. Pure SQLite `MemberGrantCurrentSchemaTest.php` remains unselected; its native counterpart is selected automatically, with no MySQL skip entries added for the pure SQLite file. Discovery does not show those tests passing or skipping; these are selection-policy counts only.

All2,254 captured hashes and the78 style-selected hashes remained unchanged. All50 generated configs/listings/manifest were archived and verified before exact removal; root Git status was clean afterward. `summary.json` and source-before/source-after bind the outcome. No failing validation command occurred in this selection. An earlier read-only exploratory lookup for a prior packet's nonexistent commands glob returned a harmless lookup error; it was not a policy/style/discovery check. No old evidence was edited or normalized.

`public-artifacts.json` is the explicit public archival allowlist. `artifact-digests.json` binds every other listed file. The packet contains source hashes and public test metadata only, not .env, credentials, customer/media data or PDFs.
