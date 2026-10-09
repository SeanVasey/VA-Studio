# Independent pipeline admission repair: PR #63

**Decision: APPROVE the focused development candidate `ca7e3ae46b312e1673a2067bb5d122e8aa9bc11e`. Integration findings I-1 and I-2 are resolved within the reviewed kit boundary.**

This decision supersedes the blocked integration decision at `e898282d5606b5e9c824c4cd4e3a8b9611c13777`; the [blocked addendum](INTEGRATION-ADDENDUM.md) and all earlier red evidence remain intact. The reviewer assessed the actual changed runner, root helper, provisioning, backup descriptor boundaries, unit and operator instructions, then executed independent adversarial processes and the new canonical admission cases. This approval does not certify a future candidate or actual host activation.

## Assessed repair

Each sweep opens the fixed, protected `writer.lock` inode and takes a shared lock before reading the exact root-owned admission marker and before creating or changing private runner state. Existing kit ancestry requires both trusted files; missing, symlinked, writable, foreign-owned or malformed configuration refuses. The gate is compared as bytes, including its newline, rather than through a shell value that drops NULs or newlines. Admission metadata tools use fixed absolute paths. No environment setting substitutes a different admission path.

Quiesce atomically closes the marker before requesting the exclusive writer barrier. An admitted sweep makes it refuse before service changes; admission stays closed, preventing subsequent scheduled work while the operator waits and retries. Snapshot and switch require closed admission and retain the exclusive barrier throughout their action. The separate root helper lock still serializes complete control actions. Resume holds those barriers, proves the selected worker release and healthy HTTP result, then opens admission. The independent failure cases never reopen it.

The runner's shared descriptor remains in its artisan/timeout descendants so a surviving child retains admission after the runner dies. Root application subprocesses and disposable MySQL/archive children close both privileged control descriptors. Provisioning initializes admission closed, uses noclobber creation and preserves the existing lock inode and gate state on repetition.

The kit now directs both cron and oneshot systemd starts through `/srv/vasey-staging/current`, with explicit PHP 8.4. Its instructions require removal of mirror/old-copy jobs and avoid a daemon retaining old runner code across switches. An independent default-root probe invoked the actual runner through an owned `current` symlink: all five command fixtures had the selected physical release as their cwd, and private cursor state was created in that release, with no mirror state. The shared admission barrier prevents a root `switch` during an admitted sweep. Arbitrary manual SQL or unreviewed scripts remain an explicitly documented operator responsibility.

No unresolved blocking runtime finding remains in this exact reviewed scope.

## Independent command evidence

All passing commands below exited **0** at the exact candidate:

| Command / receipt | Actual result |
| --- | --- |
| `python3 docs/verification/staging-ops-20261009/independent-review/pipeline-admission-probe.py` → [pipeline-admission-final.txt](pipeline-admission-final.txt) | **12 tests pass**: active-sweep retry and future starts, exact bytes, per-loop admission, genuine process scan, healthy-only reopening, orphan child, stable provisioning, failed resume, both root descriptors, snapshot/resume exclusion, switch barrier and trusted metadata |
| Same driver selecting `AdmissionProbe.test_current_script_default_root_runs_on_the_selected_physical_release` → [pipeline-current-path-final.txt](pipeline-current-path-final.txt) | **1 additional test passes**: alias invocation, all five physical cwd observations and selected-release private state |
| `python3 tests/ops/test_staging_pipeline_admission.py` → [pipeline-admission-canonical-final.txt](pipeline-admission-canonical-final.txt) | **9 tests pass**, including partial kit refusal and ordinary local harness behavior without a kit |
| `bash -n` separately for provisioning, deploy, backup, helper and runner; Shellcheck `-x` on those five files | Exit **0**, no syntax or lint diagnostic |

The 12-method independent execution includes seven failed-resume subcases, nine trusted-file/ancestry subcases, seven malformed byte values, actual two-process control exclusion and four subordinate descriptor checks. The later default-root method was added and executed separately; the earlier 12-method receipt is not presented as a 13-method execution.

The unchanged three selected safety tests were also executed against source extracted read-only by `git show e898282d...:<path>`, without changing any git ref:

```sh
python3 docs/verification/staging-ops-20261009/independent-review/pipeline-admission-probe.py --source-sha e898282d5606b5e9c824c4cd4e3a8b9611c13777 AdmissionProbe.test_admitted_sweep_refuses_quiesce_and_future_starts_until_retry AdmissionProbe.test_manual_pipeline_command_is_caught_by_actual_process_scan AdmissionProbe.test_orphan_artisan_child_retains_admission_until_it_finishes
```

The retained [independent red](pipeline-admission-red.txt) exits **1** with **3 failures**: old quiesce admits an active sweep; old snapshot ignores a standalone command; and old quiesce admits a surviving child after runner death. These are product safety assertion failures. The integrator's corrected canonical old-source receipt separately reports **9 methods / 12 failures**, while its current canonical receipt passes.

The reviewer additionally inspected [pipeline-repair-final-sqlite.txt](../pipeline-repair-final-sqlite.txt): the integrator's exact committed-source run reports genuine PHP 8.4.26 / PHPUnit 12.5.34, **89 tests / 876 assertions**, exit **0**, no skips. This is inspected integrator evidence, not a repeated independent commerce journey or MySQL concurrency claim.

## Source carry and evidence bounds

Explicit `git diff --exit-code` comparisons exited **0** with no differences:

```sh
git diff --exit-code 2c91f8e620cd870a2e213b4af790a1dbfab8de9a..ca7e3ae46b312e1673a2067bb5d122e8aa9bc11e -- app config resources/contracts .github ops/staging/nginx ops/staging/php-fpm ops/staging/workers ops/staging/env.staging.example ops/staging/forge-deploy.sh ops/staging/normalize-mysql-dump.py ops/staging/validate-runtime.php tests/Unit/StagingRuntimeValidatorTest.php tests/ops/test_staging_dump_normalization.py tests/ops/test_staging_deploy_refresh.py
git diff --exit-code 4902c45e..ca7e3ae46b312e1673a2067bb5d122e8aa9bc11e -- ops/staging/test-commerce/env.test-commerce.example ops/staging/test-commerce/stripe-listen.service ops/staging/test-commerce/vasey-test-commerce-pipeline.timer scripts/ops/validate-test-commerce-profile.php tests/Unit/TestCommercePipelineRunnerTest.php tests/Unit/TestCommerceProfileValidatorTest.php tests/Feature/StagingTestCommerceProfileJourneyTest.php
git diff --exit-code ca7e3ae46b312e1673a2067bb5d122e8aa9bc11e -- app tests scripts ops .github config resources/contracts
```

The changed helper, runner, provisioning, descriptor closures, unit and documentation were reviewed directly. The prior 1/6/4/9 helper reviews, 13/63 runtime checks, 12 parser checks, 1 frozen-profile check and native nginx/MySQL receipts remain historical evidence at their recorded sources. They are not claimed as fresh executions of this changed helper. The unchanged HTTP/parser/application components carry under the explicit comparisons above; expensive native migration and HTTP runs were not repeated.

The independent harness retains actual canonicalization, regular-file/link predicates, file modes/sizes/bytes, stable inode checks, subprocess lifetime and kernel `flock`. It maps root identity only in owned fixture ancestry and substitutes privileged service, runuser, curl and backup operations with harmless commands. The manual-command test uses genuine `pgrep` on a harmless named sleep process. No database, real private file, host service or `/etc` entry was changed.

[Attempt 1](pipeline-admission-attempt1.txt) records **12 harness failures** because this sandbox's umask created the fixture's nominal 0644 lock with actual mode 0600. The source correctly refused it. Explicitly chmodding the owned fixture produced the retained [10-method green attempt](pipeline-admission-attempt2.txt), followed by the expanded final 12-method execution and separate current-path probe. That first attempt is not product-red evidence. One trailing space on its unittest progress line was normalized for publication; outcomes and traces were preserved.

Only new reviewer-owned files in this independent-review directory were written. No product source, canonical test, earlier receipt, commit, branch, remote, account, credential or provider was changed. `git diff --check` exited **0**. Real Forge/DigitalOcean root permissions, services, bind mounts, sudoers, AppArmor, DNS/TLS, backups and paired-key restoration still require actual private-host acceptance. The first real Stripe TEST purchase and Sean's host/catalog/rights/account inputs remain open.

Approval is limited to the exact focused source above and the documented controlled runner boundary. Publication/base successors require an explicit final equivalence check; runtime changes require re-review. Current cheap preflight, Codex findings and expected-head merge requirements remain the integrator's gates.
