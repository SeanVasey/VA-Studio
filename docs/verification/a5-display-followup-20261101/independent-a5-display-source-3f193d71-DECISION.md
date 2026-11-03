# Independent A5 display source review — 2026-11-01

Reviewer: separate non-author agent, Sol 6.1. Source-only review.

Candidate: `3f193d717b881abdadbc84a12cc49d9d71613836`
Base main: `2ab0c41e946e30cc402afc8ec107a3670e399abd`
Worktree: `/workspace/VA-Studio-a5-display` (HEAD matched candidate; clean status at inspection).

Decision: **SOURCE-ONLY CONDITIONAL ACCEPTANCE; no final actual-tested-SHA approval.** No P0, P1 or P2 finding identified in the exact candidate diff. Deferred Codex P2 r4368889957 / Bugbot r4368906963 must remain open until execution evidence and final independent review are recorded.

## Reviewed evidence

Read root AGENTS, November 1 handoff, deferred-ledger A5 entries, and candidate verification packet. Inspected the exact base-to-candidate diff (four files), complete StaffCommerceDirectory, RefundDisputeCase and RefundDisputeAuthority, affected Filament callers/shared Blade views, existing administration tests, new regression sources, real observation/allocation fixtures and existing lifecycle coverage references. No application source was edited.

The directory replaces its raw retained-allocation sum with the existing domain `RefundDisputeCase::load(..., forUpdate: false)->refundedByLine()`. The helper derives object status from the latest accepted encrypted observation, checks observation identity and registered object amount/currency, keys totals by actual order-line IDs, and initializes every verified line to zero. It counts only status `succeeded`; failed, pending, absent and other statuses cannot contribute retained allocations. No raw-sum fallback remains. If original evidence cannot be verified or case loading fails, allocations remain absent and project null; the view shows `unknown`, and evidence is `attention`. Verified original title/total survives an incomplete case, including paid_exception without grants.

Existing configured own-test-account/order/payment scoping, fresh staff/MFA checks, and upstream capture/provider evidence boundaries are unchanged. Helper loading with false never selects its lockForUpdate branches and performs no domain writes. No pricing, refund policy, grant, payment, access-right, allocation, or history mutation was added. The view continues escaped interpolation and introduces no provider identifier, bearer key, private metadata or customer-data projection. Customer details call the same private order projection and include the same shared Blade view as order details.

New tests invoke genuine synthetic provider observation and allocation domain commands, rather than mocking the projection/helper. Their expectations use independent arithmetic (101+203=304 versus retained 611), verify retained allocation bytes/history and original evidence, and exercise actual Livewire modal sources. The paid_exception fixture uses a real late payment finalization and checks no A5 rows were written. These are meaningful regression sources, not evidence that execution has passed.

## Outstanding execution conditions

1. Wait for the exclusive PCC database/heavy-test slot to be released. Do not restart, duplicate or disturb its frozen `5e4b93` full run. This review ran no DB, rendering, native, browser, build, heavy, CI or live action.
2. Prepare the lane's contained locked vendor/autoload environment as documented. Execute the first two new regression methods against the original base directory source and retain failures proving the incorrect displayed retained totals, rather than an environment/setup failure. Restore candidate source before candidate verification.
3. Execute the candidate's full `StaffCommerceAdministrationTest.php` with the documented SQLite CI environment, including real order and customer modal rendering, old privacy/authority/refusal coverage, the mixed-status total and paid_exception fallback. Preserve exact command, exit status and test counts, tied to this exact commit or its fully reviewed successor.
4. Final reviewer must assess those receipts against the actual tested SHA and any resulting changes. Syntax/Pint/diff checks alone do not close this money-display finding. No merge/launch/native-concurrency approval is given here.

Additional unexecuted coverage limits: the new pending refund is unallocated; it does not independently test succeeded→pending with retained rows. Source inspection establishes exclusion through the helper's strict succeeded predicate, and existing lifecycle tests include a regression scenario, but no new runtime proof for that display transition is claimed. Corrupt-original/corrupt-snapshot fallbacks are established by source inspection and not a new rendering regression. These do not identify a source defect or require unrelated refactoring.

No secrets, customer data, address, logo/profile assets, production actions or repository source changes were introduced by this review.
