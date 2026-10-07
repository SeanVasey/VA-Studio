# D-27 — Retain private staff reports of buyer assent

This preparatory domain interface retains a staff report against the exact frozen
D-26 packet. It does not observe an authenticated buyer action and does not verify
buyer identity. A staff assertion cannot satisfy the machine policy's verified
account or verified guest claim and explicit purchase-binding requirements.

`ProductionBuyerAssentObservations::review` requires current catalog authority and
configured staff MFA. It returns the exact retained seller declaration, complete
line license disclosures, machine assent text/version, advertised subtotal and
unknown tax/total, signed for that staff observer and packet ciphertext hash.
`retain` accepts that exact review, supplied legal name/email, strictly boolean
`reported_accepted: true`, an opaque bounded observation reference and request key.
It neither accepts a client total nor turns supplied identity into verification.
There is no public buyer route in this increment.

The immutable encrypted record preserves the original review and staff report,
server creation time and observer. It carries explicit false values for verified
buyer identity, verified buyer act, purchase binding, payable, execution and
external facts. Model serialization hides ciphertext, private hash and request
key; audits retain only safe evidence references and the unverified boundary.
No plaintext identity hash, marketing consent, inventory hold, order, payment,
provider request, grant or delivery is created.

An actor-scoped key serializes under the existing staff mutex. Identical retries
return the same canonical original body; changed reports or disclosures fail.
Recovery authenticates encrypted original evidence and current staff authority,
including after policy closure, without reinstating sale eligibility. Packet,
source history, observation selectors and exact audit receive a final fixed
captured-primary proof after application/crypto callbacks. This inherits D-26's
trusted callback boundary and does not sandbox arbitrary transaction lifecycle
code. Native concurrency remains separate evidence from ordinary MySQL tests.

The additive `239000` migration refuses foreign or preexisting tables before DDL,
retains parent/user foreign keys and supplies insert/update/delete fences that
also reject replacement and upsert overwrites. `down()` unconditionally refuses
before any schema/data mutation, preserving real Migrator bookkeeping even for an
empty table. Parent rollback tests drop only explicitly asserted-empty children
inside disposable test fixtures with foreign-key enforcement unchanged.

The next consumer must independently bind a verified buyer identity and actual
affirmative buyer action to the exact production order disclosure, authoritative
amounts, current operative inventory/provider context and durable order. It may
use this report as historical supporting evidence; it must never use the staff
report or its signature as a substitute for that independent buyer proof.
Focused execution evidence belongs to the integrating PR and source-bound receipt.
