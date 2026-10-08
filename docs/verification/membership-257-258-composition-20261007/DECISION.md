# Independent review decision: membership 257/258 preparation composition

- Reviewer: Claude Code independent reviewer/integrator (harness session `session_01VqqWczBqWjDYScoPVae4UR`).
- Branch: `harness/membership-257-258` (local only, not pushed).
- Integrated base: `d20d4394edcc84bf3c5a193f598d9b044b0753aa`.
- Exact reviewed composed commit: `695d041fc098acb7cf10508d1ac819288268b9f7`
  ("chore(memberships): compose frozen 257/258 preparation onto integrated identity").
- Final branch commit for these decisions: `9759b937` plus the docs-only commit that adds this file.
  `9759b937` changes only `tests/Feature/ProductionMembership/MembershipSchemaPreparationTest.php`
  (composition drift D1, test-only); every product/runtime byte is identical to `695d041f`.
  Other commits after `695d041f` change only `docs/verification/membership-257-258-composition-20261007/`.
- Sources: 257 runtime `3cd105667a76bb15443a9fd0119065effda8bb34` (frozen head `7fa3c62e`);
  258 runtime `c4cd6d9355257a39d2740b0a4fe55c3e376ef5b3` (frozen head `fb573a41`).
  The per-file origin and resolution are in `source-map.json`.

## Decisions

| Component | Decision | Scope |
| --- | --- | --- |
| Composition (41 owned paths onto `d20d4394`, plus test-only drift fix `9759b937`) | **APPROVED** | Byte-exact owned paths only. Two shared-file resolutions: `MembershipRows.php` from 3cd and `MemberGrantIntent.php` from c4 (approved FAMILY `production-member-origin-v1`). No identity/checkout/support/discovery path was taken from either branch. |
| 257 Rows3cd statement guard (`MembershipRows::nativeStatements`) | **APPROVED, narrow scope** | Finite exact PDO class admission (`PDO`, `Pdo\Sqlite`, `Pdo\Mysql`) and default `[PDOStatement]` statement factory, checked before the constructor's first SQL and at the start of every `context()`. This closes the statement-factory callback primitive it was written for, on both drivers. |
| 257 Rows3cd as a general "no application callback during captured reader SQL" closure | **NOT APPROVED (SQLite)**; finding F1 | Genuine red on SQLite with an application-defined SQL function (below). MySQL has no equivalent PDO-level SQL callback, so the native/production path is not affected by F1. |
| 258 origin guard: retained redemption → paid period → exact account/user/original identity/invoice + current active pair | **APPROVED** | Structural guard only. Author probes plus five independent variants refuse, and the exact graph is admitted, on SQLite and native MySQL 8.4.11. |
| 258 activation ↔ 257 consume coupling | **NOT PRESENT**; finding F2 (prerequisite for operative work, not a preparation defect) | Activation and consume rows are structurally independent. Characterized, not approved. |
| Preparation 257/258 overall | **APPROVED as default-off, unregistered preparation only**, subject to F1 being closed before any operative consumer | |

### What this decision does not approve

It does not approve any operative award, reservation, consumption or member
grant writer, any Billing259 or other provider code, any invoice or settlement
semantics, any plan price, currency, allowance, credit cost, rollover,
late-invoice, cancellation, refund, dunning, grandfathering, honor or retention
fact, any member terms or profile, any registration, route, UI or config
binding, or production activation. Synthetic fixture rows are not grants.
Structural SQL guards do not authenticate invoices, buyers, ciphertext, seals
or file bytes.

## Findings

### F1: SQLite application function runs inside captured reader metadata SQL (genuine red)

Probe: `adversarial/MembershipRowsSqliteFunctionCallbackReviewTest.php`.
Inside one outer `DB::transaction`, after constructing `MembershipRows`, the probe
registers `Pdo\Sqlite::createFunction('lower', …)` on the captured handle. The
callback withdraws `production-memberships.enabled`. Then it calls
`$rows->assertCurrent()`.

Result on composed `695d041f` (SQLite): **1 failure / 2 assertions / 0 errors**.
Observation (`adversarial/rows-sqlite-function-observation.json`):
`refused: false`, `calls: 30912`, `policy_enabled_after: false`.
`MembershipSchema::up(false)` runs `... WHERE lower(name) = lower(?)` against
`sqlite_master`, and SQLite lets application functions override built-ins. The
reader admits the changed handle and the policy is withdrawn mid-read. This is
the same failure class 3cd was written to close (policy withdrawn by a callback
during reader SQL while the reader succeeds), reached through a sibling
primitive the finite class/statement check does not cover.

Severity: production uses MySQL, where PDO exposes no SQL-level PHP callback, so
F1 does not reach the native path. But `MembershipPolicy` admits
`verified_production` on any driver, and `MembershipRows` admits SQLite. Required
before any operative consumer (IMPLEMENTATION-PLAN 0.1): refuse SQLite for
`verified_production`, and on SQLite check `pragma_function_list` (non-builtin)
and `collation_list` before any SQL, keeping this probe as the regression.

The same `lower()` pattern likely exists in other captured readers (identity,
checkout, `MemberGrantSchema`). They were not tested here and are outside this
lane; they are reported for root.

### F2: activation and consume are not structurally coupled (characterization)

Probe: `adversarial/MemberOriginalRedemptionJoinReviewTest.php`, final section.
With zero rows in `production_membership_credit_events` (no award, reserve or
consume), an origin, its two artifacts and an activation were all admitted on
SQLite and native MySQL. The activation guard checks only
`length(reservation_event_hash) = 64`, and the 257 consume guard checks only the
shape of `grant_origin_id`/`grant_receipt_hash`. The 258 preparation document
states that structural fixtures are not grants. The author's own
`test_pending_original_requires_bounded_contiguous_artifacts_before_structural_activation`
already inserts an activation without credit events, so this is consistent with
the declared scope. It must be closed before activation becomes operative
(IMPLEMENTATION-PLAN 0.2).

### O1: default fetch mode is not pinned (observation, non-blocking)

`PDO::ATTR_DEFAULT_FETCH_MODE` accepts `FETCH_CLASS|FETCH_CLASSTYPE` on PHP
8.4.26. With that mode, a default-mode `fetch()` calls autoloaders with a column
value as the class name (verified in isolation). The only default-mode fetch in
the membership reader path is `MembershipSchema` line 334
(`SHOW CREATE TABLE … ->fetchAll()`). It is reached only when a trigger-named
table exists, and that path then refuses. Recommendation: pin the default fetch
mode in the same successor guard as F1.

### O2: shared native server cross-schema interference (environment, not product)

`CapabilityMigrationOwnership` (base migrations 236/238/239) scans
`information_schema.TRIGGERS` server-wide. On the shared 3306 server, other
sessions' partially migrated schemas (`vaseyaudio_features253`,
`vaseyaudio_paid252`, `vaseyaudio_p2`, each holding `production_track_*`
triggers) made 6 of 9 native cases error during `migrate:fresh` at migration
238 with `Unexpected production capability external or additional table guard`.
Those raw results are retained unchanged as `native-257-attempt1-env-interference.*`,
`native-258-attempt1-env-interference.*` and `processlist-interference-observation.txt`.
They are setup errors, not membership assertions. The base check is deliberately
conservative, but it makes concurrent native runs of this repository on one
server block each other. It is reported for root, not changed here.

## Commands and results

All PHPUnit runs use the worktree's own Composer autoload:
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <selection> --log-junit <file>`
with `APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M=` (synthetic).
PHP 8.4.26, PHPUnit 12.5.34.

### SQLite (in-memory, composed commit)

| Selection | Recorded | Executed | Assertions | Failures | Errors | Skipped | Author expectation |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `tests/Feature/ProductionMembership` (`sqlite-ProductionMembership.*`) | 24 | 21 | 58 | 0 | 0 | 3 (named native-only) | 24/21/58/3: matches |
| `tests/Feature/ProductionMemberOriginals tests/Feature/ProductionMembership/MembershipPreparationTest.php` (`sqlite-258-author-selection.*`) | 22 | 21 | 52 | 0 | 0 | 1 (named native-only) | 22/21/52/1: matches |
| `tests/Feature/ProductionMemberOriginals` alone (`sqlite-ProductionMemberOriginals.*`) | 14 | 13 | 36 | 0 | 0 | 1 | supplementary |
| Review probe F1 (`adversarial/rows-sqlite-function.*`) | 1 | 1 | 2 | **1** | 0 | 0 | genuine red, finding F1 |
| Review probe 258 join (`adversarial/origin-join-sqlite.*`) | 1 | 1 | 8 | 0 | 0 | 0 | |

SQLite showed no composition drift. Native MySQL showed one test-only drift (D1, below), which is fixed in `9759b937`. No product source was edited after composition.

The first 258-join probe run hit a harness error in the probe itself
(`customer_accounts` updates are refused by the identity retention guard), with
1 error and 0 assertions. It is retained as `adversarial/origin-join-sqlite-initial-harness-error.*`
with the original probe source `adversarial/initial-MemberOriginalRedemptionJoinReviewTest.php.txt`.
The corrected probe uses the user-side `email_verified_at` predicate instead.

### Native

Environment: MySQL 8.4.11 Community. The shared 3306 server was unusable for
this run because of cross-schema interference (O2). All native results below
come from a **private disposable `mysqld` 8.4.11 instance** started for this
review on `127.0.0.1:3317`, with its datadir in the session scratchpad, the same
binary, and a `sql_mode`, collation, `log_bin=1` and InnoDB defaults matching
the shared server. Database `vaseyaudio_member257`, user root (synthetic
password), `APP_ENV=testing`, `CACHE_STORE=array`, `SESSION_DRIVER=array`,
`QUEUE_CONNECTION=sync`. Each case is a separate PHPUnit process with an exact
`--filter` (`native-private-8.4.11/ledger-*.json`, `drift-fix/ledger-native.json`).

| Case | Commit | Result (tests/assertions/failures/errors/skips) | Author expectation |
| --- | --- | --- | --- |
| 257 `MembershipNativeStatementClosureTest` | 695d041f | 1/3/0/0/0 | 1/3: matches |
| 257 `test_native_foreign_check_reserves_actual_global_symbol_before_any_owned_ddl` | 695d041f | **1/0/0/1/0**: `3730 Cannot drop table 'production_membership_redemptions' referenced by … 'production_member_origins_f0'` | composition drift D1 |
| same case | 9759b937 | 1/3/0/0/0 | |
| 257 `test_native_foreign_table_local_unique_does_not_pollute_owned_fk_dictionary` | 695d041f | 1/2/0/0/0 | |
| 257 `test_native_changed_enum_check_is_not_normalized_into_owned_provenance` | 695d041f | 1/2/0/0/0 | |
| 257 `test_data_bearing_partial_guard_prefix_refuses_and_retains_original_plan` (not native-only; run to confirm D1) | 695d041f | **1/0/0/1/0**: same 3730 | composition drift D1 |
| same case | 9759b937 | 1/3/0/0/0 | |
| 258 five-case author regex (owned retry, pending original, global CHECK, other account, other invoice) | 695d041f | 5/17/0/0/0 (3+7+3+2+2) | 5/17: matches |
| Review probe 258 join (`adversarial/MemberOriginalRedemptionJoinReviewTest.php`) | 695d041f | 1/8/0/0/0, observation `origin-join-observation-mysql.json` | |
| Frozen canary `MembershipForeignSchemaCanaryTest.php` (SHA256 `72648803…cdaca8a`, unchanged before and after) | 695d041f | 1/7/0/0/0 | independent 1/7: matches |

Native-only membership cases on the final commit `9759b937`: 257 four cases
with 10 assertions (statement 3, foreign CHECK 3, foreign UNIQUE 2, changed
enum 2) and 258 one native-only case (global CHECK, 3 assertions), all passing.
The 257 cases unaffected by the test-only change carry from `695d041f`. Full
native directories were not run (cost policy); only the selections above.

#### Foreign-schema canary lifecycle (`canary-lifecycle.txt`)

The canary hard-codes database `vaseyaudio_service_review` and needs
`MEMBERSHIP_FOREIGN_SCHEMA`. On the private instance the coordinator created
`vaseyaudio_service_review` and `vaseyaudio_member257_foreign` with a
same-named `users(id BIGINT UNSIGNED PRIMARY KEY)` table (14:11:18Z). It ran the
unchanged canary with `DB_DATABASE=vaseyaudio_service_review`
`MEMBERSHIP_FOREIGN_SCHEMA=vaseyaudio_member257_foreign`, which wrote
`foreign-schema-snapshot.json` and restored its own local FK in `finally`. A
shell `trap` then dropped both databases (14:13:15Z). Post-cleanup checks: zero
matching schemata, zero `KEY_COLUMN_USAGE` rows referencing the foreign schema,
zero `mysql.db` grant rows. The root account was used, so no user or GRANT was
created and nothing needed revoking. Nothing was created on the shared 3306
server for the canary.

The private instance was shut down and its datadir removed after the runs
(`private-instance-lifecycle.txt`). The review's own schema on the shared 3306
server was left empty, and no other schema there was touched.

#### Composition drift D1 (fixed, test-only)

In the composed tree, schema 258's empty `production_member_origins` and
`production_member_activations` hold FKs to 257 `production_membership_redemptions`
(and the period chain). Two 257 test fixtures drop 257 parent tables directly,
which SQLite permits on empty tables but MySQL refuses with error 3730. Neither
author branch could see this: 257 has no 258 tables, and 258's native selection
did not include these 257 cases. Commit `9759b937` adds a test helper that drops
the empty 258 tables first. No product source changed. The original reds are
retained (`native-private-8.4.11/257-foreign-check-attempt1.*`,
`257-data-bearing-prefix-predrift-fix-attempt1.*`), and the post-fix runs are in
`drift-fix/`. SQLite after the fix is unchanged: 24/21/58/3 skips and
22/21/52/1 skip (`drift-fix/sqlite-*`).

### Static checks

- Scoped Pint over all composed owned paths: `{"tool":"pint","result":"passed"}` (`pint-composed.txt`).
- Pint over both review probes: passed (`adversarial/pint-review-probes.txt`).
- `git diff --check`: clean.
- `MembershipRows.php` SHA256 `154b0296…ace8aa` and `MembershipNativeStatementClosureTest.php`
  SHA256 `1b1bd944…7965e` equal the 257 statement receipt (`final-receipt.json` on the 257 branch).
- The 24 unchanged synthetic membership files listed in the 258 receipt map reconcile byte for byte.

## Hashes (composed commit)

| Path | SHA256 |
| --- | --- |
| `app/Domain/Memberships/Production/MembershipRows.php` | `154b0296b74d37bd4b376861e20482ba7fff99bc54d722781566b41965ace8aa` |
| `app/Domain/Memberships/Production/MembershipSchema.php` | `93898457684c9005853e539d344142b1c8bc1141a0554e1e76466bc378ced799` |
| `app/Domain/Memberships/Production/MemberGrantIntent.php` | `7bd43b0410567621d264b8fa92a1b7d1e959d631563cab6c0fa6109b9ec9459c` |
| `app/Domain/Grants/Member/MemberGrantSchema.php` | `064af8e1b0c775475a28fab7300a0d5473b58da11836fb6eddf47de24b04aed7` |
| `database/migrations/2026_10_07_257000_production_membership_periods.php` | `d30af452a097a647cd67f49e2e8cdac7b25e3e98f710529dab0a1c360a28549e` |
| `database/migrations/2026_10_07_258000_create_production_member_originals.php` | `4a20e69a6a71290a9bd5186632b6d663e7268986282398875308f6c85499bd6e` |
| `tests/Feature/ProductionMembership/MembershipSchemaPreparationTest.php` (after D1 fix, `9759b937`) | `9400478d109e325fd5e2e2948e24c162e2c877fc080b498a3bc81693b870fd11` |
| `MembershipForeignSchemaCanaryTest.php` (frozen canary, carried byte-exact) | `72648803519da41d025222e000ecfeceef051ca3e0cdcd0dd01c4dbdbcdaca8a` |

Every composed file's git blob and SHA256 are in `source-map.json`. Evidence
artifact digests are in `evidence-digests.json`.
