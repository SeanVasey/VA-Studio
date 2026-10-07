# Persistent service source table correction

Runtime/test child `9ce31963abc3ba89f22ed9b39c009023cedef77d` changes only
`ServiceProjectAttachmentSourceV1` and its existing committed-read test. The owned
predecessor is exact `8444f15ea3f5f3c74994fef48705b2a193de7cba`; all public source,
receipt and attachment contracts, retained graph commitments, deadlines and
original transaction/token expiry behavior remain unchanged.

The independent reviewer reproduced a genuine native failure: after a current
receipt was committed, renaming the permanent event table and creating a same-name
view over its exact retained rows passed `proveClosed`. MySQL's `SHOW CREATE TABLE`
returns a view definition, and the predecessor excluded only temporary tables.
The original independent canary and red receipt are retained by the reviewer.
The author's added event-view case independently failed against frozen 8444:
**1 failed / 5 assertions / 0 errors**. Its unmodified source is part of this child;
the original red output is retained here.

The native closure now requires exactly one dictionary row with the captured
schema spelling, physical table spelling and `BASE TABLE` type. The subsequent
qualified `SHOW CREATE TABLE` must identify that exact name and begin with
`CREATE TABLE`, so a temporary shadow still refuses. These raw metadata checks
precede the existing permanent actor/account/source reads. They do not create a
new transaction, invoke a connection resolver, renew authority, adopt a renamed
table or alter any survivor. SQLite's existing exact persistent table-type check
is unchanged.

Frozen 9ce author evidence on actual Oracle MySQL
**8.0.46-0ubuntu0.24.04.4 (Ubuntu)**, isolated synthetic
`vaseyaudio_service_projects`: **7 cases / 44 assertions**, all passed. Four cases
replace each captured table (`users`, `customer_accounts`, `service_projects`,
`service_project_events`) with a genuine durable view over byte-identical original
rows. Each confirms the dictionary's `VIEW`, refusal at idle depth zero/no physical
transaction, and exact `SHOW CREATE TABLE`/row preservation after restoring the
real table. Each restored original receipt closes successfully again. The bounded
selection also includes successful customer/operator closure and the prior native
temporary stale-user shadow refusal.

Affected SQLite selection: **36 recorded / 32 executed / 143 assertions**, passed;
the four native dictionary cases are explicitly skipped. All existing source and
receipt regressions are included. Scoped Pint and `git diff --check` passed.
These are focused engine-labelled checks, not a full native matrix or concurrency
proof. The peer owns the byte-identical independent canary rerun on its separate
schema and the parent owns composed source review; this receipt does not claim
either has already approved the child.

```sh
php -d auto_prepend_file=/tmp/service-support-contract-autoload.php vendor/bin/phpunit \
  tests/Feature/ServiceProjectCommittedReadTest.php \
  --filter '/test_permanent_native_view|test_receipt_closes_original_read|test_current_operator_read|test_temporary_copy/'
php -d auto_prepend_file=/tmp/service-support-contract-autoload.php vendor/bin/phpunit \
  tests/Feature/ServiceProjectCommittedReadTest.php \
  tests/Feature/ServiceProjectAttachmentAuthorityTest.php
```

Commands used `/workspace/VA-Studio-service-attachment-closure`, its isolated
autoload/package symlinks and the previously recorded frozen support contracts.
Native commands sourced only the private author task env and used authorized
loopback network access. Credentials were not printed or committed. No shared
registrations, dependency manifests, live settings, customer data, messages,
payments, hosted CI, push or merge were changed.
