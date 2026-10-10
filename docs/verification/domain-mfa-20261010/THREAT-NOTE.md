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
| Staff password phished; attacker drives a write below the panel in staging (job, artisan tinker, a route that omits the page middleware) | Completed for an unenrolled admin (#68 C2 probe) | Refused: `AuthorizationException` with `StaffWriteAuthority::REFUSAL`; no row or audit change |
| Same, enrolled victim | Completed | Still admitted at the domain level: enrollment is the only state the panel records. The panel path itself requires the code; a below-panel caller needs server access, which is the stronger credential (independent review, #68) |
| Attacker with host shell and a staff password runs `vasey:rights-scope` in staging | Write completed with the password alone | Refused without `--attest`; with it, an append-only audit row names the operator, time, action, scope, revision, environment and enrollment state before the write, and the write's own audit event links to it. The flag is deterrence and attribution, not a cryptographic factor: shell access to the host remains the controlling credential |
| Attestation reused for another scope, revision, action or operator | n/a | Refused: the domain binds the attestation to the exact write inside its transaction |
| Attestation object kept alive and reused later | n/a | Refused after 900 seconds (`ATTESTATION_LIFETIME_SECONDS`) |
| Attestation recorded inside a transaction that rolled back | n/a | Refused: the domain re-reads the audit row before admitting |
| Attestation left by a non-staff password holder | n/a | None recorded: the command checks `administer-catalog` before recording; the domain re-checks under lock |
| Attestation in production | n/a | Never recorded (the environment refusal precedes it) and never admitted (`requireFor` refuses outside test commerce) |
| Enrollment withdrawn while a panel request is in flight | Not checked by these services | The rule is evaluated on the row each service reads under its own lock inside its transaction (`ManageRightsScope`: exclusive user lock; `PromotionAdministration`: shared lock). A locking read returns the committed row and holds it until the transaction ends, so a withdrawal is either already visible or waits. No further read is made: a locked re-read would add a third `users` lock to the writer's audited lock sequence, and a non-locking one could return an older REPEATABLE READ snapshot |

## Residual risk and non-claims

- Neither service verifies a TOTP code at call time; no domain service in this repository does. A fresh per-request
  step-up would be a separate design (and a new session artefact) that this change deliberately does not introduce.
- `local` and `testing` keep enrollment optional by the existing policy; a developer host is not a networked panel.
- The console attestation is explicit and audited, not strong authentication. The staging runbook's host access
  controls remain the control for the console path.
- Not exercised here: a real staging host, a real console session, browser flows, Stripe, MySQL 8.4 (this container has
  8.0.46 only).
