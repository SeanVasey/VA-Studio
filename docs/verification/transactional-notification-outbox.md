# Private test transactional notification foundation

This T32 / FP-075 child prepares durable notification work. It does not send
receipt email, wire an existing producer, enable a provider, infer consent or
complete CRM parity. The existing identity proof capture and contract-render
outbox remain separate and unchanged.

The only notice type is `test_order_ready`. `enqueueOrderReady(orderId,
CustomerPrincipal)` uses current customer access and purchase access, including
the exact existing claimed-order proof, then reconstructs the retained activated
test-order graph. Its event identity is the activation UUID plus account UUID.
It privately encrypts a fixed canonical envelope: the verified canonical
recipient, opaque account/order/activation references, fixed test template and
test-only marker. No proof, password, original owner key, bearer link, private
media path, order body, price or legal text is copied. Neither the event key nor
notice UUID grants order or delivery access. A ready notice records the retained
activation decision; it does not establish current private-file health or a
fresh download authorization.

Public domain methods are:

| Method | Result / boundary |
| --- | --- |
| `enqueueOrderReady(string, CustomerPrincipal): array` | `notificationSchema`, `notificationId`, fixed `type`, `testOnly`, `replayed`; an exact replay changes no intent, time, attempt or audit. |
| `claim(string, bool retryKnownFailure = false): ?NotificationLease` | Internal redacted, nonserializable lease; no replacement claim for an active, accepted or uncertain attempt. |
| `dispatch(string, bool retryKnownFailure = false): array` | Private local capture only; reports `notificationSchema`, `notificationId`, `testOnly`, `state`. |
| `complete(NotificationLease, string receiptHash): array` | Positive inspection of original canonical bytes and current claim/authority is required; a late or losing claim cannot override a winner. |
| `reconcile(string): array` | Reads the exact original capture. It never invokes storage delivery or reserves another attempt. |
| `status(string): array` | Internal operational projection, including positive original-byte verification for accepted capture. There is no HTTP/CLI entrypoint in this child. |

All commands require their own transaction boundary. Lock order is user,
account, order, existing immutable claim/activation evidence, notice, then
attempt. A short lease commits before filesystem I/O. Final raw reads recheck
authority, immutable intent and the complete expected attempt rows after
application callbacks. The complete final state/authority/notice/attempt-range
proof uses the primary PDO connection after all framework reads and their
`QueryExecuted` callbacks. Pure policy/capture checks precede that final proof;
no ORM/framework query follows it. Audit stores safe hashes, fixed state/reason and the
test-only marker; unexpected capture logs only the exception class.

The fixed local test policy allows 30-second leases and at most three attempts.
Only a definite pre-message private-storage refusal permits an explicitly
requested retry. Generic exceptions and expiration are uncertain. An uncertain
capture is never automatically redelivered; a matching original file can move
it to accepted through read-only reconciliation. Accepted means **private test
capture accepted**, not an email sent or received. Missing or altered bytes do
not establish acceptance or a safe resend.

The capture adapter requires a private local disk, canonical fixed envelope,
0700 owned directory and 0600 owned regular file. It rejects links and multiple
hard links, opens new messages exclusively, fsyncs bytes and never overwrites
an existing or partial crash file. There is no network, mail, notification or
queue channel. Defaults are disabled; production and all other transports are
refused even when configured enabled.

The additive migration owns only `transactional_notices` and
`transactional_notice_attempts`. Restrictive foreign keys retain the account,
user, original order, activation and optional exact purchase claim. Database
guards enforce byte-exact identities, insertion bounds, immutable intent and
claims, legal attempt transitions and SQLite replacement denial. Unexpected,
partial, temporary, modified or populated schema is not adopted or erased.
Rollback verifies owned schema and guards and refuses external child references
before any DDL.

MySQL table creation explicitly selects InnoDB. SQL storage retains one extra
character beyond a canonical UUID or hash, so padding cannot disappear before
the guards check the exact 36-byte UUID or 64-byte lowercase hash. A native
probe on checkpoint `779ffbce` observed a submitted 37-byte UUID with trailing
space stored as 36 bytes; that candidate's raw-insert guard test failed. Its
100-case native receipt retains 99 passing cases, the failure and all six actual
PRIMARY waits. Four further actual SQLite `QueryExecuted` probes and one genuine
MySQL withdrawal probe confirmed an earlier framework-only proof could commit
an intent after its final range read changed account authority, recipient,
attempt range or policy. These are retained negative source evidence; the
corrective PDO proof and padding/engine checks require fresh positive receipts.

The integrating lane owns empty-child rollback setup in the existing parent
migration tests: CustomerAccountMigrationTest, CustomerPurchaseClaimMigrationTest,
TestFulfillmentActivationMigrationTest, TestContractIssuanceMigrationTest,
TestOrderFinalizationMigrationTest, TestPaymentEvidenceMigrationTest,
HostedCheckoutMigrationTest, OrderPreparationMigrationTest,
SharedInventoryMigrationTest, PromotionMigrationTest and QuotePricingMigrationTest.
This component preserves those files and does not claim their composed
compatibility before the integration change is tested.

The first source checkpoint fixes the domain API and schema for review and
fixture integration. Focused working-source feedback passed four functional
SQLite cases / 44 assertions; retained initial failures were fixture key/setup
errors before the relevant notification assertion or enqueue. Full adversarial,
migration and genuine native race evidence is still being developed. A source
checkpoint or test definition is not final acceptance. Checkpoint `777225ea`
also passed the same four cases / 44 assertions on genuine disposable MySQL
8.4.11 with normal durability and clean shutdown. The extended first SQLite
run retained one test setup failure: its audit baseline preceded creation of a
second order. The comparison now begins after all fixtures exist. The first
19 migration cases / 256 assertions passed on SQLite.

The extended suite covers current recipient and access withdrawal, credential
changes, callback mutations, missing/partial/linked/public captures, definite
pre-message refusal versus unknown outcome, explicit retry bounds, expired
leases and positive original-byte reconciliation. New migration tests exercise
exact insert shape, immutable rows, replacement denial, legal transitions,
temporary/aliased/foreign objects, changed schema/guards and external references.
Six independent-process MySQL cases cover duplicate enqueue, competing dispatch,
withdrawal at enqueue/dispatch/reconcile and late completion versus the
reconciliation winner. Each requires an observed `performance_schema`
`data_lock_waits` record on the exact `users` PRIMARY key, distinct process and
connection identities, and the complete retained intent/attempt/audit result.
SQLite discovery of those six cases is not execution; it skips them explicitly.

Exact tested source,
commands, failures and remaining conditions will be recorded in the final
component packet. No hosted full matrix, provider action or production operation
was performed.

## Independent continuation review — October 6

The preserved corrected component source `f4c54f61985fca73a30789927b6b5c49305cafe4`
passed the focused SQLite functional/migration selection: 74 discovered cases,
68 passing cases, 576 assertions and six explicitly skipped native races. An
independent isolated rerun reproduced those same counts. The source-bound
original receipt is `corrected-f4c54f6-sqlite.xml`; that evidence does not prove
MySQL concurrency or acceptance of a later source change.

Independent review found that accepted `status()` inspected the private capture
after its last database authority fence. Exact negative test commit
`8701a275ab40dc74b1c6aaeb2f2c14dceda0e185` retained four actual failures: account
withdrawal, recipient change, notification-policy withdrawal and activation
account change during a positive inspection all returned accepted status.

Accepted status now follows positive original-byte inspection with a fresh
locked authority, canonical capture, accepted receipt and complete primary-PDO
proof. Inspection remains outside transactions. The final read creates no
intent, attempt, audit, capture write or replacement delivery. Nonaccepted
status retains its original read-only projection and lease-expiry semantics.
The dedicated regression checks the actual adapter callback, retained notice/
attempt/audit rows and unchanged original bytes; it also asserts that no mail
or notification was sent.

The continuation lane runs the affected functional class on the frozen
correction and requests an independent review of that actual tested commit.
The preceding component's completed native receipt passed 74 cases / 917
assertions with no skips, failures or errors and retained six actual independent
session waits on the exact `users` PRIMARY key. Its source and dependencies were
unchanged, and that evidence remains bound to `f4c54f6`; it does not certify the
later status correction. No new native run, hosted workflow, full matrix, producer wiring or
real email delivery is authorized by this status correction. Shared test
registrations and old parent-migration fixture ordering remain integration-owned.
