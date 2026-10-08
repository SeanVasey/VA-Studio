# Independent review: Free256 lane, plan step D1 (production-free grant family 256)

- Reviewed SHA: `1860e00d3a7d3852d19126ae01ee54f50cef8660` (`origin/harness/free-256`, PR #53).
- Code head: `16b420e7`. `1860e00d` adds only `docs/verification/free-256-20261007/README.md`.
- Base: `fad3ab444e89ffb57dc915f61f7f788b626e330a`. `git diff --name-status fad3ab44..1860e00d` lists 43 files, all `A` (added). No existing file is modified.
- Reviewer: an independent reviewer agent. It authored no lane commit and changed no app code, flag or registration. It made no commit. The only change in this worktree is the untracked `independent-review/` directory.
- Date: 2026-10-08 (UTC). Work was interrupted once by a usage limit after every run had finished. The resumed session started no new test.

## Environment

- Review worktree `/home/user/VA-Studio-review-free256`, made with `scripts/dev/mkworktree.sh` at `1860e00d`. It symlinks the main checkout's locked `vendor/` and has its own Composer autoload.
- Two short-lived detached worktrees, both now removed:
  - `/home/user/VA-Studio-review-free256-base` at `fad3ab44`, for question 7.
  - `/home/user/VA-Studio-review-free256-mut` at `1860e00d`, for the mutations. Mutating a separate tree kept the native runs, which were still loading app files, on unmodified source.
- PHP 8.4.26 and PHPUnit 12.5.34. SQLite runs use `:memory:` through `phpunit.xml`. The upgrade probe uses a file-backed SQLite database in the scratchpad.
- Native MySQL used private `mysqld` 8.4.11 instances:
  - Each was started with `--no-defaults --socket= --mysqlx=OFF --bind-address=127.0.0.1`, with datadirs under `scratchpad/review-free256-mysql/`.
  - Ports were 3641, 3642, 3643, 3644 and 3645. All were free in `/proc/net/tcp`, and 3306 and other lanes' instances (3531, 3567, 3613) were not touched.
  - Each instance held one schema, `rv256`.
  - Speed-only flags: `--skip-log-bin --innodb-flush-log-at-trx-commit=2 --sync-binlog=0 --innodb-doublewrite=OFF`. Isolation stayed at the default `REPEATABLE-READ`, which was verified.
- One schema per instance was required. With several schemas on one server, `migrate:fresh` fails in the pre-existing `CapabilityMigrationOwnership` cross-schema trigger scan (I-10). The first parallel attempt was aborted for that reason; its logs are retained under `native/aborted-parallel-1/` and `native/diag-migrate-*.txt`.
- The `APP_KEY` is synthetic and comes from the fixture (`base64:` of 32 × `F`). All terms, assets, accounts and hashes are synthetic.

## Decision

**APPROVE WITH CONDITIONS, for a development merge only.** The batch is default-off, unregistered and unmounted, and it adds files only.

The sensitive invariants hold on the reviewed commit, with evidence:

- **Authority.** Independent review, staff authority and the approved-terms gate are enforced server-side and backed by append-only guards. With the app checks removed, the DB guards still refuse (mutations S1, S3, S4).
- **Assent.** Assent is literal and bound to the locked principal. The origin cap held in 10 native two-process races.
- **Originals.** They are write-once, recovered by hash and byte-identical on re-render.
- **Delivery.** Every artifact needs an exact-owner, one-use, 300-second authorization. Bytes are hash-verified before the first byte leaves. Revocation denies delivery.
- **Schema.** On both drivers it refuses every malformed, out-of-order, update and delete case tried. Native prefix recovery passed for all 37 prefixes, and native temporary-parent and parent-drift checks refuse.

Two Medium findings, F-1 and F-2, are not safe for activation or mounting. They do not block a default-off development merge.

This decision does **not** approve:

- activation (`enabled`, approved terms hashes, or `verified_production` provenance);
- mounting any route, controller, Filament action, provider binding or HTTP delivery endpoint;
- binding a real `ProductionFreeGrantSources` adapter;
- real terms, assent wording, assets, prices or caps;
- use of the `test-buyer-pdf-v2` base fonts and packages, which are marked `test_only`, for a production profile;
- any production storage host;
- the owner-doc steps the lane lists as not implemented: the commit observer (step 3), committed-frame delivery validation (step 5), and the ff0 HTTP identity capsule.

## Findings

| ID | Severity | Finding | Recommendation | Evidence |
| --- | --- | --- | --- | --- |
| F-1 | **Medium** | **Historical grants are coupled to the current renderer runtime.** Every read goes through `ProductionFreeGrantDefinitions::graph()`, which calls `ProductionFreeGrantRenderProfile::validate($payload['profile'])` and requires the sealed profile to equal `current()`. `current()` depends on `MANIFEST_HASH`, `profile-assets.json`, the main-resident `test-buyer-pdf-v2` metadata and `V1_LIMITS`. The reads affected are library `index`/`show`, `authorize`, `redeem`, `revoke`, `render` and `recover`. A legitimate later renderer revision (edit `ProductionFreeGrantText.php`, then update the manifest and `MANIFEST_HASH`) made **every** existing owner's library and asset delivery refuse with `profile_changed`, including grants already rendered. An edit to the manifest alone (U3) does the same. An edit to the implementation alone (U2) leaves reads working and refuses only rendering (`render_failed`). | Verify a historical definition's profile against its own sealed `profile_hash` (the definition and origin columns are already sealed), and require equality with the current runtime only for `render` and `recover`. Keep a registry of historical profile versions. Add a regression in which an existing origin stays readable and deliverable after a profile revision. | `mutations/U1.txt`, `U2.txt`, `U3.txt`, `control-after-reverts.txt`, `sqlite-probe-upgrade-*.txt` |
| F-2 | **Medium** (mount condition) | **The delivery snapshot is unbounded.** `ProductionFreeGrantDownloads::snapshot` creates a UUID-named, unlinked, writable spool file for each redemption. It has no slot lease, no free-space reserve, no disk budget and no read-only reopen or read-back. The main-resident `PrepareTestDeliveryStream` has all of these (3 flock slots, a 16 MiB reserve, chmod 0400 and read-back). The only limiter is 5 authorizations per minute per origin. Up to 1 GiB per redemption is held until the stream closes. Four concurrent snapshots were held with nothing bounding them. | Before mounting, reuse the slot, reserve and read-back pattern, or add a global concurrency and disk budget. Hash on read-back from the spool, not only on the write path. | `sqlite-probe-delivery.txt` (`delivery.consumer`: 4 unlinked snapshots held; `delivery.gib`: 1 GiB in 8.1 s) |
| F-3 | Low | **MFA is a no-op wherever 256 can run.** `AdminMultiFactor::satisfiedBy` returns true when the admin panel does not require MFA. `AdminPanelProvider` requires it only when `app()->isProduction()`, and the 256 policy admits only `local` and `testing`. The lane tests force `isRequired: true`. With the shipped panel rule, an admin without MFA can propose, approve, open and revoke. In addition, `satisfiedBy` checks enrollment, not a session challenge. | Require MFA enrollment unconditionally for 256 staff authority (at least for `verified_production`). Keep the session challenge at the Filament mount. | `sqlite-probe-authorization.txt` (`auth.mfa_not_required`) |
| F-4 | Low | **MySQL guard parents can be shadowed by a session TEMPORARY table.** With a session `TEMPORARY TABLE users` that marks a customer `is_admin=1`, the reviews insert guard admitted that customer as reviewer. Domain commands refuse while the shadow exists (`parent_floor`). The forged row is permanent (append-only): after the shadow is dropped, the definition reads `tampered` for good, which is a denial of service. With a valid `APP_KEY` seal it would be an accepted approval. Exploiting this requires executing SQL on an app session. | Record this as a residual: SQL cannot qualify past a temporary shadow. Keep the commit-observer step (owner doc step 3) re-proving the parent floor immediately before commit. Consider an operator-recorded quarantine for a definition with an unverifiable row. | `native/native-probe-schema-guard.txt` (section h) |
| F-5 | Low | **`fopen('x+b')` follows a dangling symlink.** PHP resolves the path before `O_EXCL`. A dangling `original.pdf` symlink planted in a claim directory makes `ProductionFreeGrantFiles::store` create an **empty 0600 file at the outside target** before `sameFile()` refuses (`storage_failed`). Nothing is written to it and nothing is published. Kernel `O_EXCL` (Python) refuses the same link. Planting requires write access to the private root. | Write to a fresh random name inside the just-created 0700 claim directory, then `link()` it to `original.pdf`. `link()` neither follows a destination symlink nor overwrites. Alternatively, `lstat` and refuse any existing entry before opening. | `sqlite-probe-rendering.txt` (`render.symlink`) and the shell reproduction in this review's log |
| F-6 | Low | **The renderer child sandbox is wide.** `open_basedir` is the whole project root, so the child can read `.env` (`APP_KEY`) and, in production, `storage/app/private`. `mail`, `pcntl_exec`, `putenv`, `gethostbyname` and `dns_get_record` remain callable, and there is no network namespace. The environment is cleared, URL fopen is off, sockets and process functions are disabled, and widening `open_basedir` at runtime is refused. The same pattern is in `FreeGrantRendererProcess` and `IsolatedContractRenderer`. The code is hash-pinned and the input is data-only, so this is defense-in-depth. | Narrow `open_basedir` to the script, the four renderer classes, the fonts and the package directories. Add `mail`, `pcntl_*`, `putenv` and the DNS functions to `disable_functions`. | `sqlite-probe-rendering.txt` (`render.isolation` reach JSON) |
| F-7 | Low (mount condition for root's adapter) | **Delivery does not re-prove current source readiness.** `authorize` and `redeem` never call `ProductionFreeGrantSources::prove`; only `open()` runs. A later rights withdrawal, takedown or scanner quarantine does not stop delivery of an existing grant unless the adapter's `open()` refuses. | Root's adapter must refuse quarantined, withdrawn or taken-down assets in `open()`. Alternatively, call `prove` (or a narrower delivery proof) at redemption. | Code review (`ProductionFreeGrantDownloads`) |
| F-8 | Low | **`APP_KEY` rotation breaks every row.** Seals and request-key hashes use only the current `app.key`, and `app.previous_keys` is not honoured. A key rotation would make every 256 row read as `tampered`. Other families follow the same pattern. | Before production, decide on a seal key that does not rotate, or support verification against previous keys. | Code review (`ProductionFreeGrantRecords::seal`, `ProductionFreeGrants::requestKeyHash`) |
| I-1 | Info | **Forging an approval needs `APP_KEY` plus direct INSERT.** The guard refuses a forged self-review. A forged review naming a different verified admin without MFA is admitted and accepted by `graph()`, because MFA is invisible to SQL. A forged `open` for unapproved terms is refused at customer review (`stale_terms`). An unkeyed seal is refused (`tampered`). A DBA column edit behind a dropped and restored guard is refused (`tampered`). | None for this merge. | `sqlite-probe-authorization.txt` (`auth.forgery`) |
| I-2 | Info | Revocation denies all four roles: outstanding and new authorizations both fail, and no redemption is recorded. The owner's library still lists the origin with `revoked=true`, `deliverable=false` and the artifact hashes. This is by design. | None. | `auth.revocation` |
| I-3 | Info | The display hash is not bound to the principal, and `review()` is not server-recorded: a client can compute `displayHash` without calling `review()`. When B replays A's exact assent body, the result is a new origin for B (B's own authenticated literal assent), never a second grant for A. | Decide at mount whether to record the typed review server-side. | `sqlite-probe-assent.txt` |
| I-4 | Info | Removing a terms hash from configuration stops new assent but not delivery of existing grants. This is consistent with immutable granted licenses. | None. | `assent.replay` |
| I-5 | Info | A transfer that has already been redeemed keeps streaming after the module is disabled. This is the lane-disclosed gap (owner doc step 5, committed-frame validation). | Covered by condition 3. | `sqlite-probe-default-off.txt` |
| I-6 | Info | Race losers get the opaque `refused_by_guard`, not `cap_reached` or `already_redeemed`. A redemption loser makes a full snapshot before being refused. | Map guard refusals at the mount. | `native-race-log.json` |
| I-7 | Info | With `enabled=false`, the migration still installs 9 tables and 27 triggers (0 rows), runs the parent floor, and `down()` refuses. This is the repository pattern for retained evidence. | None. | `default_off.migration` |
| I-8 | Info (evidence correction) | **The native directory skips 39 cases by design, not 0.** The skips are the 37 prefix cases, the temporary-shadow case and the parent-drift case (all `sqliteOnlyRecovery`). The lane's "Untested" list is accurate. The reviewer's native probe covered all three natively. | Port the three cases to native, or keep the reviewer probe as evidence. | `native/native-*.junit.xml` |
| I-9 | Info | `recover()` raises a raw `ContractIssuanceException` when the runtime changes (not wrapped). Rendering wraps the same failure as `render_failed`. | Wrap it for consistency. | Code review |
| I-10 | Info (adjacent, pre-existing, not 256) | `CapabilityMigrationOwnership` (around line 161) scans `information_schema.TRIGGERS` across all schemas. A second app schema on the same server makes `migrate:fresh` fail with "external or additional table guard". A separate task was suggested. | Separate task. | `native/diag-migrate-1.txt`, `native/diag-migrate-2.txt` |

## Verified claims (answers to the seven questions)

### 1. Authorization (`sqlite-probe-authorization.txt`; mutations S1 and S2; native section (h))

- The checks that refuse:
  - author as reviewer: `self_review`;
  - a reviewer without MFA when the panel requires MFA: `mfa_required`;
  - an unverified admin, a customer, a demoted model or an unsaved model: `staff_refused`;
  - open before review: `not_reviewed`;
  - open with terms not listed: `stale_terms`.
- The author may open or close after an independent approval. The independence lives in the review row and its guard (`d.author_user_id <> NEW.reviewer_user_id`).
- **Mutation S1** removed the app-level `self_review` check. The DB guard still refused (`refused_by_guard`), and the suite caught the mutation.
- **Mutation S2** removed the guard predicate. The suite caught it (`Guard must refuse`).
- Resealed forgery: see I-1. Approval cannot be forged without `APP_KEY` plus INSERT, and even then terms approval is re-checked at assent.
- Revocation denies later delivery for all four roles (I-2). Library reads continue and show the revoked state.
- **Mutation S4** removed the app revocation check. The redemption and authorization guards still refused.

### 2. Assent (`sqlite-probe-assent.txt`; `native-race-log.json`; mutation S3)

- Only the literal boolean `true` is accepted. `'true'`, `1`, `'yes'`, `null`, `false`, a missing key and an extra key are all refused.
- The principal comes from `ProductionCustomerSessions::principal(Request)`, and the actor is `request->user('customer')`. A mismatched principal/actor pair is refused (`identity_refused`, from the lane test plus this probe).
- Replay with the same request key is idempotent, including after close. Reuse of a key is refused. A cross-principal replay creates the replayer's own origin (I-3).
- The origin `terms_hash` equals the definition `terms_hash`, which equals the configured approved hash (`65a3172c…`). The assent hash is recorded in the sealed row, and `marketing_consent` is `unknown`.
- **Native concurrency (two forked processes, separate MySQL sessions):** 0 cap violations in 10 rounds.
  - At cap 1 (6 rounds, 3 free-running and 3 behind a held definition lock), exactly 1 origin each time.
  - At cap 2 (4 rounds), 2 origins each time with no deadlock.
- The DB cap guard does a current read. A REPEATABLE READ session whose snapshot showed 1 origin was refused (`1644`) on the third insert after a concurrent commit reached the cap of 2.
- **Mutation S3** removed the app cap check. The trigger still refused.

### 3. Rendering and originals (`sqlite-probe-rendering.txt`; mutation S5)

- Three fresh isolated child renders produced the stored SHA, and `recover()` reported the result identical.
- **Write-once** holds in each of these cases:
  - **Second store:** refused, and the file was unchanged.
  - **Pre-existing file:** refused by the lane test. With mutation S5 (`c+b` in place of `x+b`), the planted file was overwritten and published, and the test caught it. The `x` mode is load-bearing.
  - **Hard link:** a hard link to the original made it `original_unavailable`; after the link was removed, recovery passed again.
  - **Truncation:** detected by both `recover` and the contract redeem, the file was left as found, and no redemption was recorded.
  - **Symlinks:** a symlinked `original.pdf` (live or dangling), a symlinked claim directory and a symlinked origin directory were all refused, and the outside directory was unchanged. Exception: F-5, an empty file at the dangling target.
- **Path traversal:** `..`, nested `..`, upper-case and NUL segments in the origin or claim position are refused (`invalid_input` or `storage_failed`), and traversal storage paths are refused by `verify`. Nothing is created.
- **Renderer isolation:** it runs as a child `php -n` process with the environment cleared (an injected secret variable was not passed). It has `allow_url_fopen=0`, sockets and process functions disabled, and input bounded at 1 MiB, which is refused before spawning. See F-6.
- **Manifest enforcement:**
  - `MANIFEST_HASH` equals the SHA of `profile-assets.json` (`b909c5fa…`), and each of its 5 file hashes matches.
  - Implementation drift is refused before rendering (U2: `render_failed`, work `claimed,failed`).
  - Manifest drift refuses everything (U3, see F-1).

### 4. Delivery (`sqlite-probe-delivery.txt`; mutations S4 and S6; native redemption race)

- **Per role (`contract`, `master_wav`, `download_mp3`, `stems_zip`):**
  - a foreign owner gets `not_found`, and a foreign actor `identity_refused`;
  - a wrong token gets `token_refused`;
  - the exact bytes and hash are delivered once, and a replay gets `already_redeemed`;
  - the TTL is 300 s, and an expired authorization gets `expired`.
- **Rows the SQL guards refuse:**
  - a wrong claim (another owner's origin under this account);
  - a contract whose hash is not the original's;
  - a redemption after expiry or with a role or hash mismatch;
  - an authorization for a revoked origin;
  - a second redemption.
- **Bytes are checked by hash.** The lane's drift test passes, and mutation S6 (hash check removed) was caught.
- **Native redemption race:** in 3 of 3 attempts, exactly 1 redemption.
- **`writeTo` cannot read outside the private root.** `ProductionFreeGrantTransfer` holds only `stream`, `filename`, `mimeType` and `deadline`. The snapshot is unlinked under `<private root>/delivery/production-free-spool`, and the spool directory is empty after delivery.
- **The 1 GiB limit holds.** `MAX + 1` is refused at propose (`invalid_input`). Exactly 1,073,741,824 bytes were snapshotted, streamed and hash-matched in 8.1 s. See F-2 for the missing disk budget.

### 5. Schema (`native/`; native probe)

**Native lane directory.** This is the first native run of the Approval, Assent, Rendering, Library, Delivery and FrozenBytes classes: **93 tests, 522 assertions, 0 failures, 0 errors, 39 skipped (I-8). Every shard's exit code is 0.**

| Shard (instance) | Selection | rc | Tests | Assertions | F | E | Skipped | JUnit time |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| a1 (:3641) | Schema prefix #0–22 | 0 | 23 | 23 | 0 | 0 | 23 | 3435.1 s |
| b1 (:3642) | Schema prefix #23–36 | 0 | 14 | 14 | 0 | 0 | 14 | 2382.9 s |
| b2 (:3642) | RenderingTest | 0 | 8 | 56 | 0 | 0 | 0 | 1308.3 s |
| c1 (:3643) | Schema, not prefix | 0 | 13 | 121 | 0 | 0 | 2 | 1900.3 s |
| c2 (:3643) | ApprovalTest | 0 | 10 | 64 | 0 | 0 | 0 | 1145.3 s |
| d1 (:3644) | Assent, Delivery, FrozenBytes, Journey, Library, RequestIdentity | 0 | 25 | 244 | 0 | 0 | 0 | 3732.7 s |
| **Total** | `tests/Feature/ProductionFreeGrants` | | **93** | **522** | **0** | **0** | **39** | 04:36:07Z to 05:38:20Z |

**Reviewer native probes.**

| Probe | rc | Result |
| --- | --- | --- |
| `NativeSchemaGuardProbeTest` run 1 | 2 | 1 error (probe bug: MySQL refuses `CREATE TEMPORARY TABLE users LIKE users` with 1066). Retained. |
| `NativeSchemaGuardProbeTest` run 2 (fixed probe) | 0 | OK, 1 test, 102 assertions |
| `NativeConcurrencyProbeTest` | 0 | OK, 1 test, 30 assertions |

**Guard red/green.** Every positive control was admitted, and each of the following was refused:

- **Origins:** a stale or closed availability; a staff user acting as customer; drift of the definition hash or terms hash; a second origin per account; a row timestamped before its availability.
- **Work:** an ordinal gap; failed-first; `created_at` at or after the lease (CHECK); a second claim under a live lease; a failure with the wrong claim or the wrong lease.
- **Originals:** a second original; a row after lease expiry; a wrong claim; oversize bytes.
- **Revocations:** a customer actor; a second revocation.
- **Authorizations:** a revoked origin; `created_at` at or after `expires_at`; a foreign account.
- **Redemptions:** a second redemption; one after expiry; a role or hash mismatch.
- **Update and delete:** UPDATE and DELETE on all 9 tables.

**Native recovery and parent floor.**

- Prefix recovery passed for 37 of 37 prefixes, compared on `SHOW CREATE TABLE` output plus trigger statements.
- An earlier guard hole is refused (`schema_prefix`).
- A temporary `users` or `customer_accounts` shadow is refused, both by the installer and by a domain command (`parent_floor`).
- Drift of `customer_accounts.active` or `users.email_verified_at` is refused before any owned DDL, with no owned object created.
- SQLite counterparts pass in the lane suite. The migration is inert while off only in data terms (I-7).

### 6. Default-off (`sqlite-probe-default-off.txt`; `registration-grep.txt`)

- All 14 entry points refused, each with the exact reason, under all 5 conditions:
  - the entry points: `propose`, `approve`, `open`, `close`, `read`, `review`, `accept`, `revoke`, `index`, `show`, `render`, `recover`, `authorize` and `redeem`;
  - the conditions: `disabled`, environment `production` (`environment`), `verified_production` (`provenance`), an unbound capability (`capability_absent`) and a string `enabled` (`changed_policy`).
- The shipped config has `enabled=false`, `provenance=null` and `approved_terms_hashes=[]`.
- No reference to the family exists in `bootstrap`, `routes`, `app/Providers`, `app/Http`, views, JS or seeders. `git diff --quiet fad3ab44..1860e00d -- bootstrap routes app/Providers app/Http README.md CHANGELOG.md docs/development-order.md` returned 0.

### 7. The four adjacent failures

They reproduce identically on base `fad3ab44`, which has no 256 file (`git ls-files` matched 0), and on head. The failing test sets were compared name by name and are identical:

- `ProductionTrackCapabilitiesMigrationOwnershipTest`: 23 tests, 6 errors.
- `RightsEvidenceGuardMigrationTest`: 33 tests, 9 errors.
- `ServiceProjectSchemaTest`: 3 tests, 1 failure.
- `DiscoveryEpochMigrationTest`: 9 tests, 2 failures.

They are pre-existing and not caused by 256. Evidence: `adjacent-{base,head}-*.txt` and `.junit.xml`.

### Lane suite and Pint

- SQLite `tests/Feature/ProductionFreeGrants`: 93 tests, 559 assertions, 2 skipped (the native-only cases), rc 0. This matches the lane README.
- Pint `--test` over the 35 owned PHP files: `passed`, rc 0.
- `ProductionFreeGrantFrozenBytesTest` passed natively (d1). It pins 20 main-resident dependencies by SHA-256.

## Conditions

1. **F-1, before any operative definition exists (before activation or `verified_production`).** Decouple historical reads and delivery from the current renderer runtime, and add the upgrade regression (mirror `probes/UpgradeProbeTest.php`).
2. **F-2, before the delivery endpoint is mounted.** Add bounded spool slots, a free-space reserve or disk budget, and read-back verification.
3. **Before mounting.** The lane-disclosed owner-doc steps 3 and 5 (commit observer, committed-frame delivery validation, I-5) and the ff0 identity capsule remain open and must be completed.
4. **F-3, before mounting staff actions.** Require MFA independently of the panel's production-only rule, and challenge MFA in the session at the mount.
5. **F-7, in root's sources adapter.** `open()` must refuse withdrawn, quarantined or taken-down assets, and it must serialize exclusive scope and inventory as the owner doc requires.
6. **F-4, F-5, F-6 and F-8.** Track them as Low hardening items before production provenance.

Release boundaries carried forward unchanged: `enabled=false`, no approved terms, no binding, no route, and verified-production provenance refused.

## Not reviewed or untested

- **HTTP:** nothing is mounted, so CSRF, session marker over real requests and response headers were not exercised.
- **Callback and commit attacks:** committing, postcommit, first-byte withdrawal, raw reopen, custom PDO statement classes. These are out of scope as owner-doc steps 3 and 5 are not implemented.
- **Disk-full** during spool or original storage.
- **Assets above 1 GiB** cannot be defined. Behaviour was tested at exactly 1 GiB on SQLite only, not natively.
- **Native races:** only assent, render and redemption were raced. Worker-lease expiry races and authorize bursts across many origins were not.
- **Production fonts and packages:** the `test-buyer-pdf-v2` base has `test_only: true`; Sean must decide (lane README).
- **Wider suites:** the full repository suite, frontend and Foundation CI were not run.

## Commands and results (exit codes are `rc=$?` captured on its own line)

From `/home/user/VA-Studio-review-free256`, with `P='php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' --'`:

```
$P --colors=never --log-junit … tests/Feature/ProductionFreeGrants                  # OK 93 tests, 559 assertions, 2 skipped; rc=0
vendor/bin/pint --test <35 owned PHP files>                                          # passed; rc=0
$P review-evidence/probes/AuthorizationProbeTest.php                                 # OK 4/59; rc=0
$P review-evidence/probes/AssentProbeTest.php                                        # OK 2/44; rc=0 (first run failed on a key-order assertion in the probe; rerun after the fix)
$P review-evidence/probes/RenderingProbeTest.php                                     # OK 4/63; rc=0 (first run: 2 probe failures — F-5 discovery and a closure-capture bug; probe adjusted)
$P review-evidence/probes/DeliveryProbeTest.php                                      # OK 3/51; rc=0
$P review-evidence/probes/DefaultOffProbeTest.php                                    # OK 2/83; rc=0
DB_CONNECTION=sqlite DB_DATABASE=<scratch>/db.sqlite $P --filter test_prepare|test_observe review-evidence/probes/UpgradeProbeTest.php   # OK; rc=0 each
review-evidence/mutations/run-mutations.sh                                           # S1..S6 rc=1 (caught); U1..U3 rc=0 (observations); every revert: git diff --quiet -- app database resources rc=0
native: review-evidence/native/run.sh <port> <out> <args>  (DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=<port> DB_DATABASE=rv256 DB_USERNAME=root DB_PASSWORD= DB_URL=)
        review-evidence/native/shards.sh                                             # six selections above, all rc=0
base/head adjacent: $P tests/Feature/<4 files>.php in each worktree                  # rc 2,2,1,1 on both
```

## SHA-256 of owned files at `1860e00d`

These were computed with `git show 1860e00d:<path> | sha256sum`. The full list of 35 PHP files plus `profile-assets.json` is in `review-evidence/owned-sha256-1860e00d.txt`. The renderer files match `profile-assets.json`.

| Path | SHA-256 |
| --- | --- |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantDefinitions.php` | `65296d6b41ec5d6aa675ce83a82a6faca7738cc1095003c0aa79bff7ac8415b0` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantDocuments.php` | `637f75133945b1c10ba5a4761f76b8f636afbeac8acd8046d1035697622ff69d` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantDownloads.php` | `6c02d5ca6ebe4c297e453968a0bfcee95fa69a73a126905dce211cf79444c6fd` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantException.php` | `eabf6b34b15b2b05babf5c7c5331befa2682354412001d48c676868660555247` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantFiles.php` | `3c531240a21a77c56056eff69f8826fa055a0adf141d15f45a989b09e70eb853` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantInput.php` | `55f42c03143e39b37a559c7c0ef0a33ed039c894b2caee0ddd7b6074c3cd85db` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantLibrary.php` | `a1adab75801feb6a3fd145eaa64bfb049d6c7f6e2c045cdf0d054b43b5307ab7` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantPdfRenderer.php` | `eea4bc7e5ba7a7df18eac0ac2dd85720813372d94b19d1dc303e789cf9b7b261` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantPolicy.php` | `092f5555d3b2d385c1172ed9573decd1befbc57a1b42a435f7d1a475a88fc48a` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantRecords.php` | `284427c582d3e89307ab7338e7c2fad690c9afe5370ed806be38c63e1303e465` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantRenderInput.php` | `16566e3fca45b7e8ad0d25393632f035711e26a3b6acfd0edb8fc4ee7e55d93f` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantRenderProfile.php` | `a236ad2bbc1e8531d142320bb2d66ef189270d53ffdea9362a5c945c0064076f` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantRendererProcess.php` | `ce40e9b1a1bb85c7924b21951488ab54b24baa042be2df1e5a1b4fd9a1a85921` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantRequestIdentity.php` | `308611727a276e0347f76cdbda71fcb286956efa89367c3ec695de49fb6a57b6` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantRows.php` | `5ed9e71840a50872f6dfaec59909ce3b3b380697b065c21ff9dd2d2a027cd2a7` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantSchema.php` | `75effc3d033a072124bf1fb79ec88584d14002ec76ca1017a7f0a7b611d8cc6a` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantSources.php` | `30ece43c63ae1b99a25710e6ed26acc3453a8691b3e2408a71c74f94c5c93d61` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantStaff.php` | `5eb49f63a1557f750ffae79a054ba56125d74c7e316ff1321fd3f895e0974d96` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantText.php` | `93af5758af8c646ba794a6864701db2771f23040ad537953794b434ab16fb522` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantTransfer.php` | `4dac749c168126dc5bc7b551c54332f5aaf0103efa9473e3b840a6eed687689b` |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrants.php` | `c32623d74b6358fc3cfb52c87f2d67b0b81761a9c1c837ed6e23177597d6d621` |
| `config/production-free-grants.php` | `ca60196e2db1cbb4d3d1f25ee0e2af3cfd816405d5f1a7caa1f4def178c51d6e` |
| `database/migrations/2026_10_07_256000_production_free_grants.php` | `0e9d42566feeeb6730726451a9db8605634f3ce579e1848010b7354973eb681e` |
| `scripts/render-production-free-grant.php` | `ecf6cf22f902a180b7dbcb3ae771fe9fcb0e5282b74274a6d53e08412e6a3199` |
| `resources/contracts/production-free-v1/profile-assets.json` | `b909c5fa80e882d7d6f2ab4bf8b05f315f46695d4a9acb621b0350a02d7f7b1f` |

## Evidence index (`review-evidence/`)

- `probes/`: the reviewer probe sources, none of which are in the suite.
  - `AuthorizationProbeTest`, `AssentProbeTest`, `RenderingProbeTest` (with `renderer-child-reach.php`), `DeliveryProbeTest`, `DefaultOffProbeTest` and `UpgradeProbeTest`.
  - The native-only `NativeSchemaGuardProbeTest` and `NativeConcurrencyProbeTest`.
- `sqlite-probe-*.{txt,junit.xml}`: the SQLite probe runs.
- `sqlite-production-free-grants.*`: the lane directory on SQLite.
- `pint.txt`: the Pint result.
- `registration-grep.txt`: the registration and mounting greps.
- `mutations/`: `mutate.py`, `run-mutations.sh` and S1–S6 and U1–U3 output, each with its diff, test result and the post-revert `git diff --quiet` rc. Also `control-after-reverts.txt`.
- `native/`:
  - shard and probe `.txt`, `.junit.xml` and `.rc` files, including the retained `native-probe-schema-guard-run1-error.*`;
  - `run.sh`, `shards.sh`, `native-start.txt` and `native-end.txt`;
  - `private-instance-lifecycle.txt` and `mysqld*.err`;
  - `timing-journey.txt` (the first single-case timing run);
  - `diag-migrate-1.txt` and `diag-migrate-2.txt` (I-10);
  - `aborted-parallel-1/`: the first multi-schema attempt, aborted, with every first case erroring from I-10.
- `native-race-log.json`: per-round race outcomes.
- `adjacent-{base,head}-*.{txt,junit.xml}`: question 7.
- `owned-sha256-1860e00d.txt`: the owned-file hashes.

## Cleanup

- All five private instances (3641–3645) were shut down with `mysqladmin shutdown` (rc 0). Their error logs end with "Shutdown complete", no process with a `review-free256-mysql` datadir remains, and nothing listens on 3641–3645.
- The datadirs `data`, `data2`, `data3`, `data4` and `data5` were deleted from `scratchpad/review-free256-mysql/`; only the init logs, error logs and lifecycle remain there. Other lanes' daemons (`review-member-mysql`, `review-tax255-mysql`) were left running and untouched. See `native/private-instance-lifecycle.txt`.
- The base and mutation worktrees were removed (`git worktree remove`; the mutation tree was proven clean first).
- The review worktree is at `1860e00d`, and its only change is the untracked `docs/verification/free-256-20261007/independent-review/`.

## Addendum 1: hardening delta `51c9fecf..68c4e03c` (code `f1666ae3`)

- Scope: the fixes for F-1, F-2, F-3, F-5 and F-8 in `f1666ae3`, plus the lane evidence in `68c4e03c` (`hardening/README.md`). Codex also raised F-1, F-2 and F-8. `51c9fecf` itself (the main merge plus this review's evidence) is the base and was not re-reviewed.
- Reviewer: the same independent reviewer. I authored no lane commit and made no commit or push. I changed no app code, flag or registration.
- Worktree: `/home/user/VA-Studio-review-free256b`, a detached worktree at `68c4e03c` made with `scripts/dev/mkworktree.sh`.
  - The mutations ran in a separate detached worktree at `68c4e03c`, `/home/user/VA-Studio-review-free256b-mut`. Every revert was proven with `git diff --quiet -- app database resources` (rc 0), and that worktree was then removed.
  - The old worktree `/home/user/VA-Studio-review-free256` was removed only after `diff -rq` showed its `independent-review/` was identical to the copy committed in `51c9fecf`.
- Native environment: a private `mysqld` 8.4.11 on 127.0.0.1:3731 with schema `rv256b`. It used the same `--no-defaults --socket= --mysqlx=OFF` pattern and its datadir was under `scratchpad/review-free256b-mysql/`. Other lanes' instances on 3711 and 3721, and 3306, were not touched.
- Evidence is in `review-evidence/addendum1/`.

### Verdict

**APPROVE WITH CONDITIONS carries to `68c4e03c` for a development merge.**

| Finding | Status | How it was checked |
| --- | --- | --- |
| F-1 | Closed | Mutations re-run against the fixed code |
| F-3 | Closed (domain enrollment) | Re-run probe |
| F-5 | Closed | Re-run probe and the `link()` reproduction |
| F-8 | Closed for 256 rows, with residual A1-7 | Code review and probe |
| F-2 | Closed for slot bounding and read-back | Re-run probe and the cross-process probe |

F-2 had two remaining gaps when reviewed at `68c4e03c`: crash residue and per-slot rather than aggregate free space (A1-2 and A1-3, which Codex also found). Addendum 2 reviews the fix for both.

The same exclusions as the original decision apply: this approves no activation, mount, real sources adapter, real terms or assets, and no verified-production provenance.

### Did each fix close its finding without opening a new path?

- **F-1 (release registry).**
  - Re-running U1, U2 and U3 against `68c4e03c` (`addendum1/mutations/`):
    - **U1** (legitimate renderer revision): existing owners keep library show and index, and their `master_wav` authorization (`ok`). Render of a pending origin is refused with `profile_changed`, before any claim row is written (`work=""`). At `1860e00d` every read was refused.
    - **U3** (manifest-only drift): reads `ok`, render `profile_changed` with no claim.
    - **U2** (implementation drift without a manifest update): reads `ok`, render `render_failed` (`claimed,failed`), as before. The manifest is unchanged, so `requireCurrent` passes and the child's runtime check refuses.
  - **Can `RELEASED` be bypassed?** Not without `APP_KEY`. A stored profile is reachable only through a sealed definition payload. Five checks bind it:
    - `validateStored` admits only a hash released for the profile's own provenance;
    - `graph()` requires profile provenance to equal the definition's;
    - `originGraph()` requires the origin's frozen profile to equal the definition's;
    - `originGraph()` requires the origin's `profile_hash` column to equal the hash of that profile;
    - the row seal and insert guard (`o.profile_hash = NEW.profile_hash`) bind the rest.
  - **Registry discipline.** A revision whose runtime profile is not appended to `RELEASED` fails closed: new proposals read `profile_unregistered`, and the lane test fails when the runtime profile is not listed.
- **F-2 (spool).**
  - Re-running the original probes (`rerun-probe-*.txt`) gave exactly the expected flips:
    - a fourth concurrent snapshot is now refused with `spool_busy` (Spool line 141);
    - the spool directory now holds `slot-0.lock`;
    - the 1 GiB transfer still passes through the spool.
  - Cross-process check: with three snapshots held in the parent, a forked child was refused `spool_busy` (`sqlite-addendum-probe.txt`), so the `flock` lease holds across processes.
  - The read-only reopen and read-back from the spool are present, as in the main-resident adapter.
  - The remaining gaps at `68c4e03c` are A1-2 and A1-3 below.
- **F-3.** The original probe `auth.mfa_not_required` now errors with `mfa_required`, raised from `ProductionFreeGrantStaff.php:42` under the shipped non-production panel rule. The lane's `StaffMfaTest` covers every staff action. The session challenge at the mount remains condition 4.
- **F-5.**
  - The original probe `render.symlink` now finds **no** file at the dangling target.
  - PHP `link()` returns false onto a dangling-symlink destination and creates nothing at the target. It also refuses an existing name (`php-link-semantics.txt`).
  - Publication is atomic: the fully written, fsynced, 0400 inode becomes `original.pdf` in a single `link()`.
  - Residual (Info): there is no directory fsync after `link`, which was also true before.
  - Crash between `link` and `unlink`: the claim's original has nlink 2, so `verify` refuses it. The DB publish happens only after `store` returns, so that claim fails and a new claim path is used; the temporary name is orphaned. Info.
- **F-8.**
  - `verify` computes `hash_equals` for every candidate key with no short-circuit. Timing reveals only the number of keys.
  - A key in neither list never verifies; the lane test and probe (`delisting → tampered`) confirm this. Tamper detection against unknown keys is unchanged.
  - The `request_key_hash IN (…)` lookup equals the old `=` lookup when no previous key is configured.
  - Residual A1-7 below.

### Findings in this delta (Low or above)

| ID | Severity | Finding | Recommendation | Evidence |
| --- | --- | --- | --- | --- |
| A1-1 | Low | **A renderer revision strands pending origins.** Origins that are not yet rendered stay unrenderable (`profile_changed`). Master, MP3 and stems delivery require a published original, so those owners also get no assets until the old runtime is restored. This is the intended immutability, so it is an operational gap. | Before deploying any profile revision, drain pending renders (operator runbook or command). Alternatively, keep the previous renderer runnable side by side. | `addendum1/mutations/U1.txt` (`pending.render=refused:profile_changed`, `pending.authorize.master_wav=refused:original_pending`) |
| A1-2 | Low | Codex P2-1 confirmed: a snapshot left behind by a killed worker makes its slot permanently skipped. With residue in all 3 slots, every redemption is refused `spool_busy` until an operator removes the residue. | Fixed in `5a2fadd2`; see Addendum 2. | `sqlite-addendum-probe.txt` (`addendum.spool`) |
| A1-3 | Low | Codex P2-2 confirmed: free-space admission is per slot. With free space fixed at size + reserve, two concurrent snapshots were both admitted although together they need 2 × size + reserve. | Fixed in `5a2fadd2`; see Addendum 2. | `sqlite-addendum-probe.txt` |
| A1-4 | Low | Codex P2-3 confirmed, and **pre-existing at `1860e00d`** (my original review missed it): a failed render appends `claimed` and `failed`, so only **16** attempts fit before `attempts_exhausted` (32 work rows). | Fixed in `5a2fadd2`; see Addendum 2. | `sqlite-addendum-probe.txt` (`addendum.attempts`) |
| A1-5 | Low | Codex P2-4 confirmed by code, and **pre-existing**: the library index took the first 1,000 origins by UUID (`CurrentRows` orders by `id`) before sorting by time, so an account with more than 1,000 origins could miss its newest. That needs more than 1,000 distinct definitions per account. | Fixed in `5a2fadd2`; see Addendum 2. | Code review (`ProductionFreeGrantLibrary::index` at `68c4e03c`) |
| A1-6 | Low | **A race loser gets a raw deadlock error.** In 2 of 3 native cap-1 rounds with a held definition lock, the losing process got a raw `PDOException 1213 Deadlock found` instead of an opaque refusal. The likely cause is a gap lock from the request-key `FOR UPDATE` read meeting the definition lock. The cap still held: 0 violations, all cap-2 rounds admitted both. This is not caused by the delta: with no previous keys, the `IN` lookup is equivalent to `=`. The first review's run simply did not hit the interleaving. | Before the mount, map SQLSTATE 40001/1213 to a fixed refusal (for example `busy`), or retry the transaction a bounded number of times. Never let raw driver text reach a response. | `addendum1/native-race-log.json` (rounds 1 and 3) |
| A1-7 | Low | **Previous keys keep forging power while listed.** A row sealed now with a listed previous key verifies (probe). Rows are append-only and cannot be resealed, so a 256 deployment must list every historical key for as long as its rows exist. A compromised old key therefore cannot be retired without stranding the rows it sealed. | Before production, use a dedicated, versioned seal key that is separate from `APP_KEY`, or add an operator re-attestation record that lets an old key be retired. | `sqlite-addendum-probe.txt` (`listed=true`, `after delisting=false`) |

### Rulings on the implementer's judgement calls

1. **Runtime equality also for `review`/`accept` and staff `open`: agreed.** Without it, a customer could assent to a definition this runtime cannot render, producing an origin that can never get its original (A1-1). `approve`, `close`, staff `read`, `revoke` and delivery correctly use only `validateStored`.
2. **`graph()` requires the stored profile's provenance to equal the definition's: agreed.** It is strictly tighter, and it closes reading a released profile under the other provenance's definition. The lane test refuses that case with `profile_unregistered`.
3. **Default root from `path.base`: agreed, Info.** In the application it equals `dirname(__DIR__, 4)`, and the bounded child has no container, so it falls back. If the two ever differed, only `current()` and `requireCurrent` would read the other tree. `RendererProcess` and the child still verify with the explicit file-relative root, so a mismatch can only refuse or strand, never admit an unverified render. Suggestion: outside `testing`, assert that `realpath(path.base)` equals the file-relative root.
4. **`Files::store` deletes its own temporary name on failure: agreed.** The removal is identity-checked (dev, inode and a regular file), the name is a fresh 128-bit random, and published or pre-existing files are never touched. The header comment was updated accordingly.
5. **Origin `profile_hash` column checked against its stored profile: agreed.** It duplicates the seal's binding as defense in depth, at no cost.
6. **Spool settings in the policy array: agreed.** They are validated with bounds (1 to 16 slots, reserve at least 16 MiB), and the `changed_policy` re-proof covers a change mid-command. Info: the slot bound is enforced by `flock` on the spool directory, so keep the spool on host-local storage. `flock` is not a reliable cross-host lease on network filesystems.

**The implementer's identity note: confirmed.** `grep` finds `config('app.key')` used directly in eight main-resident customer classes, including `ProductionIdentity/IdentityPolicy`, `CustomerAccess`, `CustomerIdentityChallenges`, the consent and suppression evidence classes, and `IdentitySmtpFactory`. After an `APP_KEY` rotation, customer 256 commands therefore fail with `identity_refused` before any 256 row is read, whatever 256 does. This is not a 256 defect. It makes a store-wide key rotation unsafe until the identity owner adds previous-key handling. That, plus A1-7, is a condition before production.

### Runs (exit codes captured on their own line)

| Selection | Driver | rc | Result |
| --- | --- | --- | --- |
| `tests/Feature/ProductionFreeGrants` at `68c4e03c` | SQLite | 0 | 113 tests, 747 assertions, 2 skipped (native-only); matches the lane |
| Original reviewer probes re-run unchanged (`rerun-probe-*.txt`) | SQLite | Assent 0, DefaultOff 0, Authorization 2, Rendering 1, Delivery 2 | The only failures are the four expected flips (F-3, F-5, F-2 ×2); everything else unchanged |
| `addendum1/probes/AddendumProbeTest.php` | SQLite | 0 | 2 tests, 14 assertions |
| Upgrade mutations control, U1, U2, U3 (`mutations/run-upgrade-mutations.sh`) | SQLite (file) | 0 each; revert rc 0 each | As listed above |
| `NativeConcurrencyProbeTest` | MySQL 8.4.11 :3731 | 0 | 1 test, 30 assertions. Cap violations 0; render race: 1 original; redemption race: 1 of 1 in 3 attempts; A1-6 observed |
| `ProductionFreeGrant{Spool,KeyRotation,ProfileRevision}Test` | MySQL 8.4.11 :3731 | 0 | 14 tests, 145 assertions, 0 skipped. The fixes are driver-sensitive here (`IN` lookup, `FOR UPDATE` reads, sealed reads) |
| Pint `--test` on the 16 changed PHP files | n/a | 0 | passed |

Native ran from 06:24:10Z to 06:52:46Z. `StaffMfaTest` and `FilesTest` were not run natively: they are filesystem- and model-level and unchanged by driver.

### SHA-256 at `f1666ae3`

The full list is in `addendum1/sha256-f1666ae3.txt`. Key files:

| Path | SHA-256 |
| --- | --- |
| `ProductionFreeGrantRenderProfile.php` | `ee849cc7a6f3f07f5b3dc7a6c3a24a6a9067c32d530f27712b5bfac65ff58c92` |
| `ProductionFreeGrantSpool.php` | `fbc293e1c79500c1574aec08d6469225a93bf3c0aef2d8610f70ce461294f734` |
| `ProductionFreeGrantRecords.php` | `7b8d686646bd14c31757ee4c40eaf400066b604148a023c833afa0e74ac8b516` |
| `ProductionFreeGrantFiles.php` | `833f7792fc248e3531bc9c996a9ac0ad101c707e6d6d29954396bd0065c197c1` |
| `ProductionFreeGrantStaff.php` | `5e4175ae6c1450341ef5a430c80bb9e7b5b990b130abc13968ce62a38f675cc9` |
| `ProductionFreeGrantDefinitions.php` | `425e4851bb7c7cf8d55c1349e6b43ce8066f43fcd9fafc64347247e8903af261` |
| `ProductionFreeGrants.php` | `5b2e954ff8ab75c57e5034e64d940f9e714573db0b461a02e803064b16e1529b` |
| `ProductionFreeGrantDownloads.php` | `b7dafab80efee195cee2762216f751a2df4f0787aa280f105c5d5fdf31639b82` |

### Conditions after Addendum 1

- **Condition 1 (F-1): satisfied.**
- **Condition 2 (F-2):** satisfied at `68c4e03c` except for A1-2 and A1-3; see Addendum 2.
- **Condition 4 (F-3):** satisfied for the domain. The session MFA challenge at the mount remains.
- **Added:**
  - A1-1: a drain runbook before the first profile revision.
  - A1-6: map deadlocks to a refusal before the mount.
  - A1-7 and the identity layer's key handling: before production.
- **Unchanged:** conditions 3 and 5, and F-4 and F-6.

### Evidence (`review-evidence/addendum1/`)

- **Runs:** `sqlite-directory.*`, `rerun-probe-*.{txt,junit.xml}`, `probes/AddendumProbeTest.php` and `sqlite-addendum-probe.*`.
- **Mutations:** `mutations/` holds `mutate.py`, `run-upgrade-mutations.sh`, and `prepare`, `control`, `U1`, `U2` and `U3` output with diffs and revert rc.
- **Native:** `native/` holds the runner scripts and the JUnit, text and rc files for the two native selections; `native-race-log.json` holds the race outcomes.
- **Other:** `php-link-semantics.txt`, `pint.txt` and `sha256-f1666ae3.txt`.

## Addendum 2: Codex review round `68c4e03c..5c8696a0` (code `5a2fadd2`)

- Scope: the fixes for Codex P2-1 to P2-4 (A1-2 to A1-5 above) in `5a2fadd2`, and the lane evidence in `5c8696a0`.
- Same reviewer and rules: no commit or push, and no change to app code, flags or registration. The review worktree was checked out detached at `5c8696a0`; this DECISION.md and the untracked `addendum1/` and `addendum2/` carried over unchanged.
- Native environment: the same private instance on :3731, which was then shut down and its datadir deleted (see Cleanup below).
- Evidence is in `review-evidence/addendum2/`.

### Verdict

**APPROVE WITH CONDITIONS carries to `5c8696a0` for a development merge.**

- All four fixes close their item. The spool fix answers the crash question: a dead holder's reservation does not leak.
- One new Low finding, A2-1: a test errors on MySQL.
- The availability window that Codex raised later (A2-5) is confirmed, and the implementer is fixing it.

### Judgement of each fix

1. **Residual snapshot reclaim (A1-2).**
   - Rule: a residual at exactly `slot-N.snapshot` is unlinked only while that slot's `flock` is held, and only if it is a regular, single-link file owned by the effective uid. Anything else leaves the slot skipped.
   - **Safe.** Holding the flock proves the previous holder's open file description is gone, because flock is per description and is released at process death. `unlink` removes only the directory entry. A planted symlink, directory or hard link is refused by `lstat` mode and nlink (lane test). A foreign-owned residue is kept and its slot skipped (probe 4: all 3 slots `spool_busy`, 3 residues kept).
   - Probe 2: after a SIGKILLed holder, the next admission reclaimed the residue and succeeded.
   - Re-running the A1 probe at this head flips as expected: residue in all slots now gives `contract:ok,master_wav:ok`.
   - Caveat (Info): this relies on `flock` being a real lease. Keep the spool on host-local storage, as ruled in judgement call 6.
2. **Aggregate reservation (A1-3).**
   - Mechanism: slot choice, the free-space probe and the sidecar write are serialized by `admission.lock`. `pending()` sums `slot-N.reserve` only for slots whose lock is **currently held** (a non-blocking probe under the gate).
   - **A crash between reserve and release does not leak.** In probe 1 a holder was SIGKILLed mid-fill. That left `slot-0.reserve = 00000000000001048576` and a residue, with its flock released. The next admission, with free space for exactly one snapshot, was **admitted**: the dead holder's reservation is ignored because its lock is free, and the next holder of that slot overwrites it.
   - A **live** holder mid-fill is counted: probe 3 gave `refused:spool_space`.
   - Written bytes stay reserved until completion, so they are counted twice for a moment. That over-counts, which is the safe direction (Info).
   - Info: `admission.lock` is a blocking `flock` with no timeout, and a malformed lock or sidecar file makes every admission fail closed (`artifact_unavailable`) until an operator intervenes. Both are acceptable for a bounded critical section.
3. **Attempt count (A1-4).**
   - The allowance now counts `claimed` rows (`< 32`). The CHECK allows ordinals 0 to 63, and `originGraph` reads 64 rows. 32 claims, each followed by a `failed` row, is exactly 64 rows (ordinals 0–63), so the read window and the guard match.
   - The A1 probe at this head now reports **32** attempts and 64 work rows.
   - **Native check, done:**
     - `AttemptLimitTest` (2 cases, including a full 64-row chain) passed on MySQL.
     - `test_migration_installs_nine_tables_with_three_guards_each_and_retry_is_a_no_op` passed on MySQL. That test exercises the installer's exact CHECK-clause comparison with the new `63` literal.
   - The in-place edit of migration `256000` is acceptable only because it has never been applied to a shared or production database (Info A2-2).
4. **Library window (A1-5).**
   - `newest()` orders by `created_at DESC, id DESC LIMIT n` in SQL, with `FOR UPDATE` on MySQL. The page is then fetched by `id IN (…)`, and `total` stays exact.
   - **Native:** `ProductionFreeGrantLibraryTest` (3 cases) passed on MySQL, so `newest()` runs natively. The lane's own regression does not (A2-1).
   - The optional page size (1 to 1000) is a constructor argument, not customer input. Agreed.

### Findings in this delta

| ID | Severity | Finding | Recommendation | Evidence |
| --- | --- | --- | --- | --- |
| A2-1 | Low | **`ProductionFreeGrantLibraryWindowTest` errors on MySQL.** It reads `sqlite_master` with no driver check (MySQL error 1064). It is not in the SQLite-only census, so the final Foundation CI MySQL shards would fail on it. | Mark it SQLite-only with a skip and add it to the native-only skip census, or make the guard drop/restore driver-portable. | `addendum2/native/native2-attempts-library-schema.txt` (4 tests, 82 assertions, 1 error, rc=2) |
| A2-2 | Info | Migration `256000` was edited in place (CHECK `0..31` changed to `0..63`). A database that ran it before `5a2fadd2` now refuses every 256 command with `schema` (fail-closed) and will not re-run the migration. | Before merge, confirm that no shared environment applied `256000`; recreate local and review databases. | Code review (`ProductionFreeGrantSchema` definition and the `up(false)` comparison) |
| A2-5 | Low (confirming Codex; fix pending as addendum 3) | **Availability history window.** `Definitions::graph()` reads availability with `all(…, 1000)`, which `CurrentRows` orders by `id` (UUID), while the CHECK allows ordinals 0 to 9,999. Past 1,000 events, the ordinal walk sees a UUID-chosen subset: `tampered`, or a stale `current`. | Being fixed. Also see the survey below. | Code review |

**Survey of bounded-window reads at `5c8696a0`** (asked by the coordinator):

- The **only** chain read whose window is smaller than its guard is availability (1000 against ordinals 0–9,999).
- The work chain read (64) exactly matches its CHECK (0–63).
- The library page now uses `newest()`.
- Every `one()` call reads `LIMIT 2` on a unique key and refuses duplicates: definition, review, origin, original, revocation, authorization and the request-key lookup.
- Counts (cap, rate limit, already-redeemed, total) are unbounded `COUNT(*)`.
- Performance note (Info): every `originGraph()` re-reads and verifies the whole availability chain of its definition (decrypt plus HMAC per row). The library index does this once per listed item, up to 50 times. The fix for A2-5 should bound or cache this.

### Runs (exit codes captured on their own line)

| Selection | Driver | rc | Result |
| --- | --- | --- | --- |
| `tests/Feature/ProductionFreeGrants` at `5c8696a0` | SQLite | 0 | 120 tests, 857 assertions, 2 skipped; matches the lane |
| `addendum2/probes/SpoolCrashProbeTest.php` | SQLite (forked processes) | 0 | 1 test, 5 assertions; observations as above |
| Addendum-1 probe re-run at `5c8696a0` | SQLite | 1 | Expected flips: residue reclaimed (`ok`); 32 attempts |
| `AttemptLimitTest`, `LibraryWindowTest`, schema install test | MySQL :3731 | 2 | 4 tests, 82 assertions, 1 error (A2-1); the other 3 pass. 06:55:14Z to 07:02:05Z |
| `ProductionFreeGrantLibraryTest` | MySQL :3731 | 0 | 3 tests, 31 assertions |
| Pint `--test` on the changed PHP files | n/a | 0 | passed |

SHA-256 of every changed file at `5a2fadd2` is in `addendum2/sha256-5a2fadd2.txt`:

| File | SHA-256 |
| --- | --- |
| `ProductionFreeGrantSpool.php` | `eacad81821edd18261f3a7a08a72a7215178acdad5e3597fdd1c37f01717337a` |
| `ProductionFreeGrantSchema.php` | `0c6af23f5350a5187612ebcc03ac042475166339ca7d2c42246e9a65b6beac20` |
| `ProductionFreeGrantRows.php` | `e2d9b02d8d98787c12e4efb17d587333956d6734ec529d83d3c9b4916c362f6a` |
| `ProductionFreeGrantLibrary.php` | `e3962dd8e8a6b15368194bcf9ac05db71ecec2d0d38f3c9f3ae357961f1ec998` |
| `ProductionFreeGrantDocuments.php` | `b07170870c7ce5568466325379a175e3596443d767137fa56d25754eef99409c` |
| `ProductionFreeGrants.php` | `b6ca6ab1f4185e1124616f195e1ca22ab18a4a8da9a1dfc1092486b46874aeb7` |

### Conditions after Addendum 2

- A1-2, A1-3, A1-4 and A1-5 are closed. Condition 2 (F-2) is satisfied.
- **Added:**
  - A2-1 must be fixed before the final integrated Foundation CI run.
  - A2-2 must be confirmed before merge.
  - A2-5 is pending addendum 3.
- Otherwise unchanged from Addendum 1: A1-1, A1-6, A1-7 and the identity key-rotation handling; conditions 3 and 5; F-4 and F-6.

### Cleanup

- Private instance :3731 (pid 22993) was shut down with `mysqladmin shutdown` (rc 0). Its error log ends with "MySQL Server - end", the process is gone, and nothing listens on 3731. Its datadir `scratchpad/review-free256b-mysql/data` was deleted.
- Other lanes' instances on 3711, 3721 and 3741, and 3306, were not touched.
- The lifecycle is in `addendum2/native/private-instance-lifecycle.txt`.
- The mutation worktree `-free256b-mut` and the old review worktree `/home/user/VA-Studio-review-free256` were removed.
- The review worktree is `/home/user/VA-Studio-review-free256b`, detached at `5c8696a0`. Its only changes are the modified `DECISION.md` and the untracked `review-evidence/addendum1/` and `review-evidence/addendum2/`. Nothing was committed or pushed.
