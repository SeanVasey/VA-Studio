# Independent final source carry — exact f0f55324

**APPROVE SOURCE WITH THE EXISTING EXECUTION CONDITIONS. No validated P0–P2 finding in the final carry.** Exact reviewed clean head: `f0f553243e31a128bc08d7f9624d1ffc3a564fd3`. This reviewer did not author the candidate. This is a bounded final source review, not runtime, merge, mount or launch acceptance.

Publication-only presentation refresh: source approval and execution conditions are unchanged. Execution status below is as of the original source review; this refresh makes no claim about subsequent ROOT runtime outcomes. The original decision is retained privately, SHA256 `d1df22b728e8ee6c62c6c5a18adb81ec77b489bd0a4e9dcb569dff88cdf99fe0`.

## Source continuity

Read-only Git verification found the entire App tree identical across independently reviewed `a249574f3c8d3a3e31c26a864b023ea47d01c33f`, tested `5b001577b0521cf472f14b8ae88fc13ad2567e3b` and exactf0: tree `809fc7124ce21147501627918316acc0ba949987`. No sensitive App change requires another review here.

The reviewed [IdentityRows access path](https://github.com/SeanVasey/VA-Studio/blob/f0f553243e31a128bc08d7f9624d1ffc3a564fd3/app/Domain/Customers/ProductionIdentity/IdentityRows.php) uses the existing unique `(origin_id, sequence)` index only for the exact native locking origin predicate. Existing IdentityMigrationOwnership/IdentitySchema admission authenticates index name, ordered columns, uniqueness and physical metadata before use; owned-frame metadata locks/currentness preserve that admitted definition. Selected rows, bounds, id ordering, FOR UPDATE, owner authentication, immutable-prefix checks, current/committed proofs and deadlines are preserved. Existing gap-lock, filesort, contention and target-host limits remain.

The unchanged [paging implementation](https://github.com/SeanVasey/VA-Studio/blob/f0f553243e31a128bc08d7f9624d1ffc3a564fd3/app/Domain/Memberships/Production/MembershipCustomerIndex.php) retains account-scoped keyset anchors, full anchor/window/sentinel history in current/committed/pre-bytes proofs, full-binding deduplication with changed-fact refusal, original60-second deadline and MAX_PERIODS100. The same controller action retains N7 admission; no alternate mount or authorization bypass is introduced. The [manual counter](https://github.com/SeanVasey/VA-Studio/blob/f0f553243e31a128bc08d7f9624d1ffc3a564fd3/docs/verification/member-mount-gates-20261101/HistoricalProofCounter.php) retains exact2B retained-proof counts, excludes current-principal wrappers from that instrumentation count, and preserves argument-free bounded stack inspection and original SensitiveParameter/text/binary HMAC delegation. The carried Ops fixture repair waits for a complete positive ASCII PID within the original deadline and retains its lock/orphan/resume/cleanup assertions. These source conclusions support this standalone approval; unchanged surfaces were not re-reviewed during the final carry.

Retained independent review provenance (SHA256; originals retained privately):

| Scope / reviewed source | Decision digest |
| --- | --- |
| Existing-index correction, a249574f | `c25457070b79450ef3d97dcb3cf00c73540a8ae6c2493b368f374613787e9fc9` |
| Paging, 4ecc9f56 | `34e5de880ab94245447b4bd43a45f0937e84e9b112fadb053e3a75d2a1110dfd` |
| Counter, 06c33a60 | `e9d127eae31bcf39eeaaa3c106b9213181b86c0580425878ca5da144908c2c33` |
| Remaining final delta, 06c33a60 | `49325098f6b3517fd7c1c88fda9ebe3ea31045cda72f5979bbfcb4637710c1ae` |
| Ops lease readiness, c012a11f | `5404f495e0c055cda6a5f30dd898e25c65bb769dad99bbdfcdc8d4878310bc61` |

## Complete later delta

Only four paths changed in the [a249→f0 carry](https://github.com/SeanVasey/VA-Studio/compare/a249574f3c8d3a3e31c26a864b023ea47d01c33f...f0f553243e31a128bc08d7f9624d1ffc3a564fd3):

- Native regression: corrected the invalid `ProductionCustomerSessions` import to the actual `ProductionIdentity` namespace and sorted those two imports. Its entire core test body, real recovery/retained-binding/foreign-award protocol and assertions are unchanged. The wrong-import setup failure remains failed evidence, not a baseline red or accepted execution.
- SQLite census: appends exactly the new MySQL-only recovered-index method. Old335 entries remain the exact ordered prefix of336; first150 compact-JSON hash remains `d93da4e7a13a8c6aff8f97f363eb6d93dde884bad53c9ae50eeb0ed32514c71c`. The existing native class is selected; this is explicit engine routing, not removal or quarantine of a product case.
- GitHub and GitLab: descriptive count comments only, to2709 selected of10178 cases in269 files. No workflow job, trigger, guard or cost-policy behavior changes.

The public discovery receipt records actualb2 source, generator/outer exits0,25 listing witnesses, zero prepared events and those exact counts; it explicitly claims no product execution and no retained per-PHP raw streams/stderr-emptiness proof. b2→f0 is only the two comment changes, so carrying its descriptive discovery counts is justified without relabeling the executed source.

## Evidence and remaining condition

Inspected public summaries report5b001's focused native1/26 plus owner-native4/83, totaling five selected cases/109 assertions, and selected SQLite68/2454. They retain their actual source attribution; App/test bytes carry tof0, not a new f0 test execution. Public local CI safeguard evidence reports207 tests and all five exits0; this is not hosted Foundation acceptance.

**All four original physical pools on exactf0 remain UNRUN**, awaiting source sealing/cold qualification and ROOT execution. Require their complete original results: real recovered bindings, all nine samples/workers, full100 traversal, exact2B proof counts, unchanged60-second requests, source origins, original JUnit and complete outer/observer/cleanup/lease evidence. Preserve old counter, setup, domain-deadline, transport/observer and original physical failures at their original source; do not infer acceptance from partial samples or provisional JUnit.

ROOT may satisfy these already-defined runtime conditions and record accepted results without another full source review of unchangedf0. A substantive new source delta or concrete new failure requires its bounded impact assessment. Membership mounting remains literal-false; final exact-SHA/manual Foundation and other release gates are unchanged.

Only source/status/diff/blob/census inspection and public evidence-summary reads occurred. No PHP, SQL, Docker, test, vendor, working-tree or existing-evidence mutation was performed. The original review wrote only its external decision. This presentation refresh archives that decision byte-for-byte and replaces only this unpublished public copy; no source review, runtime assessment or approval condition is added.
