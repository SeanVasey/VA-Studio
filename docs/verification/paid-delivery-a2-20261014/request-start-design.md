# A2 request-start admission design

Proposed development scope on actual C4c merge ae01c740 plus its receipt-only
98bb9a91 checkpoint. No A2 application check or host verification has run.
Root owns the controller, paid download/deadline integration, new request-budget
value, affected tests and this packet. Frozen paid profiles/members remain exact.
Routes and operative flags remain off while the pre-mount conditions are open.

C11(k) requires a budget captured before identity proof and checked before
locating and before the commit frame inserts an attempt. Existing authorization
admission remains valid-at-arrival: PaidGrantRequestInstant is validated once,
not aged again after identity or snapshot. Its seven existing cases remain
relevant; synthetic wall-clock travel is not physical elapsed-time evidence.

A separate private-constructor, nonserializable request-budget object captures
hrtime at entry, the validated server admission instant and the then-validated
snapshot bound before identity work. The elapsed server-start age at capture
shortens the initial 60-second observation bound. The original overall
first-byte bound is 60 + captured snapshot_seconds + 60 (420 at the default,
1920 at the validated maximum); it can only shorten. This object is timing
state, not identity, policy, payment or download authority. Preserve the
existing request-instant value/API and its valid-at-arrival semantics.

The controller creates this budget before principal proof, checks the initial
bound after identity, and passes the exact budget/instant to redeem. The first
locate/frame budget is capped by the initial request bound, including checks
before locate and after it. Snapshot keeps its original phase allowance, capped
by the original request bound. The fresh commit/before-byte frame keeps its
60-second allowance, capped by the same overall bound; check immediately before
paid_redemptions insert. Keep all current/committed/producer/raw/first-byte proofs.
No authorization lifetime, render, transfer, observation or retained policy cap
increases. A direct trusted domain call without an HTTP budget keeps its existing
entry-time budget; no request-start coverage is claimed for an uncaptured caller.

Missing or implausible server-start values retain the existing stricter
admission fallback, but cannot establish the real proxy's elapsed start.
Document this limit and keep valid per-request SAPI/host-clock verification a
pre-mount gate. Admission checks refuse late work after a blocking SQL call;
they do not promise to interrupt it. Host/proxy/FPM, both DB wait variables,
worst-case statement counts and slow-client verification remain open.

Acceptance uses a genuine paid producer, owner principal, original, authorization
and physical synthetic transfer. A short real delay plus a server instant near
the original bound proves expired identity admission records no attempt; compare
the same test/source with the old controller. A min-only shortened request budget
during actual preparation proves refusal before attempt insertion and cleanup.
Run affected request-instant/download/HTTP selections and a private native MySQL
case where it adds meaningful evidence; record exact source, commands, counts,
skips, failures and limits. No full Foundation or GitLab dispatch.

Whole-order two-line failure/retry and guarded root HTTP integration follow in
the coherent A2 batch after this timing contract is independently assessed.
Actual production mounting still requires the open C4-C8/C11 conditions and
Sean's commercial/account/host inputs. No provider or customer operation follows
from these synthetic checks.
