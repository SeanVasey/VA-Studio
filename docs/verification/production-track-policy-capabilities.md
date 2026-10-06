# Production track policy capability contract

This successor prepares a production pricing/order interface. It does not activate
the legacy local/testing commerce paths, change provider accounts, establish legal
or tax facts, send messages, create orders or authorize payment collection.

The first source checkpoint implements the strict machine-policy validator and
adverse synthetic fixtures. The persistence and APIs below are the frozen next
implementation contract, not functionality completed by that first checkpoint.

## Owned boundary and dependencies

The new `App\Domain\Commerce\ProductionPolicy` namespace owns strict machine
policy validation, encrypted immutable candidate/approval/closure persistence and
a checked private projection. New models and tests remain in that namespace. The
reserved migration is `2026_10_06_236000_production_track_policy_capabilities.php`.
All three MySQL tables explicitly use InnoDB. Existing source-policy schema and
historical pricing/order readers remain unchanged; shared registration belongs to
the integration owner.

A candidate requires the current authored source version with all fourteen
categories explicitly declared and its authenticated independent source
acknowledgment. Each machine category supplies the corresponding source SHA256.
The declaration, hash and staff acknowledgment are authored evidence, not proof
that an external account, commercial decision or professional review is valid.

## Version 1 machine choices

Every field is explicitly supplied. There is no selected currency, tax, capture,
identity, refund or delivery default. Closed records reject additional keys,
coercion, malformed UTF-8, credential shapes and payloads above 32 KiB.

| Category | Required software choices |
| --- | --- |
| seller_identity | Explicit legal name |
| provider_account | Stripe account identity, live mode, dated API version, capture method and bare HTTPS return origin |
| currency | Supported ISO code and its exact minor-unit exponent |
| tax_calculation | Provider-calculated tax or explicitly declared exemption; inclusive/exclusive behavior, bounded rate ceiling and exact rounding strategy |
| assent | Version and exact bounded assent text |
| license_terms | Immutable published license authority, exact purchased revision, publication required, prior grants retained |
| buyer_identity | Verified account or verified guest claim with explicit verified purchase binding |
| recovery | Matching identity recovery, transactional mail and no implied marketing consent |
| reservation_and_exclusives | Explicit reservation, provider lifetime and retry bounds; pending cutoff, late time basis and paid exception reconciliation |
| refunds_and_disputes | Provider/operator refund authority, exact partial-line allocation, dispute access and exclusive reopening rule |
| original_documents | Renderer profile, production-original purpose and original preservation |
| storage | Private POSIX or private object adapter, adapter version and opaque boundary identity |
| delivery | Matching single-attempt private transfer, authorization lifetime, budget count/window and byte limit |
| privacy | Retention/deletion policy identities, explicit purpose consent and unknown consent retained |

Version 1 supports USD/EUR/GBP/CAD/AUD with exponent 2 and JPY with exponent 0 as
technical capabilities. That list does not select the merchant's currency. Other
currencies require a separately reviewed schema increment. Provider calculated
tax is required to retain provider-exact results; declaring an exemption requires
an explicit zero ceiling and still needs external qualification evidence.

## Public internal API

`PrepareProductionTrackCapabilities::review(?Candidate, SourceDraft, array,
User): array` captures an exact source/family/actor baseline and proposed machine
policy. `SaveProductionTrackCapabilities::applyReviewed(array, User): Candidate`
revalidates it, appends a generation or returns an exact no-op without writes.
Versions cannot be reused in a source family, even after closure.

`ReviewProductionTrackCapabilities::review(Candidate, User): array` and
`applyReviewed(array, array reference, User): Approval` persist a distinct software
choice approval. The staff member must be independent of the source creator,
source editors and all candidate authors in that source family. A source reviewer
may also supply this separate software approval if they meet those independence
rules. This artifact states `external_facts_verified:false` and
`execution_allowed:false`.

`CloseProductionTrackCapabilities::review(Candidate, User): array` and
`applyReviewed(array, array reason, User): Closure` persist one irreversible
closure. Closure remains available for an old candidate when the current source
has moved; it never reopens or deletes historical policy. New source/candidate
state and authorization must still match the reviewed closure baseline.

`ReadProductionTrackCapabilities::project(Candidate, array expectedContext,
User): array` returns an authenticated private version 1 projection for the latest
current-source candidate with an exact independent approval and no closure.
`withLockedForAdapter(Candidate, array expectedContext, User, Closure): mixed`
provides that same projection to a trusted domain adapter inside the standalone
transaction, then proves all source/family/actor rows through captured primary PDO
after the adapter and every Laravel/model callback. An adapter may prepare a
future immutable pricing/order snapshot there; provider I/O and charges remain
outside this contract and disabled. Caller context must match Stripe account,
live mode, currency, API version, adapter identities and the production preparation
purpose exactly. No caller file path, remote reference fetch or environment-only
activation exists.

## Persistence and proof contract

The source draft is the family fence. Locks follow actor, source draft, retained
source versions/acknowledgments, immutable candidates/approvals/closures and audits.
All historical payloads authenticate; every semantic read is current/locking,
including no-ops. Signed captures bind exact raw source/family history, actor,
intent and supplied choices. A source/candidate update, closure, historical row
change or authority ABA invalidates a prepared write. No active mutable pointer is
introduced. Immutable generation order selects the latest candidate.

Candidate payloads, approval references and closure reasons are encrypted. Clear
metadata/audits contain bounded identities and ciphertext commitments rather than
merchant declarations. Version uniqueness uses a keyed version identity. INSERT
triggers explicitly reject every primary/unique identity replacement, including
SQLite REPLACE when recursive triggers are disabled. UPDATE/DELETE guards retain
immutable history. All model and QueryExecuted callbacks finish before the final
direct-primary-PDO actor and complete graph proof.

## Next consumer and remaining acceptance

The next production pricing/order adapter must freeze projection purpose/schema,
source version and acknowledgment identity/hash, candidate and approval identity/
hash, machine-policy version/hash, exact currency/exponent, provider account/mode/
API, tax strategy, legal assent/license revision, identity, reservation and
fulfillment commitments in a new snapshot schema. Legacy test snapshots must
remain readable without reinterpretation. Tax quotes must be authoritative and
bound to the exact provider calculation and checkout, never client totals.

Approval of software choices is separate from merchant/legal approval, tax
qualification, runtime credentials, live webhook acceptance, original-contract
renderer, private storage, transactional mail, workers and verified delivery.
Those remain unresolved execution blockers. Final integrated acceptance is still
deferred; this contract receives focused adverse source/authority/immutability/
concurrency checks and an independent source-bound review before composition.
