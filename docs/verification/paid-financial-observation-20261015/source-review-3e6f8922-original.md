# Independent A5 source checkpoint

DECISION: REVISE

Reviewed exact source **`3e6f89222c0832c08bf0205ee79077935b0c4e28`** against approved design `b6a8b72cac8af133e003f8daa48a04b97c99d09c`. No open P0/P1 found; two owned P2 corrections are required. This is source review, not final evidence or merge approval.

1. `FinancialObservation::ownAccount` omits the actual Stripe Charge.transfer pointer. A non-null destination transfer is silently ignored and a candidate is accepted despite the specified transfer-ambiguity refusal. Reject that field and add before/after negatives; leave the legacy financial normalizer unchanged.
2. The expectation drops original execution_context.capture_method and normalization hardcodes automatic. Both original V1 and Tax sources support automatic_async and bind the actual provider method to that context. Retain the original supported method in the typed financial tuple and require exact raw PI matching. Use actual authored/reviewed/paid async V1 and Tax journeys to prove compatibility, rather than editing original rows or reflecting sources.

No additional material source correction was found in this bounded pass. The new slice otherwise remains coherent: original held sources/proofs and complete line extraction; captured literal/raw policy checks; separate buyer-reviewed Tax gross; bounded primitive inputs/lists/status sums; fixed unattested summary with no settlement/disposition effect; reconstruction refusal; current policy gates and caller-owned cleanup. No new SQL/provider/clock/frame/receipt/commit authority is introduced.

The current10/331 and affected old69/291 passes are useful focused evidence but lacked the two missing cases. Root reports test-only f9 controls reached both async rejections and the accepted transfer,3/7/F1/E2 with PHP2/recorder1/OS1. Root also preserved52/1F safeguard discovery from a test driver getter. Replacing that getter with captured reader primary/driver is a test-only correction preserving the real wrong-reader check, without policy weakening. These source-note outcomes are reported, not reviewer-executed or final portable reconciliation.

Preserve originals, complete the two-source repair and meaningful targeted/compatibility/static checks on frozen source, then fresh actual source and final sealed evidence gates. Exact-head cheap preflight and expected-SHA merge remain. No new native matrix is warranted solely for pure normalization/identity correction. All defaultfalse/unattested/no-effects/business-policy/host/provider/live/frozen-P2 conditions remain.

The reviewer read source/tests only and wrote original runtime MD/JSON. No implementation/test/DB/network/CI/shared-file operation occurred.
