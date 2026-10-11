# Threat note: staff multi-factor authority below the admin panel

Scope: `PromotionAdministration` and `ManageRightsScope` (reached by `vasey:rights-scope`), after this change.
Both services are test-commerce only (`TestEnvironment::admitsTestCommerce()`: local, testing, staging) and refuse
production outright; this note is about staging, the hosted rehearsal that staff reach over the network.

## What the panel guarantees, and what it does not

- Filament challenges the TOTP code inside the login page and forces enrollment before any page where the panel
  rule is required. A Livewire snapshot is checksummed, so a page an unenrolled user never loaded cannot be driven.
- The panel keeps no per-request or per-session "MFA verified" flag. What an authenticated panel session leaves
  behind is an enrolled staff row under a required rule: `EnsureMultiFactorAuthenticationIsEnabled` checks exactly
  that (`hasEnabledProviders`) on every panel request, and `AdminMultiFactor::satisfiedBy` is the repository's
  domain-level reading of the same rule, already used by `TestPaymentExceptionOperations`, `FreeGrantStaff` and
  `CatalogDraftImporter`. The new `StaffWriteAuthority` evaluates that same panel rule (required by the admin panel;
  an enabled provider on the staff row) on the row each service has already read under its own lock, without a
  further read; it adds no parallel TOTP step.
- A route cache built under `local` served staging without page-level MFA until #68 C1 was fixed. The domain-level
  check closes the class of defect where a route, job, console or future caller reaches the service without the page
  middleware: the service refuses the unenrolled administrator itself.

## Threats considered

| Threat | Before | After |
| --- | --- | --- |
| Staff password phished; attacker drives a write below the panel in staging (a job or a route that omits the page middleware) | Completed for an unenrolled admin (#68 C2 probe) | Refused: `AuthorizationException` with `StaffWriteAuthority::REFUSAL`; no row or audit change |
| Attacker with host shell access uses `artisan tinker` (or any PHP run on the host) in staging | Completed | **Not refused.** Tinker runs in the console, so it can call `StaffWriteAuthority::attest()` for any staff user and pass the attestation to the service, or save an app-authentication secret on a staff row and so enroll it. The first leaves a `staff.console_attestation` audit row; the second leaves no audit row. Shell access to the host is the real control; this change does not and cannot defend against it (corrected after the independent review of `d806d88`, which found the earlier row listed tinker as refused) |
| A future HTTP caller (route, controller, Livewire action) records an attestation to skip the panel rule | n/a | Refused since `fb4dce8`: `attest()` throws `StaffWriteAuthority::CONSOLE_ONLY` unless `app()->runningInConsole()`, before any read or record. Queue workers and the scheduler also run in the console, so a job could still record one; no job does today |
| Same, enrolled victim | Completed | Still admitted at the domain level: enrollment is the only state the panel records. The panel path itself requires the code; a below-panel caller needs server access, which is the stronger credential (independent review, #68) |
| Attacker with host shell and a staff password runs `vasey:rights-scope` in staging | Write completed with the password alone | Refused without `--attest`; with it, an append-only audit row names the operator, time, action, scope, revision, environment and enrollment state before the write, and the write's own audit event links to it. The flag is deterrence and attribution, not a cryptographic factor: shell access to the host remains the controlling credential |
| Attestation reused for another scope, revision, action or operator | n/a | Refused: the domain binds the attestation to the exact write inside its transaction |
| One attestation reused for a second write: block then unblock, a further versioned change, or a replay of the same write (Codex P2 r4240117094) | Admitted for 900 s: the value bound only operator, action, scope key and (null) revision | Refused since the 2026-10-11 follow-up. The attestation also binds the requested blocked state, expected control version and a keyed hash (HMAC-SHA256 under the application key) of the evidence reference, in the value and in its audit row; and the first admitted write appends a `staff.console_attestation_consumed` audit row (subject: the attestation's row) in its own transaction, after which every use is refused with `REFUSAL`. Audit rows stay append-only; the marker is a new row. A write refused after admission rolls the marker back with it, so the same attestation can then retry only the identical operation it binds |
| Two concurrent uses of one attestation | n/a | Every use first locks the same operator row FOR UPDATE (the binding requires that operator), so the second waits for the first transaction and then sees its consumption row. **Residual:** the already-used check is a plain read; on MySQL a caller that opened an outer transaction with an older REPEATABLE READ snapshot, and obtained the same in-memory value in a second connection, could miss a concurrent consumption. The value cannot be built outside `attest()` (private constructor), and the full-operation binding limits any such reuse to the identical operation, which the domain treats idempotently (register and link return the existing row; block fails `INVENTORY_CONTROL_CONFLICT` once the version moved). Not exercised on MySQL |
| Attestation object kept alive and reused later | n/a | Refused once spent (above) and in any case after 900 seconds (`ATTESTATION_LIFETIME_SECONDS`) |
| Attestation recorded inside a transaction that rolled back | n/a | Refused: the domain re-reads the audit row before admitting |
| Attestation whose audit row was changed underneath (the model is append-only, the table is not) to name another scope, action or revision | n/a | Refused since `fb4dce8`: `recorded()` requires the row's `action`, `scope_key` and `offer_revision_id` to match the value being admitted, not only the row's existence and actor; since the 2026-10-11 follow-up also `reference_hmac`, `blocked` and `expected_version` |
| Attestation left by a non-staff password holder | n/a | None recorded: the command checks `administer-catalog` before recording; the domain re-checks under lock |
| Attestation in production | n/a | Never recorded (the environment refusal precedes it) and never admitted (`requireFor` refuses outside test commerce) |
| Enrollment withdrawn while a panel request is in flight | Not checked by these services | The rule is evaluated on the row each service reads under its own lock inside its transaction (`ManageRightsScope`: exclusive user lock; `PromotionAdministration`: shared lock). A locking read returns the committed row and holds it until the transaction ends, so a withdrawal is either already visible or waits. No further read is made: a locked re-read would add a third `users` lock to the writer's audited lock sequence, and a non-locking one could return an older REPEATABLE READ snapshot |

## Residual risk and non-claims

- Neither service verifies a TOTP code at call time; no domain service in this repository does. A fresh per-request
  step-up would be a separate design (and a new session artefact) that this change deliberately does not introduce.
- `local` and `testing` keep enrollment optional by the existing policy; a developer host is not a networked panel.
- The reference hash is keyed by the application key, so the audit row does not expose a guessable reference to
  someone who can read audit history but not the key. An attestation recorded before a key rotation and used after it
  is refused (fail closed); it lives 900 seconds.
- The console attestation is explicit and audited, not strong authentication. The staging runbook's host access
  controls remain the control for the console path, for `artisan tinker` and for any other code run on the host.
- C2 is closed for `PromotionAdministration` and `ManageRightsScope` only. `app/Domain/Catalog/PrepareExclusiveOffer.php`
  still links a rights scope with only the gate and environment checks (no app or route caller today), and the
  panel-only catalog writers still rely on the panel's page middleware.
- Not exercised here: a real staging host, a real console session, browser flows, Stripe, MySQL 8.4 (this container has
  8.0.46 only).
