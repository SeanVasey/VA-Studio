# Production identity APP_KEY rotation — focused evidence (2026-10-08)

**Status:** focused development evidence only. Not Foundation CI, not final acceptance, not reviewed.

## What changed and why

Codex P2 on PR #53 (verified): `IdentityPolicy::digest()` keyed every production identity HMAC with
`config('app.key')` alone. After a routine rotation (old key moved to `APP_PREVIOUS_KEYS`) every stored
identity digest stopped verifying, so `ProductionCustomerAccess::read()/lock()` threw `IdentityException`
and every customer command (grants, account features, consent, sign-in) refused. Historical grants and
downloads became unreachable.

The fix mirrors `ProductionFreeGrantRecords::keys()/verify()` (harness/free-256):

- `IdentityPolicy::keys()` — current `app.key` first (absent or empty → `IdentityException`, unchanged rule),
  then each distinct `app.previous_keys` string entry of at least 32 bytes.
- `IdentityPolicy::digest()` — writes with the current key only (unchanged output).
- `IdentityPolicy::matches($purpose, $value, $expected)` — `hash_equals` against every candidate; no candidate
  short-circuits the others.
- `IdentityPolicy::digests($purpose, $value)` — candidate list (current first) for `IN (...)` lookups.

Stored rows are never rewritten or re-keyed. No schema, migration, trigger, deadline, receipt format or
hash-pinned contract asset changed (`resources/contracts/*/profile-assets.json` pin no identity file).

## Call sites

| Site | Handling |
| --- | --- |
| `IdentityEvidence::verify` (challenge/origin/verification/notice hashes) | verify-any-key; `hash()` writes current key |
| `ProductionCustomerAccess::historical` origin `owner_digest` | verify-any-key |
| `ProductionCustomerAccess::read` origin `owner_digest`, last observation `credential_binding`, `recipient_hmac` | verify-any-key |
| `ProductionCustomerAccess::read/historical` projected `owner_digest`/`credential_binding` | current-key only: in-process values compared only with another read in the same process (principal mint/`match`, receipt closure); never compared with stored evidence |
| `CompleteIdentity` proof/payload/recipient/address/completion/credential/bound-credential checks | verify-any-key; new origin/observation digests current key |
| `IdentityRequests` address fence lookup | lookup-candidates: one exact `address_hash = ?` locking read per candidate (current key first), rows merged in id order; more than one row → `IdentityException` (422, nothing written); none → insert the current-key fence as before |
| `IdentityRequests` request replay lookup | lookup-candidates: one `request_hash = ?` read per candidate; more than one row → refuse |
| `IdentityRequests::recoverable` credential/recipient | verify-any-key |
| `IdentityRequests` recovery `bound_credential_binding` | copies the stored, just-verified last-observation binding instead of recomputing, because `historical()` compares it byte-for-byte with the prior observation (which may be old-key). Without this a post-rotation recovery would lock the customer out permanently. |
| `WorkIdentityNotice` payload/recipient/credential/lease checks | verify-any-key (lease token is in-process; harmless) |
| `ProductionConsentRecords::recipientMatches` (new) used by `event()`, `ProductionConsentWithdrawal`, `ProductionSuppressionRecords::target`, `ProductionConsentPreferences` status/suppression projection | verify-any-key; `recipientHash()` writes current key |
| `IdentityHistoricalCommittedReceipt::capture` owner digest | current-key only: both sides derived in this capture under the witness frame |
| `IdentityOriginalCommitWitness::configuration` | now also closes `app.previous_keys` (the verification input) for the frame, like `app.key` |
| `CommittedReadContext` configuration hash | unchanged: already includes `app.previous_keys` |
| `ProductionCustomerPrincipal::sessionBindingDigest` | intentionally current-key only: rotation invalidates the session marker; the customer signs in again (home redirects to sign-in) |
| `SmtpIdentitySettings` capability commitments | current-key only (configuration pin, not stored data): operator re-pins `transport_capability` after rotation |
| `CustomerIdentityChallenges` (test-only private-capture identity) | fence + request lookups: one exact locking read per candidate (ambiguous → no challenge, generic ack); with no previous keys the original `insertOrIgnore` + lock path runs unchanged; resend derives the proof under whichever configured key issued it; completion replay hash verify-any-key; new values current key |

## Tested source

Base `705a712e6a3d22ea8e4483c44d2cc47a2d9fa52b` (origin/main) plus this uncommitted working tree in
`/home/user/VA-Studio-idkeys` (branch `harness/identity-key-rotation`). PHP 8.4.26, PHPUnit 12.5.34,
SQLite in-memory; MySQL 8.4.11 private loopback instance for native-only methods.

### Why per-candidate equality instead of `whereIn`

`mysql-in-lookup-locks.txt` (private MySQL 8.4.11, real `IdentitySchema` DDL): `address_hash = ?` is a `const`
lookup on `pia_address_unique` that locks one record or one gap. `address_hash IN (?, ?) ORDER BY id LIMIT 2 FOR
UPDATE` uses a range on the unique index on a populated table, but on an empty table (case C) and on a small table
with stale statistics (observed while preparing case B) MySQL chose an `index` scan of `PRIMARY` and next-key locked
every row plus the supremum, i.e. the whole table. One exact read per candidate keeps the original lock shape for
every key; with no previous keys configured the SQL is byte-identical to before.

## Red (before the fix) — `red-new-tests.txt`

Source: base app/ exactly as `705a712e` with only the final test files applied (app/ restored with
`git checkout -- app`, then the saved patch re-applied; the re-applied diff hash matched).

- `ProductionIdentityKeyRotationTest`: 10 tests, 8 errors + 1 failure (undefined `keys()/digests()`; `IdentityException`
  from `ProductionCustomerAccess::read` and `IdentityEvidence::verify` after rotation; replayed request created a
  second challenge; ambiguous fences were not refused; re-sign-in after rotation returned null). The only passing
  test, `test_new_identity_writes_use_only_the_current_key`, is a guard that also holds on main.
- `ProductionConsentKeyRotationTest`: 2/2 fail (sign-in after rotation refused).
- `CustomerIdentityKeyRotationTest`: exact request after rotation created a second challenge (1 error); the
  removed-key guard passes on main too.
- `IdentityHistoricalCommittedReceiptTest` new `previous_keys` mode: fails (a changed `app.previous_keys` was not
  closed by the witness).

## Green (final source)

- `green-sqlite-affected-suites.txt`: 52 suite invocations on SQLite (all `tests/Feature/Production{Identity,
  IdentityAdapters,Features,Suppression,AccountFeatures,Membership,MembershipBilling,MemberOriginals,TaxCheckout}`,
  every `ProductionCheckout*`, `ProductionCommerceReadiness`, `ProductionBuyerAssent*`, `ProductionAmount*`,
  `ProductionIdentity{Registration,SmtpBinding}`, `Customer{Identity,Session,Consent,Suppression,Account}*` file and
  `tests/Unit/ProductionFeatures`): every invocation `exit=0`, zero failures/errors. Skips are the pre-existing
  MySQL-only methods.
- `green-mysql-native.txt` (private loopback mysqld, deleted afterwards): `ProductionIdentityNativeRaceTest` 6/6,
  receipt native + all withdrawal modes incl. `previous_keys` 7/7, committed-frame native 1/1,
  `CustomerIdentityConcurrencyTest` 9/9, `ProductionIdentityKeyRotationTest` 10/10, `ProductionConsentKeyRotationTest`
  2/2, `CustomerIdentityKeyRotationTest` 2/2, `IdentityInspectionCostTest` 1/1.
- `pint.txt`: `vendor/bin/pint --test` on every changed PHP file — passed.
- `database-receipts-selftest.txt`: `python3 -I scripts/ci/test-database-receipts.py` — 34 tests OK.
  `scripts/ci/database-sqlite-skips.json` is unchanged: every new test runs on SQLite.

Tested-source fingerprint: `git diff HEAD -- app tests CHANGELOG.md | sha256sum` =
`5325164f028e317ae280a5774f1634c054a28aa5180c1194006a653bd62ed71f`; new test files sha256
`63ce4754…` (ProductionIdentityKeyRotationTest), `d7c5e90e…` (ProductionConsentKeyRotationTest),
`1f130cf7…` (CustomerIdentityKeyRotationTest).

## Untested or open conditions

- No Foundation CI, browser or HTTP-level rotation test; no independent review yet (required: authorization change).
- Rotation is simulated in-process (`config()` + encrypter reset); a real deploy with `APP_PREVIOUS_KEYS` and queue
  worker restarts was not exercised.
- Rotation was not exercised under concurrent MySQL races (the native race suites run with one key, which is
  byte-identical SQL to main).
- Suppression after rotation: a new withdrawal written under the new key carries a new-key `recipient_hmac`, and the
  schema triggers require target/event `recipient_hmac` equality, so a second suppression target can be created for
  the same address. Not changed (schema is out of scope); needs Sean's decision.
- Retiring a previous key permanently refuses every identity, consent record and evidence row it wrote, because stored
  digests are immutable and never re-keyed. Old keys must stay in `APP_PREVIOUS_KEYS` indefinitely until a re-keying or
  re-enrollment design exists.
- Customers are signed out by a rotation (session marker is current-key only) and identity mail stops until the
  operator re-pins `production-customer-identity.transport_capability`.
- Legacy test-only identity: completed-replay and recovery still fail after rotation through
  `CustomerAccess::stamp()` (current-key HMAC, outside this change). Many other `config('app.key')` HMAC sites outside
  the identity layer (quote/inquiry owners, legacy consent, listening, commerce evidence, free grants, ...) have the
  same rotation behaviour and were not changed.
- Ambiguity refusal (two fences) is reachable only from data written before this fix; it returns the generic 422.
