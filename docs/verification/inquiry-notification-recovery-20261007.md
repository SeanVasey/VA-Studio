# Inquiry operator alert recovery — October 7, 2026

This recovers the retained inquiry-specific alert pipeline onto main
`5b58e2e`. The source ancestor is
`ae8ca1d8a4c61f0edd207cdb63ae828c128d9e45`; its original implementation/history
is available with `git show ae8ca1d8:docs/verification/inquiry-notification-intents.md`.
That old source's receipts remain historical, not acceptance of this recovery.
The repaired PHP source is frozen at
`e2900e800d375e6956a0948aecb50cb323009d7d`, tree
`6da96085d7cc20d7f463787df19bb559b7dfd0a3`.
The [source map](inquiry-notification-recovery-20261007/source-map.json) records
all recovered blob identities, predecessor/current SHA-256 values and all fifteen
owned PHP hashes. A following documentation-only commit preserves that source.

## Behavior and retained boundaries

A newly accepted inquiry and one minimal original-operator notification intent
commit together. An exact request replay returns the original saved receipt and
does not enqueue again. Customer confirmation remains `saved`. The current
displayed-notice admission locks, encrypted payload, request identity,
order-context replay refusal and support/conversation source are preserved.

`CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED` defaults to false. An explicitly
bound reviewed `InquiryAlertTransport` is separately required; this change binds
no transport and sends no email. A job carries only a numeric intent locator,
runs after commit and tolerates a missed queue wakeup. Recover missed wakeups,
due definite retries and expired claims with the bounded command:

```sh
php artisan vasey:process-inquiry-alerts --limit=25
```

The operator/receipt association is immutable. Claims are serialized and use
fresh original-operator authority/MFA checks; the handoff is outside every
instantiated database transaction. The terminal check reads the captured writer
PDO directly after all framework/model/container/query callbacks, checking the
current claim, original inquiry binding and the authority/enrollment row. Panel
MFA configuration and the default-false flag must also remain effective. Only
the captured adapter invocation follows that proof. The final unlocked check cannot
retract an external handoff after I/O begins. Changing the configured operator
never substitutes a recipient for retained work. No inquiry name, email, subject,
message, notice, retention reference or credential enters the intent, job or
transport message.

| State | Meaning / automatic recovery |
| --- | --- |
| pending | Durable saved intent; disabled/unbound workers leave it untouched. |
| processing | One fenced 120-second claim; duplicate workers do not send again. |
| retry | Trusted typed failure proves no submission; retry after 30/60 seconds, maximum three attempts. |
| submitted / handed_off | Transport accepted submission; recipient delivery is unknown. |
| unknown | Ambiguous handoff, expired/interrupted claim or stale completion; never automatically resent. |
| blocked | Original authority/MFA/configuration withdrawal or definite retry exhaustion; no automatic recipient substitution. |

No mail/provider credentials, sender selection, arbitrary recipient address,
production activation, historical backfill, manual unknown-outcome resolution,
retention deletion or delivered-message claim is added. T15/WP-09 remain open.
Generic `test_order_ready` notices and their historical private capture behavior
remain separate and unchanged.

## Recovery mapping and adaptations

Recovered byte-for-byte from the source ancestor: `InquiryNotificationIntent`,
`InquiryAlertNotSubmitted`, `InquiryAlertTransport`, `OperatorInquiryAlert`,
`NotifyInquiryOperatorJob`, `ProcessInquiryAlerts`,
`InquiryNotificationConcurrencyTest`, `InquiryNotificationRace`, and
`inquiry-notification-race-worker.php` (nine blobs).

Adapted retained source:

- `InquiryNotificationWork`: current locking authority/MFA reads during claim
  and pre-handoff; standalone claims refuse outer transactions; another complete
  transaction check immediately before transport I/O; captured-primary terminal
  proof of the current claim, original inquiry binding, authority/enrollment and
  panel configuration after framework callbacks.
- `InquiryNotificationWorkTest`: actual bounded command/lost-wakeup/due-retry/
  expired-claim journey, standalone-claim refusal and terminal callback withdrawals.
- Original `2026_10_01_000033_inquiry_notification_intents.php` becomes the new
  additive `2026_10_07_243000_inquiry_notification_intents.php`. Original state,
  identity, UUID/date, retry and terminal SQL predicates are preserved; current
  ownership protections refuse external dependencies before DDL.
- `InquiryNotificationMigrationTest`: the new migration path plus external
  reference refusal cases.
- Existing `SubmitInquiry`: one import and one transactional `retain()` call
  after its inquiry audit; its order-context refusal and every admission/replay
  rule stay intact. Existing `config/inquiries.php`: one default-false flag.
  These two shared paths were explicitly coordinated with the integration lead.

The migration validates table/column/index/FK/guard identity, refuses foreign or
nonempty partial installations, resumes exact empty committed DDL prefixes,
retains populated history on rollback and preserves parent evidence. Matching
names alone are not ownership. Its external-dependency admission follows the
current shared `CapabilityMigrationOwnership` policy while retaining the old
inquiry migration's interrupted-installation/empty-rollback behavior.

## Executed evidence

No full Foundation,
browser, real provider, email delivery or MySQL 8.4 pass is inferred from these
focused checks. The available native server is MySQL
`8.0.46-0ubuntu0.24.04.4`, not the production target MySQL 8.4.

The initial invocation omitted a synthetic application key; its failures are
not product acceptance. After correcting the invocation, shared workspace
exhaustion caused storage-creation errors. Only this worker's generated vendor
package copies were replaced by links to the unchanged locked original packages,
retaining independent generated autoload/composer/bin files. Subsequent reflection
checks resolve the inquiry source/test classes to this isolated worktree.
Dependencies and lockfiles were not installed or changed.

An isolated source-bound SQLite canary demonstrates the inherited empty-rollback
gap: an external view referencing the intent table did not cause refusal. The
same canary passes against the protected successor. The correction refuses
external references before removing owned guards or tables, rather than relying
on an eventual database error after partial DDL.

The integration lead's independent review reproduced three terminal-handoff
failures in the recovered worker: after the final framework authority read, a
`QueryExecuted` callback could withdraw admin authority, disable the flag or
transition the claim to `unknown`, yet the synthetic adapter ran. The worker
successor adds the complete direct-PDO proof above; the unchanged independent
canary and dedicated callback regressions are rerun against it. These checks
exercise a private synthetic sink; they send no email.

The first external-dependency MySQL run preserved a synthetic view past its
case. The following case correctly refused to install over that view during
setup (13 passes, one setup error). Each new foreign-reference case now removes
only its own synthetic dependency in `finally`; production ownership refusal is
unchanged. An existing reflection-only private-step test is updated to pass the
captured connection/PDO after that private signature changed.

| Tested source | Selection | Result / scope |
| --- | --- | --- |
| `5c8d31f723368094651a536f016caca18f76b458` | Core notification work/migration/native worker suite on MySQL `8.0.46-0ubuntu0.24.04.4` | 42 executed, 733 assertions; no failures, errors or skips. Historical predecessor of the terminal repair. |
| Same core source | SQLite notifications plus customer HTTP/admin, order-context HTTP and conversations | 180 recorded / 175 executed, 4,456 assertions; five expected native-only skips. |
| `59d5b0b336ef14bd9e78d9cd18ab314bd35fe596`, tree `dc12ace18f70ece51cc9ddf43b96cd41e03835d3` | Same composed SQLite selection after external-dependency repair and transaction-observer regression | 184 recorded / 179 executed, 4,502 assertions; five expected native-only skips. Historical predecessor of the terminal repair. |
| Final PHP `e2900e800d375e6956a0948aecb50cb323009d7d` | Composed SQLite selection above, seven persistent terminal withdrawals and the unchanged three independent terminal canaries | **194 recorded / 189 executed, 4,543 assertions**, five native-only skips; no failures/errors/warnings. |
| Same final PHP source | Native MySQL: all work cases, actual worker race, three external-reference refusal datasets, all interrupted-installation/empty-rollback prefixes, three unchanged independent terminal canaries | **24 executed, 373 assertions**; no failures/errors/skips/warnings. |

The complete native worker test observes an InnoDB `PRIMARY` record wait between
independent processes/connections before releasing the winner. It asserts one
synthetic handoff, one attempt and one submission audit; a SQLite pass is never
substituted for this race.

The [receipt manifest](inquiry-notification-recovery-20261007/receipts.json)
binds the copied [final SQLite JUnit](inquiry-notification-recovery-20261007/sqlite-final-junit.xml),
[final native JUnit](inquiry-notification-recovery-20261007/mysql-final-junit.xml)
and [historical core native JUnit](inquiry-notification-recovery-20261007/mysql-core-junit.xml)
to their actual source and SHA-256 identities. The unchanged independent probe
is copied for reproduction; final runs used the independent worktree's original
path with identical bytes.

Both final selections used `--fail-on-empty-test-suite`,
`--fail-on-phpunit-warning` and `--display-warnings`, a private synthetic app key,
the isolated worktree's generated autoload and locked dependencies. Native checks
used the separate disposable inquiry database/account, not another agent's
tables. All fifteen owned PHP paths passed `pint --test`; `git diff --check`
passed. No runtime PHP source changed while final checks ran.

Reproduce the composed selection with:

```sh
php vendor/bin/phpunit \
  tests/Feature/InquiryNotificationWorkTest.php \
  tests/Feature/InquiryNotificationMigrationTest.php \
  tests/Feature/InquiryNotificationConcurrencyTest.php \
  tests/Feature/CustomerInquiryHttpTest.php \
  tests/Feature/CustomerInquiryAdminTest.php \
  tests/Feature/OrderInquiryHttpTest.php \
  tests/Feature/InquiryConversationHttpTest.php \
  docs/verification/inquiry-notification-recovery-20261007/InquiryTerminalHandoffCanaryTest.php \
  --fail-on-empty-test-suite --fail-on-phpunit-warning --display-warnings
```

For the final affected native selection use the three dedicated notification
files plus that unchanged canary and this exact filter:

```text
InquiryNotificationWorkTest|InquiryNotificationConcurrencyTest|InquiryTerminalHandoffCanaryTest|test_external_dependencies_are_refused_before_adoption_or_empty_rollback|test_every_committed_installation_prefix_can_resume_and_repeated_up_preserves_all_guards|test_every_empty_rollback_prefix_and_absent_table_can_resume_without_changing_parent_evidence
```

The manifest lists the five exact native-only method tuples; the integration
lead owns their SQLite-skip ledger registration. The complete historical core
native receipt covers unchanged raw state/identity/FK/index predicates; final
native checks cover the changed migration admission and runtime handoff
boundaries. These remain affected checks, not a final Foundation run.

## Independent review and integration

The integration lead's final independent review of this exact repaired source
is pending at this record. Its earlier review found the three terminal blockers;
the unchanged probe passes against the repaired source on both databases. Its
final review disposition belongs to the integration record; this feature must
not be integrated from either predecessor that lacks the terminal repair.
The source commit above is the final review target,
and a documentation-only successor retains identical PHP bytes. Runtime receipts
remain distinct from historical source claims; no final full integrated
acceptance, target MySQL 8.4 pass, real provider delivery or production activation
is asserted here.
