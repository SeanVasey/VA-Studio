# A5 staff refund display follow-up — 2026-11-01

Prepared from main `2ab0c41e946e30cc402afc8ec107a3670e399abd` before source edits.

## Contract and ownership

Resolve only Codex P2 r4368889957 and duplicate Bugbot r4368906963. Staff order and customer detail must display succeeded attributed refund totals, excluding retained allocations whose current accepted refund status is failed or pending. Reuse `RefundDisputeCase::refundedByLine()` with read-only case loading; no new refund rules. Preserve immutable allocations, provider status history, money, rights, access, authorization and default-off gates.

Owned files: `app/Domain/Commerce/RefundDispute/StaffCommerceDirectory.php`, `tests/Feature/RefundDispute/StaffCommerceAdministrationTest.php`, the shared detail display `resources/views/admin/staff-order-detail.blade.php`, and this packet. No shared ledgers, workflows, census, production mounts or dependencies.

Acceptance: genuine synthetic observation and allocation commands record a succeeded refund; a subsequent failed observation retains allocation/history but removes its displayed amount. Mixed succeeded/failed/pending refunds retain a positive succeeded-only aggregate. Exercise authenticated directory and actual staff modal rendering, including customer detail. Invalid evidence must never fall back to raw allocation totals. Preserve existing refusal tests.

## Verification plan and slot restriction

PCC owns the exclusive database/heavy-test slot. Until released: source inspection, PHP syntax and Pint only; no DB/native/browser/build tests. Host has no PHP; use existing `va-pcc-runtime-20261101` PHP 8.4.27 via `docker exec --user 1000:1000 --workdir /workspace/VA-Studio-a5-display`, without modifying its PCC files or runtime.

After release: preserve the old StaffCommerceDirectory source outside Git, execute the new regression methods against that old source first, capture actual failure reason, restore candidate and run `tests/Feature/RefundDispute/StaffCommerceAdministrationTest.php` with standard CI SQLite environment from the handoff. Restoring the raw sum is the required display mutation. Run affected lifecycle tests if necessary. Use `.env.example`, absent `public/build`, own locked vendor metadata and physically contained packages for isolated rendering. No dependency update. No native concurrency claim from SQLite.

Independent money review must assess the actual tested SHA before PR. Merge order: PCC, membership, MIG gates, then A5. No push, PR, CI, activation or launch claim during preparation.

## Current evidence

Source inspection confirms the detail projection groups and sums every retained allocation, while domain case/reporting already use accepted snapshot status. Tests are queued, not executed; no reproduced failure or passing feature result claimed yet.

## Prepared implementation (database slot still held)

The directory now calls `RefundDisputeCase::load(..., forUpdate: false)->refundedByLine()` and projects its per-line result. An unreadable/incomplete case preserves original line details when available, marks evidence `attention` and renders `unknown`; it never falls back to a raw sum. The shared order/customer detail labels the amount `succeeded refunds by allocation`.

Three regression methods are prepared (not executed):

- `test_staff_detail_excludes_a_failed_refund_but_retains_its_allocation_and_status_history`: succeeded 101 → failed, allocation bytes retained, history retained, projected/rendered total zero in order and customer modals.
- `test_staff_detail_aggregates_only_succeeded_allocations_with_failed_and_pending_refunds_present`: succeeded 101 + 203, retained failed 307, pending 409; projected/rendered allocation 304 while raw retained sum remains 611. Customer projection is also checked.
- `test_paid_exception_detail_keeps_original_lines_and_shows_unknown_refunds_without_a_complete_case`: genuine late-confirmation exception with no grants stays readable, original total retained, refund allocation unknown, no A5 writes.

All use existing labelled synthetic policies/fixtures; no new commercial decision or real provider call. Pending allocations cannot be created by the real domain, so the pending object is deliberately unallocated.

Fresh preparation checks, all exit 0:

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-a5-display va-pcc-runtime-20261101 php -l app/Domain/Commerce/RefundDispute/StaffCommerceDirectory.php
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-a5-display va-pcc-runtime-20261101 php -l tests/Feature/RefundDispute/StaffCommerceAdministrationTest.php
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-a5-display va-pcc-runtime-20261101 php /workspace/VA-Studio/vendor/bin/pint --test app/Domain/Commerce/RefundDispute/StaffCommerceDirectory.php tests/Feature/RefundDispute/StaffCommerceAdministrationTest.php
git diff --check
```

Pint reports PASS for both files. No pure-unit check was needed: the changed behavior depends on database-backed accepted observations and real staff rendering. `.env` is copied from `.env.example`; `public/build` is absent. Own contained vendor/autoload setup is **not yet prepared** to avoid filesystem load during PCC's timed canary. Do that after slot release, without dependency changes; never use another worktree's generated app autoload metadata.

Old-source reproduction is preserved at `/workspace/pcc-evidence/a5-display-old-StaffCommerceDirectory.php` (copied using `git show 2ab0c41e:app/Domain/Commerce/RefundDispute/StaffCommerceDirectory.php`). It can also be reconstructed from that Git blob. After release, save candidate source, substitute this original source, run the first two methods with the command below, capture failures, restore candidate before any further check, then run all three plus the full affected file. This is queued old-source evidence, not a failure claim.

```sh
# Inside the lane worktree/runtime, after isolated locked vendor setup and slot release:
env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
  -- --colors=never --fail-on-phpunit-warning tests/Feature/RefundDispute/StaffCommerceAdministrationTest.php
# Old source: add --filter 'test_staff_detail_(excludes_a_failed|aggregates_only_succeeded)'
```

No native query, concurrency, full-suite, rendering pass, dependency audit, independent money approval or merge acceptance claimed. New methods extend an existing native-selected file; integrator should recalculate discovery/count comments only when integrating, without changing frozen census entries. Shared deferred ledger and CHANGELOG still need the integrator's tested receipt after actual verification/review; neither finding is closed by this preparation.

## Independent source review recorded

The separate review of exact source candidate `3f193d717b881abdadbc84a12cc49d9d71613836` is retained byte-for-byte in [independent-a5-display-source-3f193d71-DECISION.md](independent-a5-display-source-3f193d71-DECISION.md). It found no P0–P2 source findings and gives conditional source-only acceptance, **not final actual-tested-SHA approval**. This documentation update carries the reviewed runtime code unchanged.

Both deferred findings remain open. The exclusive DB slot still precedes contained locked vendor setup, old-source failure reproduction, and full affected SQLite staff projection/render/refusal coverage. Preserve exact commands, outcomes and source SHA; a final independent money review must assess the actual tested SHA and receipts before PR. Pending-with-retained-allocation and corrupt-evidence rendering coverage remain unexecuted limits described in the decision. No tests were run for this docs-only update; no acceptance, closure, merge, native-concurrency or launch claim is inferred.

## Actual focused execution and independent approval — 2026-11-02

The earlier queued/source-only entries above remain historical preparation records. The bounded display behavior is now verified at exact tested candidate `ac4eecda8c865d4e25baad8d588da3d1e4e72924`. Separate actual-source/evidence review **APPROVES**, with no P0–P2 finding, in [the exact independent decision](independent-a5-actual-ac4eecda-DECISION.md), copied byte-for-byte. This is focused development evidence. The duplicate findings are not yet merged or closed in shared ledgers.

Under root's explicit serial SQLite lease, the old-source phase ran once with only `StaffCommerceDirectory.php` substituted from exact baseline `2ab0c41e946e30cc402afc8ec107a3670e399abd`; candidate test/view bytes remained. Actual PHPUnit exit **1**: **2 tests, 3 assertions, 2 failures, 0 errors/skips**. At test lines 130/164 the actual wrong totals were respectively **101 versus expected 0** and **611 versus expected 304**. These were intended projection assertions, not setup/type/timeout failures. PHPUnit elapsed **12.828 s**, process **13.0295 s**. Root inspected the full log/JUnit and explicitly authorized continuation. Candidate source was restored exactly before the next phase.

The candidate full `StaffCommerceAdministrationTest.php` ran once, including all three new methods: actual exit **0**, **8 tests, 116 assertions, 0 failures/errors/skips**, PHPUnit **44.959 s**, process **45.1702 s**. PHP **8.4.27**, PHPUnit **12.5.34**. Passing cases exercise genuine synthetic observation/allocation commands, retained allocation/history, succeeded-only totals, actual order/customer Livewire modal rendering, paid_exception original lines/unknown display/no A5 writes, and existing privacy/MFA/authority/out-of-scope/unset-policy refusals. No separate duplicate regression invocation or other suite was run.

Exact execution commands (the root-authorized external runner captured these arguments, JUnit, log, actual PHPUnit exit, UTC start/end, elapsed and original/executed/restored file hashes):

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-a5-display va-pcc-runtime-20261101 env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/a5-evidence-20261102/old-execution/junit.xml tests/Feature/RefundDispute/StaffCommerceAdministrationTest.php --filter 'test_staff_detail_(excludes_a_failed|aggregates_only_succeeded)'
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-a5-display va-pcc-runtime-20261101 env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning --log-junit /workspace/a5-evidence-20261102/candidate-execution/junit.xml tests/Feature/RefundDispute/StaffCommerceAdministrationTest.php
```

Private synthetic-only receipts are external, not portable repository artifacts: `/workspace/a5-evidence-20261102/old-execution/` and `candidate-execution/` each retain `command.json`, `command.sh`, `phpunit.log`, `junit.xml`, `phpunit.exit`, `receipt.json` and exact old/candidate source snapshots. Each phase directory was exclusively created, preventing overwritten receipts. Both receipts record `restore_verified=true`; all three affected source/test/view hashes match the frozen candidate and the worktree was clean afterward. No PHP test process remained when the lease returned to root.

Runtime preparation copied 160 packages from the member lane only after exact `composer.lock` and installed package version/source/dist-reference agreement. The own contained vendor holds 35,698 files / 1,083,695,232 bytes; each copied file SHA256 matched its source. Only vendored `.git` internals were omitted. There are no package/resource symlinks or shared mutable Composer metadata. Own `composer dump-autoload --no-scripts --no-plugins --optimize` exited 0; existing ambiguous vendor classes and fixture PSR-4 skips remain recorded in the external log. No dependency updates or package scripts ran. Non-bootstrap reflection resolved this lane's StaffCommerceDirectory, Tests\TestCase and Tcpdf; contract fonts/renderer resources and vendor paths were physically contained. `.env` was reset to exact `.env.example`; `public/build` was absent. Preparation receipts are `vendor-copy-receipt.json`, `vendor-source-hashes.json`, `composer-autoload.log`, `reflection.log` and `runtime-ready.json` under the same private evidence root.

Limits remain: the pending refund is unallocated; succeeded→pending with retained allocations and corrupt-original/corrupt-snapshot modal rendering were not newly executed. Paid_exception unknown fallback was exercised. SQLite does not establish native MySQL query/locking/concurrency behavior. No real provider, production action, dependency audit, full branch/application suite or Foundation run is claimed. Earlier source review and these focused passes do not become whole-branch acceptance.

Publication/integration still waits for membership, then MIG, then A5. Root retains the exact integrated-SHA review, cheap preflight/protection gates and shared-ledger closure duties. This receipt batch changes documentation only; it authorizes no PR, push, merge, deployment or launch action. No additional test/DB invocation accompanied this documentation update.
