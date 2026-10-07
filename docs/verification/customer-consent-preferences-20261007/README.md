# Versioned customer communication preferences and consent capture

Executable `0143f4b6bb7c529aed003fdfc741bf93f2e3a9ed`, tree
`1379247a58593c145d27f951a4020fab57294663`, is an isolated T32/FP-081 child
of the frozen notes/listening source `ebb096eee0fbdfabe81a6d7eb622f50d342e9477`.
All 17 changed paths are new and owned by this child: `Customers/Preferences`,
its configuration and migration, the dedicated component/support/tests.
Root owns routes, request grammar, CustomerPrivacy/session/CSRF registration,
capability properties and mounting. No shared root-owned file was edited.

This is real private first-party choice persistence with immutable exact notice
originals and customer controls. It is not verification of historical/legal
consent, a customer import, a marketing sender or a provider suppression claim.
WP-11, architecture security-operations/data-contracts, FP-081/FP-091 and U-13/
U-016 remain the source-backed requirements. No production legal copy, review
approval, consent provenance or processor is fabricated. All test notices and
review references explicitly identify synthetic fixtures; production notice
configuration is absent by default.

## Exact policy and durable graph

The one initial purpose is `email_marketing`. `customer-preferences.php` defaults
`test_grants_enabled` to false and `email_marketing` to null. A server-authored
policy requires exact fields `purpose,version,notice,review_reference`:
`purpose=email_marketing`, an 80-character bounded canonical ASCII version,
a meaningful plain notice of at most 2,000 Unicode characters/8,000 UTF-8 bytes,
and a meaningful review reference of at most 200 characters/800 bytes. LF/tab
are preserved; malformed UTF-8, other controls, format and blank text refuse.
These format checks do not verify a legal approval. A reviewed production adapter
and actual owner/privacy review evidence remain required before activation.

The default `LocalConsentRuntime` only permits synthetic local/testing grants
with the explicit true flag. `ConsentRuntime::grantsEnabled()` is a pure additive
adapter seam; the domain service has no permanent production-environment refusal.
A replacement consent adapter cannot bypass existing CustomerAccess/current
identity authority. The production identity adapter after migration 247 remains
an independent dependency. Read and withdrawal use current customer authority
and remain possible when the grant runtime, purpose notice or notice version
is disabled/changed.

Migration `2026_10_07_250000_customer_consent` adds three tables:

| Table | Retained behavior |
| --- | --- |
| `customer_consent_policies` | Immutable exact notice, version, public notice hash, review reference and complete policy hash; unique purpose/version |
| `customer_consent_events` | Append-only account/purpose/revision capture, granted/withdrawn choice, affirmative flag, policy link, source and UTC time; encrypted server recipient snapshot and account-scoped keyed HMAC |
| `customer_consent_states` | Unique account/purpose pointer to the matching retained event and monotonic revision; owner/purpose/creation identity cannot change |

Unknown is absence of a current event, not false/granted/imported consent. An
old grant under a changed/unavailable notice or a different current server
recipient projects unknown and preserves its original evidence. Nothing
promotes purchases, accounts, listening activity or imported addresses to a grant.
`first_party_customer` identifies this capture mechanism, not verified historical
or legal consent. The plaintext recipient is taken only from the currently owned
server user row; no client address, account ID or provider target is accepted.
The encrypted snapshot binds its exact account/purpose/revision/event identity.
Neither the address, internal identifiers, history, recipient HMAC nor ciphertext
enters the customer DTO. Exact source/hash/time and policy originals stay retained.

The original tables, customer/membership/listening history and migration records
are preserved. Each table uses one atomic CREATE containing its keys/FKs. Nine
retention/graph triggers follow in a known ordered prefix. Before DDL, up proves
all retained objects and a contiguous exact unlogged installation prefix; a
recorded migration with any missing object, foreign/drifted columns/indexes/
triggers/constraints, a gap or temporary shadow refuses before changes. Every
actual post-DDL interruption can restart without adopting drift. `down()` always
throws before any query, preserving even empty schema and the repository record.
This is an operational rollback refusal; code reversal retains this evidence.

## Root registration contract and customer behavior

`CustomerConsentPreferences::read(CustomerPrincipal, User): array` and
`change(CustomerPrincipal, User, array): array` return the minimized preferences.
`ConsentException` gives generic status 422 (invalid/unavailable intent), 409
(stale/exhausted revision), or 503 (unverifiable retained evidence); existing
CustomerAccessException gives 403. Root registers private GET/POST
`/account/communication-preferences` with exactly `{preferences: ...}`:

```json
{"preferences":{"schema":1,"purposes":[{"purpose":"email_marketing","version":0,"status":"unknown","notice":null,"canGrant":false}]}}
```

A notice, when available, has exact `version,hash,text`. Status is one of
`unknown,granted,withdrawn`; revisions are integers 0..2,147,483,646.

| Command | Exact additional fields |
| --- | --- |
| `grant-consent` | `version,purpose,noticeVersion,noticeHash,affirmative:true` |
| `withdraw-consent` | `version,purpose` |

The grant must match the exact currently displayed/configured notice version
and hash and requires affirmative true. There is no inferred/default opt in.
Notice-version reuse with different original copy/review refuses. Every explicit
accepted choice, including withdrawal from unknown/already withdrawn, increments
the revision. Lost response retry with an old body/version returns 409; it is not
an idempotent resend. Reload GET, inspect current state, then make a fresh intent.
This prevents stale grant resurrection. Normal root 4,096-byte request grammar
suffices: notice text is never an inbound field.

The domain locks user -> account -> current graph, validates current and preceding
events plus known immutable policy originals, builds a pure DTO, then rechecks
configuration/runtime and current identity after the last framework callback.
Captured-primary raw PDO proof verifies the actual user/account and exact bounded
retained graph/current-policy absence range. Reads do not scan the full history.
Late authority, recipient, policy or graph changes fail and roll back. Intended
model fields are bound before projection, preventing a saved-model refresh from
rebinding the expected revision to another committed choice.

Root mounts named `CustomerCommunicationPreferences({scope:string})` behind
customer capability with a fresh opaque authenticated session scope/key. It
shows the exact notice as text, starts the affirmative checkbox unchecked, saves
an explicit opt in and permits withdrawal without a current notice. No browser
storage is used. Strict DTO/envelope checks, a streamed 32 KiB response cap,
20-second abort, current scope/generation ownership, pending guard, pagehide and
unmount protect local state. The response must confirm exactly the next revision
and intended status; opt-in confirmation must preserve the displayed notice.
Stale/uncertain writes clear notice/choice drafts and require a fresh GET; no old
choice is replayed automatically. Revoked/expired authority requires fresh sign-in.

## Actual selected verification

Exact frozen source passes 101 PHP cases / 486 assertions, zero errors/failures/
skips, plus 27 focused frontend cases, TypeScript and Pint. Raw JUnit/stdout,
reflection to this checkout, runtime/source identities and hashes are retained.
The PHP selection includes all 66 new domain/boundary/migration cases and 35
existing account/listening primary/notes-boundary regressions. New cases cover
unknown/default/false/invalid policy, explicit affirmative/current notice,
withdrawal without notice and repeated monotonic withdrawal, stale intent,
private/cross-account/recipient refusal, actual listening and order preparation
without consent inference, current recipient changes, replaced runtime seam,
15 final-account QueryExecuted read/grant/withdraw callbacks, same-state model
refresh, all 12 actual migrator interruption steps, retained original policy and
prior history, recorded-tail drift, direct SQL delete/update/REPLACE defenses and
operational migration-record rollback refusal.

```sh
APP_KEY='base64:a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=' \
  /workspace/.va-studio-toolchain/bin/php vendor/bin/phpunit \
  tests/Feature/CustomerConsentPreferencesTest.php \
  tests/Feature/CustomerConsentBoundaryTest.php \
  tests/Feature/CustomerConsentMigrationTest.php \
  tests/Feature/CustomerAccountAccessTest.php \
  tests/Feature/CustomerListeningNotesBoundaryTest.php \
  tests/Feature/CustomerListeningPrimaryProofTest.php \
  --log-junit docs/verification/customer-consent-preferences-20261007/frozen-php.xml
npm test -- tests/frontend/customer-communication-preferences.test.tsx
npm run typecheck
/workspace/.va-studio-toolchain/bin/php vendor/bin/pint --test \
  app/Domain/Customers/Preferences tests/Support/ConsentFixtures.php \
  tests/Feature/CustomerConsentPreferencesTest.php \
  tests/Feature/CustomerConsentBoundaryTest.php \
  tests/Feature/CustomerConsentMigrationTest.php \
  database/migrations/2026_10_07_250000_customer_consent.php \
  config/customer-preferences.php
git diff --cached --check
```

The explicit APP_KEY is reproducible synthetic testing-only material generated
from 32 bytes `k`; no real key or `.env` was copied or changed. Initial 19-case
verification had one harness assertion matching the substring `email` in the
legitimate `email_marketing` purpose. The repaired assertion checks the exact
email field marker and actual private values; its original failure receipt stays
visible. Intermediate focused checks are labeled before the final recorded-tail
case and production-adapter test refinement. Final results apply to the exact
frozen source only. When readable logs trim final blank lines, adjacent gzip
sidecars preserve exact original stdout.

Vendor package namespaces link to the existing locked original packages; this
checkout has independent Composer metadata/autoload/bin and verified reflection.
Node modules reuse the existing locked installation. No new dependency install,
provider credential, queue, external send, CI dispatch, merge or push occurred.

This author leaf did not run native MySQL migrations/optimistic contention,
root HTTP/session/CSRF/privacy/mount integration, browser/device/full Foundation
acceptance or production activation. Root must compose and independently review
this exact sensitive source. The next mandatory dependent T32 child is suppression
outbox migration 251000: atomic server-owned withdrawal intent, definite versus
unknown outcome, no ambiguous resend or implicit unsuppress, exact positive
receipt reconciliation and no marketing sends. This leaf does not claim that
suppression child, full CRM, owner-reviewed production policy, production identity,
legacy consent provenance or full T32/WP-11 acceptance is complete.
