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

PHP/Composer are unavailable locally. No PHP syntax, Pint, PHPUnit, MySQL or hosted acceptance is claimed. Focused seller selection includes the three new test files, and full MySQL/SQLite gates and independent review must assess the final integrated source. Actual notification policy, production transport/timeouts, email/address changes, provider acknowledgment/reconciliation, unknown-state operator resolution, routing/backfill and retention deletion remain separate decisions and implementation children. Customer-facing receipts still say only `saved`.

Executed local source safeguards: receipt suite 24 tests, focused selector 21 tests, partition safeguards 35 tests and CI-scope safeguards 22 tests, plus whitespace checking. The independently implemented schema child ran 63 source-extracted SQLite trigger checks and a separate 13-check calendar probe; these are direct SQLite SQL checks, not Laravel migration, PHPUnit or MySQL execution. No runtime pass is inferred from them.
