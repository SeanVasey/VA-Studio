# DEF-OPS deferred operations and CI findings batch, 2026-10-24

Lane DEF-OPS (branch `harness/deferred-ops-20261024`, base `707649a6`). Scope: the deferred findings that
`docs/handoff/2026-10-24/COMPLETION-PLAN.md` groups as DEF-OPS, from `docs/handoff/2026-10-07/codex-deferred-20261011.md`.
Ops scripts, CI scripts, command code and documentation only. No schema migration, no live host, DNS, credential or payment,
and no production hold is lifted. Files owned by in-flight lanes (C4e routes and membership, A5 refund and operations, MIG
migration domain) are not touched.

## Work package (as written before the code)

Each finding is read in full in the ledger and in its review record before it is changed. A finding that is invalid, already
fixed, or not worth its code is recorded as such with the reason instead of being built.

| Finding | Planned disposition |
| --- | --- |
| O1 P3s (ledger entry of 2026-10-18; closed by O2 except O2 P3-1) | Verify the O2 closures on main; fix O2 P3-1 (shipped record without a size) in `ops/staging/backup.sh` with a red/green test |
| O3 R6, N1 | R6: production hold reads its config keys from `CapabilityBoundary`, docblock corrected, consumer pinned by a test. N1: rewrap the two long lines |
| O4 P3-1, P3-3, P3-4, P3-5 | P3-1: test the configured `connection` and `lock_connection`. P3-3: detect non-default cache stores that reopen the backlog, or document. P3-4: NTP note. P3-5: refresh the stale scheduler descriptions (mind `build-kit.py --check`) |
| K3 P3-1, P3-2 | Interactive-console wording or `stream_isatty` across the three commands; refusal tests for policy version, challenge completed between `message()` and `relay()`, and the `lstat`/inode checks |
| PR #149 P2 r4305529286 | Bound `vasey:test-identity-links list` to the newest N captures by mtime, report how many older were not examined, batch lookups |
| K4 P3-1, P3-2, P3-3 | Read-only transaction and `REPEATABLE READ`, explicit `CheckoutIntent` columns, `OUTPUT_RAW` and `OutputFormatter::escape()` in `vasey:test-commerce-evidence` |
| C1 P2 r4305244895, r4305244899, P3-A to P3-D | Resume refusals when staff edited an interrupted draft (rights evidence, media hash), generated-media brand check, wording, docblock |
| #141 Dependabot P2 r4305283401 | Exclude the two testing-library packages from `npm-minor-patch` |
| M2 R3 (R4 note) | Widen the Composer exclusion to `tecnickcom/tc-lib-*` |
| `scripts/ci/test-related-browser-stage.py` | Find and fix the root cause of the failures on main without skipping or weakening the safeguard |

## Results

Tested source: code at `faceb5f8b74ed2520c6cd52ab9719f07c64151cc` plus the one-line test edit described under Native MySQL, on `harness/deferred-ops-20261024` (`origin/main` at `707649a6`, which was still the newest main when this was finished, so the merge was a no-op, plus six commits). The commit that adds this section changes only documentation. Nothing was pushed, no PR opened, no GitHub comment
made and no workflow dispatched. No host, DNS, credential, payment or production hold was touched or lifted.

| Finding | Disposition | Where | Evidence |
| --- | --- | --- | --- |
| O1 P3-2, P3-3, P3-6, P3-B, P3-D (and the PR #148 bot findings) | Already closed by O2 at `c94770ad`; verified present on main (flock around the ship cleanup, 7-character decoy and symlink tests, template wording, scrub test, capability-boundary note) | `ops/staging/backup.sh`, `tests/ops/test_staging_backup_offhost.py`, `scripts/ops/capability-boundary.php` | `tests/ops/test_staging_backup_offhost.py` 74 tests OK |
| O2 P3-1 (the O1 remnant): record without a size plus a failing size listing passed "already shipped"; a failed `stat` wrote an empty size | **Fixed with test.** Both sizes must be digits and equal; the size is read before the record is written and a failed write leaves no record. Red against the old script (the "already shipped" line appeared) | `ops/staging/backup.sh` | two new tests; the unit file 74 OK |
| O3 R6 | **Fixed with test.** The production validator names its four environment keys and reads the config keys from `CapabilityBoundary`; a missing or linked boundary file, or a key the boundary lost, is `runtime.unavailable`. The docblock (three validators now) and the consumer shape are pinned | `ops/production/validate-runtime.php`, `scripts/ops/capability-boundary.php`, `tests/ops/test_production_runtime_validator.py` | 28 tests OK |
| O3 N1 | **Fixed.** The two long lines are rewrapped (and rewritten for the new design) | `docs/ops/production-activation-packet.md`, `ops/production/README.md` | read |
| O4 P3-1 | **Fixed with test.** Two separate SQLite connections stand in for `connection` and `lock_connection`; the default connection's expired rows survive. Red against `DB::connection()` (3 failures) | `tests/Feature/PruneExpiredCacheRowsTest.php` | 26 tests OK on SQLite |
| O4 P3-3 | **Fixed with test (detect by pruning).** The command prunes every distinct database store in use: the default, `filament.cache_store`, `cache.limiter` and the database members of a `failover` store (a cycle ends), each connection and table once. A store of another type is left to it. The old "failover default is a no-op" test case was a documented limitation and is replaced by a pruning case | `app/Console/Commands/PruneExpiredCacheRows.php` | same file |
| O4 P3-4 | **Documented.** NTP is a host requirement when more than one host shares the cache | `ops/production/README.md`, `ops/staging/README.md` | read |
| O4 P3-5 | **Fixed.** Workers conf header and the monitoring guide name both scheduled commands. The semantic-delta snapshot was regenerated and the diff reviewed (only the header line and its hunk offset moved); `build-kit.py --check` is `ok, 13 files` | `ops/staging/workers/vasey-staging-workers.conf`, `docs/ops/monitoring-and-host-baseline.md`, `docs/verification/production-ops-kit-20261017/semantic-delta.diff` | `tests/ops/test_production_kit.py` 50 OK |
| K3 P3-1 (and C1 P3-B) | **Fixed by wording.** A `stream_isatty(STDIN)` check cannot be tested without a production seam (the tests drive the prompt through a mocked question) and is not the gate: the confirmed password and staff authority are. The three commands now say "an interactive session (not --no-interaction) ... at a hidden prompt" and their docblocks say a piped password is accepted | `TestIdentityLinks.php`, `ManageRightsScopes.php`, `StagingSyntheticCatalog.php`, `docs/ops/staging-runbook.md` | tests updated |
| K3 P3-2 | **Fixed with tests, one part not built.** Non-current policy version (a second pending row is inserted on a retired version beside a current-version twin, so only the version differs) and a challenge completed or mismatched between `message()` and `relay()` are refused without an audit row; both red when the check is removed. The `fstat` inode comparison only differs in a race between two syscalls, so it is not testable without a seam in a final class; the `lstat` mode check stays covered by the linked, misnamed and directory cases | `tests/Feature/TestIdentityLinkRelayCommandTest.php` | 21 tests OK |
| PR #149 P2 r4305529286 | **Fixed with tests.** `list` examines the newest `--limit` (default 50, max 200) captures by file modification time, prints and audits how many older ones were not examined, and runs one challenge query and one staff-address query for the whole listing (counted at limits 2, 6 and 50). `print` still finds any capture by id. Pruning old captures in the identity domain is not built: no retention policy exists and it is a domain change | `TestIdentityLinkRelay.php`, `TestIdentityLinks.php` | same file |
| K4 P3-1 | **Fixed with test.** On MySQL: `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`, `START TRANSACTION READ ONLY`, the reads, `ROLLBACK`. The guard also refuses `FOR UPDATE`, `FOR SHARE`, `LOCK IN SHARE MODE` and `INTO`. It is registered once per connection and holds only its switch, because Laravel cannot remove one callback; the test proves a second and third run add none | `app/Console/Commands/TestCommerceEvidence.php` | 17 tests OK on SQLite |
| K4 P3-2 | **Fixed with test.** `['id', 'public_id', 'order_id', 'provider_expires_at']` for all four reference forms | same | same |
| K4 P3-3 | **Fixed with tests.** JSON through `OUTPUT_RAW`, text values through `OutputFormatter::escape()`; both red when reverted | same | same |
| C1 P2 r4305244895 | **Fixed with test.** Both rights fields must equal `SyntheticRehearsalCatalog::rights()`; red against the old prefix check (4 failures) | `SyntheticRehearsalBuilder.php` | `StagingSyntheticCatalogTest` 51 OK |
| C1 P2 r4305244899 | **Fixed with tests.** An interrupted source and a ready preview or artwork are accepted only when the source SHA-256 equals the deterministic generated file's; otherwise "... media that differs from the synthetic definition; it was left unchanged." Red when the check is disabled. Limitation recorded: the master is bit-exact, the artwork is a GD PNG, so a changed GD or zlib build could refuse an interrupted draft (reset is the remedy) | same, `docs/ops/staging-runbook.md` | same |
| C1 P3-A | **Fixed with test.** No generated source or derivative equals any file under `public/` (and `assets/` once it exists; it is absent today) | test file | same |
| C1 P3-C | **Fixed with tests.** The generic failure says nothing was written when it occurs before the write phase; the resume wording stays for the write phase | `StagingSyntheticCatalog.php` | same |
| C1 P3-D | **Fixed.** The test docblock names the two fixture inputs (scanner verdict and seller tag) | test file | same |
| #141 Dependabot P2 r4305283401 | **Already fixed on main** (both testing-library packages are in `exclude-patterns`); verified | `.github/dependabot.yml` | read |
| M2 R3 | **Fixed.** `exclude-patterns: ["tecnickcom/tc-lib-*"]`, which covers the transitive renderers that the `profile-assets.json` files pin | `.github/dependabot.yml` | YAML parsed |
| M2 R4 | **Not built, by reason:** no current code uses a frozen-time funnel or throttle; it stays a note | ledger | n/a |
| `scripts/ci/test-related-browser-stage.py` | **Root cause identified; the safeguard is unchanged and nothing is skipped.** See below | `scripts/ci/test-related-browser-stage.py` | see below |

### The browser-stage failures

Reproduced on `707649a6`: 9 failures without `npm ci`, then 4 with `npm ci` (the brief's number), then 2 after `npm run build`.

1. **`node_modules` missing:** the WebKit backport test and the config tests need Playwright (`npm ci`).
2. **`public/build` missing (the 4 failures):** the bootstrap runs `vasey:doctor --json`, whose `frontend_build` check fails without a Vite
   manifest, so the bootstrap stops in phase `installation_diagnostics` (not `customer_provisioning`: the phase labels in the
   bootstrap are set in order and this one is last before the doctor call), and `tests/browser/run.mjs` refuses to start. The hosted
   stage runs `npm run build` before this script. The PHP unit suite needs the opposite (a manifest makes seven Inertia HTTP tests
   return 409), so a developer container cannot satisfy both at once.
3. **ClamAV signatures (the last 2, the two ordinary-stage cases):** `/var/lib/clamav` is empty here and `freshclam` is refused by the
   proxy (HTTP 403), so the genuine scanner reports `scanner_signatures_stale` in phase `customer_purchase`. The ordinary fixtures
   require the genuine scanner and fresh signatures by design (`docs/verification/customer-native-scanner-fixtures.md`).

None of these is a product or test defect, so the fix is diagnostic: a failure message used to be only "The native isolated PHP
bootstrap failed." The test now appends the bootstrap's own fixed `{phase, exception_class}` line, but only when stderr ends with
exactly that line and the phase is one of the sixteen known phases (anything else is dropped, so no private output can reach
a failure message), plus every unmet prerequisite (Playwright, manifest, scanner signatures older than
`media.max_signature_age_seconds`). Two new tests cover the helpers. With the prerequisites present (`npm ci`, `npm run build`),
12 of the 14 cases (10 of the 12 original) pass here, the related-bootstrap and runner cases among them; the two ordinary cases need signatures that
only the hosted runner can load. Local result is stated, not inferred.

## Commands and results

Environment: PHP 8.4.26, PHPUnit 12.5.34, Laravel 13.38, Node 24.22.0 (nvm), `public/build` absent for PHP tests, SQLite `:memory:`
with the direct-PHPUnit command from `CLAUDE.md`'s working facts.

| Command | Result |
| --- | --- |
| `PruneExpiredCacheRowsTest` | 26 tests, 134 assertions, OK |
| `TestIdentityLinkRelayCommandTest` | 21 tests, 1427 assertions, OK |
| `TestCommerceEvidenceCommandTest` | 17 tests, 227 assertions, OK |
| `StagingSyntheticCatalogTest` and `PruneExpiredCacheRowsTest` together | 51 tests, 1045 assertions, OK |
| every `scripts/ci/test-*.py` except the browser stage | rc 0 (ci-scope 27, database-receipts 61, focused-tests 53, gitlab-database-receipts 32, gitlab-setup 12, gitlab-writer-feedback 7, php-test-runtime 5, workflow-cadence 14); `test-phpunit-shards.py` (52) failed once, see below, rc 0 after the selection change |
| every `tests/ops/*.py` | rc 0, 20 files; backup 74, validator 28, kit 50, capability profile 17, monitoring 44 |
| `python3 ops/production/build-kit.py --check` | `build-kit: ok, 13 files` |
| `composer validate --strict` | `./composer.json is valid` |
| `python3 scripts/ci/test-related-browser-stage.py FailureDiagnosisHelpers` | 2 tests OK |
| `python3 scripts/ci/test-related-browser-stage.py` with `npm ci` and `npm run build` (then `public/build` removed) | 14 tests, 12 pass; the 2 ordinary-stage cases fail on stale ClamAV signatures, and now say so (phase and unmet prerequisites in the message) |
| Pint on each PHP file changed | passed (repository-wide Pint is not part of the gate and fails on 243 older files) |

Census: `test-phpunit-shards.py` failed with "driver-branching test file neither selected nor residual:
tests/Feature/TestCommerceEvidenceCommandTest.php", because the K4 test now branches on the driver. It joins the reviewed native
selection (`include_files`) so the MySQL framing runs natively in CI. Regenerated with
`python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`:
"Proved 2516 selected MySQL-native tests in 240 files (of 9616 discovered tests in 700 files)" (was 2499 in 239 of 9595; the 21
new cases are in four existing files, one of them selected). The count comments in `.github/workflows/final-verification.yml` and
`.gitlab-ci.yml` were updated and the generated `phpunit-ci-mysql-*` files deleted. The SQLite skip census is append-only and
untouched: no test was added that skips. The new MySQL selection entry has no timing weight yet (the fallback applies, as for 77 other files).

### Native MySQL 8.4.11

A private `mysqld` (own datadir, port 3391, socket under `/tmp/defops-my`; stopped afterwards, no other lane's server touched), database
`vaseyaudio_defops` with `VA_LOCAL_DISPOSABLE_MYSQL`. Full-file native runs of the `FinalizationDatabaseMigrations` classes take about
5 minutes per test on the shared CPU, so only the cases that touch MySQL behaviour were run:

| Selection | Result |
| --- | --- |
| `PruneExpiredCacheRowsTest` (whole file; includes the separate-connection cases) | 26 tests, 134 assertions, OK |
| `TestCommerceEvidenceCommandTest` (reference forms with the `SET TRANSACTION` / `START TRANSACTION READ ONLY` / `ROLLBACK` framing, guard, column list, markup, write attempt) | 5 tests, 183 assertions, OK |
| `TestIdentityLinkRelayCommandTest` (newest-by-mtime limit, one-query-each, limit validation, policy version, completed-between) | 5 tests, 83 assertions, OK |

Not run natively: the rest of those files, and `StagingSyntheticCatalogTest` (SQLite only; 51 tests OK). The first native attempt was
invalid (a second run shared the database after I stopped a loop); it was discarded and rerun cleanly. After the final edit to
`TestCommerceEvidenceCommandTest` (the write-attempt case now uses the framing-aware read check) the whole file passed again on
SQLite: 17 tests, 230 assertions.

Incident to disclose: while stopping my own background loop I ran `pkill -f vendor/phpunit/phpunit/phpunit`, a pattern that also matches
other lanes' direct-PHPUnit processes. It may have sent SIGTERM to PHPUnit runs of other worktrees at about 18:13 UTC. Nothing else of
theirs (files, servers) was touched; affected lanes should rerun any test run that ended without a result.


## Needs an independent review

Security-relevant or sensitive changes, in priority order:

1. `ops/production/validate-runtime.php`: the production hold now takes its config keys from the shared boundary file. It still refuses
   each flag unless the literal false, and fails closed when the boundary is missing, linked, or lacks a key. Confirm no path lets a
   flag through that the old constant would have refused, and that the production release always ships `scripts/ops/capability-boundary.php`
   (the staging validator already requires it).
2. `app/Console/Commands/PruneExpiredCacheRows.php`: it deletes expired rows from more stores than before (limiter and Filament stores,
   failover members). Deletion safety is unchanged (`expiration <= cutoff`, cutoff read once, delete re-checks it). Confirm a TOTP
   replay record or a held lock in a non-default database store cannot be expired early by this reading of the store list.
3. `app/Domain/Customers/TestIdentityLinks/TestIdentityLinkRelay.php`: the listing's verification was split into a file-level and a
   challenge-level phase with batched lookups. Every check of the old `verified()` is kept except one: the staff-address exclusion now
   matches in PHP with ASCII `strtolower` after `LOWER(email) IN (...)` in chunks of 500, so on MySQL it is narrower than the old
   collation-aware `LOWER(email) = ?` (accented or trailing-space staff addresses are no longer withheld; independent review P3-1,
   deferred).
4. `app/Domain/Catalog/SyntheticRehearsal/SyntheticRehearsalBuilder.php` and `ops/staging/backup.sh` (off-host shipped-record logic).
5. `app/Console/Commands/TestCommerceEvidence.php`: the read-only transaction framing on MySQL.

## Not done, and why

- Pruning old identity captures in the identity domain (PR #149): no retention policy; a domain change.
- A separate test of the `fstat` inode comparison (K3 P3-2): race-only, needs a production seam.
- M2 R4: a note for any future frozen-time test; no code uses one.
- Browser-stage ordinary cases and Chromium/WebKit journeys: need the hosted runner's genuine ClamAV signatures.

## Independent review

[Decision record](independent-review-faceb5f8-DECISION.md): **APPROVE WITH CONDITIONS** at `faceb5f8`, no P0 to P2; all seven focus areas
hold their safety property (production hold fails closed for every damaged-boundary case; the prune deletes only `expiration <= cutoff`
in every store; the evidence command is read-only on native MySQL). The condition, correcting the identity-relay sentence above, is
applied here. Deferred to the per-lane batch: P3-1 (staff-address exclusion narrower on MySQL; let the database match recipients and add
a mixed-case test), P3-2 (SQL comments get a locking clause past the evidence guard; MySQL still refuses `FOR UPDATE` in READ ONLY but
allows `FOR SHARE`) and P3-3 (the new `backup.sh` digit and `stat` guards are not pinned by tests).
