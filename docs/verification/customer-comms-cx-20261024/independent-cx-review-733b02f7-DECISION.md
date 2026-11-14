# Independent review: lane CX (customer communications and data rights)

- Branch `harness/customer-comms-cx-20261024`, worktree `/home/user/wt/cx`, HEAD `733b02f7884b055cbf48d9c8494b3f57f8dfd610`. The commits after `0bd4519c` change documentation only. Main base is `a8f17e5c`. PR diff: `git diff a8f17e5c...733b02f7` (89 files, +4594/−61).
- Reviewer: independent Claude subagent. I worked only in my own scratch worktrees and a private mysqld. `/home/user/wt/cx` is unchanged (`git status` is clean and HEAD is still `733b02f7`).
- Date: 2026-10-29.

## Verdict: APPROVE WITH CONDITIONS

There is no P0 or P1. The three migrations are additive and forward-only. On a populated MySQL 8.4.11 database they do not touch or rewrite any existing table, and a real `migrate:rollback` is refused before any DDL runs.

The guards hold on both engines. Authorization, ownership, single use, send-once and default-off behaviour all hold under the tests and my own adversarial checks.

Under the freeze-and-batch rule, the two P2s below can merge and go to the lane's follow-up batch. **Condition C1 (F1) must be fixed, with its regression test, before any environment sets the retention schedule.** Today, deletion approval refuses while the retention schedule is unset, so the defect cannot run yet.

## Findings

### F1: P2. Deletion approval leaves the customer's prepared export, with the full pre-erasure profile, in the database

- Where: `app/Domain/Customers/DataRights/CustomerDataRights.php:178-219` (`approveDeletion`). The export is written at `:68-71`. Purging happens only in `download()`, in `expireDue()` (called only from the same customer's next `requestExport`) and in `pruneExpired()` (`:247`). `app/Console/Commands/PruneCustomerDataExports.php:11` says the prune command is "Not scheduled automatically".
- Failure path:
  1. The customer requests an export. The request is `ready`, and the ciphertext holds the customer's name, email, orders, consent and order-inquiry message text.
  2. The customer then requests deletion, and staff approve it.
  3. Approval withdraws access and pseudonymizes `users.name` and `email`, but it never touches the open export.
  4. The `customer_data_exports.ciphertext` row stays decryptable with `APP_KEY`, and it also lands in backups.
  5. The export request stays `ready` with `open_slot = 1` for good. The customer can no longer download it, so `download()` never purges it. Their access is withdrawn, so `expireDue()` never runs for them either. Only a manual `vasey:customer-data-exports-prune` removes it.
- This contradicts the packet's "erases or pseudonymizes only mutable profile data that no retained record depends on". The export is mutable, it is not retained evidence, and it is absent from `RETAINED`.
- Reproduced with my probe test `ReviewerProbeTest` (kept at `cx-review/ReviewerProbeTest.php` in the scratchpad; not committed). After approval it printed `export ciphertext PRESENT (1024 bytes), export request state=ready, open_slot=1` and `decrypted export still contains original email: yes`.
- Fix: inside the approval transaction, lock and expire every open export of the account. That means `expire()`, which purges the ciphertext and moves the request to `expired`, with its event. Record it in `actions` (for example `'pending_exports' => 'purged'`) and add the probe as a regression test. Separately, schedule the prune command once a host exists (U-02). F4 tracks that.

### F2: P2 (docs accuracy). The corrected `PRIVACY.md` section 8 adds a new over-claim: "encrypted backups"

- Where: `docs/legal/PRIVACY.md:152` ("protected by server access controls and encrypted backups") and the appendix row at `:253` ("Backups are encrypted (`ops/staging/backup.sh`, age)").
- In the code, age encryption in `ops/staging/backup.sh` is opt-in and off by default:
  - `:20` reads "Optional hardening (age encryption …) is off unless /etc/vasey-staging/backup.conf …".
  - `:51` sets the default `VASEY_BACKUP_REQUIRE_ENCRYPTION:=0`.
  - The production backup target is still open (U-02, Project Notes).
- So the fix repeats, for backups, the kind of error it removes for files.
- The file part of the correction is accurate. `ContractFiles` writes plain `original.pdf` (`app/Domain/Contracts/ContractFiles.php:39-50`) and `AttachmentFiles` writes plain `original.bin` (`app/Domain/SupportAttachments/AttachmentFiles.php:42`).
- Fix: say backups "will be encrypted once off-host backup encryption is enabled". Alternatively, add "backup encryption required (`VASEY_BACKUP_REQUIRE_ENCRYPTION=1`) and a production backup target" to the appendix prerequisite, next to file encryption at rest.
- The text is an unapproved draft and is not published, so this does not block the merge.

### F3: P3. The staff-account refusal in deletion approval is untested

- Where: `app/Domain/Customers/DataRights/CustomerDataRights.php:188-191` (`if ($user->is_admin) … 'staff_account'`).
- Mutant R11 (refusal removed) survives `CustomerDataRightsTest` and `CustomerDataRequestAdminTest` (`OK (22 tests, 263 assertions)`).
- The case is reachable if a customer user is later promoted to admin. Approval would then replace an administrator's email and password.
- Fix: add a test.

### F4: P3. Expired export ciphertext stays until the same customer exports again or an operator runs the prune

- This is documented (`docs/customer-data-rights.md:20`, `PruneCustomerDataExports.php:11`).
- Fix: schedule `vasey:customer-data-exports-prune` with the host (U-02). Until then the 15-minute lifetime is a download window, not a storage limit.
- Related to F1.

### F5: P3. A delivery stuck in `submitting` has no visibility or recovery

- Where: `app/Domain/Notifications/Mail/TransactionalMailAdapter.php:182-198`.
- If a worker crashes after committing `queued → submitting` and before the transport call or the final transition, the row stays `submitting` forever.
- It is never reported as `uncertain`, and `docs/transactional-mail.md:10-11` does not mention it. The behaviour is safe, since the message is never resent. The gap is operational only.
- Fix: document it, or show `submitting` older than N minutes as uncertain in an operator listing.

### F6: P3. The deletion retention record omits some retained classes that hold personal data

- Where: `CustomerDataRights::RETAINED` (`:35-42`).
- The approval detail lists the retained classes. It omits `transactional_notices` (their encrypted capture holds the recipient email), `transactional_mail_deliveries` and `customer_data_exports` (the ledger rows).
- Nothing deletes these classes, so this is an accuracy gap in the retention evidence, not data loss.
- Fix: add them with their retention class once U-13 sets the schedule.

### Informational (no finding)

- **Mutant R3**: removing the schema-version check from `EditorialContent::legal()` survives, but the mutant is equivalent. v2–v4 releases have no `legal` key after schema validation, so the `is_array` check still returns null.
- **Mutant R9b**: removing `AdminMultiFactor::satisfiedBy` survives in `testing`. The panel does not require MFA there, and the extra enrolled-authenticator check still refuses (R9a is killed).
- **Mutant R16**: removing "every preflight check passed" survives. A hash-pinned genuine `DECLARED_FACTS_VALID` receipt never carries a blocked check. This is defence in depth.
- **SQLite forward compatibility**: the delivery insert guard refers to `transactional_notices` from another table, as the pre-existing `transactional_notice_attempts` guard already does (`2026_10_06_231000…:126`).
  - Any future SQLite rebuild or rollback of `transactional_notices` must first dispose of both triggers. That is what the nine roundtrip fixtures now do, and it reproduces the original error when it is not done (M16).
  - This is consistent with the existing design. It does not mask a production problem; see question 1 below.

## Focus questions answered

### 1. Migrations

- **268100** only creates and drops triggers. The successor is created before the predecessor is dropped. The preflight compares exact definitions on both engines, and temporary shadow tables are refused.
- `down()` refuses while a v5 release exists. Otherwise it restores the restrictive predecessor first. This matches `2026_10_01_000032`.
- **268200 and 268300** create new tables only, with foreign keys to `customer_accounts` and `users` (268200). Their `down()` methods throw, the same forward-only pattern as `264000` and `264100`.
- Populated native check:
  1. I migrated a fresh database to main `a8f17e5c`.
  2. I populated it through the application: 4 users, 3 customer accounts, site releases v1 and v2, and a publication.
  3. I ran `migrate` from the CX worktree.
  4. `CHECKSUM TABLE` and `CREATE_TIME` were identical for all 213 pre-existing tables. The changes were 4 new tables and 12 new triggers, and `site_release_images_valid_insert_v4` was replaced by `_v5`.
- **Rollback question**:
  - I inserted a deletion request, then ran `migrate:rollback --step=3` and `--step=1`.
  - Both runs **refuse at the first migration rolled back, `268300`** (`LogicException: Retain transactional mail delivery evidence; this migration is forward-only`), before any DDL.
  - Checksums are unchanged, all three CX migrations stay recorded and the `_v5` guard is still present.
  - So a real `migrate:rollback` cannot reach the notification family while `268300` is applied. On MySQL, triggers are not re-validated on DDL anyway.
  - The SQLite-only test change does not mask a production ordering problem.
- **Triggers, adversarial native SQL** against the migrated, populated database: all 11 forbidden writes were refused (`ERROR 1644` or `1062`):
  - deleting a request;
  - resolving without staff or a hash;
  - changing the account;
  - a case-changed state (`Completed`);
  - a second open deletion request;
  - an export row for a deletion request;
  - an event with a wrong sequence;
  - updating or deleting an event;
  - a mail delivery without its notice;
  - reopening a resolved request.

  The legitimate transitions were accepted: the first event at sequence 1, and `received → completed` with staff and a hash.

### 2. Data rights

- **Ownership**:
  - Every read in the export is keyed by the locked principal's account or owner key, and each order is re-proved through `PurchaseAccess::assertOrder`.
  - Download is bound to the account, the request id and a hashed 32-byte token. It is single use, and the purge happens in the same transaction.
  - Mutants R5, R6, R7 and R17 are killed.
- **Staff authority**:
  - Staff need the `administer-catalog` gate, the panel MFA enrollment rule and an enrolled authenticator, re-read under a row lock inside the transaction.
  - Approval also requires the expected version and is audited.
  - Mutants R8 (retention), R9a (enrollment), R9c (gate) and R10 (access left active) are killed.
- **Irreversibility guards**:
  - Retention values are checked before the transaction.
  - Approval changes only `customer_accounts.active` and `access_version`, deletes `customer_saved_tracks` and the user's `sessions`, and pseudonymizes `users`.
  - The test `test_approval_withdraws_access_…` proves the immutable-table snapshot (orders, order lines, finalizations, grants, verified payments, grant contracts, claims, consent events and states, inquiries, audit prefix) is byte-identical.
  - Consent is not touched.
- **Races**: 4 of 4 native race tests pass (see the commands). The lock order is user → account → request for both customer and staff commands.
- **Export contents**: no owner key, tokens or secrets. Another customer's data is absent (test asserts). The export is encrypted at rest, served `no-store, private` as an attachment, and lives 15 minutes, but see F1 and F4.

### 3. Mail

- **Default-off**: sending needs, in order:
  1. `enabled === true`;
  2. in `local` or `testing`, a `MailFake` (R12 killed);
  3. an SMTP mailer with a real-looking host and port;
  4. a sender;
  5. hash-pinned facts and receipt files;
  6. a `DECLARED_FACTS_VALID` operator receipt with the exact check list;
  7. sender, host, port and security matching the facts.

  On top of that, the intent re-verification (`TestTransactionalNotifications::status` → `TransactionalNotificationPolicy::requireEnabled` → `admitsTestCommerce`) runs before a delivery row is recorded and again before transport (R14 killed). Production is therefore inert even without a pre-launch hold, because no test intent can verify there.
- **Send-once**: a unique `notice_id`, row locks, the `queued`-only claim (R13 killed), one try, dispatch after commit, and no automatic retry of `uncertain`.
- **Header injection**: the recipient must equal `CustomerIdentityPolicy::email($email)`, the sender is regex-restricted, the order reference is a UUID, and the subject and header values are fixed or UUIDs.
- **Marketing consent**: nothing writes consent, and the template says it is not a marketing message.
- **Deploy scrub**:
  - `forge-deploy.sh` unsets `VASEY_CUSTOMER_DATA_*` and `VASEY_TRANSACTIONAL_MAIL_*`.
  - `capability-boundary.php` holds both flags (13).
  - The staging runtime validator, the profile validator and the production template state both as `false`.
  - The production validator reads the boundary but holds only its four pre-launch flags, which is adequate given the environment gate above.
  - The ops tests pass.

### 4. Legal pages

- **Published only**: the public route reads only `SiteContent::current()`. Drafts appear only through the staff preview (gate plus panel middleware).
- **No approval reference in public output**: `chrome()` exposes only titles and paths, and the projection omits the approval reference (R1 killed).
- **XSS**: text is bounded plain text (`<`, `>` and control characters refused) and React renders it as text nodes. No `dangerouslySetInnerHTML` exists in `resources/js`.
- **Street address**: none is added anywhere in the diff or the fixtures (pattern grep). The placeholder "[registered postal address — inserted at publication]" is used in `TERMS.md` and `PRIVACY.md`.
- **Encryption wording**: the files claim is accurate. The backups claim is not (F2).

### 5. Packet claims

All reproduced:

- SQLite CustomerComms suite: 96 tests, 765 assertions, 4 skips.
- Native: 13 of 13.
- Census: 306 entries; main's 302 are an exact prefix, followed by the 4 CX race methods.
- Native selection proof: 2636 of 9874 tests in 254 files.
- The Python safeguards and ops tests match the counts in the packet.
- `tsc` exits 0.
- My mutants R1–R17 are equivalents of the security-relevant packet mutations M1, M3 and M5–M14. Every non-equivalent one is killed except R11, which is F3.
- I did not re-run M2, M4, M15 or M16 myself. M16's failure mode is consistent with my analysis of the SQLite rename and trigger validation.

## Commands and results

All runs used my scratch worktree `cx-review/wt` at `733b02f7`, with vendor and node_modules hard-linked, and `public/build` absent. All paths below are under the scratchpad `/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad/`.

- **SQLite** (`cx-review/sqlite.sh`, the brief's direct-PHPUnit command):
  - `tests/Feature/CustomerComms` → `OK, but some tests were skipped! Tests: 96, Assertions: 765, Skipped: 4.`
  - The nine roundtrips plus `SiteRelatedTrackImageMigrationTest` and `TransactionalNotificationMigrationTest` → `OK (126 tests, 1605 assertions)`.
  - `SiteImageMigration`, `SiteContent`, `SiteEditorialHttp`, `SiteEditorialContent`, `InstallationReport`, `StagingRuntimeValidator`, `ProductionEnvironmentTemplate`, `TestCommerceProfileValidator`, `SiteImageRelease` and `TransactionalNotificationTest` → `OK (281 tests, 4352 assertions)`.
- **Native MySQL 8.4.11**: my own `/opt/mysql-8.4.11/bin/mysqld` (pid 4152), with its own datadir `cx-review/mysql/data`, port 3491 (checked free), socket `/tmp/cxrv.sock`, and `DB_DATABASE=VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_cxrv`, run via `cx-review/mysql.sh`:
  - `LegalSiteSchemaMigrationTest`, `CustomerDataRequestMigrationTest` and `CustomerDataRightsConcurrencyTest` → `OK (11 tests, 272 assertions)`.
  - `--filter test_database_guards` on `CustomerDataRightsTest` and `TransactionalMailTest` → `OK (2 tests, 27 assertions)`.
  - Real migration on `vaseyaudio_cxrvmig`, via `cx-review/artisan.sh` with APP_ENV=local:
    1. `migrate --force` from the main `a8f17e5c` worktree → exit 0.
    2. Populated through `cx-review/populate.php`.
    3. `migrate --force` from the CX worktree → three migrations DONE.
    4. `migrate:rollback --step=3 --force` and `--step=1 --force` → `268300 … FAIL LogicException: Retain transactional mail delivery evidence; this migration is forward-only.`
    5. Checksums are unchanged (`cx-review/checksum-*.txt`).
  - The adversarial SQL results are listed under question 1.
- **Probe** `ReviewerProbeTest` (SQLite) → fails as expected and shows F1.
- **Mutants** (`cx-review/mutants.py`, separate worktree `cx-review/mut`; results in `cx-review/mutants.log`):
  - Killed: R1, R5, R6, R7, R8, R9a, R9c, R10, R12, R13, R14, R17.
  - Survived: R3 (equivalent), R9b (equivalent in testing), R11 (F3), R16 (defence in depth).
- **Safeguards**:
  - `test-phpunit-shards.py` 52 OK, `test-ci-scope.py` 27 OK, `test-workflow-cadence.py` 14 OK, `test-focused-tests.py` 53 OK, `test-database-receipts.py` 61 OK.
  - `tests/ops`: `test_staging_capability_profile` 18 OK, `test_staging_code_drift` 10 OK, `test_production_kit` 50 OK, `test_production_runtime_validator` 28 OK.
- **Selection and census**:
  - `phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` → `Proved 2636 selected MySQL-native tests in 254 files (of 9874 discovered tests in 725 files) across 24 nonempty shards.` I deleted the generated files.
  - Census: 306 methods; `new[:302] == main` is True; the 4 appended methods are the CX races.
- **Frontend** (Node 24.22.0): `npx tsc --noEmit` exit 0; `npx vitest run tests/frontend/legal-pages.test.tsx` 3 of 3 pass.

## Not tested

- The full SQLite suite (I ran 1 + 21 files). The full native selection (I ran only the 13 CX native tests). The full vitest suite. Browser specs. Foundation CI.
- Any real SMTP or provider, staging or production host, `forge-deploy.sh` executed end to end, or `ops/production/validate-runtime.php` against a real host.
- Packet mutants M2, M4, M15 and M16, which I did not re-run myself.
- Filament UI rendering of the legal editor and the data-request list in a browser. Only the existing Livewire tests ran.
- Performance of `approveDeletion` and of the export on large accounts (500 orders or 2000 rows).

## Cleanup

My mysqld (pid 4152) is stopped and the three scratch worktrees are removed. See the final lines of `cx-review/PROGRESS.md`.
