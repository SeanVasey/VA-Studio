# T15b — retained operator inquiry alert intents

Implementation candidate, October 1, 2026, based on `e173e8e6416d382a9b93f1150d04656e49bf74ea`. T15 and WP-09 remain open. The frozen PR #89 source is untouched. The owner-authorized first-party store scope and decision-register U-13 permit a transactional notification interface before choosing a processor or production retention policy.

## Bounded contract

A new inquiry and one minimal notification intent now commit atomically. Exact request replay returns the original saved receipt without another intent or wakeup. The intent pins the original inquiry ID, original responsible operator ID and fixed `operator_inbox_v1` purpose; SQL/model guards prohibit retargeting or deleting that evidence. It retains no customer body, sender address/name, subject, privacy notice or retention reference. The original encrypted inquiry and its policy/site-release evidence remain unchanged.

`CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED` defaults to false. No transport binding, mailer adapter, provider account, credential or external send is supplied. Flag-only activation is insufficient. A later reviewed processor implementation must satisfy the `InquiryAlertTransport` contract and the owner's processor/notification policies. Tests bind only synthetic processors. This child is operational outbox engineering, not working email delivery or production activation.

A queue wakeup contains only the numeric intent locator and runs after commit. Enqueue errors are discarded without logging their text and cannot undo the saved inbox acknowledgment. `vasey:process-inquiry-alerts --limit=25` scans one bounded fair batch of pending intents, due retries and expired claims. It fails when disabled/unbound or when its limit is invalid. Already blocked, submitted or unknown work is excluded before applying the limit.

| Retained state | Meaning and next automatic action |
| --- | --- |
| `pending` | Saved intent, with no transport attempt. Disabled/unbound workers preserve it. |
| `processing` | One fenced 120-second claim. Repeated locator jobs cannot claim it while live. |
| `retry` | A typed adapter guarantee states no submission occurred; retry after 30/60 seconds, at most three attempts. |
| `submitted` / `handed_off` | The trusted transport accepted the handoff. Recipient delivery/inbox arrival is unknown. |
| `unknown` | An arbitrary handoff error, interrupted or expired active claim, or late completion might have crossed I/O. Automation never resends it. |
| `blocked` | Original operator authority/MFA, configuration withdrawal or definite retry exhaustion stops automation. No automatic recipient substitution or manual replay flow exists. |

Fresh persisted original operator verification, the existing staff gate and the panel's required MFA enrollment rule apply at claim and immediately before handoff. A changed globally configured recipient cannot retarget a retained inquiry. The processor receives only a fixed-purpose message, original internal operator ID and opaque receipt/protected admin path. It must not reconstruct an email address from inquiry input. An administrator revocation after the final check cannot retract an already accepted external handoff; provider activation must document that boundary.

Claims and completions lock the same intent row. Claim tokens fence stale completions, and an expired processing row moves to `unknown`, never a new claim. The actual handoff step is private to one `process()` execution, eliminating a same-token public replay entry point. Processor I/O is outside transactions on every instantiated database connection. No state claims exactly-once email or confirmed delivery.

## Acceptance source and current limitations

Prepared regressions cover atomic rollback and exact replay, disabled/unbound behavior, missing queue wakeup recovery, locator-only queue serialization, fresh operator/verification/MFA/configuration withdrawal, immutable original association, duplicate claims, bounded definite retries, ambiguous outcomes, pre-handoff and in-flight lease expiry, late-worker fencing, secondary-connection transaction refusal, sanitized audit outcomes, direct SQL identity/state/date guards and populated rollback refusal.

The separate MySQL contention case starts two real PHP processes/connections, observes an InnoDB `PRIMARY` row wait on the same intent, then runs the actual processor path with a private file-backed synthetic sink. It requires one handoff, one attempt, one submitted audit and the original inquiry/operator association. SQLite deliberately skips that exact method; it does not establish serialization. The explicit skip policy lists only that method. Historical timing weights are unchanged.

PHP/Composer are unavailable locally. No local PHP syntax, Pint, PHPUnit or MySQL execution is claimed; final hosted acceptance remains pending. Focused seller selection includes the three new test files, and full MySQL/SQLite gates and independent review must assess the final integrated source. Actual notification policy, production transport/timeouts, email/address changes, provider acknowledgment/reconciliation, unknown-state operator resolution, routing/backfill and retention deletion remain separate decisions and implementation children. Customer-facing receipts still say only `saved`.

Executed local source safeguards: receipt suite 24 tests, focused selector 21 tests, partition safeguards 35 tests and CI-scope safeguards 22 tests, plus whitespace checking. The independently implemented schema child ran 63 source-extracted SQLite trigger checks and a separate 13-check calendar probe; these are direct SQLite SQL checks, not Laravel migration, PHPUnit or MySQL execution. No runtime pass is inferred from them.

## Initial hosted focused feedback and fixture repair

[Focused run 36832050896](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36832050896) checked out `ae02a43ce7b737c8ccd7f3c747c45abb69b8eebf`, tree `4c80ef68d7795b0a62cdecef6bed103c6a9a52e2`, with PHP 8.4.26 and PHPUnit 12.5.34. It ran the four existing customer-inquiry files and all three new notification files using `php vendor/bin/phpunit` with `--log-junit=inquiry-notification-results.xml --fail-on-empty-test-suite --fail-on-phpunit-warning --display-warnings`. This informative focused workflow is separate from the required final acceptance gates.

The actual [SQLite job 110270679038](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36832050896/job/110270679038) failed: **115 tests, 3,114 assertions, one error, six failures and four explicit MySQL-only skips**. All six migration failures compared a freshly inserted model's attribute order (`id` last) with a persisted reload (`id` first); the reported column values and types were unchanged. The withdrawal worker case failed during inquiry setup because its previous direct SQL verification revocation left the retained in-memory operator stale. With a frozen test clock, assigning the old timestamp again did not mark verification dirty, so Eloquent correctly left the persisted operator unverified and the intake service correctly refused it.

The complete actual [MySQL job 110270678920](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36832050896/job/110270678920) also failed, with **115 tests, 3,481 assertions, one error, the same six failures and no skips**, in 4 minutes 37.113 seconds. Its error and all six raw-map diffs match the SQLite fixture defects; it reported no additional failing case. Both full decoded job logs were inspected before freezing this fixture repair. The original hosted source has not passed either focused job.

The repair normalizes only the key order of complete `getRawOriginal()` attribute maps and still uses strict `assertSame` comparisons on every raw value/type. It refreshes the same original operator before restoring test authority, then preserves real persisted verification/MFA withdrawal after claiming. No application service, permission check, migration trigger, immutable identity, privacy assertion or uncertainty rule is changed. A pass for the repaired source remains pending.

## Follow-on UUID storage guard

The fixture-only repair is commit `03bf39eda2887504683f34811648954491e7d7d5`. Subsequent independent source review identified a separate inference: the [MySQL 8.4 string-type documentation](https://dev.mysql.com/doc/refman/8.4/en/char.html) says excess trailing spaces in `VARCHAR` values can be truncated with a warning even in strict mode, while the [trigger documentation](https://dev.mysql.com/doc/refman/8.4/en/create-trigger.html) describes basic column checks before trigger activation. A 36-byte UUID with appended spaces could therefore lose that padding before the exact-length trigger sees the value in a `VARCHAR(36)` column. This has not been reproduced against the candidate: the initial raw-order assertion failure stopped the malformed-claim loop before its existing UUID-plus-one-space case.

Only the new, unaccepted migration's `claim_token` storage width changes from 36 to 64. The SQL and model validation still require exactly 36 bytes and the same canonical lowercase UUID format. The existing one-space, appended NUL/newline, uppercase and malformed-token rejections remain; additional raw SQL cases cover UUIDs padded to 64 and 65 bytes, an embedded NUL with an otherwise 36-byte token and a substituted multibyte character. Every rejection still compares the complete persisted raw row strictly. Longer storage keeps padding within the column visible to validation, and truncation beyond 64 cannot produce a valid 36-byte token. MySQL execution of this strengthened source and final acceptance remain pending; no provider, recipient, attempt or state contract changes.

Local reversible source checks confirm the only migration changes are the storage declaration and comment; all SQL predicates and the model are byte-identical. The schema author independently executed the exact source-extracted SQLite UUID predicate against ten canonical/malformed inputs successfully. These are limited source/SQLite predicate checks, with no Laravel migration, PHP or MySQL execution inferred.
