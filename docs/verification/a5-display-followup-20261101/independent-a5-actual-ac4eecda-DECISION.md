# Independent A5 actual execution decision

Candidate: `ac4eecda8c865d4e25baad8d588da3d1e4e72924` in `/workspace/VA-Studio-a5-display`.
A5 implementation baseline: `2ab0c41e946e30cc402afc8ec107a3670e399abd`.
Prior source-review runtime: `3f193d717b881abdadbc84a12cc49d9d71613836`.
Reviewer: separate non-author Sol 6.1, applying code-verification. Read-only source and completed evidence inspection; this external decision is the only file written. No tests, DB/container/CI operations, product/shared-ledger edits, push or PR.

**APPROVE the bounded A5 display repair at the exact tested candidate, with the coverage limits below. No P0–P2 finding identified.** This is independent source plus actual focused SQLite/Livewire evidence approval, not approval to merge out of order, production readiness, native concurrency or broader branch acceptance. Root retains MEMBER→MIG→A5 integration/publication gates and final integrated-SHA duties.

## Source and invariant assessment

Reviewed applicable AGENTS, packet and prior independent source decision; inspected the A5 baseline diff, complete StaffCommerceDirectory, read-only RefundDisputeCase loading/status/aggregation, RefundDisputeAuthority, Filament detail consumers, shared order/customer templates and affected test sources. Independently compared the three owned runtime/test/view paths across 3f193d71 and ac4eecda: all identical. Broader branch differences include already integrated PCC; this decision does not re-review or assert whole-branch byte identity.

At StaffCommerceDirectory.php:96–111, the raw retained RefundLineAllocation sum is replaced by the existing RefundDisputeCase::load(...,forUpdate:false)->refundedByLine(). The load verifies original line/payment/grant consistency and decrypts/cross-checks the latest accepted observed snapshot against registered object identity, amount and currency. False bypasses its lockForUpdate branches; loading/refundedByLine performs reads only. RefundDisputeCase.php:141–155 initializes every verified line to zero and includes allocations only when that object's accepted snapshot status is exactly succeeded. Failed, pending, absent or other statuses cannot contribute. No new money, refund-policy, grant, rights, access or allocation/history write is added.

If original verification or complete case loading fails, allocatedMinor remains null; evidence is attention and the escaped Blade view renders unknown. Original verified line title/total survives a case failure, including paid_exception without grants. There is no raw-sum fallback. Both authenticated order and customer detail use the same private projection and shared template. Existing fresh staff/MFA checks, configured own-account test scoping and minimized public/keyed references remain; no bearer/provider identifier, raw customer data or new unsafe output is introduced by this change.

## Actual evidence and identity

The candidate execution was active on initial inspection. Its partial JUnit/log was not used as a completed result. After completion notification, independently parsed the final JUnit, read full log, command.json and receipt.json, then checked current hashes and clean HEAD.

- Old counterfactual: old-execution/receipt.json records candidate ac4eecda with only actual 2ab0c41e StaffCommerceDirectory substituted. Independently compared old-source.php with that exact Git blob: identical. Actual PHPunit exit1; JUnit2 tests / 3 assertions / 2 failures / 0 errors or skips. Failed total was 101 against expected 0 after succeeded→failed; mixed total 611 against expected 304. These are intended product-projection failures, not setup/API errors. Candidate view/test bytes were retained. restore_verified=true and restored hashes match current files.
- Candidate: candidate-execution/receipt.json records exact ac4eecda, one full affected-file invocation, actual exit0; full JUnit8 tests / 116 assertions / 0 failures, errors or skips. PHPUnit log agrees, PHP 8.4.27, 44.959 s test time / 45.1702 s process. The command uses SQLite:memory:, testing/CI environment, own lane vendor/autoload, fail-on-phpunit-warning and tests/Feature/RefundDispute/StaffCommerceAdministrationTest.php.
- Independently recomputed all three current source/test/view SHA256 hashes: they match executed_hashes, original_hashes and restored_hashes. Current HEAD is ac4eecda and git status is clean. Production source remained candidate bytes in the successful invocation.

The eight actual passing cases cover guest/customer/MFA denial and resource abilities; genuine synthetic observation/allocation/actions and customer detail/privacy; failed-refund retained-allocation exclusion; succeeded-only 101 + 203 = 304 while raw retained sum 611 persists; real order/customer modal rendering; paid_exception original lines plus unknown display/no A5 rows; unset-policy no-write refusal; grouped-query source behavior; and rechecked detail authority/out-of-scope refusal. New expected totals derive from independent arithmetic, not the aggregation implementation. Allocation bytes/status history and original evidence assertions remain. This establishes a meaningful old-red/candidate-green display regression, including actual rendering rather than projection-only stubs.

## Limits retained

Pending 409 in the mixed test is deliberately unallocated; this execution does not prove a succeeded→pending transition with retained allocations. The strict succeeded helper predicate establishes its exclusion by source inspection; no fabricated retained pending allocation is required for this bounded repair. Corrupt-original/corrupt-snapshot rendering is not newly executed; unreadable complete-case behavior is directly exercised through a genuine paid_exception and null/unknown fallback, with corruption refusal supported by source inspection. These limits do not identify a remaining P0–P2 source defect.

SQLite grouped-query assertions do not prove actual MySQL ONLY_FULL_GROUP_BY, locks or concurrency. No new native guarantee is claimed. No real provider, refund, live account, production mount/activation, dependency audit, full application suite or Foundation run was performed or inferred. Existing synthetic fixture policies do not become commercial decisions. Broader final acceptance, cheap preflight/protected reviews and exact integrated-source review remain root-owned.

The two duplicate display findings may be recorded as addressed for this bounded candidate with this actual evidence; any later integrated changes must preserve the status-aware projection and scope. This decision does not change merge order or authorize publication by itself.
