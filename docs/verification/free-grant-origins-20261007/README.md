# Free grant author receipt — October 7, 2026

Final author source: `28ae480b05f34d295720d2ac816e92e9d9039742`, with runtime
byte-exact to `d8e57770aa54cf62d9ba35800cc16447c4e16727`, a successor of
`ebf6298633870a0f1c7b431f8aa4bb25c26e94a9`, from main
`3fb7dd05142e4539e2f0af17831d66a1544d0cb0` in the isolated
`codex/free-grant-origin-20261007` worktree. Both candidates are author evidence,
pending independent actual-source review and parent's real shared registration.
No hosted CI, provider request, external email, production credentials, live
activation, purchase, repository dependency change, push or merge occurred.

The private disposable author database is `vaseyaudio_free_grants` on the existing
loopback native Oracle MySQL `8.0.46-0ubuntu0.24.04.4` `(Ubuntu)`, port 33067.
It is distinct from every other lane's database and the hosted MySQL 8.4 image.
The rootless package/binary signature provenance is retained outside the repository
in `/workspace/.va-studio-toolchain/mysql/provenance.json`. Credentials are absent
from this receipt and its evidence.

## Source and selections

The base `ebf6298` affected SQLite selection passed 37 recorded / 36 executed /
2352 assertions, with one explicit native-only contention skip. Its native schema
selection passed 8 recorded / 7 executed / 768 assertions, with one explicit
SQLite-only composite-PK dependency skip. The separately observed two-worker
native capacity test passed 1 / 68; its polling assertions count is timing-dependent.

The `ebf6298` native affected selection recorded 8 cases: 7 passed / 140 assertions
and one fixture error. `CREATE TEMPORARY TABLE users LIKE users` raised native
1066 before that masking probe ran. This is retained red evidence, not a green
authority result. The successor creates the temporary table from exact `SHOW
CREATE TABLE` DDL, preserves the exact stale user row and keeps the real withdrawal
and final refusal/original-preservation assertions. Its first native run then
exposed PDO conversion of an Eloquent boolean to an empty string (native 1366),
also before the masking assertion. The final fixture inserts the exact captured
persisted raw row instead of model attributes, for both native and SQLite.
The other seven predecessor
cases remain historical evidence only; the successor's focused native run below
rechecks the corrected masking probe, retry deadline and affected real HTTP journey.

The short successor adds a read-only status method/route and mounted status/retry
UI. All existing `FreeGrantDownloads` authorize/redeem methods, the entire schema,
identity/source/assent/document domains, renderer/profile and capacity workers
remain byte-exact to the predecessor. The source-map file records the complete
candidate file hashes. The earlier unaffected native migration and capacity proofs
are carried only for those exact unchanged sources.

The runtime successor's affected SQLite selection passed **22 / 357 assertions**,
TypeScript passed, and the mounted frontend passed **8 / 8**. Its native selection
recorded three cases: the actual lease-deadline retry and real HTTP PDF/WAV/status
journey passed **2 / 109 assertions**, with the masking fixture's one 1366 error.
That entire mixed receipt stays under `original-failures/native-status-successor`.
The final test-only successor's corrected exact-row masking case then passed
**native 1 / 4** and **SQLite 1 / 4**, with all withdrawal/refusal/retained-row
assertions intact. Both passing native runtime cases and all frontend/runtime
bytes are unchanged in this final fixture successor. These are separately selected
results, not a fictional rerun of the mixed selection. Pint passed and diff checks
passed before the source freeze.

## Commands

All commands ran in `/workspace/VA-Studio-free-grants` with the rootless toolchain
activation. Native shells additionally sourced the private author env file and
used the tool's loopback network permission; no environment values were printed.

```sh
vendor/bin/phpunit tests/Feature/FreeGrantDefinitionsTest.php \
  tests/Feature/FreeGrantJourneyTest.php tests/Feature/FreeGrantAdministrationTest.php \
  tests/Feature/FreeGrantAuthorityTest.php tests/Feature/FreeGrantDownloadsTest.php \
  tests/Feature/FreeGrantHttpTest.php tests/Feature/FreeGrantSchemaRecoveryTest.php \
  tests/Feature/FreeGrantConcurrencyTest.php
vendor/bin/phpunit tests/Feature/FreeGrantSchemaRecoveryTest.php
vendor/bin/phpunit tests/Feature/FreeGrantConcurrencyTest.php
vendor/bin/phpunit tests/Feature/FreeGrantPreparationStatusTest.php \
  tests/Feature/FreeGrantHttpTest.php tests/Feature/FreeGrantAuthorityTest.php
npm run typecheck
npm test -- tests/frontend/free-grant-journey.test.tsx
vendor/bin/phpunit tests/Feature/FreeGrantAuthorityTest.php \
  tests/Feature/FreeGrantPreparationStatusTest.php tests/Feature/FreeGrantHttpTest.php \
  --filter '/test_terminal_real_container_resolution_withdrawal.*#3$|test_abandoned_live_claim|test_actual_customer_page_review/'
vendor/bin/phpunit tests/Feature/FreeGrantAuthorityTest.php \
  --filter '/test_terminal_real_container_resolution_withdrawal.*#3$/'
```

The raw logs/JUnit identify exact recorded selections and outcomes. No full
SQLite/native/browser matrix or real browser engine run was performed.

## Meaningful boundaries

The evidence includes server staff authoring and separate review through actual
Filament actions; exact original terms/name/affirmative assent and stale/replay/
cross-customer refusal; true native two-buyer contention with both worker connection
IDs observed waiting on the exact InnoDB `rights_scopes` row; real isolated offline
PDF generation; real exact WAV/PDF native form attachment streams; late credential,
account, feature flag and audit closure withdrawal; authority withdrawn during
held-descriptor I/O; bounded current status without token disclosure; restore-only
completed originals; and all eight tables' ordinary SQL/IGNORE/REPLACE immutability.

Migration faults use the actual Migrator/ledger. SQLite interrupts every generated
statement. Native faults select five representative boundaries: first CREATE,
first foreign key, first unique index, first guard and last guard. This is not
exhaustive native interruption at every statement. Retry preserves prior parents,
retained rows, old ledger, all surviving columns/storage/explicit keys/FKs/guards,
and adds only the exact missing prefix/ledger. InnoDB may replace an automatic
single-column FK index with the subsequently authored unique index having that
leading column; surviving fully formed SHOW CREATE definitions after guard faults
must remain byte-exact. Drift, partial rows, temporary shadows, external references,
composite SQLite ID primary keys and destructive down refuse without lost data.

Original failed receipts are retained under `original-failures/`. Corrections do
not overwrite them or claim their initial candidate passed. The first renderer
environment lacked local `libargon2`; the successor pins its canonical rootless
loader path and explicitly required non-database extensions. Earlier fixture
failures (CSRF test registration, canonical key comparison, assertion identity,
prefix-vs-drift expectation and strict PDO boolean representation) are recorded
with their actual outcomes.

## Limits

Synthetic local source assets and original declarations are isolated test input;
they certify no commercial terms, seller identity, real storage durability,
production authority or live payment fact. Both flags default off. The operative
T23/approved-terms successor and actual parent application registration remain
unapproved prerequisites. Production scope accounting, retention and storage/
backup/recovery are still open. No claim of complete T18/T24/T25/WP-08 or launch
readiness is made.
