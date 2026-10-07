# Private test-contract issuance

Status: **Merged WP-08 increment, 2026-09-26.** [PR #65](https://github.com/VASEYDEV/VASEYAUDIO/pull/65) accepted private original test-contract issuance after the [WP-07 finalization prerequisite](test-payment-finalization.md). The [ordered acceptance record](development-order.md#current-increment-and-next-handoff) retains its actual tested source, runtime results and independent-review evidence. This does not establish production legal approval, an actual Stripe transaction, active entitlements or completed WP-08.

The increment turns one retained paid grant into one private original test PDF. It keeps the original grant, full terms and purchased revision evidence unchanged. Contract issuance and delivery activation remain separate: the entitlement and fulfillment outbox stay pending, and no PDF or asset download route is exposed.

## Enablement and prerequisites

`VASEY_TEST_CONTRACT_ISSUANCE_ENABLED` maps to `contracts.test_issuance_enabled` and defaults to false. `VASEY_TEST_CONTRACT_ISSUANCE_POLICY` maps to `contracts.test_issuance_policy` and is blank by default. Fresh issuance requires `local` or `testing`, configured Stripe `test` mode and a valid own-account ID, plus the exact explicit policy below. The process does not call Stripe or charge a buyer.

```json
{
  "schema_version": 1,
  "purpose": "test_contract_issuance",
  "version": "test-contract-issuance-v2",
  "profile": "test-buyer-pdf-v2",
  "originals": "preserve_first_committed",
  "missing_original": "restore_only",
  "buyer_identity": "unverified_guest",
  "entitlements": "pending",
  "lease_seconds": 300,
  "max_attempts": 5,
  "retry_seconds": 60
}
```

The current development runtime selects v2. Existing explicit v1 configuration is refused; upgrading package files does not convert its meaning to v2. Issuance stays disabled by default and requires deliberately supplying the exact v2 policy above. This is local/testing enablement only.

The policy envelope is bounded to 4 KiB and must match `ContractIssuancePolicy::CONTRACT`; additional or changed values are rejected. It is a nonbinding development policy. Buyer identity remains an unverified guest, marketing consent remains unknown, and existing license `renderer_version` remains historical review-HTML provenance rather than approval of this buyer PDF profile.

Rendering requires the dedicated pinned PHP 8.4 profile, the resolved Composer dependencies and generated local font assets. The broader application can support other documented PHP runtimes while this particular renderer refuses a runtime that differs from its retained profile. Private local storage must not be served publicly. Production storage, archival format, legal review and worker isolation remain scoped readiness work.

## Retained request, work and original

| Record | Purpose and invariant |
| --- | --- |
| `contract_render_requests` | One immutable request per grant and render outbox entry, with an input hash, exact profile/hash, canonicalization version and preallocated opaque document ID. Buyer input stays in the original encrypted grant record. |
| `contract_render_work` | Separate recoverable coordination: `pending`, `processing`, `retry`, `completed` or `quarantined`. Fresh UUID claims have 300-second leases; no more than five attempts. Completed work cannot be changed or deleted. |
| `grant_contracts` | One immutable original-document manifest per grant/request, bound to the winning claim, exact input/profile hashes, private path, PDF hash, byte size, page count and issue time. It is not a download authorization. |

Application validation and SQLite/MySQL guards preserve identities, grant/outbox/request bindings, bounded states and claim deadlines. A result must belong to the currently processing claim. Immutable evidence and completed work cannot be rewritten to make a new rendering look like the original. Schema rollback refuses populated issuance evidence; an empty disposable database can exercise down/up. Production recovery is forward repair with the records, original files and encryption keys retained.

Rendering and filesystem I/O occur outside every open database transaction. The worker obtains a bounded claim, validates the retained grant and request, renders/stores a candidate original, then rechecks the current claim and deadline before publishing the logical result. A stale worker cannot complete its successor's claim or replace an original. Failed storage or lost claims can leave unreferenced private claim paths; they are not automatically deleted or reused.

Durable request/work state is the recovery authority. An asynchronous ID-only job is a latency aid; the bounded scanner must also recover missed dispatch, due retries and expired claims. Rendering/storage failures retry under the retained attempt policy. Changed evidence, a changed profile, unsupported input or an invalid PDF require attention rather than silent substitution. Exhausted claims stop after the fifth attempt. The scanner and targeted issuance/verification mode below expose only opaque grant identities and bounded outcomes; there is no replay/reset option for quarantined or completed work.

## Queue and console recovery

`IssueTestContractJob` carries only the internal grant ID and uses the `contracts` queue, one queue attempt and a 90-second job timeout. `DispatchTestContract` dispatches only to supported asynchronous queues after commit; a synchronous request must not run the renderer. Queue failure leaves the retained grant/outbox and any existing request/work evidence available for scanning. Configure the approved test policy before using the worker or console commands.

```sh
# Process a bounded page of eligible paid test grants.
php artisan vasey:issue-test-contracts --limit=25

# Continue a sweep using the exact NEXT_AFTER value printed by the previous page.
php artisan vasey:issue-test-contracts --limit=25 --after=GRANT_UUID

# Process or verify one known retained test grant; this never replaces an original.
php artisan vasey:issue-test-contracts GRANT_UUID

# Run the dedicated asynchronous worker in the configured local/testing environment.
php artisan queue:work --queue=contracts --tries=1 --timeout=90
```

Replace `GRANT_UUID` with the trusted opaque grant ID; do not use that placeholder literally. The scanner scopes to the configured own account and paid test grants, filters eligibility before applying its limit, and selects missing request/work, pending work, due retry or expired processing. It excludes grants with retained original records. Its stable grant-ID cursor advances past each attempted item, including failures, and prints `NEXT_AFTER=<grant UUID>`. Continue the cursor through an empty page; begin a later retry sweep without a cursor so newly eligible earlier work can be revisited. This is explicit keyset pagination, not a scheduled background sweep.

The optional grant argument performs a targeted attempt or verifies the retained exact original. A missing original is never regenerated. Command outcomes are limited to `ready`, `pending`, `retry`, `quarantined`, `busy`, `unavailable`, `changed`, `original_unavailable` or `stale`; raw renderer errors, buyer input, storage paths and secrets are not printed. `ready` means the original exists for this issuance boundary, not that delivery is active. No command resets completed/quarantined records, issues a URL or grants download access.

## Pinned renderer and profile provenance

The retained v1 profile uses `tecnickcom/tc-lib-pdf` **8.76.2**, source reference `c383bd3ac09164c3fd1a6ac7da8f5460d19e47e3`, and the real Composer-resolved package graph. The font importer is `tecnickcom/tc-lib-pdf-font` **4.4.0**, reference `658e04565534c0cdd3d8d7ff12f1c6ebeab5f7ce`. Exact installed package references and asset hashes form part of `test-buyer-pdf-v1`; its manifest and pinned implementation remain immutable.

Current `test-buyer-pdf-v2` uses PDF **8.76.3**, reference `d417129fad37d49dc9fc0d1e229e9740e1c774a3`, and font importer **4.4.1**, reference `a78b8e0ac9284d1594b6ba68488cf65c3e958f7b`. Its [separate provenance and actual converted manifest](../resources/contracts/test-v2/PROVENANCE.md) retain the complete resolved graph and implementation/font identities. `scripts/build-contract-profile-v2.php` applies its explicit pins; it must not rebuild or overwrite v1. Direct and isolated dispatch require the exact profile version. Upgraded package bytes cannot render a retained v1 request as v2.

Unmodified DejaVu Sans regular/bold source fonts and their original license come from `tecnickcom/tc-font-mirror` **2.4.0**, commit `3251310e5f8e92659ac3ef1133e591ed681ef95d`. [The profile provenance](../resources/contracts/test-v1/PROVENANCE.md) records source Git blob identities and SHA-256 hashes. `scripts/build-contract-profile.php` verifies source hashes and uses the locked importer to generate the six local font assets and their manifest. It does not run a nested Composer installation or an upstream bulk font download. This profile is document-specific and does not alter the storefront's [approved brand theme](brand/README.md).

The renderer fixes A4 portrait pages, 15 mm margins, 10 pt DejaVu Sans, UTC metadata, no font subsetting and no stream compression. Document IDs and metadata derive from the retained grant, input/profile hashes and effective time. It uses escaped fixed sections containing every original frozen input field and full terms, with no live buyer, seller, catalog, license or asset lookup. It produces ordinary PDF; **PDF/A, PDF/UA, universal Unicode support and production archival acceptance are not claimed**.

The input policy admits bounded Latin, Greek, Cyrillic and Common-script text, then checks actual glyph coverage before rendering. Unsupported glyphs, combining marks, bidi/control characters and unsupported scripts fail instead of being omitted or silently replaced. Broader language/layout support requires another versioned profile and its own fixtures; it must not regenerate historical originals.

| Resource | Candidate bound |
| --- | --- |
| Canonical input | 1 MiB; the combined input/profile transport envelope must also fit within 1 MiB |
| PDF output | 16 MiB |
| Pages | 100 |
| Child runtime | 60 seconds |
| PHP child memory | 128 MiB |
| Processing claim | 300 seconds, five attempts, retry no earlier than 60 seconds |

New rendering verifies the installed runtime, pinned packages and physical asset hashes. Retained profile validation uses the trusted manifest and immutable metadata, so ordinary historical status reads do not load the renderer or need the font files. An immutable request keeps its original profile. Existing v1 requests and originals remain readable through retained v1 validation independently of current selection. A pending v1 request whose exact runtime is absent is quarantined with `profile_changed` after its owned attempt; automatic retry does not substitute v2 or alter the request. Existing completed originals remain exact and restore-only: a missing original reports `original_unavailable` without regenerating bytes or rewriting completion. An archived v1 worker/runtime route is not implemented by this upgrade.

## Isolation and private storage boundaries

`IsolatedContractRenderer` invokes a bounded PHP subprocess without Laravel bootstrap, strips inherited environment values and uses explicit locale/timezone settings. It passes only the frozen input/profile envelope. URL wrappers and common network/process functions are disabled, resource access is allowlisted to the committed font directory, and text is escaped before entering the renderer. Process timeout, output bounds and strict response/hash validation reject unexpected or partial results; raw stderr is not surfaced as customer output.

These are application and PHP process restrictions, **not complete operating-system isolation**. The child still runs with the host's OS identity and `open_basedir` covers the project tree. No dedicated container, network namespace, read-only filesystem mount or independently enforced OS memory/CPU sandbox is established by this increment. Production worker isolation and actual deployment acceptance remain open. Do not infer that the process can never reach sensitive files merely because inherited environment values were removed.

`ContractFiles` permits only a nonpublic private local disk. A candidate path is exactly `contracts/test/{request UUID}/{claim UUID}/original.pdf`. Claim directories require mode `0700`, writes use exclusive creation, and a sealed original requires mode `0400`. Byte length, PDF framing and SHA-256 are verified before and after storing; a new descriptor verifies the recorded bytes before logical publication. Symlinks, hard links, paths outside the private root, public roots and unexpected modes are rejected. The storage root is unserved and rejects group/world-writable modes. The application checks assume the private tree is controlled by trusted workers; they are not a complete defense against a hostile process with the same operating-system identity racing directory replacements. This is guarded local storage, not hardware write-once retention or accepted object-store durability.

After a manifest has committed, processing reuses only that exact original. **A missing or corrupt original is restore-only**: report `original_unavailable`, preserve its manifest and completed work, and restore the same bytes/path from a verified backup. Never rerender from current templates or replace a document to conceal loss. This batch provides no automatic restore command, orphan cleanup, PDF download endpoint or signed-link issuer.

## Customer projection

Owner-checked order and checkout responses retain their existing schema/identity fields and add `contractStatus`. `fulfillmentStatus` adds `pending_activation`. Coherent combinations are:

| Payment | Finalization | Contract | Fulfillment | Meaning |
| --- | --- | --- | --- | --- |
| `not_started` or `not_verified` | `not_started` | `not_started` | `not_started` | No verified payment; checkout itself uses only `not_verified`. |
| `verified` | `awaiting_finalization` | `not_started` | `not_started` | Payment is retained; no paid finalization yet. |
| `verified` | `paid_exception` | `blocked` | `blocked` | Review is required; no contract or delivery is issued. |
| `verified` | `paid` | `pending` | `pending_contracts` | Required contract issuance is not complete. |
| `verified` | `paid` | `attention` | `blocked` | Unfinished issuance is quarantined and needs attention. |
| `verified` | `paid` | `issued` | `pending_activation` | Every required grant has a retained original-document result; delivery remains inactive. |

Order `status` remains `prepared`, `paid` or `paid_exception`; checkout session `status` keeps its earlier vocabulary. A verified checkout still requires a non-null intent ID and `url: null`. Ordinary order/checkout GETs validate retained database evidence and request/result/work linkage; **they do not read PDF bytes**. `issued` is the recorded issuance state, not a fresh filesystem health check. If an explicit worker check discovers a missing original, it reports `original_unavailable`; immutable completed work is not rewritten into an attention state. Guarded activation must verify actual document and purchased-asset durability before future download access.

The browser labels all these outcomes as test activity. It hides payment creation/retry/reconciliation actions after verification and offers GET-only status refresh. A failed or regressive payment/finalization response cannot reopen payment. A stale `issued` → `pending` response is rejected, while a coherent server-reported attention state can conservatively block delivery. Unknown or incoherent fields fail closed. Recovered owner status preserves verified payment while the checkout endpoint is unavailable. Private paths, raw errors, buyer input and provider identifiers are not rendered or stored in browser persistence.

## Verification and next increment

The UI candidate `b4f666f` passed `npm test` (195 tests in 12 files), `npm run build` (TypeScript and Vite) and `git diff --check` locally on Node 24.19.0. One new synthetic browser scenario covers pending → attention → issued through keyboard GET refresh; it subsequently executed in the accepted Chromium/WebKit CI suite. Synthetic HTTP responses prove UI handling, not PDF generation or durable contract issuance.

The renderer, storage, schema and coordinator were accepted through [PR #65](https://github.com/VASEYDEV/VASEYAUDIO/pull/65) after [CI 36257160215, attempt 2](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36257160215/attempts/2) completed all required jobs at unchanged source `2961ae5a087340460500322482080db294d7cbe0`. The [ordered acceptance record](development-order.md#current-increment-and-next-handoff) retains the exact candidate/tree, full MySQL/SQLite, frontend/browser and build/audit evidence, plus the first attempt's historical scheduling failure. This includes real locked-library output/determinism, unsupported text/resource rejection, failure recovery, expired claim races and exact-original preservation; synthetic provider fixtures do not establish production acceptance.

Continue the [ordered activation and delivery handoff](development-order.md#next-dependency-ready-development-slices): whole-order document/asset verification precedes internal authorization and owner HTTP/UI delivery. The activation proof itself leaves entitlements pending; it does not activate customer downloads. Agree guest/account recovery, production storage/backup/restore and legal/archival policies for their respective scopes. Preserve all 14 work packages and the 103-item parity baseline. This increment advances issue #8 without closing it or declaring launch readiness.
