# C3 freshness consumer checkpoint

Worktree: `/workspace/VA-Studio-billing-retrieval-consumers`; branch `harness/billing-retrieval-consumers-20261013`.
Assigned base: `45d1299406821627f4ba02af186e324a70c6ade0`.
Producer helper dependency `a8facf90b1360f5c9b593b4bad40a5962be423b6` was cherry-picked as `f707675e`.
Consumer source commit: `cd58ada94e4e88c82d1d565dabc92cbaa01e112d`.

The worktree has its own Composer autoload metadata; package directories are symlinks. A direct ReflectionClass check returned `/workspace/VA-Studio-billing-retrieval-consumers/app/Domain/Memberships/Production/MembershipAwards.php`.

## Executed checks

- `bash /workspace/runtime/membership-c3-consumer-evidence/static.sh`: all eight owned files passed `php -l`; Pint `--test` passed; `git diff --check` passed. Retained separate stdout/exit files and identical before/after eight-file SHA-256 lists. This ran on the initial assigned base plus the then-uncommitted exact consumer bytes.
- `bash /workspace/runtime/membership-c3-consumer-evidence/unit.sh`: direct PHP8.4.26 PHPUnit12.5.34; `tests/Unit/BillingFreshnessWindowTest.php`; **9 tests, 17 assertions, zero skips/errors/failures, exit0**. Retained exact script, text, JUnit, exit, source overlay, base and identical before/after eleven-file SHA-256 lists. The consumer bytes were committed unchanged immediately afterwards as `cd58ada94e4e88c82d1d565dabc92cbaa01e112d`.
- `git diff --cached --check` passed before the consumer commit.

No test-body/database commands were run during the prior C2 native freeze. The pure unit run began only after root explicitly released focused SQLite execution. These nine arithmetic tests open no database and make no SQL or provider request.

## Scope and limits

The unit tests use explicit synthetic UTC values and Reflection to reach private technical-window arithmetic. A synthetic future monotonic anchor makes real runtime elapsed checks lenient while exact cap arithmetic is asserted. They prove boundary equality, no upward renewal, query-start latency accounting, overflow refusal, backwards-sample refusal, permanent expiry retirement, and non-copyable technical identity. They do not prove physical elapsed time, host/database UTC correctness, a native MySQL query, or paid authority issuance.

`MembershipBillingFreshnessTest` contains two real evidence/accessor cases plus four award/reserve precommit/aftercommit datasets. **None has run yet** at this checkpoint. Runtime migration263, producer runtime and fixture dependencies are still pending integration. The Context boundary fixtures deliberately shorten only the actual issuer-window's private monotonic cap using test Reflection. They retain the same receipt/PDO identity and readonly original cap, directly inspect Context terminal admission, then let the normal operation complete/refuse. This is synthetic cap-consumption/propagation evidence, not ten-minute physical freshness expiry. An omitted-Context-admission comparison is planned after integration.

No Foundation/GitLab dispatch, native suite, provider access, publication, runtime activation, commercial input change, period reseal or invoice graph change was performed. Consumer commit is available for integration, not acceptance-ready.

## Upgraded native interval race source checkpoint

Test-only commit `b229f5bfa4ea0bedfd15dd687908bc9d92259d62` adds `BillingNativeDatabaseIntervalOwnershipRaceTest.php`. Dependencies cherry-picked locally: schema `57c9bf9fc3dc5e326248abc8baa3e1b14e4a5ece` as `a5445c88`, shared fixtures/worker receipts `ecc971d95c5de5d446e6be7f0f237069809f2566` as `05c9f0f8`. The producer's typed-interval runtime writer is still pending at this source checkpoint.

`bash /workspace/runtime/membership-c3-consumer-evidence/native-arbitration-static.sh` passed syntax and Pint and listed exactly one test method. Retained separate outputs/exits and identical before/after hashes for the new file plus shared worker. Discovery ran no test body or database migration. No native execution/pass is claimed.

The new method positively controls each exact observation/attempt candidate with an independent insert+rollback under the upgraded guards before racing the same complete issued start/end identity graph. A parent-held start row allows explicit `performance_schema` evidence that both independent worker connections are waiting; both workers record their earlier repeatable-read snapshots. Outcomes permit exactly one saved row and one SQLSTATE45000 or bounded1213 deadlock refusal. A45000 loser records its still-old snapshot; a deadlock victim makes no post-rollback snapshot claim. JSON transcripts retain process/connection identities, version/schema, waits and actual outcomes. The method checks final retained envelope/interval identity, no award/credit events, and idle framework/PDO transactions. It deliberately does not reuse impossible cross-phase token combinations from the retained262 historical race.

## Context terminal-admission omission comparison

Coherent integrated base for both runs: `bea13e9cf4f6d50fe4c51023186afd6e846ccefc`, checked out detached in the independent consumer worktree. Earlier consumer branch and commits remain preserved. Own autoload Reflection again returned this worktree's `MembershipAwardContext.php`.

Exact command runner: `terminal-check.sh`, invoked first with `terminal-omission-red`, then `terminal-restored-green`. Both use direct PHP8.4.26/PHPUnit12.5.34, isolated SQLite `:memory:`, separate `/tmp/va-studio-membership-c3-terminal`, and exactly this filter:

`MembershipBillingFreshnessTest::test_owned_context_carries_the_original_cap_through_precommit_and_committed_admission`

Four datasets: award before commit; award after commit; reserve before commit; reserve after commit.

The red overlay (`terminal-omission.overlay.patch`) deletes only `$this->invoiceFreshness?->assertDeadline();` from `MembershipAwardContext::admit` and `MembershipReservationContext::admit`. No test, billing producer, snapshot helper or other Context code changed. **Red: 4 tests,56 assertions,4 expected failures,zero errors/skips,exit1.** Every failure was the same assertion at test line137: the separately observed Context `contextReason` was `null`, expected `expired_invoice_proof`. The ordinary operation still refused via other source checks; those refusals by themselves are not evidence that Context terminal admission retained its cap. This comparison specifically demonstrates that the direct Context admission assertion detects omission of its carried original window. It does not claim an unauthorized award/reservation became durable in the mutant.

`git restore --` restored exactly the two owned Context files. `git diff --exit-code` and `git status --short` confirmed clean integrated source before green. **Restored green: 4 tests,80 assertions,zero errors/failures/skips,exit0.** The same four datasets additionally verified their expected precommit rollback versus postcommit durable period/event/redemption counts and idle transactions. Both runs retained all1243 listed PHP-source before/after SHA-256 hashes unchanged within their own run. Final green tracked diff/status are empty; no product/test edits or commits were made for this comparison.

Separate JUnit/text/exit/start/finish/base/hash receipts are retained for both runs. These remain explicit synthetic cap-consumption tests of actual issuer-window propagation. No native database workload or physical ten-minute freshness-expiry claim was performed.
