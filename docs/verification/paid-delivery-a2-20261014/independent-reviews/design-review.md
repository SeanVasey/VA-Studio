# Independent A2 request-start timing design review

Reviewer: `/root/c4c_final_packet_review`, separate non-author reviewer applying AGENTS, Code Verification and its matrix. Target: proposed `/workspace/VA-Studio-paid-delivery-a2/docs/verification/paid-delivery-a2-20261014/request-start-design.md` on base `98bb9a917e77cbb12a40b4d1b4ad01b152092cdb`. Proposal SHA-256 at review: `cc39b48ff82d1811aed59118559cce633fea3541495421863da760cdb3360acb`. No implementation existed. Only this runtime note was written; no application/DB/test/host/CI/network work or source edit occurred.

**Design direction accepted, with the concrete requirements below made explicit before implementation. This is not an application or merge DECISION.** A separate request timing value addresses the missing pre-identity allowance without revalidating valid-at-arrival authorization. The author has agreed to the original-age/overall arithmetic, captured snapshot cap, earliest monotonic capture and exact budget-owned admitted instant; the revised contract should retain these commitments.

Read Paid252 Addendum15 §5 and its conditions, Addendum18 conditions, runtime C11(k), current Controller/RequestInstant/Downloads/Deadline/Commands, Policy, projection/consumer admission, native spool and transfer cleanup, plus affected existing request-instant/snapshot/download/HTTP test oracles. C11 remains open for real host/proxy/FPM verification; C2/C4–C8 remain as recorded in Addendum18.

## Required timing and API contract

Capture the original monotonic tick before instant capture, policy/configuration resolution or identity callbacks. Validate the admitted server instant once through the existing RequestInstant rule. Derive its nonnegative server-start age once during this factory, before identity; use conservative microsecond-to-nanosecond conversion. Capture validated snapshot seconds,30–1800. All subsequent checks use original monotonic bounds, without a later wall-age calculation, Carbon refresh or server-start fallback.

Let `N` be the earliest captured monotonic tick, `A` the once-captured age in nanoseconds and `S` the captured validated snapshot seconds:

```
originalInitial = N + 60e9 - A
originalOverall = originalInitial + S*1e9 + 60e9
first phase     = min(existing first-phase deadline, originalInitial)
snapshot phase  = min(snapshotStart + min(S, currentValidatedS)*1e9,
                      effective originalOverall)
commit/firstbyte = min(commitStart + 60e9, effective originalOverall)
```

Original age shortens both initial and overall bounds. Anchoring overall at `N + (60+S+60)` alone would omit pre-controller age and permit otherwise unbudgeted phase gaps. The captured snapshot allowance must constrain its own phase: with captured30 and later configuration1800, an overall cap alone could still enlarge the snapshot past its original30. Lower later valid bounds can shorten; increased configuration never enlarges this request. All mutable effective caps must only decrease and any copies used by phase admission must reflect the applicable shortening before insertion/first byte.

The existing `PaidGrantDeadline::start()`1–300 limit remains unchanged. Do not enlarge it to hold420/1920 seconds; the separate timing value carries the overall bound while the existing observation phases keep60. The request value is timing state, not owner/payment/policy authority, and requires no generic caller write permit or renewed proof.

Keep `PaidGrantRequestInstant`'s existing public factory/value and valid-at-arrival semantics; its seven existing cases should remain unchanged. An optional sixth domain argument preserves the current fifth parameter and trusted direct-domain callers. When a request budget is supplied, its owned exact admitted-instant object must match the fifth argument, or the domain must obtain the instant from that budget; mismatched independent objects cannot combine old admission with a fresh budget. No raw HTTP budget/deadline, caller clock or serialization/deserialization path is introduced. Trusted legacy calls without that HTTP budget retain their existing domain-entry timing, with no request-start coverage claim.

## Required enforcement and cleanup boundaries

- Check original initial admission after identity/body work, before Downloads locate, after it and before the first phase. Commands also performs policy/current-owner work and a second metadata locate; use the same capped Deadline and check after callback-heavy work before that locate/next frame. An entry-only check can miss time spent in these later source calls.
- Cap snapshot and commit/before-byte Deadline by the same original overall. Inside the commit operation, recheck after current inspection and attempt-count reads before the `paid_redemptions` insert call. Retain the existing terminal consumer admission/producer/current/committed/raw proofs so native SQL waits exceeding the bound cannot bypass the owned pre-commit refusal.
- A check before a method call cannot interrupt subsequent schema metadata, native INSERT or physical commit waits. Do not claim that synchronous deadlines guarantee cancellation or prevent every late physical commit; actual host wait/worker/proxy behavior remains C11 evidence. Refusal after a genuinely committed attempt must retain truthful consumed-attempt semantics without compensation or automatic retry.
- Resolve callback-capable configuration/services before terminal raw proof. Pass the capped original phase Deadline through existing consumer admission and ProjectionRead; no config/provider/wall-clock refresh follows their raw closure. The same commit deadline reaches before-first-byte proof; streaming receives its existing separate transfer cap.
- Every newly added check after `PreparedDeliveryStream::handle()` returns belongs inside the existing try/catch that immediately closes its exact unlinked descriptor and lease on refusal. Do not rely on eventual destructor timing. Pre-byte refusal keeps `PaidGrantTransfer::writeTo()` finally cleanup and emits no private/error bytes. Never delete or regenerate the purchased object.

## Proportionate meaningful regressions

1. Preserve the existing seven request-instant cases, especially synthetic65-second wall travel across identity and the trusted direct-domain fifth argument. Those prove authorization time judgement, not elapsed physical delay.
2. Use a genuine paid producer/principal/original/authorization through HTTP, a server instant near the original initial bound, and a short controlled real delay in the actual identity path. Assert the delay hook ran outside wrapped domain catches, elapsed time crossed that original bound,410/refusal and zero durable attempts. The same test must expose old-controller behavior; no real60-second sleep is necessary.
3. Narrow the genuine request budget during actual physical preparation; assert exact phase observation, refusal before an attempt, descriptor/lease cleanup and immediate same-account spool-slot reacquisition. The unspent authorization remains usable when a new legitimate request fits its bounds.
4. Exercise captured snapshot30 versus later1800, a valid shortening, overall-cap arithmetic with server age, and expiry during late commit preparation. These catch cap renewal and late preinsert checks instead of merely restating getters.
5. Hold a genuinely committed transfer past the original overall/before-byte cap; assert no emitted bytes, exactly one truthful consumed attempt, and immediate spool cleanup. Preserve existing successful phase separation and size-derived transfer tests.

Run the affected request-instant, snapshot, transfer, download and HTTP selections with source-bound receipts, using the existing paid namespace clock seam only where explicitly synthetic. A selected real MySQL wait case is appropriate if the batch claims that late native waits are refused; it is not a substitute for real proxy/FPM/metadata-timeout verification. No routine full matrix is required for this bounded development slice.

Fallback values may retain stricter authorization admission but do not reveal actual proxy start, pre-PHP queue time or a per-request clock under persistent workers. Keep C11's per-request SAPI/clock, backlog, both DB wait variables and40–548 locking-read count, worker/proxy timeout, slow-client and physical storage gates explicit. Do not close the pre-mount conditions or claim production readiness from synthetic timing tests.

No new code defect or application severity finding is assigned to an unimplemented design. The required corrections are specification clarifications; the source/API/cleanup constraints above remain implementation and final independent review criteria.
