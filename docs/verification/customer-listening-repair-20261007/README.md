# Primary evidence fencing and nondestructive listening migration

This repair supersedes the terminal proof and migration behavior in the original
listening leaf `d540f60aabe8a802f1a9d242b90569e9886b257c`. Final executable source is
`3339f945655012c2a5544dbbff891b607eced18c`, tree
`e40ba6d72bc75f55bb008978d9653fd3dae2f831`. The two repair commits are
`288273df8398074be928a44256c0f87ca8005a03` and its child `3339f945...`.
They change only the owned listening service, new local evidence helper, additive
migration and dedicated tests. Root owns route/controller/privacy/page mounting,
shared ledgers and integration. No public API or frontend implementation changed.

## Concrete defects and resulting behavior

A `QueryExecuted` callback runs after an ORM row has been fetched. A callback on
the last account query could withdraw account authority, change credentials or
disable policy while the original implementation still released private names
and committed a new library revision. Public-query callbacks could supersede the
retained library row; the final account callback could unpublish a saved track
while its old public metadata/link escaped. A saved-model callback could refresh
the same instance to another valid encrypted revision, causing physical evidence
to bind to that instance rather than the intended local revision. Those cases now
refuse and roll back without releasing a private projection or stale public link.

Each operation captures the original primary PDO, connection and database inside
its command transaction, before ORM callbacks. After the last framework access
query, a callback-free raw primary proof verifies present user/account authority,
credential binding, enabled policy and the exact account library row or absence.
Current MySQL reads use `FOR UPDATE`; SQLite reads name the `main` catalog. Native
temporary-shadow checks prevent framework reads from resolving different objects.
The expected physical row also must match the intended decrypted state/version;
a same-instance saved callback cannot silently rebind it.

Before each authoritative `PublicCatalog::selections` call, a bounded raw closure
records the exact known track IDs and their rights, media/run/recording,
offer/revision, license/review/template and linked inventory dependencies. The
final proof compares those rows, relevant configuration, actual private adapter
and file/directory identities; an effective-license-window transition also
invalidates the evidence. PublicCatalog continues to decide eligibility. The
closure never enumerates the catalog: ID chunks remain at most ten, dependency
queries fetch at most 257 rows and refuse if more than 256 are needed. Heavy
history or changed evidence returns the existing generic 503 contract, requiring
a fresh GET and a new intent; no failed POST is automatically replayed. Existing
unavailable placeholders still expose no catalog metadata or URL.

The original multi-statement schema installation could leave a successful CREATE
unrecorded after an interruption, then refuse its retry. SQLite and MySQL now use
one atomic CREATE containing the account uniqueness and foreign key. A retry
accepts only the exact complete owned schema; extra columns/indexes/triggers,
foreign keys, marker or other metadata drift and temporary shadows refuse
without changing the object. Real migrator tests inject failure immediately after
the successful CREATE event, then retry and retain prior customer identities and
real membership credit history.

Operational `down()` always throws before any query or mutation, including empty
or drifted storage. It preserves schema, private preferences and Laravel's
migration record. There is no operational drop/reset path in this migration;
disposable tests alone use the existing isolated `db:wipe` lifecycle. Existing
customer, membership, order, payment, license and historical entitlement schemas
remain untouched. Access stays default-off outside the existing local/testing
policy, with no mail, marketing consent inference or live activation.

## Frozen verification

Final exact-source results passed **107 PHP tests / 549 assertions**, zero errors,
failures or skips, and are recorded in `source-runtime.json` and raw logs.
The focused PHP selection covers the 25 original listening cases, 18 real last
account callback cases across read/create/no-op, 17 freshness/model/shadow cases,
9 atomic migration/ownership/retention cases and the existing customer access and
public eligibility regressions. All 24 frontend cases passed; TypeScript, Pint,
autoload reflection and whitespace checks passed. Frontend/TypeScript executed
at `288273d`; all frontend sources and dependency locks are byte-identical at
final `3339f94`. The final PHP, Pint and whitespace checks apply to `3339f94`. PHP 8.4.26 uses SQLite
`:memory:`; Node is 24.19.0.

```sh
/workspace/.va-studio-toolchain/bin/php vendor/bin/phpunit \
  tests/Feature/CustomerListeningLibraryTest.php \
  tests/Feature/CustomerListeningMigrationTest.php \
  tests/Feature/CustomerListeningPrimaryProofTest.php \
  tests/Feature/CustomerListeningFreshnessTest.php \
  tests/Feature/CustomerAccountAccessTest.php \
  tests/Feature/PublicCatalogRelatedLinksTest.php \
  --log-junit docs/verification/customer-listening-repair-20261007/frozen-php.xml
npm test -- tests/frontend/customer-listening-library.test.tsx
npm run typecheck
/workspace/.va-studio-toolchain/bin/php vendor/bin/pint --test \
  app/Domain/Customers/Listening \
  tests/Feature/CustomerListeningLibraryTest.php \
  tests/Feature/CustomerListeningMigrationTest.php \
  tests/Feature/CustomerListeningPrimaryProofTest.php \
  tests/Feature/CustomerListeningFreshnessTest.php \
  database/migrations/2026_10_07_242000_customer_saved_tracks.php
git diff --check
```

The original access callback probe failed all 18 cases before the repair. The
first raw-row proof compared arrays in differing column order; normalization by
column name repaired that implementation error while retaining exact values.
Four early media mutation probes hit existing immutable-evidence SQL triggers,
so they were replaced by permitted new uncleared rights and a changed storage
adapter. Their actual error receipt remains here. The temporary-shadow canary
was red using the prior `288273d` evidence class injected from its exact Git blob
into the final dedicated test, and green with the final helper. The preceding
`288273d` broader selection passed 106 tests / 545 assertions; its receipt is
historical and separate from final source acceptance. Raw original/intermediate
receipts are retained rather than overwritten. Readable frontend/typecheck logs
trim final blank lines; adjacent `.txt.gz` files preserve their exact stdout bytes.

Installed vendor package namespaces still link to the original locked packages,
with independent Composer metadata/autoload/bin resolving this checkout. No
manifest/lock or dependency installation changed. This leaf did not run native
MySQL DDL/concurrency, the mounted HTTP integration, browser/device/full Foundation
acceptance or hosted CI. Independent native and composed-source checks belong to
root/recovery review evidence, and are not claimed here. T32 lyrics/notes,
feature export/deletion, consent/preferences/suppression, and full cutover remain
separate open children; this favorites/playlists batch does not close T32.
