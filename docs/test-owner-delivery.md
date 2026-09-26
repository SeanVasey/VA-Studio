# Internal test owner-delivery foundation

Status: **Dependent WP-08 implementation candidate, 2026-09-26.** [D-19](architecture/D-19-test-owner-delivery.md) records the approved direction implemented by domain source through `47c644c`, schema through `a8d9d1a` and private-stream adapter through `f16a497`. [D-17 issuance](test-contract-issuance.md) and [D-18 activation](test-fulfillment-activation.md) require acceptance first. This guide describes internal services and console control, not a customer download API. Independent domain/schema and stream source reviews accepted the candidate conditionally; full CI and real MySQL execution remain pending.

The foundation selects the exact original contract or purchased media revision from an immutable complete-order activation. It adds technical access controls, short-lived authorization and one committed stream attempt. Pending entitlements and outbox remain pending; grants, licensed permissions, original contracts, purchase hashes and exclusive inventory do not change. Buyer identity remains `unverified_guest`.

## Explicit configuration

`VASEY_TEST_DELIVERY_ACCESS_ENABLED` maps to `delivery.test_access_enabled` and defaults to false. `VASEY_TEST_DELIVERY_ACCESS_POLICY` maps to `delivery.test_access_policy` and is blank by default. Issuance, redemption and enabling a control require strict boolean true, `local` or `testing`, Stripe `test` mode, a valid configured own account and this exact JSON policy:

```json
{
  "schema_version": 1,
  "purpose": "test_owner_delivery",
  "version": "test-owner-delivery-v1",
  "scope": "activated_order_owner",
  "storage": "private_local",
  "verification": "fresh_sha256",
  "token_bytes": 32,
  "authorization_ttl_seconds": 60,
  "new_authorizations_per_order60_seconds": 3,
  "stream_attempts": 1,
  "ranges": "disabled",
  "pending_entitlements": "preserve",
  "buyer_identity": "unverified_guest"
}
```

`TestAccessPolicy::CONTRACT` is authoritative. The JSON envelope is at most 4 KiB, and extra or changed values are refused. Older preparation, checkout, payment, finalization, issuance and activation flags are not required. Blocking a control and reading retained authorization evidence do not require the current access flag/policy, but they still require the supported local/testing environment and configured test account. These limits are separate from licensed exploitation terms and do not establish a lifetime website download cap or production policy.

## Provisioning and restriction

The command resolves an opaque order UUID within the configured test account and verifies its complete activation. A missing control denies access and cannot be enabled directly. First provision it blocked, then explicitly enable the same version:

```sh
php artisan vasey:control-test-delivery ORDER_UUID block --expected-version=0 --reference=test-case-001
php artisan vasey:control-test-delivery ORDER_UUID enable --expected-version=0 --reference=test-case-001
# The successful enable above yields version 1; block it using that observed version.
php artisan vasey:control-test-delivery ORDER_UUID block --expected-version=1 --reference=test-case-002
```

Use the actual opaque order UUID and observed version. `--expected-version` is required: a canonical nonnegative decimal below 4,294,967,295. `--reference` is required: 1–192 ASCII characters, beginning with an alphanumeric and then using alphanumerics or `._:/-`. Only its digest enters the audit. Output contains the opaque control UUID, `blocked`/`enabled` and current version; failures use a generic message and nonzero exit.

Initial provisioning records blocked version zero. Each actual state change increments by one; requesting the same state with the correct version is a no-op. The final reachable version, 4,294,967,294, is reserved for a blocked state; 4,294,967,293 is the maximum enabled authorization/redemption version. Further enabling is refused so version exhaustion cannot prevent the final block. A stale version conflicts. Control changes serialize with issuance/redemption under the order lock. Re-enabling cannot revive a token captured under an earlier version. Withdrawing the global access flag denies issuance and uncommitted redemption; the command can still block within the supported account/environment. This is a technical delivery restriction, not a refund, license revocation, deletion or exclusive re-listing operation.

## Internal interfaces and exact identity

| Service | Implemented contract |
| --- | --- |
| `IssueTestDelivery::handle(orderPublicId, ownerKey, grantPublicId, kind, idempotencyKey)` | Checks current policy, exact owner, complete activation, selected member and enabled control; freshly verifies bytes, then atomically records an authorization and bounded audit. Returns `IssuedTestDelivery` with opaque ID, secret accessor, expiry and frozen filename/MIME. |
| `ReadTestDeliveryAuthorization::forOwner(orderPublicId, ownerKey, authorizationPublicId, token)` | Owner- and token-bound database-only reconstruction of retained authorization/redemption; no current access flag, file read or consumption. Still scoped to the supported test environment/account. This internal result contains private evidence and must never be serialized as a customer response. |
| `RedeemTestDelivery::handle(orderPublicId, ownerKey, authorizationPublicId, token)` | Revalidates policy, ownership, complete evidence, control and expiry; prepares a new private snapshot, then commits one redemption before returning its owned descriptor DTO. |
| `ManageTestDeliveryControl::handle(orderPublicId, blocked, expectedVersion, reference)` | Audited internal primitive behind the console command; never changes purchase rights. |
| `PrepareTestDeliveryStream::handle(target)` | Internal exact-file adapter returning `PreparedDeliveryStream`. A caller-supplied path is not a supported public selector. |

The caller supplies the original 64-character order owner key through a trusted internal boundary. This is not identity derived from a buyer email, order UUID or provider ID. The future HTTP layer must derive it from the existing owning session on every request. Losing or rotating that session context does not grant an automatic email/account recovery path.

The selector is one grant UUID plus `contract`, `master_wav`, `download_mp3` or `stems_zip`. A contract resolves only its retained first original; a media role resolves exactly one pending entitlement and the matching historical asset. No current title, newest revision, license text, client filename or arbitrary path is substituted. Filenames are server-derived ASCII `{grant UUID}-{kind}.{pdf|wav|mp3|zip}` with exact role MIME. The authorization binds these descriptors and the complete activation snapshot/hash.

## Authorization, retries and consumption

New issuance first performs unlocked policy/control/budget checks to reject obvious failures before expensive I/O. It prepares and closes a fresh verified snapshot, then locks order → control, reconstructs database evidence and rechecks the same policy/account/control version. The order mutex serializes all targets and idempotency keys. No private-file/provider/rendering I/O occurs in a transaction; all open connections must have zero transaction depth on entry.

At most three committed authorizations may have `issued_at > now - 60 seconds`; a row exactly on the trailing boundary no longer counts. Expired, abandoned and consumed authorizations still count while inside that window. Future timestamps or backwards time return `retry`. An exact UUID idempotency key is hashed with the owner and order. Identical replay returns `already_issued` without a new secret or budget charge; a changed selector conflicts. A lost response secret cannot be recovered, extended or reset. A deliberate fresh key requests another authorization subject to the same budget.

A new secret contains 32 random bytes transported as 43 base64url characters. Only its SHA-256 is stored, including inside encrypted evidence; the raw token exists only in the new result. Token checks use constant-time digest comparison. The result refuses PHP serialization and redacts the token from debug output. Trusted callers must not log it or serialize the result through another mechanism. No HTTP token transport is implemented yet.

`expires_at = issued_at + 60 seconds`, in UTC whole seconds. Redemption requires `now < expires_at`; exact equality is expired. Fresh target verification happens again, and the authorization's original expiry still applies after file preparation, lock waits, evidence verification and auditing. Under order → control → authorization locks, the service verifies the graph and captured control version, then inserts one immutable redemption/audit. Time and policy are checked again after transactional observers before the callback returns. Unique authorization membership prevents a second committed redemption.

Pre-commit failure closes the prepared snapshot and leaves no redemption. After commit, the attempt is consumed even if a transport disconnect or process failure delivers zero bytes. Expiry or a later block does not recall an already committed stream. This is **one committed stream attempt, not confirmed receipt**. No range, resume, reset, secret recovery or lifetime download-count mechanism is included.

| Bounded domain reason | Meaning / recovery |
| --- | --- |
| `not_found` | Invalid/foreign owner or selector, unknown authorization, malformed or wrong token; disclose no private locator. |
| `unavailable` | Unsupported environment/account or current access policy; correct configuration before a new attempt. |
| `blocked` | Missing activation/control, blocked control or obsolete captured version; preserve history and review explicit control state. |
| `changed` | Retained graph/evidence mismatch; investigate instead of substituting current records. |
| `already_issued`, `conflict` | Identical or changed idempotency replay; never recreate a secret under the existing record. |
| `budget_exhausted`, `expired`, `redeemed` | Rolling technical limit, elapsed authorization or consumed attempt; a fresh request remains subject to policy/control/budget. |
| `target_unavailable` | Selected file/snapshot cannot be safely prepared; restore exact bytes or resolve private storage capacity/permissions. |
| `retry` | Backwards/inconsistent clock or other explicitly retryable boundary; do not rewrite historical timestamps. |

These are internal reasons, not an implemented HTTP status/error mapping. Database/infrastructure exceptions are not converted into successful issuance or consumption.

## Private snapshot lifecycle

The domain reconstructs retained database provenance before invoking the local POSIX adapter. The adapter reuses strict private-root, exact-path, regular-file, single-link, permission, length/hash and identity checks from D-17/D-18. Source media is copied and hashed from the same checked descriptor in at most 1 MiB chunks. PDF verification retains the existing at-most-16-MiB bounded string. Cached integrity success cannot replace fresh physical reads. Missing originals or assets are never repaired, rerendered or recreated by delivery.

| Bound | Behavior |
| --- | --- |
| Storage | Configured unserved private local root; protected `delivery/spool` directories at mode `0700`. The spool directories/lock files may be created; purchased directories/files are not created. |
| Capacity | Three fixed slots per root, each at most 1 GiB, including both named and unlinked snapshots. The slot lease remains held until the owned descriptor closes. |
| Admission | Free space must be at least the selected size plus 16 MiB; concurrent allocations can still fail and are handled without a committed redemption. |
| Preparation time | One cooperative monotonic 60-second deadline for snapshot preparation; it cannot interrupt a stuck kernel I/O call. Redemption also retains the independent original authorization expiry. |
| Publication | Exclusive creation, bounded copy, required flush and `fsync` (missing/disabled sync denies preparation), read-back hash/size check, mode `0400`, matching read-only descriptor and unlink before return. |
| Cleanup | Ordinary failure removes only the inode this call created; unexpected replacements are not deleted. Fixed-path crash residue blocks its slot and is never automatically reused or removed. |
| Consumption | `PreparedDeliveryStream::writeTo(consumer)` emits bounded complete chunks and closes descriptor/lease in `finally`; direct `stream()` callers must close the DTO themselves in `finally`. |

The consumer must accept each complete chunk or throw. Do not pass a purchased/spool path to a path-based response, generate a public/signed storage URL or reopen a file after the database decision. The descriptor is non-serializable and must not be stored in a job/session. A future HTTP consumer must retain it only for the winning native attachment response.

Three crash residues can exhaust all slots. Fail closed, quiesce all workers and inspect the three bounded slot paths, their file types/ownership and the retained source evidence before any deliberate operator cleanup. No automatic janitor or cleanup command exists in this increment. Do not manually delete active lock files or change immutable delivery evidence to restore capacity. Restore missing purchased files from the exact retained original/revision backup; the next attempt must freshly verify them.

These controls bound trusted application-worker storage and prevent a path-reopen gap. They do not provide replicated durability, archival acceptance, object retention locks or OS isolation from a hostile same-UID process. Production managed storage and restore/recovery acceptance remain separate work.

## Durable schema and rollback

Migration `2026_09_26_000023_test_owner_delivery.php` adds:

| Record | Retained invariant |
| --- | --- |
| `test_delivery_controls` | Unique restrictive order/activation, immutable identity and creation time, blocked version-zero creation, guarded increment for every actual state change, no delete. |
| `test_delivery_authorizations` | Unique opaque UUID/token digest and order/idempotency digest; immutable original owner, activation/control/version, exact grant/target branch, policy, encrypted evidence/hash and exact 60-second timestamps. |
| `test_delivery_redemptions` | One immutable row per authorization, captured version, exact byte hash/size, encrypted evidence/hash and redemption time within issuance/expiry. |

ORM and SQLite/MySQL guards retain records, require exact policy/target spellings and matching paid/test parent bindings, and protect the exclusive target branches. Private owner/hash/ciphertext fields are hidden from normal model serialization. Canonical evidence remains bounded to 2 MiB and ciphertext to 4 MiB. SQL guards are defense in depth; domain reconstruction establishes full cryptographic evidence. The rolling technical budget is enforced under the domain order mutex, not by a standalone row count outside a transaction.

Populated rollback refuses before altering guards or tables if any control, authorization or redemption exists. Empty down/up and historical migration roundtrips are for disposable test databases. Operational rollback disables new access and preserves history; it does not erase tokens, redemptions, grants or originals.

## Evidence and remaining delivery work

The adapter author reported native PHP 8.4 verification of **75 tests / 202 assertions** at `d007dcf`, including a real 1 GiB stream under a 128 MiB memory limit, independent-process slot limits, crash residue, short writes, corruption and cleanup. This is component evidence, not final integrated MySQL/SQLite acceptance or browser evidence. Independent source review accepted that adapter subject to integration/full CI and its stated recovery/isolation limits. The schema at `a8d9d1a` passed seven PHP 8.4.26/SQLite migration cases / 165 assertions; nine modified historical empty migration roundtrips previously passed / 154 assertions. The integrated source at `b269832` passed **86 PHP 8.4.26/SQLite tests / 611 assertions** (45 authorization/consumption, seven schema and 34 snapshot cases). The final missing-`fsync` child regression separately passed **one test / three assertions**. Three independent-process MySQL races were correctly skipped under SQLite and are not recorded as executed concurrency evidence. Authoritative PHPUnit discovery assigned all **1,424 expanded tests across 92 files** exactly once into four shards; D-19 contributes 90 cases including those three MySQL-only races. The first behavioral run had one incorrect console-output expectation, corrected before the passing integration run; no implementation gate was weakened. Independent review accepted domain `47c644c`, schema `a8d9d1a` and adapter `d007dcf`; root reviewed the final `f16a497` sync requirement and regression. Final full CI, real MySQL execution and prerequisite acceptance remain required.

The next customer slice must implement the item projection, owner HTTP boundary, bounded input/method/range handling, CSRF, generic private errors and privacy/cache headers, native attachment streaming, safe filenames and the order UI. Verify foreign orders/targets/tokens, lost/changed sessions, middleware/debug/log privacy, restrictions, exact expiry, independent MySQL issuance/redemption/control races, interrupted delivery, and Chromium/WebKit behavior. Do not expose internal evidence arrays, hashes, paths or raw secrets in URLs/logs. An issuance response must not claim the download completed.

No `ReadTestDeliveryItems`, controller, download route, HTTP error mapping, frontend download control or browser attachment test is supplied here. Existing owner order/checkout projections remain `contractStatus: issued` / `fulfillmentStatus: pending_activation`; enabling internal test control does not change that customer state. Customer library/re-download history, guest claims/account recovery, refunds/disputes, production access policy, resumable/object-store delivery and archival/restore remain open. The [ordered plan](development-order.md) retains all fourteen work packages and the 103-item parity baseline.
