# Private lyric notes, own-feature export and versioned clear

This next T32/FP-079 child extends the independently approved
`ca3b1fa7eb60edd2228b73742da33179228a277e` favorites/playlists source.
Final executable `ebb096eee0fbdfabe81a6d7eb622f50d342e9477`, tree
`71804c50e260d1aeba108290634cfc533518fa07`, preserves initial source
`0ed11a25ed6a0129f09c088ecb2763222289841c` plus capacity repair
`18a6cc934b6a08e55eec9e8bb353b523919ec4a4`. These change seven owned paths: the
listening service/component, dedicated domain/boundary/frontend tests and the
existing frontend test's response-boundary fixture and actual encrypted-storage
capacity canaries. Root owns HTTP grammar,
export registration, the listening-only request body cap, privacy middleware,
page mounting and composed review. This leaf introduces no SQL table/migration,
dependency, account policy, consent, payment, grant or purchased-history change.

Customers can save, edit and delete private lyric notes on a track reference
already retained in their favorites or a playlist. Notes are owner inputs and
remain usable if the track becomes unavailable; unavailable references still
expose no catalog title, artist, URL or preview. Removing the last favorite or
playlist reference also removes the corresponding note. No sharing is enabled.

## Exact persisted and API contracts

The original encrypted V1 payload reader still requires its original exact
keys, value types, account/revision binding and bounds. Reading, exporting or a
no-op command never rewrites a V1 row. An effective mutation promotes it to V2,
whose exact ordered keys are `schema,accountId,version,favorites,playlists,notes`.
Existing favorites and playlist meanings stay unchanged. Notes are an ordered
list of exact `{trackId,body}` objects: at most 25 distinct currently owned
references, at most 2,000 Unicode characters and 4,000 UTF-8 bytes each. LF/tab
and meaningful leading/trailing spacing are preserved; invalid UTF-8, other
controls, format characters and whitespace-only notes refuse. The whole payload
stays encrypted; corrupt V1/V2 state refuses before release, export or clear.

The existing MySQL `payload` column remains TEXT (65,535 bytes). Every effective
write checks the actual encrypted envelope against a conservative 60,000-byte
budget before SQL. Count and per-note maxima are upper bounds, not a promise that
25 maximum-size notes fit. Oversized intent returns a definite 422, preserving
the entire retained row and revision; shortening or deleting notes restores room.
The customer sees: “If your library is full, shorten a note or remove older notes.”
Valid older content above the conservative budget remains readable/exportable;
deleting enough content can bring the next envelope within budget.

Every command carries the current integer revision plus exact action fields:

| New action | Additional fields | Result |
| --- | --- | --- |
| `set-track-note` | `trackId`, `body` | Create/edit own retained-reference note; identical body is a no-op |
| `delete-track-note` | `trackId` | Remove its note; already absent is a no-op |
| `clear-library` | none | Remove all favorites, playlist names/references and notes; advance revision |

Clear also advances an empty/previously absent library and retains the owned
empty V2 row. This fences an older concurrent or lost-response intent instead
of resetting the revision to zero. Stale clear or note writes return 409;
unknown/unowned references return the same generic 404. Exhausted revisions
retain the existing refusal contract. This clears this feature's current
contents; it is not an application-wide account, order or backup erasure claim.

The projected library is schema 2 with exact keys
`listeningSchema,version,favorites,playlists,notes,limits`. Limits retain
`favorites:50,playlists:10,playlistTracks:25` and add
`notes:25,noteCharacters:2000,noteBytes:4000`. The component also strictly accepts
V1 projections during transition.

`ListeningLibrary::export(CustomerPrincipal, User, int expectedVersion)` locks
and validates the current owned aggregate, requires the matching revision, then
runs the approved callback-free captured-primary authority/retained-row proof.
It returns exact own feature inputs:

```json
{"exportSchema":1,"feature":"customer-listening-library","version":1,"favorites":["1"],"playlists":[],"notes":[]}
```

Export playlist entries contain only `id,name,trackIds`; notes contain only
`trackId,body`. Export contains no account/user identifiers, owner key,
credentials, contact details, catalog metadata, private asset paths, purchase
or entitlement information. It does not scan or resolve public catalog data,
and changes no row. Root's private POST `/account/listening-library/export`
accepts exact `{version}` and wraps the result in `{export:...}`. The existing
library GET/POST envelope remains `{library:...}`.

Root applies the 16,384-byte raw body cap only to listening commands/export;
other account requests retain 4,096 bytes. Even worst-case escaped note text
fits the listening cap. Client library/export responses are streamed and bounded
to 3 MiB with complete shape/content checks. Export must match the current own
inputs/revision before a fixed-name local JSON blob is downloaded; its object
URL and temporary anchor are released. A clear requires an explicit customer
choice. Existing session/CSRF, abort/deadline, pagehide/unmount, generation,
duplicate-request and uncertain-commit recovery guards stay active. Failed or
unconfirmed writes erase private drafts and require a fresh GET before another
intent; no old note/clear POST is replayed automatically.

## Actual verification

Final exact-source results and hashes appear in `source-runtime.json` and raw
receipts. The selection carries all 107 approved listening/access/public/migration
cases and adds 40 notes/export/clear domain/boundary/capacity cases (147 total,
872 assertions). The capacity canaries alone pass 3 cases / 108 assertions. The 50 frontend cases
carry the 24 original journey/security cases and add 26 V2/notes/export/clear
cases. TypeScript, Pint, reflection to this checkout and staged whitespace
checks pass. Frontend and TypeScript ran against capacity source `18a6cc9`;
all frontend/TypeScript/config/lock blobs are byte-identical in final `ebb096e`,
whose only child change adds the requested actual-TEXT PHP canary. Runtime is PHP 8.4.26 with SQLite `:memory:` and Node 24.19.0.

```sh
APP_KEY='base64:a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=' \
  /workspace/.va-studio-toolchain/bin/php vendor/bin/phpunit \
  tests/Feature/CustomerListeningLibraryTest.php \
  tests/Feature/CustomerListeningMigrationTest.php \
  tests/Feature/CustomerListeningPrimaryProofTest.php \
  tests/Feature/CustomerListeningFreshnessTest.php \
  tests/Feature/CustomerListeningNotesTest.php \
  tests/Feature/CustomerListeningNotesBoundaryTest.php \
  tests/Feature/CustomerListeningNotesCapacityTest.php \
  tests/Feature/CustomerAccountAccessTest.php \
  tests/Feature/PublicCatalogRelatedLinksTest.php \
  --log-junit docs/verification/customer-listening-notes-export-20261007/frozen-php.xml
npm test -- tests/frontend/customer-listening-library.test.tsx \
  tests/frontend/customer-listening-notes.test.tsx
npm run typecheck
/workspace/.va-studio-toolchain/bin/php vendor/bin/pint --test \
  app/Domain/Customers/Listening \
  tests/Feature/CustomerListeningLibraryTest.php \
  tests/Feature/CustomerListeningNotesTest.php \
  tests/Feature/CustomerListeningNotesBoundaryTest.php \
  tests/Feature/CustomerListeningNotesCapacityTest.php
git diff --cached --check
```

New cases verify actual encrypted persistence and read-only exact own export,
untouched V1 reads/no-ops/export, permitted V2 promotion, reference ownership and
last-reference pruning, unavailable track edits, Unicode/byte/control/count
bounds, corrupt/extended shapes, real clearing and stale-resurrection refusal,
cross-account destructive refusal, genuine late query callbacks and same-model
revision rebinding. Frontend cases exercise actual save/edit/delete, clear
confirmation/cancel, exact blob bytes and URL release, stale/malformed/extended
exports, revoked authority, uncertain note recovery and late pagehide responses.
The original response-size canary now tests the agreed 3 MiB bound; its refusal
assertions remain intact. Actual encryption canaries exercise maximum ASCII and
multibyte inputs, zero framework SQL writes on refusal, whole raw-row/revision
preservation, fresh export and deleting/re-adding notes. A third case computes
an actual encrypted intent above 65,535 bytes from retained valid content within
TEXT capacity, then requires 422 before SQL and tests revision-preserving cleanup.
The same final capacity tests run with the original `0ed11a25` ListeningLibrary
class injected before autoload yield three genuine SQLite failures; SQLite
accepts the oversized ciphertext and the two conservative-budget assertions fail
at 61,952/64,800 bytes. This is actual capacity regression evidence, not native
SQL-overflow evidence. Root's independent reviewer owns native TEXT/SQL1406 proof
and repaired composed-source validation in its isolated database. The intermediate
`18a6cc9` full SQLite selection passed 146 / 860 before the final third canary.

Initial test failures compared the inserted model's raw columns against a
rehydrated row with a different field order. Canonical fresh-row capture repaired
those assertions without relaxing their value checks. Initial TypeScript
failures were missing callback parameter/`this` annotations. All actual failure
receipts remain here. The first combined selection hit 14 public-catalog fixture
errors because the fresh isolated checkout had no `.env` encryption key. The
first attempt to rerun supplied a mistyped synthetic key and also failed. Both
receipts remain visible. The final selection uses the correctly generated
32-byte synthetic key above, scoped only to that test process; no real key, policy
or guard changed. Invalid-key capacity iterations remain separately named.
Readable stdout copies trim final blank lines; adjacent
`.txt.gz` files retain the exact original stdout when necessary.

Installed vendor package namespaces link to the existing locked original
packages with independent Composer metadata/autoload/bin. No full vendor copy
or dependency installation was made. The prior approved source/evidence stays
intact in its worktree. This leaf did not run native MySQL contention, the root's
new HTTP grammar/export/privacy integration, actual browser/device/full
Foundation acceptance, hosted CI or production activation. Root must compose
and independently review this new batch before acceptance. Consent/preferences/
suppression is the next separate required T32 child; full CRM, source migration,
production export/retention policy, sharing and cutover remain open.
