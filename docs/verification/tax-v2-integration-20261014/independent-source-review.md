# Independent Tax255 prerequisite source/test review — 128f423d

**Bounded source result: APPROVE the inspected source/test design at exact clean `128f423d59316b530996c8d2cec8622d8b216601`, subject to the planned completed affected SQLite/native/quality receipts and separate final sealed-candidate evidence review. No P0/P1, material source finding or required source/test correction was established. This is not final batch/merge approval and does not establish completed Paid252 V2 wiring, consumer commit admission or activation.**

Reviewer: `/root/c4c_final_packet_review`, separate non-author reviewer applying repository AGENTS, Code Verification and its matrix. Read the current prerequisite design, Tax255 independent decision/addendum A1-1/A1-2 and its consumer/self-consistency conditions, relevant architecture/payment/amount boundaries and migration ledgers. Target is the five PHP changes against actual A2 merge `cfce548b4f0893a3634422062099d930428856a3`, carrying receipt-only `291a0d90ee3c8199a366bf79da4f58a4992cccf9`. Old implementation/test baseline is `0f8e16b19f68eb55b3d390cb69d174ccd6d0c59f`. Only this runtime note was written; no app/DB/test/Pint/CI/host/network execution, tracked edit or delegation occurred. The author's affected SQLite recorder was active during this review and is not assigned a passing result here.

## Source and specification assessment

The only changed application file is `ProductionTaxPaidLineAdapterV2.php`; the other four PHP paths are tests. Current actual bytes of all five paths match their exact128 Git blobs. SourceV2, HeldSourceTransaction, CurrentRows, CommandTransaction, tax evidence/provider implementation, frozen V1 and all migration/schema/contracts/resources/profile/route/config/lock/workflow paths have no changed bytes against the actual base. No flag, mount or merchant/provider capability is enabled. Git diff --check passes; clean exact HEAD was checked before and after review.

`accept` now requires the caller's typed CurrentRows and string expectedOrderId, with no optional/default admission bypass. Synthetic provenance refusal still comes first. It calls the existing SourceV2 proveRetainedCurrent before line acceptance. That proof checks the exact captured connection/primary/driver/database/prefix, framework/native depth1, native transaction, original savepoint continuity, retained order/intent rows, historical identity and table state, then checks transaction continuity again. A newly opened frame on the same primary cannot recreate the random original anchor. Another primary, nesting and autocommit fail held_transaction.

The existing canonical comparison still binds the candidate to the exact position minted by that source's APP_KEY-sealed retained state. The new strict line order_id===expectedOrderId comparison refuses source_order when the caller selected another grant order. The line is returned unchanged only after existing V2 shape, provenance, execution-context, hashes, arithmetic and ceiling checks. No unkeyed self-hash becomes authority. CanonicalJson rejects executable object types rather than invoking arbitrary JsonSerializable callbacks; these in-memory comparison/self-check steps do not create a new callback seam between the held proof and return.

This closes A1-1's adapter-side missing held-currentness/intended-order prerequisites. The expected order remains a trusted caller obligation: the future consumer must independently select/lock its target, derive order/line/origin/buyer authority only from the returned line and keep its terminal proveRetainedCurrent after use. This batch deliberately supplies no V2 committed-read receipt, physical write/consumer admission or Paid252 schema/command/policy wiring; those remain reviewed dependency work before fulfillment/activation. An accepted line is not itself a grant or durable fulfillment receipt.

assertSelfConsistent retains its public pure test seam and now has the required @internal non-authentication annotation. Grep of current app finds only accept calling it; no app consumer calls the non-authenticating seam. SourceV2 and V1 shapes/readers remain separate; live/verified_production acceptance remains refused before source comparison. Future consumer review must recheck this caller invariant.

## Test quality and behavioral traceability

| Condition/risk | Test evidence reviewed | Assessment |
| --- | --- | --- |
| Closed held frame | Genuine synthetic paid order/source/reader escape CommandTransaction; framework/native autocommit are observed, acceptance must throw held_transaction, retained reviewed rows remain exact and no ordinary grant/origin is created. | Meaningful regression; old source demonstrably accepts and fails the test. |
| Fresh frame on same primary | Old source is used with the new frame's current reader at real framework/native depth1. Original anchor continuity must fail despite the superficially valid depth. | Required design coverage present. |
| Wrong caller target | Current source/line plus a distinct UUID must throw source_order, then the same genuine line with the fixture's selected order succeeds unchanged inside the held frame. | Meaningful regression; old source demonstrably accepts and fails the test. |
| Other primary/nesting and positive recovery | Independent PDO/CurrentRows and real nested DB depth2 each refuse held_transaction; after nested frame closes, the genuine original source/reader/order accepts exact line. | Design coverage present; SQLite covers different primary with same driver. Native selection will also exercise real native current source/frame semantics. |
| A1-2 five missing money checks | Journey cases separately change PaymentIntent livemode, request metadata, on_behalf_of, session line currency, and PaymentIntent processing status while session paid. Shared public reconcile oracle demands provider_uncertain503, zero reviewed retention and unverified status, then removes that single alteration and requires genuine verified5436 retry. | All five required cases ported; pending current executed receipts. |
| Existing line authenticity/V1/live/ceiling behavior | SourceV2 roundtrip/forgery/live cases now carry actual reader/order through helpers and retain their after-use source proof. Isolated adapter shape/hash cases still clearly use nonbinding synthetic input. Reflection supplements behavioral cases by requiring both new arguments. | Existing oracles retained; source-only structural check is supplementary. |

The five Journey additions reuse actual enrolled buyer/catalog/order/assent/request/session flow and mutate only the named synthetic provider fact. They do not merely assert private method calls. A processing PaymentIntent must refuse even when returning pending would retain no money; removing the paid/succeeded conjunction would fail this refusal oracle. Prior independent Tax255 mutation evidence showed these checks survived the old Journey class but were killed by the separate money probe. Porting those same assertions addresses A1-2's owned-suite gap; current passing executions and any later mutation execution remain separate evidence.

The two new regression methods and all five money data cases are byte-identical between old test-only baseline0f8e16b1 and current128; no oracle was weakened to make the implementation pass. All current accept callsites were inspected: no stale four-argument application caller exists; existing SourceV2 test helpers are updated with actual CurrentRows and independently located orderId. No additional mandatory test was found beyond the already planned whole affected files, meaningful native source/payment cases, formatting and final packet review. There is no request for a full routine matrix or DB concurrency claim for this bounded adapter change.

## Completed old-source receipts inspected directly

Read only already completed command/result/process/JUnit receipts under `/workspace/runtime/tax-v2-integration-evidence-20261014/`:

| Source / invocation | Actual tests/assertions | Outcome |
| --- | ---: | --- |
| 0f8e16b1, sqlite-closed-frame-old-source |1/4|One expected failure: The V2 adapter admitted a source outside its held frame or expected order. Actual marker/process/verifier1; zero errors/skips. |
| 0f8e16b1, sqlite-expected-order-old-source |1/2|Same intended acceptance failure in the wrong-order method. Actual marker/process/verifier1; zero errors/skips. |

Both exact filters execute one named new behavioral test; all source/HEAD/status/runner/autoload stability gates are true. Failure stacks reach the test's fail assertion after old accept returned; no API TypeError or fixture setup error is used as regression evidence. Old accept's four-argument method actually accepts the candidate while ignoring the extra PHP user-function arguments; its unchanged source was read. This bounded phase reconciles these receipts and actual reviewed files, not the complete final portable manifest/source archive.

## Remaining evidence and acceptance gates

Complete the owned affected SQLite files, meaningful private MySQL source/payment selection and changed-five-file Pint. Native server8.4.11 readiness on33515/socket/schema is environment preparation, not an executed case or concurrency acceptance. Seal commands/actual exits/logs/XML/source/runner/loader before-and-after evidence at the final candidate, retain both original failures, and obtain a separate final immutable packet/source DECISION before exact published-head cheap preflight/protections/expected-SHA merge.

The prerequisite design is satisfied by the inspected code, and engineering/source quality has no established blocker. Executable verification of current source is pending review, so the whole batch's verification status is Partial here. Do not label A1-1's future consumer obligation fulfilled, or infer real merchant/provider/tax/account facts, committed-read/write admission, Paid252 V2 fulfillment, live funds, mount or launch acceptance. Frozen profiles/assets/migrations/V1 bytes and all existing commercial/host/physical conditions remain unchanged. No Foundation/GitLab/global acceptance is implied.

## Actual reviewed PHP SHA-256

- `app/Domain/Commerce/ProductionTaxCheckout/ProductionTaxPaidLineAdapterV2.php`: `f43aa15406a892f593d3c75d5494f568ced7a1093174edb6bf0222845907c22b`.
- `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutJourneyTest.php`: `5dfb199c41319123ae4a88b852c24f2e62074a42d461b85b5ac240546afb9d77`.
- `tests/Feature/ProductionTaxCheckout/ProductionTaxPaidLineAdapterV2Test.php`: `b304c2f0a909a5921bfac59791ab923b272d1d0550db6f9f35ebd2170e5dc32c`.
- `tests/Feature/ProductionTaxCheckout/ProductionTaxPaidSourceAdmissionTest.php`: `186e118a56713b138b411bea42810065d356c823dc09f6d3ed7459bcac3f143d`.
- `tests/Feature/ProductionTaxCheckout/ProductionTaxSourceV2Test.php`: `8af171d01a631f0fb9489a735aeed798c1687720116e60bf3bf85453685df71a`.

Recorded UTC: 2026-10-14T21:20:03.291826+00:00
