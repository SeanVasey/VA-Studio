# Free256 lane (plan step D1): operative production-free grant family 256

- Branch: `harness/free-256`, from `origin/main` at `fad3ab444e89ffb57dc915f61f7f788b626e330a`.
- Specification: `docs/handoff/2026-10-07/owners/free-content.md` (section "Resume 256 as one useful implementation batch").
- Preparation input (read only, not composed): `codex/production-free-family-20261007` at `5bfdd4f00dd4e1c2d989625a810a81ddf4bad152`
  (14-table parent column metadata; the `users` and `customer_accounts` observations shape the parent floor below).
- Status: **merged to `main` as PR #53 (`f7224ed3`) as a default-off synthetic-rehearsal batch; independently reviewed (APPROVE WITH CONDITIONS, see "Independent review" below); not registered or mounted.**
  The plan below was written before code; corrections made during implementation are listed under
  "Plan corrections". Evidence follows the plan.

## Plan

### Files owned (new; nothing shared is edited)

| Path | Role |
| --- | --- |
| `app/Domain/Grants/ProductionFree/*` | Reserved NEW namespace `App\Domain\Grants\ProductionFree` |
| `config/production-free-grants.php` | Literal `enabled => false`; no hashes approved |
| `database/migrations/2026_10_07_256000_production_free_grants.php` | Calls the installer only |
| `resources/contracts/production-free-v1/profile-assets.json` | Sealed renderer implementation manifest |
| `scripts/render-production-free-grant.php` | Bounded child process for the renderer (no Laravel, DB or env) |
| `tests/Feature/ProductionFreeGrants/*`, `tests/Support/ProductionFreeGrantFixtures.php`, `tests/Support/FakeProductionFreeSources.php` | Tests and synthetic fixtures |
| `docs/verification/free-256-20261007/*` | This evidence |

Not touched: `README.md`, `CHANGELOG.md`, `docs/development-order.md`, `routes/web.php`, `bootstrap/providers.php`,
`config/app.php`, the old `App\Domain\Grants\Free` test family, identity source, checkout source, paid252 lane files.

### Assumptions

1. Root's approved identities are used verbatim: family `production-free-origin-v1` version 1, legal purpose
   `production-free-license-grant-v1` (a distinct column), PDF profile `production-free-grant-pdf-v1`, private path
   `contracts/production-free-v1/{originUUID}/{claimUUID}/original.pdf`.
2. The paid252 renderer and transfer (`App\Domain\Grants\Paid\*`) are **not on `main`**; they exist only on the
   unmerged A2 lane `harness/paid252-composition`. A lane branch cannot import them. 256 therefore reuses the same
   main-resident substrate the paid family is itself built on (`ContractRenderer`, `RenderedContract`,
   `ContractRenderProfile`/`ContractRenderProfileRegistry` base `test-buyer-pdf-v2` fonts and packages,
   `ContractFiles` renderer workspace, `ContractIo`), references those files by SHA256 in a frozen-bytes test, and
   mirrors the paid transfer-by-hash shape without copying paid files. Recorded as a conflict with the D1 row
   ("reuses paid renderer/transfer by hash"), resolved in favour of what exists on `main`.
3. Commercial facts are Sean's. The installer, policy and commands never contain real terms, prices or assets.
   Operative open and customer assent require the definition's terms hash to be listed in
   `production-free-grants.approved_terms_hashes` (empty by default). Tests set synthetic hashes only.
4. Current rights/publication/scanner readiness and the exact private asset bytes come from a capability
   `ProductionFreeSources` that must be bound in the container (the member family's capability pattern). Nothing
   binds it; tests bind a synthetic in-memory implementation. Wiring it to the catalog/media readiness proofs is
   root's composition step.
5. The customer identity floor is `ProductionCustomerSessions::principal(Request)` for the principal and
   `request->user('customer')` for the trusted actor. Domain commands take `(ProductionCustomerPrincipal, User)`;
   inside each transaction identity is locked first (`ProductionCustomerAccess::lock`) and re-proved last
   (`proveCurrent`) with the original raw evidence.
6. Staff authority mirrors the existing staff writers (`FreeGrantStaff`): locked current `users` row, `is_admin`,
   verified email, `Gate administer-catalog` and `AdminMultiFactor::satisfiedBy(lockForUpdate: true)`.

### Acceptance criteria

- Migration `2026_10_07_256000` installs nine append-only tables through `ProductionFreeGrantSchema` with
  BEFORE INSERT/UPDATE/DELETE guards per driver, byte-exact UUID/hash/timestamp shape guards in the insert guard,
  foreign-key ordering guards, a parent floor on `users`/`customer_accounts` before the first DDL and after the last,
  strict empty-prefix recovery and a refusing `down()`.
- `config/production-free-grants.php` ships `enabled => false`; the policy refuses everything unless enabled, the
  environment is `local`/`testing`, provenance is `synthetic_rehearsal`, and the sources capability is bound.
- Staff: propose a definition (sealed terms/template/profile/asset/source manifest, source IDs and hashes preserved),
  an independent reviewer approves it (reviewer differs from author, MFA, current staff), open/close availability as
  append-only events, revoke one grant while preserving its original.
- Customer: typed review of the exact open definition, literal assent (`affirmed === true`, exact display hash and
  terms hash), one original grant per account and definition, idempotent request key.
- Rendering: deterministic sealed profile, isolated renderer process, write-once private original, append-only
  claim/fail work with leases (an uncertain claim is never reset or overwritten), recovery from stored bytes by hash,
  and a re-render that is byte-identical.
- Library: current-owner list and detail with document status and exact artifact hashes; foreign owners see nothing.
- Delivery: short-lived one-use authorization per artifact (`contract`, `master_wav`, `download_mp3`, `stems_zip`),
  exact entitlement check at redemption, hash-verified spool before the first byte, deadline enforced while
  streaming. No HTTP route is added.

### Tests (`tests/Feature/ProductionFreeGrants/`)

- `ProductionFreeGrantSchemaTest`: install/retry, guard red-green per table, prefix holes, retained data, foreign
  collisions, parent floor drift, refusing `down()`; native-only cases marked like the suppression/member tests.
- `ProductionFreeGrantApprovalTest`: author/reviewer matrix (self-review, revoked staff, no MFA, stale hash,
  disabled policy, wrong environment, unbound capability, unapproved terms).
- `ProductionFreeGrantAssentTest`: unapproved definition, closed availability, stale terms, missing/forged assent,
  replay, foreign principal, cap.
- `ProductionFreeGrantRenderingTest`: byte-identical re-render, recovery by hash, tamper refusal, lease/retry
  without overwrite.
- `ProductionFreeGrantLibraryTest`, `ProductionFreeGrantDeliveryTest`: library read side and entitlement refusal
  matrix (expired authorization, wrong principal, revoked grant, replay, hash drift).
- `ProductionFreeGrantFrozenBytesTest`: SHA256 of every main-resident file 256 depends on.

## Plan corrections (made during implementation)

1. **Original storage is not `ContractFiles::store`.** That main-resident writer hard-codes
   `contracts/test/{request}/{claim}/original.pdf`. 256 therefore has its own write-once
   `ProductionFreeGrantFiles` for the approved path `contracts/production-free-v1/{originUUID}/{claimUUID}/original.pdf`,
   rooted at the validated private delivery root (`DeliveryAssetFiles::privateRoot()`). The renderer still reuses
   `ContractFiles::createRendererWorkspace()`, `ContractIo`, `RenderedContract` and the `test-buyer-pdf-v2` base
   metadata by reference; every one of those files is bound by SHA256 in `ProductionFreeGrantFrozenBytesTest`.
2. **Revocations are a ninth table** (`production_free_revocations`), not listed in the owner doc's eight proposed
   names. Revocation must stop delivery while preserving the original, and an append-only table is the only way to
   record it without UPDATE. The guards of `production_free_authorizations` and `production_free_redemptions` read it.
3. **Rendering is a worker command** (`ProductionFreeGrantDocuments::render(originId)` / `recover(originId)`), not a
   customer request. The render input is the sealed origin payload alone, so the worker needs no customer identity.
4. **Asset byte cap** is `DeliveryAssetFiles::MAX_BYTES` (1 GiB), because the delivery snapshot is a
   `PreparedDeliveryStream`, which refuses larger sizes. The schema CHECK on redemptions allows up to 4 GiB so a
   later larger-snapshot adapter needs no DDL change.
5. **No `AuditEvent` rows.** There is no Eloquent subject model for these tables; each append-only row carries its
   actor id, timestamp and sealed payload, which is the audit evidence. Root may add audit fan-out when it composes.
6. Added `ProductionFreeGrantJourneyTest` and split the synthetic sources capability into
   `tests/Support/FakeProductionFreeSources.php` (PSR-4).

## What was built

| Class (`app/Domain/Grants/ProductionFree/`) | Role |
| --- | --- |
| `ProductionFreeGrantSchema` | Nine append-only tables, per-driver guards, parent floor, empty-prefix recovery, refusing `down()` |
| `ProductionFreeGrantPolicy` | Default-off; local/testing + `synthetic_rehearsal` + bound sources capability; approved-terms gate |
| `ProductionFreeGrantStaff` | Locked current staff row, pinned credential/MFA columns, `administer-catalog` gate, `AdminMultiFactor` |
| `ProductionFreeGrantRows` / `ProductionFreeGrantRecords` | Fixed internal SQL on the captured primary; encrypted canonical payloads; keyed row seals |
| `ProductionFreeGrantDefinitions` | `propose`, `approve` (independent reviewer, exact hash), `open`, `close`, `read`, verified graph |
| `ProductionFreeGrants` | Customer `review` (display hash), `accept` (literal assent, idempotent request key), staff `revoke`, origin graph |
| `ProductionFreeGrantRenderProfile` / `Text` / `PdfRenderer` / `RendererProcess` / `RenderInput` + `scripts/render-production-free-grant.php` | Sealed `production-free-grant-pdf-v1` profile and bounded child renderer |
| `ProductionFreeGrantFiles` | Write-once private originals (`x` mode, 0400, verify by hash), private spool directory |
| `ProductionFreeGrantDocuments` | Leased append-only claim/fail work, publish, `recover` (stored bytes + byte-identical re-render) |
| `ProductionFreeGrantLibrary` | Current-owner `index` and `show` |
| `ProductionFreeGrantDownloads` / `ProductionFreeGrantTransfer` | One-use short-lived authorizations, hash-verified unlinked snapshot, deadline-bounded stream |
| `ProductionFreeGrantRequestIdentity` | Request-to-identity step only: `ProductionCustomerSessions::principal(Request)` + `request->user('customer')` |
| `ProductionFreeGrantSources` (interface) | Capability for current readiness proof and private asset bytes; nothing binds it |

Identity: customer commands take `(ProductionCustomerPrincipal, User)`; inside each transaction
`ProductionCustomerAccess::lock` runs first and `proveCurrent` last with the original raw evidence; the identity
provenance must equal the policy provenance. Staff commands lock the staff row first and re-prove it last.

## Deliberately left out (not implemented in this batch)

- **HTTP**: no controller, route, middleware, Inertia page, Filament resource or provider binding. The delivery
  endpoint is root's mount after A2 (paid252) composes; `ProductionFreeGrantTransfer::writeTo()` is the seam.
- **Connection-local commit observer** (owner doc step 3): commands re-prove identity/staff/policy as the last
  statements inside each Laravel transaction, but no free-owned `TransactionCommitting` observer proves the graph
  immediately before the physical commit, and callback-caused raw reopen/nesting/dispatcher drift is not attacked.
- **Committed-frame delivery validation** (owner doc step 5): redemption uses two ordinary current-owner
  transactions around the snapshot, not `IdentityCommittedFrame` committed validation plus plain nonlocking module
  proof on the original deadline before headers/first byte.
- **ff0 identity capsule** (`ProductionFreeGrantHttpIdentity`) is not composed. `ProductionFreeGrantRequestIdentity`
  derives the principal from the T23 session marker and the actor from the customer guard, but it does not retain a
  terminal HTTP proof across the response the way the ff0 capsule does.
- **Exclusive scope/inventory serialization** and live catalog/rights/scanner readiness: delegated to the
  `ProductionFreeGrantSources` capability, which nothing binds. No adapter over the current catalog proofs exists.
- **Verified production provenance**: refused by policy until Sean's facts, a storage host and composed proofs exist.
- Audit-event fan-out, staff list/search reads, customer history pagination beyond 50, retention migration.
- Old free/Test family: untouched; nothing relabelled or adopted.

## What only Sean can supply

- The operative free-grant **terms text** (its SHA256 goes into `production-free-grants.approved_terms_hashes`),
  assent wording, titles and terms references. Tests use visibly synthetic placeholders only.
- Which tracks/licenses/assets are offered free, the per-definition origin cap, and whether stems are included.
- The production **storage host** and retention policy for originals (U-02), scanner/readiness facts for assets.
- Whether the retained `test-buyer-pdf-v2` fonts/packages (whose base metadata says `test_only: true`) may back a
  production profile, or a production font/package manifest to replace them.
- Authorization to enable verified-production provenance at all.

## What root must do to compose

1. Review this exact SHA independently (licensing, authorization, migration: sensitive per AGENTS.md).
2. Bind `ProductionFreeGrantSources` to an adapter over current publication/rights/scanner/media readiness and
   `DeliveryAssetFiles` bytes; serialize exclusive scope/inventory there.
3. Add the staff UI/actions and customer controllers/routes (author-owned NEW files), mount after A2, and decide
   the `ProductionFreeGrantHttpIdentity` (ff0) composition for terminal HTTP proof.
4. Add the commit-observer corridor and committed-frame delivery validation the owner doc requires before any
   production provenance.
5. Register README/CHANGELOG/development-order entries; keep `enabled => false` until Sean's facts arrive.

## Evidence (facts only; focused development checks, not Foundation CI or acceptance)

Runner (worktree-specific Composer autoload, symlinked locked vendor), from the worktree root:

```sh
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionFreeGrants
vendor/bin/pint --test app/Domain/Grants/ProductionFree config/production-free-grants.php \
  database/migrations/2026_10_07_256000_production_free_grants.php scripts/render-production-free-grant.php \
  tests/Feature/ProductionFreeGrants tests/Support/ProductionFreeGrantFixtures.php tests/Support/FakeProductionFreeSources.php
```

Native runs export `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=<private instance> DB_DATABASE=vaseyaudio_free256
DB_USERNAME=root DB_PASSWORD= DB_URL=` against a disposable lane-private `mysqld` 8.4.11 (`--initialize-insecure`,
scratchpad datadir). Each native case runs `migrate:fresh` over all migrations (about 2.5 minutes per case).

| Selection | Source | Driver | Result |
| --- | --- | --- | --- |
| `tests/Feature/ProductionFreeGrants` (8 files) | `f3381c10` | SQLite | **91 tests, 547 assertions, 0 failures, 0 errors, 2 skipped** (the two `test_native_*` cases) |
| `tests/Feature/ProductionFreeGrants` (9 files, final) | `16b420e7` | SQLite | **93 tests, 559 assertions, 0 failures, 0 errors, 2 skipped** (native-only) (`sqlite-production-free-grants-final.txt`) |
| Per-file breakdown | `20eb7f5c` | SQLite | `sqlite-production-free-grants.txt`: Approval 10, Assent 8, Delivery 8, FrozenBytes 3, Journey 1, Library 3, Rendering 8, Schema 50 (2 skipped) |
| Schema: 11 selected cases (install, earlier hole, data-bearing, foreign object, external dependent, down, update/delete, relabelled copy, review/availability guards, 2 native-only) | `6b68e7fe`..`20eb7f5c` test source | MySQL 8.4.11 | **native run 1: 5 passed, 6 errors** (`native-run1-mysql.txt`). Root cause: the external-dependent case left `foreign_free_view`; MySQL `migrate:fresh` keeps views, so every later setUp correctly refused `external_dependent`. Fixed in `f3381c10` (drop in `finally`). |
| `ProductionFreeGrantJourneyTest` | `20eb7f5c` | MySQL 8.4.11 | **1 test, 20 assertions, OK** (operator, independent review, open, typed review, assent, isolated render, library, all four artifacts delivered and hash-checked, byte-identical recovery) |
| Schema: the 7 cases affected by run 1 (external dependent, down, update/delete, relabelled copy, review/availability guards, native CHECK-symbol collision, native TEMPORARY shadow) | `f3381c10` | MySQL 8.4.11 | **native run 2: 7 tests, 102 assertions, OK, 0 skipped** (`native-run2-mysql.txt`) |
| Regression: 79 migration/schema/recovery/admission files, `ProductionMembership`, `ProductionMemberOriginals`, old `FreeGrant*` | `d380f8d4` | SQLite | 75 files OK; 4 files fail **identically on base `fad3ab44` without any 256 file** (`sqlite-regression-base-comparison.txt`): ProductionTrackCapabilitiesMigrationOwnership (6 errors), RightsEvidenceGuardMigration (9 errors), ServiceProjectSchema (1 failure), DiscoveryEpochMigration (2 failures) |

Native totals: 12 distinct schema cases plus the journey passed on MySQL 8.4.11 across runs 1 and 2 (run 1's 6 errors
are retained, explained and superseded by run 2 at `f3381c10`).

Another lane ran `pkill -f vendor/phpunit/phpunit/phpunit` around 03:20-03:30 UTC; two early runs of this lane
ended "Terminated" in that window and are not cited. Every cited run used a scratchpad runner whose command line
does not match that pattern and completed with a PHPUnit summary.

Pint: `--test` passes on every owned PHP file. Renderer implementation files are hash-bound by
`profile-assets.json` and were not reformatted after hashing.

Regression note: while checking DiscoveryEpochMigration against base, the 256 migration file was moved out of this
worktree for about 15 seconds during the per-file loop; the four files that could have overlapped that window
(ProductionCheckoutMigration, ProductionCheckoutWriteAdmissionAll, MemberOriginalPreparation,
MemberOriginalSchemaPreparation) were re-run afterwards with the file in place: all OK (counts as in the table file).

## Untested

- Lane-authored native tests for Approval, Assent, Rendering, Library, Delivery and FrozenBytes, native prefix
  recovery, temporary-parent shadow and parent-column drift. The independent reviewer has since run the whole
  directory natively (93/522, 0 failures, 39 SQLite-only skips) and probed all three natively (see the review
  section below); the lane's own suite still exercises them on SQLite only.
- Concurrency in the lane suite. The reviewer's two-process native probe found 0 cap violations in 10 rounds,
  one render claim and one redemption per race (`independent-review/review-evidence/native-race-log.json`); no
  lane regression test exercises these races yet.
- Callback/commit attacks (committing/postcommit/response/first-byte withdrawal, raw reopen, custom PDO statement
  class mid-command beyond the constructor check, config withdrawal between the two delivery transactions).
- Large assets (>16 MiB) and the 1 GiB snapshot limit; disk-full during spool or original storage.
- HTTP: nothing is mounted, so no request, session-marker, CSRF or response-header behaviour is tested.

## Independent review (`1860e00d`)

`independent-review/DECISION.md`: **APPROVE WITH CONDITIONS for a development merge
only.** The batch is default-off, unregistered and unmounted, and adds files only. The
reviewer ran the directory natively for the first time (93 tests, 522 assertions,
0 failures, 39 SQLite-only skips by design; six shards exit 0), a two-process native
concurrency probe (0 cap violations in 10 rounds; one render claim, one redemption per
race), write-once, path-traversal, approval-forgery, revocation and default-off probes,
and mutations S1–S6 (all caught) and U1–U3.

Conditions before activation or mounting (none blocks this development merge):

1. **F-1 Medium:** every read compares the sealed profile with the current renderer
   profile, so a legitimate renderer revision makes existing grants refuse with
   `profile_changed`. Check stored profiles against their own sealed hash; require the
   current renderer only for render and recover; keep a registry of past profiles. Fix
   before any operative definition exists.
2. **F-2 Medium:** the delivery spool has no slot lease, free-space reserve, disk budget
   or read-back. Reuse the `PrepareTestDeliveryStream` pattern before the delivery
   endpoint is mounted.
3. Owner-doc steps 3 (commit observer) and 5 (committed-frame delivery validation) and
   the ff0 capsule before mounting.
4. **F-3 Low:** require MFA enrollment for 256 staff in every environment before staff
   actions are mounted.
5. Root's sources adapter must refuse withdrawn or quarantined assets in `open()` and
   serialize exclusive scope/inventory (**F-7**).
6. Hardening before production provenance: **F-4** (session temporary-table shadow;
   re-check the parent floor in the step 3 commit observer), **F-5** (dangling symlink
   creates an empty file outside the root; write to a random name then `link()`),
   **F-6** (renderer child `open_basedir` is the project root), **F-8** (`APP_KEY`
   rotation reads every seal as tampered).

The reviewer's I-10 (`CapabilityMigrationOwnership` scanning triggers across every
schema) was fixed on `main` by PR #50 (`df8126a2`).

## Hardening after review (F-1, F-2, F-3, F-5, F-8)

Fixed on this branch with regressions red before and green after; details, judgement calls and
evidence in `hardening/README.md`. SQLite directory 113 / 747, 2 native-only skips; Pint passed.
Still open: F-4 (session temporary-table shadow, for the step 3 commit observer), F-6 (renderer
child `open_basedir`), F-7 (root's sources adapter must refuse withdrawn assets). The F-8 fix covers
Free256 seals only: main-resident identity classes (`ProductionCustomerAccess`, `IdentityPolicy`,
`CustomerAccess`) still derive from the current `app.key` alone, so customer-facing commands refuse
after a key rotation until the identity owner adds previous-key handling.
