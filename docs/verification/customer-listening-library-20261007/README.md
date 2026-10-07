# Private customer saved tracks and playlists

Historical leaf evidence: the callback proof and migration behavior below are
superseded by [the primary-evidence repair](../customer-listening-repair-20261007/README.md).
The 66-test result applies only to `d540f60a...`, not the repaired source.

This T32/WP-11 child persists favorites and named ordered playlists for an
authorized synthetic customer account. Customers can create, rename and delete
playlists, add/remove tracks, and move tracks earlier/later. Saved references
survive navigation; every projection resolves their present eligibility through
`PublicCatalog::selections`, never a saved metadata snapshot. Withdrawn, unknown
or otherwise unavailable references show only `trackId` and `available:false`,
without title, artist, URL, preview or private media information.

Executable `d540f60aabe8a802f1a9d242b90569e9886b257c`, tree
`e58ac373e92494c84949c0760b63dc3fa41af6ed`, is based on
`717f0866444701637e7c396b4376891aa5dd995d`. All eight executable paths are new:
the listening domain/model/exception, additive migration, component, two PHP
test classes and one frontend test file. This metadata child changes this new
evidence directory alone. Root owns route/controller/privacy registrations,
the account-page mount, shared status and final composed/independent review.
This leaf alone does not register an HTTP endpoint or mount its component.

## Domain and integration contract

`ListeningLibrary::read(CustomerPrincipal, User)` and
`::change(CustomerPrincipal, User, array $command)` return the private aggregate.
Root exposes GET/POST `/account/listening-library` using the strict JSON envelope
`{library:...}` and existing session/CSRF/private-response protections. The named
`CustomerListeningLibrary` component needs no props; its account-page parent
must mount only with the explicit server capability and a fresh transient scope
key, as with membership history.

Every mutation requires the current integer `version` and exact action fields:

| Action | Additional fields |
| --- | --- |
| `save-track`, `remove-saved-track` | Canonical string `trackId` |
| `create-playlist` | Bounded private `name` |
| `rename-playlist` | UUID `playlistId`, `name` |
| `delete-playlist` | UUID `playlistId` |
| `add-playlist-track`, `remove-playlist-track` | `playlistId`, `trackId` |
| `reorder-playlist` | `playlistId`, exact ordered `trackIds` permutation |

`ListeningException::$status` distinguishes malformed/bounds (422), unavailable
track or unknown owned playlist (404), stale revision (409), and unverifiable
retained state (503). Messages contain no request values. `CustomerAccessException`
retains its existing access-refusal contract. No public response includes account,
user, owner-key, credential-stamp or access-version fields.

## Persistence, authorization and limits

The additive `2026_10_07_242000_customer_saved_tracks` migration creates one row
per existing customer account. Its encrypted payload binds the internal owner
and database revision, so transplanted ciphertext or changed revision refuses
before returning private names. All commands lock the fresh user/account first,
then that account's library row, and recheck current credential/access policy
after public readiness/media callbacks. Global optimistic revision fencing
prevents lost updates; duplicate existing track additions are no-ops rather than
duplicate list entries. New additions require present public eligibility.

The aggregate permits 50 favorites, 10 playlists and 25 tracks per playlist;
playlist names permit 80 Unicode characters without control/format characters.
At most 300 references can be projected, in at most 30 authoritative chunks of
ten IDs. The bounded test verifies every track query has an ID restriction and
limit ten, with no offset or catalog enumeration. References can be removed or
reordered even after tracks become unavailable.

The migration refuses existing/temporary objects before creation and refuses
populated rollback before changing rows or its migration bookkeeping. Verified
empty rollback/reinstallation preserves a real prior customer identity and
membership grant/history. No existing customer, membership, order, contract or
entitlement schema changes. Customer access remains default-off and always
refused outside local/testing through the existing `CustomerAccessPolicy`.

The component searches the actual public catalog, uses current server versions
and CSRF state, bounds streamed JSON, and validates the entire private projection.
It stores no preferences in browser storage, clears private state on pagehide,
ignores late/unmounted responses, and prevents duplicate requests. Uncertain,
stale or failed saves require a fresh GET before a new intent; the previous POST
is never automatically retried. Access refusal clears data and requires sign-in.
Existing approved account form/button classes supply the visual treatment.

## Actual verification

Fresh exact-executable checks passed **66 PHP tests / 385 assertions**, zero
errors/failures/skips; **24 frontend cases** passed; TypeScript and Pint passed.
Whitespace checks passed. PHP 8.4.26 used SQLite `:memory:`; Node was 24.19.0.

```sh
/workspace/.va-studio-toolchain/bin/php vendor/bin/phpunit \
  tests/Feature/CustomerListeningLibraryTest.php \
  tests/Feature/CustomerListeningMigrationTest.php \
  tests/Feature/CustomerAccountAccessTest.php \
  tests/Feature/PublicCatalogRelatedLinksTest.php \
  --log-junit docs/verification/customer-listening-library-20261007/focused-php.xml
npm test -- tests/frontend/customer-listening-library.test.tsx
npm run typecheck
/workspace/.va-studio-toolchain/bin/php vendor/bin/pint --test \
  app/Domain/Customers/Listening \
  tests/Feature/CustomerListeningLibraryTest.php \
  tests/Feature/CustomerListeningMigrationTest.php \
  database/migrations/2026_10_07_242000_customer_saved_tracks.php
git diff --check
```

The selected checks cover actual persistence/encryption, every list mutation,
cross-account and foreign/unknown playlist refusal, disabled/production/revoked/
unverified/admin/changed-credential access, stale/duplicate/bounded/malformed
commands, fresh current titles and availability, corrupt/context-swapped state,
callback withdrawal rollback, safe migration/history/bookkeeping, and the
existing access/public-eligibility regressions. Frontend cases exercise the
real search/save/create/rename/add/remove/reorder/delete flows, unavailable
placeholders, uncertain saves, revoked sessions, malformed/oversized bodies,
duplicate submission, deadlines, unmount and navigation clearing.

The first strengthened prior-membership fixture test hit the existing
`MembershipPolicy` standalone-transaction guard because it used `RefreshDatabase`.
Only the new migration test switched to the repository's accepted
`FinalizationDatabaseMigrations` disposable lifecycle. The guard remained intact;
the repair passed 3/11 before the final selection. Both actual failure and repair
outputs are retained rather than omitted.

Locked package directories link to the existing original vendor installation,
with independent Composer metadata/autoload/bin and no-scripts/no-plugins
autoload regeneration. Reflection resolves the domain/test to this checkout;
all 158 installed versions/source references match the lock. Source/dependency
digests and evidence hashes are in `source-runtime.json`. The original vendor,
dependency manifests/locks, and predecessor source/evidence remain untouched.

No hosted CI, native MySQL concurrency/DDL, browser/device/full Foundation
acceptance, live account enrollment or production activation ran. Root still
must prove the mounted/registered composed journey and seek independent review
on that actual source. Playlist sharing, lyrics/notes, actual source import,
export/retention/deletion policy, consent/CRM, full FP-079/T32 acceptance and
cutover remain open. This feature sends no mail, infers no marketing consent,
and creates no order, payment, purchased rights or entitlement.
