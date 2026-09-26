# Test fulfillment activation

Status: **Implemented WP-08 increment, 2026-09-26.** [D-18](architecture/D-18-test-fulfillment-activation.md) formalizes the approved direction from [proposal `6a1d317`](test-fulfillment-activation-proposal.md). [PR #67](https://github.com/VASEYDEV/VASEYAUDIO/pull/67) records its actual tested source, final integrated CI, independent review and merge disposition after D-17 issuance in [PR #65](https://github.com/VASEYDEV/VASEYAUDIO/pull/65). Implementation does not imply active customer delivery or production acceptance.

The increment records that one complete paid test order's original contracts and exact purchased files were verified in protected local storage within a bounded observation window. It creates one immutable proof and audit. It leaves grants, original documents, pending entitlements, pending outbox and licensed permissions unchanged. **`activated` means a retained technical proof; it does not authorize a download.**

## Explicit configuration

`VASEY_TEST_FULFILLMENT_ACTIVATION_ENABLED` maps to `delivery.test_activation_enabled` and defaults to false. `VASEY_TEST_FULFILLMENT_ACTIVATION_POLICY` maps to `delivery.test_activation_policy` and is blank by default. New activation requires a strict boolean true, `local` or `testing`, configured Stripe `test` mode, a valid own-account ID and the exact policy below:

```json
{
  "schema_version": 1,
  "purpose": "test_fulfillment_activation",
  "version": "test-fulfillment-activation-v1",
  "scope": "complete_paid_order",
  "storage": "private_local",
  "verification": "fresh_sha256",
  "max_verification_age_seconds": 300,
  "pending_entitlements": "preserve",
  "buyer_identity": "unverified_guest",
  "download_access": "disabled"
}
```

`ActivationPolicy::CONTRACT` is authoritative. The JSON envelope is bounded to 4 KiB and must match exactly; extra or changed values are rejected. This is a technical test policy, not approval of live license terms, customer identity, guest claims, download limits or production retention. Activation needs no provider request, payment capture or renderer call.

Retained paid evidence can be activated after the older order, checkout, payment-processing, finalization or contract-issuance flags/policies are withdrawn. The activation policy remains required for the command, including an explicit repeat attempt. The separate historical reader validates retained evidence without current enablement, current account configuration or physical file reads.

## Retained proof and complete membership

Migration `2026_09_26_000022_test_fulfillment_activation.php` adds `test_fulfillment_activations` and `TestFulfillmentActivation`:

| Field | Meaning |
| --- | --- |
| `id`, unique `public_id` | Internal key and opaque activation UUID. |
| Unique `order_id`, unique `order_finalization_id` | Restrictive references to one complete `paid` / `test` order and its matching finalization. |
| `policy_version`, `canonicalization_version` | Exact activation policy and `vasey-json-v1` identity. |
| `evidence_ciphertext`, `evidence_hash` | Encrypted canonical proof and SHA-256 of that stored ciphertext; hidden from ordinary model serialization. |
| `verified_from`, `verified_through`, `activated_at` | UTC whole-second file-check window and activation decision time. |

The encrypted snapshot binds original order/finalization/payment evidence hashes and test account, every grant in original line order, render-input identity, request/profile/completed-work identity, each first original's manifest/hash/private location and every exact pending entitlement. Assets retain their frozen descriptor/hash, immutable storage/provenance and stems recording binding. A shared asset is physically checked once while every grant's entitlement remains represented.

`DeliveryAssets::inspect` validates the historical commercial revision, purchased roles/descriptors, processing and scan provenance, and stems association through database evidence. It does not replace a purchase with a current offer, latest asset, current customer field or new license. Current catalog publication is not a prerequisite for an already retained grant.

Large `technical_metadata` values are represented by `MediaEvidenceValues::reference`: an exact hash of the versioned `media-values-binary64-v1` encoding. Lists, objects, scalar types and finite floating-point bit patterns remain distinct. Full immutable source rows are still validated and reconstructed on every read; a digest does not waive provenance checks. This keeps repeated stems manifests/waveforms out of the proof while retaining their identity. Canonical proof remains bounded to **2 MiB**, and ciphertext to **4 MiB**. The maximum-cart regression covers ten tracks, thirty deliverables, ten originals, 128-member stems manifests and 1,000-point waveforms without increasing those limits.

ORM and SQLite/MySQL guards reject updates/deletes, malformed identities, invalid times and unmatched/incomplete parent bindings. SQL completeness is defense in depth; only the domain's canonical encrypted graph verification establishes the application proof. One populated activation prevents migration rollback before any guard/table is removed. Empty migration down/up is limited to disposable test databases.

## Verification and atomic publication

`ActivateTestFulfillment::handle(int $orderId): string` receives a trusted internal order ID. The sequence is:

1. Validate activation policy/account and require zero transaction depth on **every open connection**. Reconstruct the full retained order/finalization graph and every request/work/original/entitlement binding. Unpaid orders and paid exceptions are ineligible. One unissued or quarantined contract prevents the whole order's activation; malformed or missing retained work is changed evidence.
2. If an activation already exists, verify its exact retained proof and return `activated`. This path does not repeat physical checks or rewrite timestamps, policy, files or historical rights. A formerly activated graph that now appears incomplete fails as `changed`.
3. Capture the start time, verify every original via `ContractFiles::verify`, then freshly stream/hash every distinct purchased asset via `DeliveryAssets::verify`. Cached `VerifiedMedia` integrity success cannot substitute for these reads. Capture the end time and reject a stale/backwards observation window.
4. Begin the transaction and lock the order, ascending grant IDs, request/work rows and ascending asset IDs. Recheck activation policy/account, rebuild the database-only graph and compare it exactly to the graph whose files were checked. No file I/O, provider request or rendering occurs under the transaction locks.
5. Verify a concurrent winner if one exists. Otherwise recheck the window including lock waits, encrypt and insert one complete proof, record `commerce.fulfillment.test_activated` with bounded opaque locators, verify the new proof and commit atomically. Failure rolls back the proof and audit together.

There is no per-line partial activation, mutable activation state, work lease or automatic queue dispatch. Independent contenders may repeat read-only verification, then converge on the retained winner under the order lock and unique constraints. A crash before commit leaves no activation; a later command can retry.

The time invariant is `verified_from ≤ verified_through ≤ activated_at ≤ verified_from + 300 seconds`. All timestamps are whole seconds, and the start cannot predate finalization or any original document's issue time. Exactly 300 seconds is permitted. Lock delays beyond the bound or clock reversal return `retry` without recording a proof.

## Strict private media checks

`DeliveryAssetFiles` reads only the `local` disk with a local driver, private visibility, `serve: false` and no prefix. It reads the configured root directly rather than resolving a filesystem adapter that could create a missing root. A missing root or directory fails; nothing is created or repaired.

| Check | Bound / behavior |
| --- | --- |
| Exact revision key | `media/revisions/{UUID}/master.wav`, `delivery.mp3` or `stems.zip`, matching the purchased role. |
| File | Regular, one hard link, mode `0400`, matching size and SHA-256. |
| Private revision directories | Real directory components with mode `0700`; no symlinks. |
| Root | Existing absolute canonical directory, not group/world-writable, outside public/served roots; overlap in either direction is rejected. |
| Public links | Configured reachable link chains, duplicate roots and existing ancestors of future subtrees are checked; a publicly reachable private target fails. |
| Streaming | At most 1 MiB per read; 1–1,073,741,824 bytes per media object; at most thirty distinct assets. |
| Runtime budget | One monotonic 300-second budget shared by purchased-asset checks, plus the separate whole-order 300-second observation age. |
| Identity | Descriptor/path and directory identities are checked before and after hashing; replacement, truncation, permission or hard-link changes fail. |
| Synthetic scans | `test-only` scan provenance can be physically verified only in `testing`, even though the general activation flag supports `local`. |

PDF checks retain D-17's exact first-original path, framing, size/hash and private-file rules. Missing/corrupt originals return `original_unavailable`. Media mismatches, unsafe storage, read failures or exhausted media verification budget return `asset_unavailable`. Changed retained descriptors/provenance fail as `changed`.

These checks record observations of trusted private local storage. They do not establish an OS sandbox, hardware write-once retention, replicated durability, a backup or an atomic filesystem/database transaction. A hostile process with the same OS identity is outside the complete protection provided by path checks. Production object storage and restore acceptance remain separate gates.

## Console recovery

```sh
# Process one bounded page of paid test orders without a retained activation.
php artisan vasey:activate-test-fulfillment --limit=25

# Continue a sweep using the previous page's exact cursor.
php artisan vasey:activate-test-fulfillment --limit=25 --after=ORDER_UUID

# Attempt one known paid test order, or verify its retained activation metadata.
php artisan vasey:activate-test-fulfillment ORDER_UUID
```

Replace `ORDER_UUID` with a trusted opaque order locator. The command scopes to the configured account and retained paid test finalizations. Limits are 1–100, default 25; an explicit order cannot be combined with `--after`. Invalid, nonexistent or out-of-scope selectors/cursors are rejected with a generic message.

The scanner excludes already activated orders, orders the rest by immutable internal order ID, and prints `NEXT_AFTER=<last attempted order UUID>` for each nonempty page, including a failed final item. It does not exclude an order merely because contracts are pending: that order receives its bounded outcome and the cursor can progress. Continue until a page is empty. Start a later sweep without a cursor to revisit earlier pending or failed work. This is explicit pagination, not a scheduled sweep.

| Outcome | Meaning and recovery |
| --- | --- |
| `activated` | One complete retained proof is valid. A repeat result is historical metadata, not a fresh file-health check or download permission. |
| `pending_contracts` | At least one required contract is unissued, including quarantined unfinished work. Resolve through the existing issuance boundary; activation never renders it. |
| `ineligible` | The internal service found no paid finalization, including an unpaid or paid-exception order. No proof or rights change occurs. The CLI selects only paid orders. |
| `unavailable` | Activation policy/environment/account or caller transaction context is unsupported. |
| `changed` | Retained evidence or exact graph/binding differs; preserve it for investigation. |
| `original_unavailable` | The first PDF cannot be verified; restore its exact recorded bytes/path. |
| `asset_unavailable` | Exact purchased media or protected storage cannot be verified; restore the same revision or correct storage safety outside this command. |
| `retry` | Transient infrastructure failure, stale window or backwards time prevented publication; perform a fresh attempt. |

Per-order output is only the opaque order UUID and outcome; raw exceptions, paths, hashes, buyer input and provider identifiers are not printed. The command can exit successfully while individual items report blocked/retry outcomes; automation must inspect those outcomes and the cursor, not equate exit zero with every order activated. There is no reset, replacement, restore, release or refund option.

## Historical reads and customer boundary

`ReadTestFulfillmentActivation::forOrder(Order $order)` returns a verified retained activation or null; ownership authorization belongs to its caller. It reconstructs the exact historical order, contract and media evidence without file reads or current activation flags. Later file loss preserves the original historical decision. Recovery never regenerates a PDF, swaps in a successor media revision, overwrites the activation or flips pending records to hide loss.

This increment does **not** add a customer activation endpoint or extend owner order/checkout status. Those projections still show `contractStatus: issued` / `fulfillmentStatus: pending_activation` after all original manifests are retained. The proposal's optional future `fulfillmentStatus: activated` was not implemented here. No original-contract or asset download route, token, signer, counter or guest-claim flow is supplied.

## Verification and next increment

The focused cases cover whole-order/mixed-cart completeness, replay and audit rollback, missing/corrupt originals/assets, cache bypass, exact provenance, observation bounds/lock waits, flags/account scope, historical file loss, poisoned evidence, scanner cursor/privacy, migration guards and independent MySQL contenders. The maximum-cart test preserves every original and entitlement while verifying compact media metadata references.

The first focused local run reported **88 cases: 86 passed, one failed and one MySQL-only case skipped**. The concrete public-link-chain guard issue was corrected. The maximum-cart regression then passed separately with **one test / 172 assertions** after copied fixture sources received distinct private keys. The combined PHP 8.4.26/SQLite run at `b76a3c1` reported **88 passed tests plus one MySQL-only skip, 751 assertions**, including the maximum cart. A further dangling-public-symlink guard and two regressions landed at `b06e16d`; all **41 private-file cases / 92 assertions** then passed. Independent source review accepted the graph, schema, lock/window and compact provenance design; the integrating source also incorporates the separately reviewed contract-storage fixes from PR #65. These local results do not establish full CI or MySQL race acceptance. The [ordered acceptance record](development-order.md#current-increment-and-next-handoff) tracks the final integrated source, full MySQL/SQLite results, independent review and prerequisite acceptance.

[D-19 internal delivery](test-owner-delivery.md) is implemented in [PR #69](https://github.com/VASEYDEV/VASEYAUDIO/pull/69): separate default-off test-access policy, exact current-byte verification, complete activation binding, opaque short-lived authorization, serialized technical limits and private snapshots. Its runtime and merge evidence are recorded in the [ordered acceptance record](development-order.md#current-increment-and-next-handoff). The next implementation is the owner HTTP/UI boundary: derive session ownership on every issuance/redemption request, protect tokens and private attachments, and expose truthful delivery outcomes. Website download counts must not reuse license exploitation fields such as `copies_downloads`. Guest/account recovery, refunds/disputes, production legal/storage/backup policy and customer continuity remain distinct work. This advances WP-08 while preserving all fourteen work packages and the 103-item parity baseline.
