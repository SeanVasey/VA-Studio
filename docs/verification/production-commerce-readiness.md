# Production track-commerce preparation

This increment adds `vasey:commerce-readiness`, a read-only, redacted inventory of the current production track-commerce boundary. It complements `vasey:doctor` and the private-server dotenv preflight. It does not invoke either checker: Doctor can query the database, run media tools and create a scanner canary; the new inventory invokes no database, provider, filesystem adapter, mailer, queue or subprocess and changes no configuration.

```sh
php artisan vasey:commerce-readiness
php artisan vasey:commerce-readiness --json
```

The current contract is `production-track-commerce-readiness-v1`, JSON schema1. It emits33 fixed check identities:10 implementation barriers,11 configuration checks and12 required acceptance categories. `configured` means a bounded effective Laravel configuration shape matches the documented preparation choice. It does not establish an account connection, filesystem safety, actual service operation or owner approval. `unverified` records evidence that this command cannot obtain. Runtime values, credentials, email addresses, private paths, exceptions and secret-derived hashes are not emitted.

Both formats exit1. `production_commerce_ready` and `deployment_authorized` remain false even when every configuration check is satisfied, because the source has no production successor for the current test contracts. No caller-supplied key, evidence file or command flag can override this boundary. The JSON report checks effective application configuration in the current process; it does not inspect another worker's process or a protected dotenv file.

The disabled-feature check covers all13 current test flags, including customer accounts/identity/claims, test order inquiries and the newer unpaid-release/refund-resolution controls. The absent-policy check covers their synthetic policy configuration. A string such as `"false"`, null in place of an explicitly disabled flag, a retained synthetic policy or `private_capture` cannot count as ready production preparation.

## Source-backed barriers

The report carries fixed source references for each implementation barrier. This is a versioned implementation inventory, not runtime source-code parsing. When a production successor is integrated, update this contract and its tests against the exact new source; preserve the original test readers and evidence.

| Boundary | Actual current implementation | Required production successor |
| --- | --- | --- |
| Pricing/tax | `PricingPolicy` is local/testing and test/USD scoped; `CheckoutEvidence` accepts fixed zero test tax only | Approved currency/tax policy, authoritative exact integer calculation, complete settlement binding |
| Inventory/exclusives | `InventoryPolicy` and `ExclusiveSelectionPolicy` require a test environment | Explicit reservation, late-payment, pending non-exclusive cutoff and post-refund policy with sorted shared-scope locking |
| Orders/assent | `OrderPolicy` requires local/testing, synthetic seller and unverified guest policy | Approved seller/buyer identity and full frozen assent under a new evidence version |
| Hosted provider | `CheckoutPolicy` and `StripeSdkCheckoutGateway` require test mode; SDK accepts `sk_test_` only; Session evidence requires `cs_test_` | Separately reviewed own-account live provider/checkout contract and secret configuration; preserve immutable requests and uncertain outcomes |
| Inbox/payment verification | `VerifyStripeWebhook` accepts non-production test snapshots; `PaymentProcessingPolicy` and `PaymentEvidence` require test mode/live false | Signed live ingestion plus durable account/mode/amount/identity verification; redirect or signature alone grants nothing |
| Paid effects | Payment/finalization migrations explicitly require `mode = 'test'`; finalization retains test policy and exact grant graph | Additive production persistence and dispatch by frozen contract version, with paid exceptions and compatible historical reads |
| Original documents | `ContractIssuancePolicy` and SQL parent guards require paid test finalization | Approved production rendering/retention profile and exact original preservation; existing PDFs are not regenerated |
| Entitlements/delivery | `ActivationPolicy`, `TestAccessPolicy` and parent guards require paid test orders; current local adapter rejects public/link/path hazards and current HTTP denies ranges | Approved production access/recovery/limits, compatible immutable authorization evidence and selected durable storage; range/resume is an explicit additional implementation |
| Customer recovery | Accounts and guest claims are local/testing; identity requires `private_capture` | Actual transactional enrollment/recovery/claim transport, expiry/replay/failure recovery and identity policy; email equality is not proof of purchase |
| Financial operations | Test exception observations exist; refunded-exception release and unpaid release are bounded test-only GET evidence operations | Approved production exception/refund/dispute/rights dispositions and retained reconciliation; do not infer revocation or resale from a refund |

The account is not missing: the decision register records Sean's September9 observation of Vasey Multimedia in both live and test modes. That connection does not configure Laravel's host credentials, tax/capture/currency choices or webhook destination. Actual account interoperability and the production application workflow remain distinct requirements.

## Next money implementation contract

The next upstream child is an **authored production track-policy draft and exact independent review**, followed by the versioned production money path. The following is a concrete implementation contract for preparation; these services and tables are not created by this readiness increment.

| Owned child | API/files | Dependency and acceptance |
| --- | --- | --- |
| Authored policy schema | `app/Domain/Commerce/Policy/ProductionTrackPolicyDraft.php`; `validateAuthored(array $authored): array` | Closed versioned schema, bounded strings/arrays, explicit declarations or unresolved entries, canonical identity, no prices/terms/tax/TTL defaults, no secrets in the payload |
| Exact save/review | `PrepareProductionTrackPolicy::review(array $authored, User $actor): array`; `SaveProductionTrackPolicy::applyReviewed(array $review, User $actor): array`; independent `ReviewProductionTrackPolicy` | Fresh server authority and production MFA, exact captured authored source and previous revision/audit cursors, changed or consumed reviews fail closed, independent reviewer cannot approve their own source, stable no-op has no audit writes |
| Retained policy versions | New `ProductionTrackPolicyDraft`/`ProductionTrackPolicyVersion` models and additive migration | Encrypt private authored merchant/legal references; retain immutable reviewed versions and approvals with restrictive SQL guards on both engines; no active commerce pointer or provider operation |
| Activation adapter, later child | New `ProductionTrackPolicy::current()` and explicit production evidence dispatch | No activation until all chosen policy declarations have actual approvals, implementation capability versions and required operational evidence; no request, environment flag or account listing substitutes for these dependencies |

The authored contract declares `schema_version`, purpose, draft/version identity and effective interval; provider, own-account reference and intended mode; supported currency and minor-unit exponent; merchant identity reference; exact license/assent/privacy reference hashes; tax source and calculation stage; buyer identity/recovery choice; shared reservation/exclusive/late-payment policy; refund/dispute/rights disposition; original document/storage/delivery policy; and evidence references. Each reference must resolve to retained source before activation. An unresolved tax/identity/legal choice is a retained draft blocker, never zero tax, a fabricated seller or an implicit lifetime download policy. Exact field limits and executable capabilities belong in that schema child before integration.

The next production pricing/order/session mapping must select a **new** frozen policy/evidence version. Historical test quote/pricing/order/checkout/payment/grant/contract/delivery records continue through their original verifiers and SQL guards. Extend persistence additively and retain strict account+mode+purpose linkage throughout the graph. New records cannot borrow a test confirmation, original, entitlement or session simply because IDs or amounts match.

Money remains integer minor units. Tax calculation stage is an explicit capability: an unsupported/unresolved stage leaves the total non-payable. If the final authoritative total/disclosure differs from what the buyer reviewed, require the approved new review/assent flow; do not silently increase an amount or mutate retained assent. Provider creation uses the exact retained request/idempotency key outside database transactions. Missing or uncertain responses preserve reconciliation evidence and pending resources, never mint a replacement charge attempt. Signature/redirect/session observations alone remain insufficient payment proof.

Prepare downstream fixtures in parallel: mixed test/live account bindings; amount/currency/tax/line mismatches; same-key conflicting bodies; delayed/duplicated/out-of-order receipts; two exclusive variants sharing one scope; paid exceptions and late success after proven unpaid release; stale workers and crash/missed dispatch; original file loss/restore; identity expiry/replay; rejected partial/contested refunds; consumed/lost download acknowledgments. Additive migrations require old populated test graph fixtures plus MySQL independent-connection races before claiming sensitive development acceptance.

## Milestone2 effort and external dependencies

These are planning ranges for incremental track-store code against main `13d7474f2f843084f7c7ab94b4e5e8105d08bbce`, not elapsed-time promises or full-store parity estimates. The implementation already contains substantial quote/order/payment/contract/delivery/customer code; its deliberate test boundaries and SQL guards still require coherent production successors. The ranges include focused component/adversarial coverage and independent review repair.

| Incremental coding | Developer-days |
| --- | --- |
| Authored production policy and compatible versioned evidence/schema |3–6 |
| Production pricing/tax/order/assent and retained hosted-session mapping |6–12 |
| Live inbox, authoritative verification, finalization and resource compatibility |5–9 |
| Production originals, entitlements and private-local delivery adaptation |4–8 |
| Account/claim/transactional recovery integration, reusing the parallel outbox foundation |3–6 |
| Production exception/refund/dispute/unpaid operations |4–8 |
| Integration/review repair and provider/restore acceptance fixture preparation |3–6 |
| **Total incremental engineering effort** | **28–55** |

Assumptions: one seller, the observed own Stripe account, one approved currency/tax/capture approach, current shared durable private POSIX storage, an acceptable production successor for the pinned offline PDF profile, and one transactional mail transport. A selected cloud adapter adds an estimated4–8 developer-days and must preserve exact original/asset identity. Additional rails, broader currency/tax calculation stages, range/resumable delivery or legacy rights/refund obligations change the estimate and must be added explicitly.

Separate account/server setup includes actual protected application credentials and webhook configuration; approved currency/capture/tax/merchant/legal/recovery choices; selected host/storage/mail transport; supervised workers/scheduler; and actual source records/assets. Separate final acceptance includes real provider interoperability, mail delivery, legal/original review, device/large-download behavior, paired database/file/key restore and the exact final consolidated matrix. Waiting for those facts is not coding effort. Parallelism can reduce elapsed implementation time but cannot remove sensitive review or upstream policy dependencies; no launch date is defensible from this range alone.

## Executed focused checks and deferred evidence

The exact final tested source and commands are recorded in the handoff receipt. The first isolated PHPUnit invocation failed before running a case because shared dependency binaries loaded two Composer bootstraps; only the untracked local dependency layout was corrected, and that negative log was retained. The first actual60-case run passed301 assertions. The final66-case run passed334 assertions, with no errors, failures or skips. It strengthens the existing-policy barrier test with the same valid synthetic configuration succeeding in `testing`, and includes the newer refund/unpaid-release flags/policies. Scoped Pint, PHP syntax checks, `git diff --check` and all8 workflow cadence guards passed. No workflow, configuration or existing commerce source changed.

No actual server, payment account, live API, mail delivery, private storage, worker or browser was exercised. This service has no concurrency/mutation path; its focused tests make no MySQL concurrency claim. Existing payment/delivery races remain part of final verification. No hosted full or manual workflow was dispatched for this increment. Current `AGENTS.md`, renamed manual workflow paths/triggers, expected-SHA guard and provenance tests must remain unchanged when the branch is integrated.
