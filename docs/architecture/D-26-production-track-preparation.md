# D-26 — Retain policy-bound production track preparation

Status: implemented and independently reviewed for focused development integration,
October 6, 2026. This records an
internal preparation interface; it establishes no merchant, tax or legal fact
and grants no purchase, payment, reservation or delivery authority.

PR #16 merged the independently reviewed authored policy and typed capability
contract at native main `a348f67eff1fdd16b1945efd3b99aa27af3dd242`, tree
`9142a8d4436aea99bb0f8aba5570601587e0e5a9`. The new consumer uses its current
approved, unclosed candidate and explicit adapter context in one standalone
transaction. Legacy quotes, prices, orders and test-only policy remain intact.

## Owned interface

`Commerce/ProductionPreparation` owns encrypted immutable preparation packets
and line commitments. The additive `238000` migration owns packet and line
tables. Staff review, apply, read and opaque-key recovery remain server-authorized
through current catalog authority and MFA; preparation does not collect buyer
identity or affirmative assent.

The first supported selection is one to ten distinct currently published
non-exclusive USD offers, because these are the catalog's existing commercial
capabilities. Unsupported currencies or test-exclusive activation cannot be
silently converted into production evidence. Advertised prices are server-owned
integer minor units with bounded summation. Tax and total remain unknown;
`payable`, `execution_allowed` and `external_facts_verified` remain false.
Stored policy, license, selection and future-order commitments support later
reviewed consumers without duplicating legacy test orders or creating inventory
holds, provider requests, grants or entitlements.

## Composed proof boundary

Current-primary rows and complete relevant selector sets supply the interpreted
catalog, license and media evidence. An ordinary earlier database read view is
insufficient even when later raw rows are stable. Idempotency and retained
recovery authenticate the exact request and encrypted evidence instead of
reconstructing a changed current selection.

The capability adapter receives an optional trusted final consumer verifier.
Authority, model and Laravel query callbacks finish before that verifier uses
the captured primary PDO; the existing direct source/family and actor/audit
proof follows it. The legacy four-argument adapter passes exactly one projection
argument; the composed proof path opts into the captured reader as a second
preparation argument. Final
consumer verification uses fixed internal SQL and bounded pure comparisons;
this contract does not sandbox arbitrary PHP or transaction lifecycle callbacks.

Catalog/media row commitments are not proof that private bytes remained intact
or that storage, a renderer or a provider is operational. Later operative quote,
payment, document and delivery consumers must supply their applicable physical,
provider, identity and assent evidence.

## Verification and remaining scope

Affected checks cover immutable packet/line history and SQL replacement refusal,
source/context/approval/closure and current staff fences, unsupported selections,
integer bounds, exact replay/recovery, late callback drift and whole-write
rollback. Migration checks preserve foreign or modified schema and refuse
retained-data teardown. Native MySQL concurrency and full integrated acceptance
remain separately named evidence, not inferred from SQLite or SQL compilation.

Shared integration selects nine fixed affected PHP files, preserving the
32-file limit and all earlier suites/exceptions. Three retained capability/policy
fixtures remove only explicitly empty new children before testing their parent
migrations. Routine PR preflight keeps the approved manual-matrix cost policy.
The clean composed selection passed 255 PHP cases / 779 assertions and all 14
actual copy-upgrade checks, without errors, failures or skips. Separate sensitive
and shared reviews approved the executable source. [The integration record](../verification/production-preparation-integration-20261006.md)
binds source, raw receipts, the corrected FK-name compilation proof and remaining
native/full acceptance limits; its integrating PR records actual preflight/merge.

Next dependencies are authoritative production tax/amount observations, explicit
buyer identity and affirmative assent, production inventory/provider bindings,
verified payment, original documents and authorized private delivery. Real
merchant/legal/storage/transport acceptance and cutover retain their gates.
