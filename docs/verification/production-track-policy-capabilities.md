# Production track policy capability contract

This successor prepares a production pricing/order interface. It does not activate
the legacy local/testing commerce paths, change provider accounts, establish legal
or tax facts, send messages, create orders or authorize payment collection.

The original checkpoint `72c1da8` supplies the strict machine-policy validator and
adverse synthetic fixtures. The continuation implements the encrypted immutable
candidate, software approval, irreversible closure and checked private projection
contract described below. These are preparation APIs; production purchase,
payment and delivery execution remain unfinished and disabled.

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
including no-ops. Signed captures bind exact raw source/family history, the actor's
raw row and retained user audit history, intent and supplied choices. A
source/candidate update, closure, historical row change or durably audited
authority ABA invalidates a prepared write. Unrecorded out-of-band SQL that
restores exactly the same authority row is not independently observable; the
audited role-change contract remains required. No active mutable pointer is
introduced. Immutable generation order selects the latest candidate.

Candidate payloads, approval references and closure reasons are encrypted. Clear
metadata/audits contain bounded identities and ciphertext commitments rather than
merchant declarations. Version uniqueness uses a keyed version identity. INSERT
triggers explicitly reject every primary/unique identity replacement, including
SQLite REPLACE when recursive triggers are disabled. UPDATE/DELETE guards retain
immutable history. Retained source-prefix reconstruction authenticates the exact
source graph each candidate committed even after later revisions append.
Candidate/approval/closure timestamps and actor identities are encrypted into
their records, and the complete minimized audit graph is checked against them.
Authority, model, audit and QueryExecuted callbacks finish before the final
direct-primary-PDO actor and complete graph proof. Transaction lifecycle
callbacks and domain preparation callbacks are trusted application code; this
contract does not sandbox arbitrary PHP or provider I/O performed by such code.

`PreparationContextV1::forMachine` derives only the exact comparison vocabulary:
purpose/schema, provider/account/mode/API/capture, currency/exponent, storage
adapter/version/boundary, renderer profile, delivery transfer and assent version.
The adapter must explicitly supply this full context. The private projection
also retains exact source, candidate, approval and machine hashes with a purpose
bound signature. Returning it does not grant activation, payment or delivery
authority; consuming source must use the checked transaction API and implement
its own future immutable snapshot schema. No consumer exists in this increment.

After an uncertain response, staff may reload the retained immutable candidate
and prepare a fresh authenticated no-op. Reusing the old pre-save capture fails
against the moved family baseline. Approval and closure are irreversible
single-record decisions and require a fresh review when their baseline changes.

## Focused continuation evidence

The isolated continuation source preserves `72c1da8` and underlying authored
policy proof `cd3ee3e` without changing the original unfinished worktree. On the
cached PHP 8.4 runtime, this exact selection passed 155 tests / 208 assertions:

```sh
php vendor/bin/phpunit tests/Feature/ProductionTrackCapabilitiesTest.php tests/Feature/ProductionTrackCapabilitiesGuardsTest.php tests/Unit/ProductionTrackMachinePolicyTest.php --colors=never
php vendor/bin/pint --test app/Domain/Commerce/ProductionPolicy tests/Feature/ProductionTrackCapabilitiesTest.php tests/Feature/ProductionTrackCapabilitiesGuardsTest.php database/migrations/2026_10_06_236000_production_track_policy_capabilities.php
git diff --check
```

Cases exercise exact no-op/successor behavior, explicit supported alternatives,
every context binding, missing/unresolved source acknowledgment, family reviewer
independence, source movement, stale competing captures, closure/version reuse,
withdrawn role/MFA and durably audited authority ABA, callback-time rollback,
retained prefix damage, audit damage, empty/populated rollback and all three
tables' update/delete/primary-REPLACE/unique-REPLACE denial with recursive triggers
disabled. The first run exposed an incorrect fixture table name and four fixture
trigger names/expected failure types; those fixture corrections are retained.
They were not compiler or commerce passes.

The broader affected selection additionally included
`ProductionTrackPolicyDraftTest`, `ProductionTrackPolicyFinalProofTest` and
`ProductionTrackPolicyEngineTest`: 234 cases, 233 passed, 377 assertions and one
explicit MySQL-only engine case skipped on SQLite. The upstream authored source
remains unchanged.

These are SQLite and unit checks. Actual MySQL record waits, engine-default
execution, repeatable-read interleavings, provider interoperability, private
host/runtime configuration, a consumer adapter and consolidated exact-source
final acceptance remain deferred. No MySQL matrix or hosted CI was launched.
Independent sensitive-domain review and integration disposition are recorded by
the integration owner against the frozen continuation commit.

## Reviewed rollback ownership correction

Independent review of `da3b688` reproduced a teardown defect: when all owned
tables were absent, `down()` still dropped a same-named trigger attached to an
unrelated table. That predecessor is preserved and its actual red canary returns
exit 1 after deleting the foreign trigger. The successor compiles the original
table and guard definitions once for both creation and read-only rollback
admission. Before any teardown DDL it checks permanent exact table/index/guard
identity and definition, complete installation, temporary shadows and external
foreign keys, views and triggers; MySQL also checks storage, typed columns,
constraints and visible routine dependencies. Ambiguous or unreadable dependency
definitions fail closed. Foreign, partial and modified objects are retained.
Populated evidence still prevents teardown; an entirely absent clean installation
is a no-op, and an exact empty owned installation can be removed and recreated.

The copied negative canary now refuses rollback and preserves the foreign trigger
and table, returning exit 0. The dedicated
`ProductionTrackCapabilitiesMigrationOwnershipTest` first passed 23 SQLite cases / 54
assertions, including missing/modified/aliased guards, missing/modified/additional
indexes and columns, partial objects, temporary shadows, external child/view/
trigger dependencies, absent-schema foreign objects and successful empty control
paths. Rejection cases observe zero teardown DDL and identical before/after
catalogs. A preceding broader affected source/compiler/ownership/unit selection
passed 257 cases: 256 passed / 431 assertions, with the same explicit MySQL-only
engine case skipped. The final fixture isolates its intrinsically SQLite
connection even when the surrounding suite selects MySQL, instead of introducing
MySQL omissions. After that fixture refinement, the exact final ownership and
guard-control selection passes 49 cases / 111 assertions with zero skips. It
supersedes the earlier ownership receipts; the preceding broader result remains
separate evidence for its recorded source. Pint, changed-file syntax and diff
checks pass. Exact-source logs, JUnit receipts, the red/green canaries and
application/test autoload bindings are
retained in the review evidence directory. MySQL execution and final integrated
acceptance remain deferred; this correction changes no policy record, execution
flag, source commitment or commerce path.

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
