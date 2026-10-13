# Final focused C3 quality checks

Actual checked head: `874d57fe1897569a520b4627acd4f34d5c3e6d80`. Requested head: `04b2a4664a5096dd459f34f711754aed0d042dc5`; the actual successor changes only `docs/verification/billing-retrieval-20261013/README.md`. Source comparison and clean status are retained in metadata/source-check JSON.

Changed-PHP base: `6c871b9c5b510c1926437bbdf6e96d661e2c2f30`. The 56 paths were discovered using the exact NUL-delimited Git argv retained in `metadata.json` and saved in `changed-php-files.txt`.

## Exact execution

Working directory: `/workspace/VA-Studio-billing-retrieval`. The retained runner `run-quality.py` executes each check sequentially; each `*.command.json` and `*.result.json` records actual argv, working directory, head, UTC timestamps, exit, duration and runner/log hashes. No discovery or database test command was run.

```bash
source /workspace/.va-studio-toolchain/activate.sh
PYTHONDONTWRITEBYTECODE=1 python3 /workspace/runtime/billing-retrieval-quality-final/run-quality.py > /workspace/runtime/billing-retrieval-quality-final/runner.log 2>&1
quality_exit=$?
printf '%s\n' "$quality_exit" > /workspace/runtime/billing-retrieval-quality-final/runner.exit
cat /workspace/runtime/billing-retrieval-quality-final/runner.log
exit "$quality_exit"
```

Runner SHA-256: `f677c515014bd920aa1dc71155ba80bc0a531d7e3cc5a479782462c736de656a`.

## Results

| Check | Cases/files | Exit | Result |
| --- | ---: | ---: | --- |
| test-phpunit-shards | 52 | 0 | PASS |
| test-database-receipts | 61 | 0 | PASS |
| test-ci-scope | 27 | 0 | PASS |
| test-workflow-cadence | 14 | 0 | PASS |
| Pint --test | 56 | 1 | FAIL: `BillingRetrievalAttemptsTest.php`, `no_unused_imports` |

All 154 Python self-tests passed, with no reported skips, failures or errors. Workflow-cadence coverage was appropriate because requested head 04b2a466 changes the final-verification workflow census comments; trigger/cost policy was not changed.

Pint failed on one of the 56 selected files, reporting `no_unused_imports` for `tests/Feature/ProductionMembershipBilling/BillingRetrievalAttemptsTest.php`. Read-only inspection identifies `use App\Domain\Memberships\Billing\BillingSchema;` at line 11 as present only in that import. The source was not edited, and this failed result was not rerun. Overall runner exit: 1.

All 2283 tracked application, migration, test, CI, configuration, resource and dependency-manifest source hashes matched before/after. HEAD stayed unchanged; final worktree status was clean. `metadata.json` retains PHP/Python versions and the resolved private local Pint proxy/autoload paths and hashes. These focused policy/style checks do not establish functional, native database, hosted CI or host/provider acceptance.

## Exploratory lookup note

Before the retained runner, a read-only inspection shell used `git diff --stat 67d -- .github/workflows`; Git returned `fatal: bad revision '67d'` (exit 128), so later `&&`-chained inspection commands in that shell did not run. No quality check ran in that shell. A later full log resolved the actual earlier source commit as `67d85d6e07953a272118ec3d339ec2bb8b7ecaec`; this lookup error is not presented as a passing command or a product test failure. That exploratory output is available in the tool transcript, not a separate saved runtime log.
