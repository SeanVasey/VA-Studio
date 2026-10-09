# Independent integration addendum: PR #63

**Decision: BLOCK the integrated candidate `e898282d5606b5e9c824c4cd4e3a8b9611c13777` pending a shared pipeline shutdown repair.**

The previous approval of the kit at `2c91f8e620cd870a2e213b4af790a1dbfab8de9a` remains valid for that exact source and its documented scope. This candidate integrates the separately reviewed test-commerce pipeline from PR #62. The components are individually unchanged, but their combined writer boundary is incomplete. This addendum assesses this exact integration, not a future main/ledger successor or a real Forge host.

## Source and evidence carry

The following commands exited **0**, with no source difference:

```sh
git diff --exit-code 2c91f8e620cd870a2e213b4af790a1dbfab8de9a..e898282d5606b5e9c824c4cd4e3a8b9611c13777 -- ops/staging/bin ops/staging/backup.sh ops/staging/provision.sh ops/staging/forge-deploy.sh ops/staging/normalize-mysql-dump.py ops/staging/validate-runtime.php ops/staging/nginx ops/staging/php-fpm ops/staging/workers ops/staging/env.staging.example app config resources/contracts .github tests/Unit/StagingRuntimeValidatorTest.php tests/ops/test_staging_dump_normalization.py tests/ops/test_staging_deploy_refresh.py
git diff --exit-code 4902c45e..e898282d5606b5e9c824c4cd4e3a8b9611c13777 -- ops/staging/test-commerce scripts/ops/run-test-commerce-pipeline.sh scripts/ops/validate-test-commerce-profile.php tests/Unit/TestCommercePipelineRunnerTest.php tests/Unit/TestCommerceProfileValidatorTest.php tests/Feature/StagingTestCommerceProfileJourneyTest.php docs/ops/staging-test-purchase.md
```

The reviewer inspected the integration receipts: [runtime](../integration-runtime.txt) reports genuine PHP 8.4.26 / PHPUnit 12.5.34, **13 tests / 63 assertions**; [normalization](../integration-normalization.txt) reports **12 passing tests**; [frozen refresh](../integration-refresh.txt) reports **1 passing test**. These are the integrator's reruns, not additional independent executions. Prior adversarial decisions and native receipts remain intact. Expensive migration, HTTP and commerce journeys were not repeated because their owned source and canonical coverage are unchanged.

The reviewed session seam remains consistent: both profiles select local HTTPS, secure and HttpOnly cookies; the actual runtime validators require effective safe session configuration. The new pipeline's cursor files reside under the existing persistent private store. The protected nginx webhook routing, contract source, app/configuration and workflow definitions do not change in this integration. CHANGELOG resolution retains both entries. None of these checks closes the writer finding below.

The integrator normalized trailing whitespace on three existing progress lines without changing their outcomes; original seven red receipts remain unchanged. `git diff --check` on the current working tree exited **0**. This is formatting of evidence, not a new runtime execution.

## Integration finding I-1: commerce writer survives quiesce

**Severity: high for backup consistency and release activation; blocks the integrated development merge.**

`ops/staging/bin/vasey-staging-ctl` stops five fixed Supervisor programs. Its `workers_stopped()` process check covers only `queue:work`, `queue:listen`, `schedule:work`, `schedule:run` and `horizon`. The new runner invokes standalone `vasey:process-stripe-receipts`, `vasey:reconcile-test-payments`, `vasey:finalize-test-payments`, `vasey:issue-test-contracts` and `vasey:activate-test-fulfillment` commands. Those commands can write database state and private contract files while Laravel is in maintenance.

The supplied commerce service/timer is outside the Supervisor group. Neither quiesce nor resume controls it. The walkthrough also offers a Forge cron job or daemon outside that group. The runner only takes its private sweep-overlap lock; it does not share a deploy/quiescence boundary. Consequently, a running sweep survives the stopped-writer proof, and a timer or cron invocation can start a new sweep after that proof, during backup/archive, migration or release switch. The root helper lock serializes helper actions but is not held by this application runner.

The reviewer ran a harmless independent red against the actual `workers_stopped()` and `cmd_snapshot()` functions, using genuine `pgrep` and a real owned process whose argument zero was `php artisan vasey:issue-test-contracts`. The five known Supervisor states were substituted with `STOPPED`, and the already-stopped web boundary was substituted with success. Backup was an owned temporary executable that only wrote its invocation argument to an owned marker. No database, mount, service, account or real private file was touched; the dummy process was terminated in `finally`.

Actual output at the exact candidate:

```text
Exact source: e898282d5606b5e9c824c4cd4e3a8b9611c13777
Harmless live process: pid=70231, cmdline=php artisan vasey:issue-test-contracts 20
Genuine pgrep, actual workers_stopped and cmd_snapshot; five Supervisor states and web-stop boundary substituted.
workers_stopped=admitted
snapshot=admitted
snapshot helper exit=0
backup invoked while pipeline-labelled process alive=True
AssertionError: RED: snapshot accepted while a new standalone commerce pipeline process remained alive
```

The safety assertion exited **1**, as expected for this product red. This probe establishes the omitted live-process category; the supplied timer configuration independently establishes future start opportunities. It is not an execution of a real commerce command.

Required repair: make the kit's chosen commerce runner part of its controlled writer set, stop/drain it before declaring quiescence, prevent scheduled restarts until resume, and prove that the restarted runner uses the selected served release. Update the kit-specific installation path so an uncontrolled systemd timer, Forge cron job or daemon is not simultaneously enabled. Adding only a process-name check is insufficient because a future scheduled sweep can still enter the protected interval. Re-review the actual repair and a red-to-green process/start-boundary case before carrying approval.

## Integration finding I-2: mirror runner path bypasses selected release

The Forge scheduler example in `docs/ops/staging-test-purchase.md` executes `/home/forge/<site>/scripts/ops/run-test-commerce-pipeline.sh`. In this kit, that tree is the Forge source/environment mirror; the selected sealed release is under `/srv/vasey-staging/current`. The runner derives its default `APP_ROOT` from its script location. Following the example therefore runs against mirror code/environment/private state rather than the selected release, and the kit cannot prove its current working directory on resume. The separate service's `<APP_ROOT>` placeholder also needs a kit-specific selected-release instruction.

Resolve this alongside I-1 by documenting and installing a controlled runner that starts on `current` and remains pinned to that release for its bounded execution. Generic alternatives may remain documented for installations that do not use this kit, provided that distinction and their own shutdown responsibility are explicit.

## Bounds

Only this new addendum was written by the reviewer. No product source, canonical test, earlier receipt, git ref, commit or remote was changed. No actual host pipeline is installed yet, so this is a development integration defect discovered before activation. Sean's Forge/DigitalOcean access, credentials, real uploads, backup destination and first real Stripe TEST transaction remain separate inputs and acceptance checks.

An approval requires the repaired exact commit and evidence. Empty component diffs alone cannot carry approval across this newly introduced interaction.
