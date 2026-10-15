# Independent amended A5 design decision

DECISION: APPROVE DESIGN WITH CONDITIONS

Reviewed immutable **`b6a8b72cac8af133e003f8daa48a04b97c99d09c`**, compared only with the prior three requirements at `ef74c3cbd4104fbee8449efc36161860ae068b30`. All three amendments satisfy the planning gate; no further required design repair remains. Original ef74 REVISE files retain their exact hashes. This authorizes implementation of the bounded first slice, not a source/test/financial/provider/merge pass.

The contract now explicitly produces an unattested `FinancialCandidate`: consistent_candidate, providerAuthenticated=false and unattested_input. Matching source identity does not authenticate provider retrieval or current financial state. The later ledger needs authenticated retrieval plus original source/positive-commit admission; this value supplies none of those capabilities.

The fixed summary separates original gross, the provider-reported Charge aggregate/flags, every exact refund status sum/count and dispute history. Historical failed/canceled/pending attempt sums may exceed gross across distinct attempts without becoming settled totals. The succeeded-sum comparison boolean does not establish atomic or final evidence. Exact USD/integer/bounds/64-bit arithmetic and invalid/incomplete taxonomy govern implementation; no legal/access/availability disposition is inferred.

Both factory and normalization policies are literal/defaultfalse, raw-admitted before callbacks/offsets and rechecked after existing proof/extraction. Reconstruction, serialization and cloning refuse. Original held-source proofs own existing SQL/marker work; the new wrappers add no SQL/DML, source/frame/clock/commit/cleanup authority. Factory failure leaves cleanup with the actual original owner.

The unchanged public SourceV1/V2 line APIs expose original PaymentIntent/account/gross and separate source versions, **no original Charge ID**. Implementation must therefore bind before/after Charges to the supplied PaymentIntent.latest_charge within the unattested envelope; it cannot claim comparison to an unavailable original Charge source field. Iterate the actual complete line count with original before/after proofs, preserving Tax buyer-reviewed gross rather than pre-tax substitution or repeated-gross summation.

Required next gates: genuine synthetic V1/Tax held-frame/multiline/wrong-reader/source and new-policy withdrawal tests; callback-free malformed metadata and serialization/shape/list/status/money/currency/account/Charge controls; frozen-source focused compatibility/static evidence with all original failures/exits; independent actual source and final sealed evidence reviews; exact-head cheap preflight and expected-SHA merge. A real retained Crypt callback is a proportionate policy-withdrawal seam, but its success remains untested here. Unchanged SQL needs no new native workload solely for this contract.

Sean's business policies, live/provider/merchant/host operations and all original release/shared-P2 boundaries remain open. Declared documentation lookup failures remain preparation failures, not authenticated provider evidence. Root-owned untracked imports of unchanged review documentation are outside the reviewed commit; no product change is present. No reviewer code/test/DB/network/CI or shared edit occurred.
