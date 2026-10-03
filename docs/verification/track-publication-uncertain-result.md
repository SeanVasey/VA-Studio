# T12: uncertain publication results

Prepared October 3, 2026 UTC on `codex/content-publication-journey-20261003`, above the journey contract at `17471045f5a55e3eaf205c686958823c470ef1b0`. The GitHub handoff candidate `6c55b4637ec858716c40055b2646e69da69230e1` is unchanged. This is a bounded application-interface correction; T11/T12 and integrated acceptance remain open.

## Problem and behavior

The strict publication command may commit and then fail to return its result. The existing `ManageTracks` component already consumed its confirmation before the call, but an unexpected exception propagated without publication-specific recovery guidance. Treating every failure as "nothing changed" would be false after a committed publish or withdrawal.

Both publish and unpublish now report unexpected non-HTTP exceptions through the existing server exception handler, send a persistent danger notification, and cancel the mounted action:

> Publication result could not be confirmed.
>
> Reload tracks to check the current publication state, then open a new confirmation before trying again.

The message includes no exception text, private path, record title, or claimed rollback. The action supplies no automatic retry. The review and table context remain cleared before authority/identity checks and application, including when the durable change already committed. A fresh applicable confirmation is needed for the normal component flow after checking current state.

Known responses keep their existing behavior: `AuthorizationException`, `ModelNotFoundException`, and `HttpExceptionInterface` propagate. `ValidationException` still produces the existing **Publication blocked** notification and cancellation. No publication service, locking order, database schema, audit content, physical-file proof, permission rule, or scheduling policy changes.

Review consumption concerns current Livewire component state. This change does not add a durable nonce that revokes every previously authenticated component snapshot; it does not strengthen the earlier replay boundary into such a claim. Domain versions, current authority/evidence and the existing action context remain authoritative.

## Regression coverage and source review

`TrackPublicationManifestEditorTest` now defines twelve expanded cases, including its four retained schema/unready/stale-offer/legacy-review cases. The added or expanded paths cover:

- Real Livewire action submission for publish and unpublish, with an exception before application and immediately after successful application.
- Exact notification title/body/status/persistence, absence of synthetic private exception text in rendered output, and reporting the original exception object exactly once.
- Unchanged full track attributes/audit count before a commit, and the correct retained status, publication-version increment and one audit after a commit.
- Closed mounted action, cleared review/context, a repeat request that never invokes the command again, and a newly mounted action using reloaded current state.
- Persisted staff-role and MFA-enrollment withdrawal after mounting; missing-actor HTTP 403; and preservation of the original missing-resource exception. These cases assert consumed state, unchanged catalog/audit and no uncertainty notification.

Existing broader stale metadata, ABA, table-context, cancellation and locked-property tests remain in `TrackPublicationGuardTest`; no assertion or scenario was removed from that class. The previous single uncertain-success case is expanded to the four outcomes above. The existing `publication` and `operator` focused selections already include the editor class, so no selection or SQLite skip policy changes are needed.

Independent source review by `/root/integration_review/publication_uncertainty_review` found no substantive implementation or test issue in the prepared files. Its exact-commit verdict and execution results belong in the eventual integrating PR; source review is not runtime acceptance.

| Prepared file | SHA-256 |
| --- | --- |
| `app/Filament/Resources/TrackResource/Pages/ManageTracks.php` | `7d75e74086a4717527760adafb90e60d7b9c8f89ca2af5ade7d8cb7b886b7d17` |
| `tests/Feature/TrackPublicationManifestEditorTest.php` | `32cbdedbf72215524d5fc7f4c2445ec8afece6f0b8b2c38b76051e2e2db5a15b` |

## Execution boundary

This workspace has no PHP executable (`command -v php` exited 1), so PHPUnit, PHP syntax, Pint and rendered browser execution were not run here. Whitespace inspection found no trailing whitespace. Notification and exception-fake APIs were checked against the installed lockfile's exact Filament notifications v5.8.4 and Laravel v13.33.0 sources. That establishes API compatibility by source, not execution.

Run the existing focused `publication` selection with SQLite first, or the broader `operator` selection if required by the integrating owner. The direct target is:

```sh
php vendor/bin/phpunit tests/Feature/TrackPublicationManifestEditorTest.php tests/Feature/TrackPublicationGuardTest.php --fail-on-phpunit-warning --display-warnings
```

Record actual counts and failures from the branch's exact head. Formatting and required full current-source CI remain gates for integration; no passing PHP, MySQL, browser, physical-device, or launch result is inferred from these test definitions. The earlier [journey contract](../experience/content-publication-journey.md) records the predecessor source behavior; this increment addresses its explicitly identified uncertainty-message follow-up only.
