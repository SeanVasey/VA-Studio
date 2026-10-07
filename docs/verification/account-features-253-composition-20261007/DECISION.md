# Independent review: production account features 253, composed

**Decision: APPROVE WITH CONDITIONS** for the exact composed commit `3946afda0f1ab943ea826fc05a077d933712131a` (tree `843bc6a0340052ce0d892ffa100bc0b00062a604`), on branch `harness/account-features-253`. That commit stacks the following on integrated base `d20d4394edcc84bf3c5a193f598d9b044b0753aa`:

- `709fbd45f02ddb16aadb6e53328f6da3340bffce`: the 39 frozen owned paths, byte-exact from `3cecea697ccaaff74664acf9d48bcaecfa8bf288`.
- `3946afda0f1ab943ea826fc05a077d933712131a`: the root default-off `config/production-account-features.php` and its boot-level test.

The approval covers a reversible development merge only. It does not cover production activation, final acceptance or launch readiness. Condition C1 (native deadline) blocks activation and final verification.

Reviewer: independent harness (Claude), 2026-10-07. This reviewer did not author 253. No identity runtime file was edited. The 10 s operation deadline and every floor are unchanged.

## Scope

- Composition of the 39 owned paths onto the canonical integrated identity (`ProductionAccountFeatureAccess`, `IdentityCommittedFrame` with the 5053 fix, `ProductionCustomerAccess`).
- Independent source review of the operation, transaction, observer, configuration, context, schema, consent and listening code.
- Two adversarial throwaway tests, kept under `adversarial/` and not in `tests/`.
- The root `production-account-features` parent and its boot-level test.

## Non-scope

HTTP routes, controllers, session capability and product controls (root integration; see the README design note) are out of scope. So are the 254 suppression consumer, email operations, provider bindings, credentials, DNS and deployment. Hosted CI, the full store matrix and native verification on MySQL 8.0 were not run. Re-review of approved identity 101 is also out of scope.

## Composition

- The `git checkout 3cecea69 -- <owned paths>` restore was compared with the source manifest (`manifest.json` sha256 `575dc804…3d90`). Result: 39/39 sha256 match, with no unlisted and no missing paths (`source-map.json`).
- Of the borrowed files, 6/7 are byte-identical in the integrated base. The exception is `IdentityCommittedFrame.php`: the canonical 5053 fix changes three lines, so a nested frame does not restore `query_only` or mark itself closed while the primary is still in a transaction.
- Composition drift found: **none**. The owned SQLite selection matched the author's receipt exactly, and no source change was needed.

## Review assertions and evidence

| # | Assertion | Verdict | Evidence |
| - | --- | --- | --- |
| 1 | Original frame anchored before Beginning delegates | Holds | `ProductionFeatureTransactionObserver::dispatch` constructs `ProductionFeatureTransaction` (outer SAVEPOINT) before `delegate->dispatch`, then `frame()->admit()` releases and re-creates it, so a commit/reopen refuses. Test `test_beginning_listener_cannot_make_the_feature_adopt_or_commit_a_foreign_transaction` passes on SQLite. |
| 2 | Committing proof after ordinary delegates | Holds | In `dispatch`, the delegate runs first, then `admitOuter` → `proveCurrent` → `frame()->admit()`, then `proved = true`, all before the PDO commit. Tests: `test_committing_listener_purpose_withdrawal_prevents_a_durable_grant` and `test_regular_begin_commit_and_aftercommit_callback_order_is_preserved`. |
| 3 | The observer stops intercepting after the original precommit proof (the recorded PublishTrack::unpublish bug) | Fixed in the final source | Both `$outerBeginning` and `$outerCommitting` require `! $this->proved`, so a later level-1 `TransactionBeginning` from a committed callback passes through untouched. `test_postcommit_public_withdrawal_cannot_release_old_public_link_and_reload_is_fresh` passes on SQLite. The reviewer's adversarial case also nests a complete second feature operation inside the committed listener; it passes on SQLite, and the outer projection is withheld. |
| 4 | Detached scalar-reference policy snapshots | Holds | `ProductionFeatureConfiguration::snapshot` rebuilds arrays recursively, so PHP references do not survive. The rollout and purpose policies compare a fresh snapshot with the captured one in guards. `test_plain_scalar_reference_cannot_mutate_the_captured_purpose_snapshot_in_place` passes on SQLite. |
| 5 | Postcommit delegates and read-only reproof | Holds | After `commit()`, the original dispatcher and manager are restored. `IdentityCommittedFrame::begin` → `lockCommitted` → `proveCommitted` (which re-reads every observed scope, fence and guard) → `access->proveCommitted`. Any throw becomes the generic 503 "unknown", with no retry or reset. Adversarial: 3/3 stale-grant vectors are refused on SQLite. |
| 6 | Mandatory current identity-floor proof | Holds | `ProductionFeatureContext::locked` calls `access->lock`; the committing phase calls `access->proveCurrent` and the binding check. Postcommit runs `lockCommitted` plus both committed proofs. No skip or optional parameter exists. `assertHeld` has no raw entry point. |
| 7 | Migration `down()` refuses without dropping | Holds | `ProductionFeatureSchema::down` throws before any query. `test_operational_down_refuses_before_queries_and_preserves_all_schema_and_history` passes. |
| 8 | No implicit consent inference | Holds | Status defaults to `unknown`. A grant requires `affirmative === true`, the exact notice version and sha256, `grants_enabled === true` and a valid closed purpose. Legacy `customer_consent_*` or `customer_saved_tracks` rows refuse with 409 instead of being adopted. Source is `first_party_customer`. Withdrawal works while grants are disabled and stays monotonic. |
| 9 | Ciphertext cap of 60000 bytes | Holds | `ProductionListeningLibrary::encrypt` refuses above `ENCRYPTED_PAYLOAD_BYTES = 60000` before save, and reads refuse above the same cap. `test_actual_over_text_capacity_envelope_is_refused_before_update_with_row_and_revision_unchanged` passes on SQLite. |
| 10 | V1/V2 note handling | Holds | `set-track-note` on V1 without reviewed promotion returns 503. Ordinary V1 writes stay at schema 1. A no-op preserves the exact ciphertext and revision. V2 is never downgraded: `clear-library` keeps `notes`. Export includes notes only. The shipped promotion is off, which the boot-level test proves. |

## Findings

| ID | Severity | Finding |
| --- | --- | --- |
| C1 | **High (blocks activation and final acceptance)** | **Native MySQL 8.4.11 deadline exhaustion.** On an isolated, single-schema 8.4.11 server on this host (load average about 5–8 from other agents), one `ProductionListeningLibrary::initialize` executed **15,525 prepared statements** (15,527 Prepare, 15,525 Execute, 39 Query; general log). The session `Questions` counter read 9,742 before commit and 16,517 at failure. About 6.1–6.6 s elapsed by commit, and the 10 s budget then expired in postcommit proof (`remaining_ms` −35 to −648), so the operation fails closed with a generic 503. `SELECT DATABASE()` alone ran 8,111 times; per-name `information_schema` probes make up most of the rest (`native-diagnosis/`). Temporary counters in 253 files only (reverted, bytes re-verified) put 253's direct guard probes at about 20 before commit. The volume is dictionary and namespace admission: `assertHeld` → consent/identity dependency admission runs 2–3 times per operation, and identity `IdentityRows` asserts the table on each read. Result: the clean native checkpoint selection was 1/10 pass, with 9 errors that are all the fail-closed 503 at fixture `initialize`. This is a performance and availability defect, not a safety defect: every observed outcome refused, and none released a stale projection or durable unauthorised write. It is not caused by composition drift (the only drift is three non-query lines). The original borrowed base was not re-measured natively here. Do not extend the deadline. Possible repair direction (for the owners, not done here): on MySQL any DDL implicitly commits and destroys the held savepoint, which is already detected, so the held-frame dictionary proof could be memoized per physical frame and reverified only for TEMPORARY shadows, plus per-frame `SELECT DATABASE()` reuse. Part of that volume is in identity code, which this reviewer may not edit. |
| C2 | Medium (condition for 254) | `ProductionFeatureContext::locked` and `ProductionFeatureTransaction::__construct` are public. A caller can mint a context inside its own level-1 transaction outside `ProductionFeatureOperation::run`. It keeps the typed identity floor (`access->lock`) but skips the committing and postcommit proofs. `ProductionConsentWithdrawalReader::read` accepts any such context. No caller exists today. 254 must call the reader only inside the `run` callback; root should consider an enforcing guard, such as the context requiring its observer to be the live dispatcher. |
| C3 | Low | `ProductionConsentWithdrawal` sealing prevents accidents only. `__serialize`/`jsonSerialize` throw and `__debugInfo` redacts, but `var_export`, `(array)` casts and Reflection still expose the recipient. Root and 254 must never pass it to responses, logs or exception context. |
| N1 | Info (environment) | Root migration 238 scans `information_schema.TRIGGERS` across the whole server. A sibling schema on the shared 3306 server (`vaseyaudio_member257`) makes `migrate:fresh` refuse. Native suites need a server holding exactly one VA-Studio schema (`invalid-contention/`). |
| N2 | Info | `down()` refusal means `migrate:rollback` and `migrate:refresh` stop at 253 in development, consistent with the other production migrations. `migrate:fresh` is unaffected. |
| N3 | Info | `service_projects` keeps a canonical identity version but is deliberately absent from the shipped parent, so it stays refused even when the parent is bound (proven in the boot-level test). |

No identity correctness defect was found.

## Commands and results

Environment: PHP 8.4.26. The worktree is `/home/user/VA-Studio-features253`, with `vendor/bin` proxies copied so the worktree autoloader is used. SQLite is the `phpunit.xml` default.

| Command | Result | Receipt |
| --- | --- | --- |
| `php vendor/bin/phpunit tests/Feature/ProductionFeatures tests/Unit/ProductionFeatures --log-junit …` (composed `709fbd45`) | **86 tests / 766 assertions: 83 pass, 3 native-only skips**, matching the author's receipt exactly | `composed-sqlite.*` |
| `php vendor/bin/pint --test <packet selection>` | passed | `composed-pint.txt` |
| Same owned selection plus `tests/Feature/ProductionAccountFeatures` (`3946afda`) | 89 / 831: 86 pass, 3 native-only skips | `root-config-sqlite.*` |
| `php vendor/bin/phpunit tests/Feature/ProductionIdentityAdapters tests/Feature/ProductionIdentity tests/Feature/ProductionIdentityRegistrationTest.php tests/Feature/ProductionIdentitySmtpBindingTest.php` (`3946afda`) | 129 / 752: 120 pass, 9 native-only skips | `root-config-identity-sqlite.*` |
| `php vendor/bin/phpunit tests/Feature/ProductionAccountFeatures` | 3 / 65 pass | inside `root-config-sqlite.*` |
| Adversarial `adversarial/AccountFeatures253AdversarialReviewTest.php`, SQLite | **6 / 63 pass**: no stale grant projection and no durable write under a withdrawn purpose or feature | `adversarial-sqlite.*` |
| Native, isolated 8.4.11 on :3407: `ProductionFeatureNativeAdmissionTest.php` (the 3 native-only cases) | **3 / 65 pass** | `native-admission-mysql84-isolated.*` |
| Native, isolated: 9-filter checkpoint plus final postcommit case (10 cases) | **1 pass, 9 errors**, all fail-closed 503 at fixture `initialize` (C1) | `native-checkpoint-clean-mysql84.*` |
| Native, isolated: single `test_regular_begin_commit_and_aftercommit_callback_order_is_preserved` | error, fail-closed 503 (C1 reproduction) | `native-single-regular-order.*` |
| Native, isolated: adversarial | **inconclusive**: 6 errors at fixture `initialize` before any vector ran (C1) | `adversarial-clean-mysql84.*` |
| Invalid runs (contention), preserved | not results | `invalid-contention/` |

The isolated server was started with `mysqld --no-defaults --user=root --initialize-insecure`, then on port 3407 with a scratch datadir, a single schema `vaseyaudio_features253` and root password `ci-only-password`. The native environment was the caller's, apart from `DB_PORT=3407`. Native diagnosis used temporary, uncommitted instrumentation in `ProductionFeatures/*` only, followed by `git checkout HEAD -- app/Domain/Customers/ProductionFeatures` and a 39/39 hash re-check.

## Hashes

- Source manifest `docs/verification/production-account-features-20261007/manifest.json` at `origin/customer-production-account-features-t32` (`3f32ffaeb4…`): sha256 `575dc80487b3553a21a643f4cac99ba1466747cb1c27b54f292e129223133d90`
- `source-map.json`: sha256 `952c58137e27e33892874a0045277dfd5a442619c5a7db206edaf29c67abdadf` (39 owned entries, each with source blob and sha256)
- `config/production-account-features.php`: `f047846aa1741219effcc5db44896e836fed17599dcb3c23a0bb78f92330483a`
- `tests/Feature/ProductionAccountFeatures/ProductionAccountFeaturesDefaultConfigurationTest.php`: `d1cc27b46aa318a33f3dd413e181c960e522b32a043f4ec47f2086589c5e9430`
- `adversarial/AccountFeatures253AdversarialReviewTest.php`: `a3135b42ae050c220907768a81076487295284d5850489a1ccdd186b4f5e2a60`

## Conditions

1. **C1** must be resolved without weakening the deadline or the floors before any activation, Foundation final verification or final acceptance. That means the owned native selection (including the 3 native-only cases, the checkpoint filter and the adversarial cases) passes on MySQL 8.4 on the target host class. Any repair in identity files needs its own identity review.
2. **C2/C3** are binding on root and 254: call the withdrawal reader only inside `ProductionFeatureOperation::run`, and never serialize or log `ProductionConsentWithdrawal`.
3. Production defaults stay as shipped. Enabling `production-account-features`, notes promotion or purpose grants needs a separately reviewed binding, policy and Sean's authorization.
