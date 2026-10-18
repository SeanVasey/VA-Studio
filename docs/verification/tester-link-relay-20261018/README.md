# Evidence: staging tester sign-in link relay (lane K3)

Recorded 2026-10-18 by Claude for the lead's lane K3 assignment. No hosted staging run was made.

## Scope

**Branch.** `harness/tester-link-relay-20261018`, based on `origin/main` `c488277c`.

**Tested source.** `72dbb2eb3f1dbe2f9f02beca0b70eedd6ffc4434`, the two code commits:

- `0886b579` feat: the command and its domain helper;
- `72dbb2eb` test: the feature tests.

The working tree was clean during the final runs below. The later documentation commit (this packet, the runbook,
the purchase walkthrough and the changelog) changes no code.

**Problem.** On the hosted staging rehearsal, test identity messages for enrolment and recovery are not mailed.
`CustomerIdentityCapture` writes each one as JSON under `customer-identity-capture/` on the private local disk, and
an operator found and relayed each link by hand on the host.

**What was built.** `vasey:test-identity-links` (`app/Console/Commands/TestIdentityLinks.php`), with its rules in
`app/Domain/Customers/TestIdentityLinks/` (`TestIdentityLinkRelay`, `TestIdentityLinkMessage`,
`TestIdentityLinkRefusal`).

- `list` shows, newest first, each verified capture's message id (the challenge UUID), recipient, kind
  (`create account` or `reset password`), creation and expiry times in UTC, and status (`pending`, `expired` or `used`).
  It counts the captures it withholds and shows nothing from them.
- `print <id>` prints the link of that one message, on its own line, only while it is pending.

Operator use is in `docs/ops/staging-runbook.md` section 6a.

**New flag.** None. **Migration.** None. **Config, ops template, CI census or workflow change.** None.

## Design choices

**Admission reuses the existing policy.** `TestIdentityLinkRelay::admit()` runs before any read. It refuses
production first: either `config('app.env')` or the running environment is production. It then refuses a
disagreement between the two, so `--env` cannot bypass the check. Then it requires `TestEnvironment::admitsTestCommerce()`
(local, testing, staging) and the unchanged `CustomerIdentityPolicy::enabled()`: test accounts on, test identity on
and transport `private_capture`. It also requires the writer's own private local disk condition. These are the
checks of `SyntheticRehearsalPolicy::admit()`, with the capture policy in place of a new flag. A separate flag would
add no protection: when the capture policy is on, the messages already sit on a host that the operator can read.
When it is off, nothing is captured and the relay refuses.

**Operator identity follows the staging operator commands.** The command takes a named staff account
(`--operator=EMAIL`). The account must exist, pass `administer-catalog` (administrator with a verified email) and
satisfy `AdminMultiFactor` (the panel's MFA enrolment rule here, so it must be enrolled on staging). The console
must be interactive. The password is asked at a hidden prompt with no visible fallback, as `vasey:rights-scope`
and `vasey:staging-synthetic-catalog` ask it. Where the panel requires the staff factor
(`StaffWriteAuthority::attestationRequired()`, true on staging), `--attest` is required. It records one
`staff.console_attestation` before any capture is read. No writer consumes the attestation; it is audit
evidence only, as in the synthetic catalog command.

A lighter precedent exists: `vasey:rights-scope list` needs no password. It was not followed, because that listing
shows only scope keys and revision ids. This listing shows recipient addresses, and `print` reveals a bearer link.

**Synthetic accounts.** The schema has no per-account synthetic marker. `customer_accounts` and `users` carry none,
and `CustomerIdentityChallenge` records only the `customer-local-identity-v1` policy version. Customer accounts exist
only where `CustomerAccessPolicy` admits them, but a tester on staging may type any syntactically valid address. A
real person's address could therefore be captured. The relay treats a recipient as synthetic only when its domain
is an IANA-reserved, non-deliverable name:

- the top-level names `.test`, `.example` and `.invalid` (RFC 2606 section 2) and `.localhost` (RFC 6761 section 6);
- the second-level domains `example.com`, `example.net` and `example.org` (RFC 2606 section 3).

The repository's own fixtures already use these names (`@example.test`, `@example.invalid`). The private capture
transport sends no mail, so testers lose nothing by using them. The runbook tells testers to use one. A recipient
that holds a staff (`is_admin`) account is also withheld. This rule is new. It changes no identity behaviour, only
what the relay shows, and the reviewer should confirm it.

**Verification of a capture.** A capture is shown only when every check below passes. Otherwise it is withheld and
counted.

- The name is `<lowercase UUIDv4>.json`.
- The entry is a regular file, not a symbolic link. `lstat` and then `fstat` must show the same inode and device,
  the private media reader's pattern. The file is at most 8 KiB.
- The JSON is one flat object with exactly the writer's seven keys. `testOnly` is `true`, `id` equals the file
  name, and `purpose` is `enroll` or `recover`.
- `to` is already normalized by `CustomerIdentityPolicy::email()` and is a synthetic address.
- `url` is exactly `<APP_URL origin>/account/access#<purpose>.<id>.<64 hex>`.
- A retained challenge has the same public id, `customer-local-identity-v1` policy, purpose and decrypted recipient,
  an identical `expiresAt`, and `sha256(proof)` equal to its `proof_hash`.
- The challenge state is `pending` (shown as pending or expired) or `completed` (shown as used).

A linked capture directory refuses the whole command. The `print` id must match the UUID pattern before any read,
and no path is ever built from JSON fields.

**Read-only and audit.** Files are opened `rb` only. No capture, challenge, address fence, account, user or quote
owner is written. The only writes are append-only `audit_events` rows, following the audited staff reads of private
inquiries (`inquiry.inbox_viewed`, `inquiry.viewed`):

- `customer.test_identity.links_listed`. The subject is the operator. The context holds the shown, pending and
  withheld counts, the environment and the attestation id.
- `customer.test_identity.link_relayed`. The subject is the challenge. The context holds the challenge UUID, purpose,
  policy, account id (null for enrolment), expiry, environment and attestation id.

Neither row contains the link, the proof or the address. Each row is written inside a transaction that re-reads
the operator's authority under `lockForUpdate`. The relay row also re-reads the challenge state and expiry. Output
follows the commit, so a refused or failed audit prints nothing. Used and expired messages are refused before any
relay row is written.

**Output minimization.** The console never prints the storage root, the capture directory name, file names, raw
JSON, `APP_KEY` or any proof other than the selected link. An unexpected exception logs only its class and prints
a generic stop message.

## Environment

PHP 8.4.26 (CLI), PHPUnit 12.5.34, SQLite in memory. `public/build` was absent. The PHPUnit processes ran one at a
time from `/home/user/wt/k3`:

```sh
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M \
  -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <files>
```

## Results at `72dbb2eb`

### 1. New feature file (`tests/Feature/TestIdentityLinkRelayCommandTest.php`)

Started 2026-10-18T02:16:51Z. **15 tests and 1,330 assertions**: 0 failures, 0 errors, 0 skips (JUnit log), in
45.3 s. Exit code 0.

Captures come from the real `CustomerIdentityChallenges::request()` and `CustomerIdentityCapture` path; tampered
cases edit those files afterwards. The command runs on a real console input. The only stubbed call is the
password question, and the stub asserts that the question is hidden with no visible fallback. Every case asserts
the complete output, the exit code and the number of prompts. Where nothing may change, a case asserts that every
row of `users`, `customer_accounts`, `customer_identity_challenges`, `customer_identity_addresses` and `quote_owners`,
and every capture file's bytes, mode and modification time, are unchanged, and that the audit count rose only as
expected.

| Case | Assertions | What it proves |
| --- | ---: | --- |
| `test_production_is_refused_before_any_prompt_or_read` | 41 | `list` and `print` refuse in production with no prompt. A configured `production` with a running `staging` (the `--env` case) also refuses. No recipient or proof is printed; state and audit are unchanged. |
| `test_a_running_environment_that_differs_from_the_configuration_is_refused` | 21 | Four configured/running mismatches (staging/testing, testing/staging, local/staging, staging/local) refuse with no prompt and no change. |
| `test_environments_outside_local_testing_and_staging_are_refused` | 26 | `development`, `Staging`, `staging-eu`, `prod` and the empty string refuse. |
| `test_the_existing_capture_policy_gates_the_relay_before_any_read` | 78 | Six policy-off variants refuse `list` and `print` with no prompt and no recipient printed: identity off, the string `"true"`, null, transport `smtp` or null, and test accounts off. A non-local disk driver also refuses. |
| `test_operator_identity_requires_a_staff_account_its_password_and_an_interactive_console` | 62 | These refuse with no audit and no change: a missing `--operator`, an unknown account, a non-admin, an unverified admin, and a non-interactive console. A wrong, empty or null password, or the customer fixture password, refuses after exactly one hidden prompt. |
| `test_invalid_input_is_refused_before_any_read_including_a_traversal_id` | 25 | These exit 2 with "Nothing was read" and no prompt: an unknown action, `list` with an id, `print` without an id, `print ../../../../etc/passwd`, an uppercase UUID and a path-shaped id. |
| `test_staging_requires_an_enrolled_operator_and_an_attestation_then_lists_and_relays` | 64 | Under `staging`, an unenrolled operator refuses. An enrolled one without `--attest` refuses `list` and `print` after the prompt and writes no audit row; a wrong password with `--attest` writes none either. With `--attest`, `list` and `print` succeed. Each attestation names the operator, the command, `test_identity_links.list` or `.print`, the scope `test-identity-messages` or `test-identity-message:<id>`, staging and MFA required and enrolled, and precedes its read. The listing and relay rows link to their attestation ids. Identity state is unchanged. |
| `test_the_list_shows_only_verified_synthetic_messages_without_paths_raw_json_or_links` | 29 | An enrolment (`@example.test`) and a recovery (`@example.invalid`, an existing account) are listed with exact id, recipient, kind, UTC times and `pending`, newest first. A non-synthetic capture is withheld (count 1). No storage path, directory name, `.json`, raw JSON, `testOnly`, link, proof or app key appears. The listing audit row's exact actor, subject and context are asserted. |
| `test_print_relays_one_selected_pending_link_without_consuming_or_changing_anything` | 27 | The exact link appears once, on its own line, with the recipient. No other message's id or proof appears. Identity state is unchanged, and exactly one relay audit row has the asserted context, without the proof, address or link. Completing the relayed link afterwards still creates the account. |
| `test_expired_and_used_messages_are_listed_as_such_and_refused_for_printing` | 38 | `list` shows `used`, `expired` and `pending` (listing counts 3/1/0). `print` refuses the used link (with its completion time), the expired link (with its exact expiry), and a link that expired between listing and printing. No proof is printed and no relay row is written. |
| `test_a_non_synthetic_or_staff_recipient_is_never_listed_or_printed_even_with_a_crafted_capture` | 96 | Four captures, all withheld from `list` and refused by `print`, with no address or id printed: a genuine non-synthetic capture; a non-synthetic capture rewritten to claim a synthetic recipient (keeping its genuine proof); a synthetic capture whose address later became a staff account; and a crafted synthetic capture with no retained challenge. |
| `test_tampered_capture_json_is_withheld_and_never_printed` | 707 | Each of 18 tamperings is withheld from `list` and refused by `print`, with no path, JSON, proof, traversal or foreign host printed and no change: invalid JSON, a scalar, a list, an extra key, a missing key, `testOnly` false or `"true"`, a traversal id, another message's id, an unknown or swapped purpose, an unnormalized or nested recipient, a foreign origin, a traversal in the link, a wrong proof, an extended expiry, and more than 8 KiB. Restoring the original bytes makes it relayable again. |
| `test_linked_misnamed_and_non_file_captures_refuse_safely` | 79 | Four entries are withheld while a plain capture is still listed: a symbolic link to the genuine capture's own bytes outside the directory, a link to `/etc/hostname`, a misnamed copy of a genuine capture and a directory with a capture name. A linked capture directory refuses both actions entirely, though every capture behind it is genuine. |
| `test_only_reserved_test_names_count_as_synthetic_addresses` | 21 | Eight reserved forms are accepted. Thirteen look-alikes are refused, including `test.com`, `notexample.com`, `example.com.attacker.net`, `example.test.attacker.net`, an uppercase domain, a bare `test` label and an IP literal. |
| `test_no_captures_yet_lists_nothing_and_records_the_listing` | 16 | With no capture directory, `list` reports nothing and records one listing row, and `print` refuses. The relay creates no directory. |

### 2. Existing identity-capture regression files

Started 2026-10-18T02:17:48Z. The files were `tests/Feature/CustomerIdentityTest.php`,
`tests/Feature/CustomerIdentityHttpTest.php` and `tests/Feature/CustomerIdentityKeyRotationTest.php`, the three
files that reference the capture. **29 tests and 213 assertions**: 0 failures, 0 errors, 0 skips (JUnit log), in
1 min 36 s. Exit code 0. Per file: 17 tests / 90 assertions, 10 / 109, and 2 / 14.

### 3. Style and whitespace

- `vendor/bin/pint --test` on the five new PHP files: passed.
- `git diff --check c488277c..HEAD`: clean.

## Failures during development

These are recorded rather than hidden. All occurred before the commits and were fixed there.

- The first full run of the new file had 3 failures and 1 error, all defects in the test helpers. `assertRefused`
  assumed the expected text directly followed `Refused: `. The state census hashed a directory entry. The code was
  unchanged by the fix.
- Mutation checks (author sanity, on the working tree before Pint reordered imports) removed, one at a time:
  the synthetic-address check, the proof-hash check, the staff-address check, the capture-policy admission and the
  configured/running comparison. Each removal made at least one case fail. The first run showed that the
  non-synthetic case passed without the synthetic check, because its crafted captures were also caught by the
  recipient and challenge checks. A genuine, unmodified non-synthetic capture was added to that case, which then
  failed under the mutation. The mutations were not re-run at the final SHA; Pint changed only import order and
  docblock references.

## Untested conditions

- No hosted staging run. Forge file ownership, the `art` alias (`sudo -u forge`) reading files that PHP-FPM wrote,
  and a real terminal's hidden prompt were not exercised. The prompt was stubbed at the question helper.
  The staging templates run PHP-FPM and the workers as the same application user.
- No native MySQL run. The relay adds no concurrency-sensitive write. SQLite ignores `lockForUpdate`, so the
  operator-row lock path in the audit transaction is unexercised under contention.
- Collation: the operator lookup uses `where('email', ...)` like the synthetic catalog command. The staff-address
  check uses `LOWER(email)`, as the identity service does. Neither was run on MySQL.
- The completion re-checks in `CustomerIdentityChallenges::complete()` (recovery binding, enrolment of an existing
  address) are not duplicated. A link the relay calls pending can still be refused at completion; the runbook says so.
- No independent review yet. This change touches identity and customer data, so review of the exact SHA is
  required before merge.

## Not done here

- No push, PR, GitHub comment or workflow dispatch.
- No edit to an existing domain service, policy, guard, migration, route, config file, `ops/` template, CI census
  file or workflow.
