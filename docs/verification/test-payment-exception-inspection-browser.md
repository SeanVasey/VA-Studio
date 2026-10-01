# Retained test-payment exception browser preparation

This is a separate native-verification child of T19-DIAG-01. It depends on inspection source preserved as `c7998417f321230f4277eaa3561a3e94899c297e`, tree `dab31f475345f5f3deabab25d768ed871a355102` (local equivalent `7642b80891fdc847812d19919d1f25c960c91eba`). It does not complete T19/T20 or WP-07 and must not be folded into the experience candidate without its own review and acceptance.

Owned source is `tests/browser/prepare-test-payment-exception-inspection.php`, `tests/browser/test-payment-exception-inspection.spec.ts`, the minimal exception configuration/marker additions in `tests/browser/run.mjs`, and this evidence contract. No application, schema or production configuration file is changed. The helper appends only to the wrapper's fresh disposable database and never resets it.

## Fixture boundary

The existing wrapper creates a fresh disposable SQLite installation, migrates it once and provisions the shared synthetic operator through the normal interactive `vasey:create-admin` command. The new CLI helper refuses operation unless raw and effective configuration agree on the exact wrapper-owned temporary database/storage/cache paths, local environment, loopback origin, SQLite driver/foreign keys, encrypted file sessions, file cache, local storage, array mail and synchronous queue. It rejects symlinked paths, existing configuration/route/event caches, attached databases, unexpected arguments/projects and a missing or mismatched private 64-hex marker. The shared operator's exact synthetic identity and persisted authority are checked. No mode resets or migrates an existing database.

HTTP payment checkout, processing, finalization and webhook flags remain disabled; provider secrets remain blank. Only the configured synthetic test account is supplied so retained history can be read. This also exercises historical inspection after fresh-payment policy withdrawal.

Preparation provisions a dedicated synthetic inspector and reviewer for each browser project through the existing admin command. The shared operator remains untouched. Only the guarded CLI process temporarily enters `testing` to reuse the established synthetic media, license and payment fixtures. It uses normal catalog publication, rights-scope linkage, quote/pricing, reviewed order preparation, hosted checkout, payment verification and finalization services. Both gateway interfaces bind to an in-process synthetic gateway. A controlled clock observes confirmation at the original cutoff, producing a retained `late_confirmation` exception with pending resources and no grants. This is synthetic historical evidence; no provider request or interoperability claim is made. Prepared tracks are normally unpublished before browser use. No contract rendering or delivery is authorized.

One separate disposable exception is designated for the attention case. Within one SQLite transaction the helper captures the exact `order_finalizations_immutable_update` trigger SQL, temporarily removes that trigger, updates only the bound synthetic finalization's evidence hash, and reinstalls the captured SQL in `finally`. It verifies the identical stored definition and fingerprint of every trigger before exposing either fixture. A failure rolls back the transaction. No deployed guard is changed and no browser endpoint can invoke this code.

Private fixture metadata is retained in a project-specific temporary file with mode `0600`. Preparation checks every pre-existing financial/resource/rights record remains byte-for-byte equal as serialized rows. Subsequent operations verify an unchanged fingerprint across 20 evidence tables and all SQLite trigger definitions, plus the complete historical verifier's verified/attention outcomes. Inspection audits must have the dedicated actor, designated subject and exact three-field minimized context. Role withdrawal/restoration is limited to that exact inspector identity and appends normal access audits.

| CLI operation | Allowed effect |
| --- | --- |
| `prepare PROJECT` | Create new synthetic evidence and private metadata once; preserve pre-existing rows and exact guards. |
| `verify PROJECT first/reopened/attention` | Read evidence/audits; advance private journey metadata only after the expected audit exists. Reopening must increase the verified inspection count. |
| `withdraw PROJECT` | After attention verification, withdraw only the dedicated inspector's persisted staff role and retain audit counts. |
| `verify PROJECT withdrawn` | Prove the withdrawn role and unchanged inspection counts, evidence and guards. |
| `restore PROJECT` | Restore only that inspector's original staff role, including after a browser assertion fails. |
| `verify PROJECT restored` | Prove restored authority, unchanged evidence/guards and monotonic audit counts. |

## Ordinary browser journey

The spec is collected once for Chromium desktop and once for WebKit mobile. It uses the ordinary login and existing exception-list action, keyboard opening/closing, the actual modal and real Livewire responses. It checks historical labels and counts, absence of resolution/refund submission, private marker/field exclusion from DOM, snapshots, responses and browser storage, close/reopen with a new inspection audit, and a corrupt graph's generic attention projection. It waits for actual modal unmount acknowledgements before reopening. It then withdraws the dedicated inspector through the guarded helper and requires the cached ordinary action's next POST and a fresh protected page GET to return 403 without a new inspection audit. Cleanup restores that inspector independently of browser assertions.

No payment UI interception, synthetic payment-success redirect, manually forged Livewire call or test web endpoint is used. Local administration does not require production MFA, so this native role-withdrawal journey does not prove production MFA; the committed adversarial feature tests cover freshly persisted MFA enrollment separately.

## Actual preparation evidence — 2026-10-01

PHP 8.4.26 with physical checkout-local dependencies was used in the isolated checkout. These checks passed:

```sh
php -l tests/browser/prepare-test-payment-exception-inspection.php
php vendor/bin/pint --test tests/browser/prepare-test-payment-exception-inspection.php
node --check tests/browser/run.mjs
npm run typecheck
npm run build
npm run test:browser -- tests/browser/test-payment-exception-inspection.spec.ts --list
git diff --check
```

- Fresh wrapper migrations, interactive operator provisioning and installation diagnostics passed. Test collection reported **2 tests in 1 file**; `--list` did not launch either browser.
- A temporary local harness derived from the actual wrapper exercised the real helper for both project identities, sequentially in one new database. Direct CLI calls to the committed inspection service supplied the audits for this helper proof; they are **not native acceptance**. For each project the helper proved verified audit counts `1 → 2`, attention count `1`, denial after role withdrawal with unchanged counts `2/1`, and successful restoration. Every verify/restore retained all evidence and exact guard fingerprints. The second preparation also preserved the first project's existing financial records.
- Nine rejection probes per project passed: duplicate preparation, mismatched marker, wrong account, foreign database path, wrong loopback origin, excess arguments, unknown phase, symlinked marker and existing configuration cache. SQLite file bytes remained unchanged after every rejection. The temporary direct-service runner was removed and adds no application capability.
- Native Chromium/WebKit execution, independent review of the exact frozen source, integration checks and any required MySQL suite are still pending. No native passing claim or MySQL locking claim follows from these checks.

The next acceptance run is a fresh isolated diagnostic of the frozen core plus this child, using `npm run test:browser -- tests/browser/test-payment-exception-inspection.spec.ts`, with actual response, audit, privacy, focus and screenshot evidence reviewed. Keep the live payment, refund/resolution, resource-release and launch gates closed.
