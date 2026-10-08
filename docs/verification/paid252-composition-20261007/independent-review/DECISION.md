# Independent review — Paid252 composition (`harness/paid252-composition` @ `4a4a4230`)

**Scope:** payment path, authorization/delivery, replay/idempotency, migration and schema guards, the
MySQL 8.0.46 version pin, the 60 s authorization budget, frontend sanity.
**Head reviewed:** `4a4a423022759ec706e7e235649552d0d2930128` (detached in `/home/user/VA-Studio-review-paid252`,
confirmed with `git rev-parse HEAD` before reading anything). Code diff reviewed as
`git diff f7224ed3 4a4a4230 -- app routes config database scripts resources tests/Support tests/Feature`
(51 files, 6,465 insertions; this isolates the Paid252 delta from the Free256/Tax255/Membership work that
the three main merges brought in). Nothing was committed or pushed. The only files I added are this
record, `review-evidence/` and `tests/Feature/PaidGrantReviewAdversarialTest.php`.
**Date:** 2026-10-08. **Reviewer:** Claude (independent reviewer lane, session
`session_01VqqWczBqWjDYScoPVae4UR`).

## Verdict

**APPROVE WITH CONDITIONS** — for a *development merge* of a default-off, unmounted family
(`config/paid-grants.php`: `rehearsal_enabled=false`, `operative_enabled=false`, `delivery_policy=null`;
`routes/paid-grants.php` is not required from `routes/web.php`; `PaidGrantPrivacy` is not prepended in
`bootstrap/app.php`). Nothing below is a release, mount or activation approval. Conditions are listed in
§ "Conditions before mount/activation".

No High finding. Two Medium findings are product risks that only matter once the family is mounted and
a delivery policy is authored; both are owner decisions, not consumer bugs. The money/entitlement
invariants hold in the reviewed source and in the adversarial cases I added.

## 1. Money and entitlement (invariants: integer minor units, durable verified payment before a grant, immutable snapshots)

* **No client-trusted totals.** The consumer never computes money. Amounts come from the frozen producer
  line (`ProductionPaidOrderSourceV1::line`, `line_amount_minor`, `line_tax_minor`, `amounts.currency`)
  and are projected verbatim (`PaidGrants.php:245`). The only client inputs on the paid surface are
  `orderId`/`batch`/`line` UUIDs, `requestKey`, `originHash`, `kind`, `nonce` and the one-use `token`;
  every one is shape-validated (`PaidGrantInput`, `PaidGrantForm`, `PaidGrantJson`) and `originHash`
  must equal the retained origin's `payload_hash` (`PaidGrantDownloads.php:72`).
* **Durable verified payment state before any grant.** Both entry points (`PaidGrants::finalizeOwned`
  line 59 and `PaidGrantCommands::run` line 55) read the order only through
  `ProductionPaidOrderSourceV1::lockedRead`, inside the consumer's held transaction, after the current
  buyer lock and the historical-binding verification. `lockedRead` itself refuses unless the intent's
  payment exists and `outcome === 'on_time'` (`ProductionPaidOrderSourceV1.php:37`, `payment_required`).
  `$source->proveRetainedCurrent($rows->current())` is kept after use in `PaidGrants::fence`
  (`PaidGrants.php:205`), which every command runs before commit. The commit itself needs the producer's
  committed-read receipt (`committedReadReceipt`, admission `PaidGrantConsumerCommitAdmission`) and the
  owner receipt is re-proved closed after commit and again before bytes (`PaidGrantProjectionRead::proveBeforeBytes`).
* **Order snapshot immutability.** `paid_order_origins` / `paid_grant_origins` / `paid_originals` /
  `paid_fulfillments` / `paid_authorizations` / `paid_redemptions` carry `*_immutable` BEFORE UPDATE and
  `*_retain` BEFORE DELETE triggers; `paid_document_work` allows only the pending→claimed→complete/failed
  transitions (`PaidGrantSchema.php:76-85`). Encrypted payloads are hash-pinned (`PaidGrantRecords::decode`)
  and `graph()` re-derives and cross-checks every hash on every read (`PaidGrants.php:131-187`). The
  schema-recovery file proves the guards on SQLite and, in this review, on MySQL 8.4.11 (§ Evidence).
  `PaidOrderOrigin` model refuses update/delete as a second line of defence.
* **Exclusive / one-use semantics.** Exclusive licenses cannot enter this family: `PaidGrantPolicy::source`
  requires `license.type === 'non-exclusive'` (`PaidGrantPolicy.php:48`), so exclusive serialization is
  out of scope by construction — there is no path that could silently revoke a prior grant. One-use of a
  download authorization is enforced three ways: `live()` refuses a consumed authorization (409), the second
  redeem frame re-checks `attempts < max_downloads` under `FOR UPDATE` and inserts the redemption
  (`PaidGrantDownloads.php:135-136`), and the schema has `paid_redemptions.authorization_once` (unique
  `authorization_id`) plus `paid_authorizations.token_once` (unique `token_hash`) and insert guards. My
  adversarial case proves the raw second insert is refused by the database.
* **Replays never duplicate grants.** `license_grants` stays at 0 across every journey (the family mints
  no grant rows; delivery authority is the redemption record alone).

## 2. Authorization and delivery

* **Token.** `token = base64url(HMAC-SHA256(app.key, "paid-license-token-v1\0".id."\0".nonce))`, 43 chars,
  stored only as `sha256(token)` (`PaidGrantDownloads.php:249-255`). Redeem compares the presented token's
  hash with the stored hash (`:115`), after `locate()` has independently proved the current buyer and
  found the authorization under the caller's account. Cross-authorization tokens, truncated/padded tokens
  and upper-cased ids are refused (403/403/422) without consuming anything (adversarial case 1).
* **Expiry versus consumed — ruling on item 2.** `live()` (`PaidGrantDownloads.php:220-224`) checks
  `now < expires_at` (410) *before* `no redemption row` (409). So once the 60 s lifetime has passed, a spent
  authorization replay returns 410, which is exactly what Step 5 saw natively. **This is acceptable
  semantics, not a defect:** both are unconditional refusals with the same body, no bytes leave, no attempt
  is consumed, and `status()` still reports the authorization as `attempted` (it ranks consumed above
  expired, `:43`). The tests' 409 expectation is correct only while the whole
  authorize→redeem→replay sequence sits inside the lifetime, so the two assertions
  (`PaidGrantDownloadJourneyTest.php:158`, `PaidGrantHttpJourneyTest.php:125`) are coupled to the wall clock
  rather than wrong. See L1 for the two ways to decouple them; my adversarial case 3 pins both orders
  deterministically with Laravel's time travel (409 inside the lifetime, 410 after it).
* **Entitlement re-proof before bytes.** `PaidGrantTransfer::writeTo` calls
  `PaidGrantProjectionRead::proveBeforeBytes()` (owner receipt closed re-read incl. `users`,
  `customer_accounts`, identity rows, the authorization row and the attempt snapshot; producer receipt;
  raw closed re-read; deadline) before the first chunk and checks the deadline per chunk. Covered by
  `test_held_transfer_rechecks_original_owner_before_first_byte…` and the two withdrawal cases.
* **Path/token shape validation; no private asset leak via previews.** Routes constrain every id to a
  v4 UUID; the middleware refuses query strings, method override, `Range`/`If-Range`, non-identity
  `Content-Encoding`, cross-site `Origin`/`Sec-Fetch-Site`, bodies > 64 KiB, duplicate JSON keys and any
  native form field other than `token`/`_token`. Targets are built only from the retained manifest
  (`target()`), `PaidGrantPrepareStream::target()` admits only `contract|master_wav|download_mp3|stems_zip`
  on disk `local`, and the preview role is never a target. The projection exposes `sha256`/`sizeBytes`
  only — no storage path (asserted by `assertDontSee($path)` in both journeys).
* **Private directories.** Spool and originals are 0700 directories, `x+b` creation, 0400 seal,
  inode-identity checks, unlinked read descriptor, public-root/symlink exclusion (`PaidGrantFiles::root`).

## 3. Replay / idempotency of commands and jobs; refused-frame handling (72fb2301)

* `authorize` is idempotent on (`account_id`, `request_key`) and refuses a different body under the same
  key (`request_hash`, 409); the same body returns the same token while live (adversarial case 4).
* `document` claims are leased (`claim_id`, 300 s), capped at 5 attempts, and `paid_originals.claim_once`
  plus the work-state trigger make a duplicate render claim unrepresentable.
* `redeem` is "one committed attempt": the redemption row is inserted in the second command frame and
  committed before bytes; a failed post-commit proof keeps the consumed attempt truthful (test
  `…retains_truthful_consumed_attempt`). This is the documented design, and the UI says so.
* **72fb2301** (`PaidGrantRows::abort` → `forgetRefusedFrameRecords`): with Laravel 13's
  `DatabaseTransactionsManager::rollback($connection, 0)` → `removeAllTransactionsForConnection`
  (`vendor/laravel/framework/src/Illuminate/Database/DatabaseTransactionsManager.php:128-131`), which is
  connection-scoped and runs no callbacks. Because every paid entry point first proves
  `outsideTransactions()` (all connections at level 0 and the raw PDO not in a transaction), the paid frame
  is the only record on that connection, so nothing belonging to a caller is lost; callbacks registered at
  depth 0 run immediately in Laravel and never linger. After a *successful* commit followed by a failed
  post-commit proof, `abort()` returns early (`committed`) and the `finally` is a no-op because the
  manager already cleared the committed record. The regression `PaidGrantRefusedFrameCallbackTest`
  (2 cases) passes on SQLite and natively (§ Evidence). **No record is lost or duplicated.**

## 4. Migration and schema guards

* One migration (`2026_10_07_252000_paid_grant_origins.php`) delegates to `PaidGrantSchema::install()`,
  which plans every DDL statement, computes the exact owned installation prefix after each statement, and
  refuses (throws, changes nothing) unless the live dictionary equals one of those prefixes. `down()` throws
  (`Retain immutable paid grant origins, originals and attempts.`), and
  `test_completed_installation…refuses_down` shows the ledger and rows survive a refused rollback.
* Drift refusal covers foreign columns, replaced guards, retained rows in a partial install, temporary
  shadows, external FK/trigger/view/routine dependencies, and dictionary-collation aliases (case/accent)
  on MySQL (`PaidGrantSchema.php:412-426`).
* `test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target` is **SQLite-only and skips on
  MySQL** (observed: 1 skip in the native file run). `scripts/ci/database-sqlite-skips.json` lists
  MySQL-only methods that skip on SQLite (the branch correctly added the native-only identity case at
  commit 1b707da5); it has no slot for a SQLite-only method, and the MySQL shards expect zero skips. **Noted
  for the census normalization; not changed here (L4).**

## 5. Ruling on the MySQL 8.0.46 pin (item 5)

`PaidGrantSchemaRecoveryTest::test_native_schema_global_exact_case_accent_fk_and_routine_identities_refuse_before_first_owned_ddl`
asserts `VERSION()` starts with `8.0.46` (`tests/Feature/PaidGrantSchemaRecoveryTest.php:255`). CI and
production are MySQL 8.4. I ran the case on a private `mysqld` 8.4.11 with the pin **temporarily
relaxed to `/\A8\.(0\.46|4\.11)/` in my worktree only** (diff kept as
`review-evidence/item5-temporary-relaxation.diff`; the edit was reverted with `git checkout --` and
`git status` shows the test file unchanged):

* **Result: the refusal behaviour holds on 8.4.11** — 1 test, 42 assertions, rc 0, 0.55 s
  (`review-evidence/item5-native-8.4.11-identity-case.{txt,xml}`). All four foreign identities
  (`paid_grant_origins_batch` exact, `PAID_GRANT_ORIGINS_BATCH` case alias, `páid_grant_origins_batch`
  accent alias as FK constraint names on an external table, and a `paid_order_origins` stored procedure)
  refuse before the first owned DDL with every pre-existing object, row and ledger entry byte-identical and
  no owned table created.
* The whole file also passes natively on 8.4.11 with the relaxation in place: 10 tests, 9 passed,
  1 skipped (the SQLite-only composite case), 613 assertions, rc 0, 26 s
  (`review-evidence/item4-native-8.4.11-schema-recovery-file.{txt,xml}`).
* The check depends on `information_schema` plus `CONVERT(... USING utf8mb3) COLLATE utf8mb3_general_ci`
  equality (`PaidGrantSchema.php:414-426`); nothing in it is 8.0-specific.

**Recommendation (minimal change, for the lane owner — not applied here):** relax the pin to the
supported family rather than keep 8.0.46. One line:
`$this->assertMatchesRegularExpression('/\A8\.(0|4)\./', DB::selectOne('SELECT VERSION() AS version')->version);`
and record the observed version in the assertion message. Keeping 8.0.46 would make the only native
identity-collision evidence permanently red on the CI/production family, which is worse than the small
loss of a point pin. If the owner wants an exact pin, pin `8.4.` (the CI family) and record 8.0.46 as a
historical pass in the handoff.

## 6. Ruling on the 60 s authorization budget (item 6)

**Product risk, amplified by the test environment — not only a test artefact.** Two independent facts:

1. **Redeem still costs ≈18.7k statements on MySQL** (Step 5 tables, unchanged by Tax255/Membership),
   about 98 % of them identity/producer dictionary inspection (`IdentityMigrationOwnership::inspect` on every
   `IdentityRows` / `IdentityHistoricalPlainRows` assertion). At 0.8 ms/statement on an idle local daemon
   that is ≈15 s; at the 2.1 ms/statement Step 5 measured under load it is ≈40 s. A production MySQL on a
   separate host adds network RTT to every one of those round trips, so redeem alone can plausibly take
   20–40 s of a 60 s lifetime on an *idle* production-shaped deployment. The 3–7 s margin is therefore not
   a property of the loaded 4-core host; the host only decides which side of zero it lands on.
2. **The authorization lifetime bounds the byte transfer, not just the entitlement proof.**
   `redeem` shortens its budget to the authorization expiry (`PaidGrantDownloads.php:128`), the spool copy
   is capped at `PaidGrantPrepareStream::MAX_SECONDS = 60` and the authorization deadline (`:32`), and
   `PaidGrantTransfer::writeTo` refuses per chunk after the deadline (`PaidGrantTransfer.php:26-28`) while
   the attempt was already consumed at the second commit. For a 1 GiB stems zip
   (`DeliveryAssetFiles::MAX_BYTES`) even the maximum policy value (600 s) needs ≈14 Mbit/s sustained; at
   60 s it needs ≈140 Mbit/s. A slow client loses a consumed attempt with no file. Free256 on `main`
   already separates the two (`config/production-free-grants.php:17-22`: base 30 s + size at
   256 KiB/s, capped at 2 h; `ProductionFreeGrantTransfer.php:11`).

**What the owner should decide (no deadline, lifetime or test was changed by this review):**

* **Lifetime.** `authorization_seconds` is policy (30–600). Raising it alone does not fix (2) for large
  assets and only hides (1).
* **Identity inspection cache** per `docs/verification/native-schema-isolation-20261007/README.md` §4
  (one full inspection per frame + a short revalidation proof + full inspection after commit). That is the
  change that moves redeem from tens of seconds to seconds and belongs to the identity/producer owner;
  it needs its own adversarial tests and identity review, so it is a pre-activation dependency, not a
  Paid252 change.
* **Transfer deadline.** Whether the paid stream should get a size-derived transfer bound independent of
  the authorization lifetime (Free256 pattern). Until decided, the consumed-without-receipt outcome is a
  known product behaviour and the UI copy already states it.
* **Test assumptions.** `PaidGrantRedeemFrameConjunctTest`, the HTTP journey case and the DownloadJourney
  replay case assert that the whole native sequence fits inside the lifetime; they are sound as *product*
  acceptance of a tuned deployment and unsound as *gate* tests on a shared loaded host. Keep them manual /
  diagnostic until (1) or the lifetime decision lands, or make the replay assertions time-aware (L1).

## Findings by severity

### High — none.

### Medium

* **M1 — Transfer is bounded by the authorization lifetime and the attempt is consumed before bytes.**
  `app/Domain/Grants/Paid/PaidGrantDownloads.php:128-136`, `PaidGrantTransfer.php:26-28`,
  `PaidGrantPrepareStream.php:18,32`. Product risk once mounted (§6 item 2). Condition C1.
* **M2 — MySQL redeem cost (≈18.7k statements, identity/producer inspection) leaves a 3–7 s margin inside
  60 s on this host and may leave none on a networked production database.** Not a Paid252 defect; the
  consumer's own statements are < 2 %. Condition C2.

### Low

* **L1 — `live()` evaluates expiry before consumption** (`PaidGrantDownloads.php:220-224`) while
  `status()` ranks consumed above expired (`:43`). Both are safe refusals. Either swap the two `require`
  lines (consumed-first makes the spent-replay result deterministic and consistent with `status()`,
  and the two journey assertions stop depending on the 60 s wall clock) or make those two assertions
  time-aware. Owner's call; my adversarial case 3 documents today's order.
* **L2 — A refused stream after headers returns 200 + `Content-Length` with an empty body**
  (`app/Http/Controllers/PaidGrantController.php:118-130`). No private bytes leak and the attempt is
  truthfully consumed; the browser shows a 0-byte attachment. Acceptable; UX note only.
* **L3 — Idempotent `authorize` replay recomputes the token with the *current* `app.key`**
  (`PaidGrantDownloads.php:93-94`): after a key rotation a replay of a live request returns 503 while
  `redeem` (hash comparison) still works. Document with the key-rotation runbook.
* **L4 — Census.** `test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target` is SQLite-only
  and skips on MySQL; the MySQL shards expect zero skips (`CLAUDE.md` §3 adaptation). Normalize when the
  family joins the sharded gate; not changed here.
* **L5 — One unexplained `zend_mm_heap corrupted` (rc 134)** in Step 5 came from the test-only
  `PaidGrantCommitFrameProbe` (asynchronous `SIGUSR1` sampler driven by a `bash` ticker,
  `tests/Support/PaidGrantCommitFrameProbe.php:57-64`). No production code is involved. Keep the conjunct
  probe out of the sharded gate or run it without the sampler there.
* **L6 — Frontend display divides minor units by 100 with two decimals** (`PaidGrantJourney.tsx:168`);
  the type pins `currency: 'USD'`, so this is display-only and consistent, but it is not currency-aware.

### Informational

* Routes are throttled per endpoint; `page` stays on Inertia, all JSON/attachment routes run
  `withoutMiddleware(HandleInertiaRequests)`; the `block(20, 5)` session lock is applied.
* `PaidGrantPolicy::enabled()` keeps rehearsal and operative flags disjoint by environment; `provePure`
  re-reads the raw repository at commit so a flipped config inside a callback refuses the frame.
* The renderer runs in a `php -n` child with `open_basedir`, `disable_functions`, no inherited environment
  and a 60 s timeout (`PaidGrantRendererProcess.php:49-85`); `resources/contracts/paid-v1/profile-assets.json`
  pins the six implementation files by SHA-256 and all six match the tree at this head (checked).
* The V2 (Tax255) paid consumer is not wired (README Step 5); this consumer is V1-only, so the Tax255
  A1-1 condition does not apply to it.

## Conditions before mount/activation

* **C1 (M1)** Owner decision on a transfer deadline independent of `authorization_seconds` (Free256
  pattern) or an explicit accepted policy with the lifetime value, recorded with the delivery policy.
* **C2 (M2)** Identity inspection cost resolved (README §4 proposal) or accepted after a native margin
  measurement on the production-shaped database, not on this shared 4-core host.
* **C3 (item 5)** Relax the 8.0.46 pin to the supported family so the native identity-collision case runs
  in CI; until then it is permanently red on 8.4.
* **C4** Mount wiring: prepend `PaidGrantPrivacy` in `bootstrap/app.php`, add the `withExceptions`
  sanitization the HTTP tests install by hand (`respondUsing`; compare `FreeGrantPrivacy` at
  `bootstrap/app.php:72-73,175`), require `routes/paid-grants.php` from
  `routes/web.php`, and re-run `PaidGrantHttpJourneyTest` against the real bootstrap instead of the
  test-installed middleware.
* **C5 (L4, L5)** Census normalization for the SQLite-only skip and a decision on the conjunct probe in
  the sharded gate.
* **C6 (L1)** Decide consumed-first or time-aware replay assertions so the two journey cases stop
  depending on the 60 s wall clock.
* **C7** An authored `delivery_policy` (`provenance=verified_production`, `max_downloads`,
  `authorization_seconds`) and `production-customer-identity.*` operative settings are Sean's explicit
  authorization per AGENTS.md (live path); none exist on this branch.

## Evidence (all under `review-evidence/`)

All runs: PHP 8.4.26, worktree autoload (`$GLOBALS['_composer_autoload_path']` = worktree
`vendor/autoload.php`, then `vendor/phpunit/phpunit/phpunit`), `public/build` absent, head `4a4a4230`.
`rc` is on its own line in every `.txt`.

| Run | Driver | Selection | Result | Assertions | rc | Wall | Files |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Paid family | SQLite | `--filter PaidGrant tests/Feature` (9 files) | 65 tests, 64 passed, **1 skipped** (native-only identity case) | 3,591 | 0 | 332 s | `sqlite-paid-family.{txt,xml}` |
| Adversarial (new) | SQLite | `tests/Feature/PaidGrantReviewAdversarialTest.php` | 4/4 passed | 99 | 0 | 58 s | `sqlite-adversarial.{txt,xml}` |
| Item 5 case | MySQL 8.4.11 | identity-collision case, pin relaxed | 1 passed | 42 | 0 | 0.55 s | `item5-native-8.4.11-identity-case.{txt,xml}`, `item5-temporary-relaxation.diff` |
| Item 4 file | MySQL 8.4.11 | `PaidGrantSchemaRecoveryTest.php` (pin relaxed) | 10 tests, 9 passed, 1 skipped (SQLite-only case) | 613 | 0 | 26 s | `item4-native-8.4.11-schema-recovery-file.{txt,xml}` |
| (a) conjunct | MySQL 8.4.11 | `PaidGrantRedeemFrameConjunctTest` | **1 failure**, line 138: deadline conjunct, observer 0.005 s past its original deadline in `historicalReceipt`; phase and dispatcher conjuncts all passed | 17,448 | 1 | 467 s (load 4.8 → 7.3) | `native-a-conjunct.{txt,xml}` |
| (b) HTTP journey | MySQL 8.4.11 | `test_actual_typed_buyer_http_finalization…` | **1 failure**, line 125: spent-replay 410, expected 409 (finalize, document, authorize, 200 redeem and exact bytes at lines 108–124 all passed first) | 48 | 1 | 402 s (load 7.3 → 5.9) | `native-b-http-journey.{txt,xml}` |
| (c) refused frame | MySQL 8.4.11 | `PaidGrantRefusedFrameCallbackTest` | 2/2 passed | 44 | 0 | 530 s (load 5.9 → 6.9) | `native-c-refused-frame.{txt,xml}` |
| Frontend | vitest 5.0.1 / Node 24.21.0 | `tests/frontend/paid-grant-journey.test.tsx` | 9/9 | | 0 | 14 s (6.1 s run) | `frontend-vitest.txt` |
| Pint | — | `vendor/bin/pint --test tests/Feature/PaidGrantReviewAdversarialTest.php` | passed | | 0 | | `pint-adversarial.txt` |

Per-file SQLite breakdown (from `sqlite-paid-family.xml`): CommitCapsule 11/11 (241), CommittingAdmission
2/2 (36), DocumentJourney 4/4 (87), DownloadJourney 5/5 (112), HttpJourney 18/18 (534), RedeemFrameConjunct
1/1 (970 — the probe samples more frames under load), RefusedFrameCallback 2/2 (42), SchemaRecovery 9/10 +
1 skipped (1,373), SourceJourney 12/12 (196).

### Adversarial cases added (kept, untracked: `tests/Feature/PaidGrantReviewAdversarialTest.php`)

1. `test_a_token_minted_for_one_authorization_is_refused_against_another_and_the_database_refuses_a_second_redemption_row`
   — cross-authorization tokens (403 both ways), 42-char and 44-char tokens (403), upper-cased id (422), all
   without consuming; the owner then streams the exact master; a raw second `paid_redemptions` insert for the
   same authorization is refused by the schema; `license_grants` stays 0.
2. `test_another_enrolled_account_cannot_locate_redeem_or_read_a_valid_authorization_and_the_owner_still_can`
   — a second SMTP-enrolled account gets 404 on redeem/status/show/authorize and an empty index; the owner's
   authorization still streams afterwards.
3. `test_expiry_refuses_without_consuming_and_a_spent_authorization_replayed_after_expiry_reports_expiry_before_consumption`
   — time travel past `authorization_seconds`: unspent → 410 and nothing consumed; spent → 409 inside the
   lifetime and 410 after it (documents the item 2 order); `status()` reports `attempted` / `unused`.
4. `test_tampered_authorize_inputs_refuse_and_the_download_budget_is_exhausted_at_max_downloads`
   — wrong `originHash` (409), extra key / unknown kind (422), same `requestKey` with a different kind or
   nonce (409), three authorize+redeem cycles, fourth authorize refused by the retained policy (409),
   `attemptCount`/`maxDownloads` 3/3, `license_grants` 0.

### Native environment and cleanup

Private `mysqld` 8.4.11 (`/usr/sbin/mysqld`, `--no-defaults --initialize-insecure` then
`--no-defaults --user=root --datadir=<scratchpad>/review-paid252-mysql/data --port=3761
--bind-address=127.0.0.1 --socket= --mysqlx=OFF --innodb-buffer-pool-size=512M`, pid 21223), database
`vaseyaudio_review_paid252`, env `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3761 DB_PASSWORD=` (empty)
plus `APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`. Port 3306 and the
other lanes' daemons (3751, 33071) were not touched; no broad `pkill` was used. A first start failed only
because the scratchpad socket path exceeds 107 bytes; the restart used `--socket=` as the sibling lanes do.
The native trio ran one PHPUnit process at a time through `helper-run-native-trio.sh.txt`
(12:35:03 → 12:58:22 UTC). The host was shared with other lanes; load average was 4.8–7.3 on 4 cores.

**Native trio reading.** (a) and (b) failed on the same exhausted 60 s budget Step 5 describes, and their
failure points are consistent with it. (a) crossed by 5 ms at the first guarded producer frame, with every
phase and dispatcher conjunct passing. (b) delivered the exact master bytes with a 200 and then hit the item 2
expiry-before-consumed order on the spent replay. This is the third independent host run on which the
journey passes or fails on load alone (Step 5 at `cb0c9759` and `21e24e8c`, and this one), which supports
the §6 ruling. (c), the 72fb2301 regression, passes natively.

**Cleanup.** `mysqladmin -h127.0.0.1 -P3761 -uroot shutdown` (rc 0). Pid 21223 is gone (`kill -0` fails),
`mysqladmin ping` cannot connect, and nothing listens on 3761. The scratchpad directory
`review-paid252-mysql/` (datadir, pid, error log and helper copy) was deleted with `rm -rf` and is gone. The
other lanes' daemons (pids 1886 and 29814) and 3306 were left untouched. The temporary relaxation in
`PaidGrantSchemaRecoveryTest.php` was reverted (`git checkout --`). The review-only `node_modules` symlink
(gitignored) was removed. `git status --short` at `4a4a4230` shows only `?? .../independent-review/` and
`?? tests/Feature/PaidGrantReviewAdversarialTest.php`.

### Not done / limits

* The adversarial file was run on SQLite only (each native case migrates the full schema and takes
  5–10 min; the host carried other lanes at load 4–5 on 4 cores).
* No concurrency claim: SQLite in-memory cannot exercise two redeems racing; the MySQL backstops are
  `FOR UPDATE` on every owned read plus the two unique indexes above.
* `vite build`, hosted CI and the other 17 native HTTP cases were not run (cost policy; same as Step 5).
* `PaidGrantPdfRenderer`/`PaidGrantText` were read for file-system and process boundaries only, not for
  document-content correctness.
