# Concrete preflight dependency blocker — supplemental evidence

PR123 replacement preflight `38718687265` failed at `composer audit` on exact `089fba7b8e66a85de8393755e9e416725a3154dd`. Scope/frontend passed, backend audit and aggregate failed, documentation skipped. This is a genuine enforced gate failure, distinct from readiness-cancelled initial `38718579419`; no merge occurred. The GitHub job116368056191 log and local locked audit both identify `PKSA-xmwy-rmx5-m7h4` / `CVE-2026-108082` in `symfony/http-foundation v8.0.15`.

The original gh log download returned403; the GitHub connector obtained the actual job log. The raw failed download receipt includes an expiring redirect URL and remains host-only; only a redacted access-limit receipt is suitable for publication. No authorization/protection setting was changed.

A minimal8.1.9 update initially refused: the package requiresPHP>=8.4.1 but the project simulated8.4.0. That exact failed command/exits remains recorded. The repair consistently changes root PHP requirement to^8.4.1 and Composer platform to8.4.1, and README prerequisites accordingly. Actual local PHP8.4.26 satisfies it. A subsequent minimal update succeeds with0 installs,1 update,0 removals: **only HttpFoundation8.0.15→8.1.9**. Every other package entry, Stripe21.3.2, Laravel13.35.0, SQL/PDO/event/container/process package selections, application/tests/config/migrations and frozen contracts stay unchanged. No audit exception/platform ignore or broader dependency upgrade is used.

Current locked audit passes with no advisories/abandoned packages, strict Composer validation and real platform requirements pass. Exact upstream old/new refs and minor comparison are recorded for independent review. This is not a lockfile-only unchanged-runtime carry: Composer inputs, installed HttpFoundation and generated loaders changed.

Required current-runtime evidence: one fresh serialized SQLite invocation over the whole three new Tax delivery files plus PaidGrantHttpJourneyTest, ProductionTaxCheckoutHttpBoundaryTest, CustomerSessionHttpTest, StorefrontMetadataTest and PaidGrantRootRegistrationTest. The extra root-registration file is reviewer-required and covers real bootstrap, default/nonboolean refusal, root sanitization and private response headers. Fresh source/runner/loader/argv/JUnit/count/skip/PHP/recorder/OS provenance will be recorded; this file does not yet claim that invocation passed.

Prior MySQL schema5/2893 and race3/76 remain exact old-dependency-runtime observations. Only independently reviewed unchanged SQL/PDO/schema/selector/wait primitives may carry, combined with fresh current-runtime Tax bootstrap/private-byte evidence. No current native execution or whole-runtime equality is claimed. Original archive/approvals and13 runs are immutable history. Updated independent source/evidence approval and new exact-head cheap preflight are required before merging. No Foundation/GitLab/host/provider/activation/release claim.

## Current-runtime recorded outcomes

Both invocations execute exact clean `bb86ceec1f1106890f4ab605009fb67091ca35f3`, with source/status/runner/loader stability and original launch-bound OS observations. No application/test source repair was needed.

| Original invocation | Tests/assertions | F/E/S | PHP/recorder/observed OS |
| --- | --- | --- | --- |
| Eight-file stop-on-defect selection | 72/3261 | 0/1/3 | 2/1/1 |
| Whole unfinished StorefrontMetadata + PaidGrantRootRegistration files | 14/227 | 0/0/0 | 0/0/0 |

The first invocation's error is the first StorefrontMetadata case: actual MissingAppKeyException,0 assertions, because this isolated worktree lacked a testing app key. RootRegistration did not execute. Before it, six whole selected files complete as components inside that failed invocation: Tax delivery18/185, new schema5/2179, native3 declared skips, V1 paid HTTP19/648, Tax checkout HTTP17/131, customer session HTTP9/118; component total71/3261/F0E0S3. Their whole process still exits2/1/1. These are sufficient current-runtime affected components under the independently reviewed scope; no whole-eight-file green exit or synthetic combined85-case run is claimed.

The continuation generates an ephemeral random32-byte synthetic APP_KEY only on the host, omits its actual value from receipts/Git/chat and records a nonsecret presence descriptor. Original recorder/launcher/bundle remain untouched; new variant/environment identities are bound separately. No customer/provider/live key is used. One root preparation attempt had a Python unterminated-string error and missing launcher before any new PHP process/file; it is retained as preparation only and corrected with structured JSON/fail-fast shell. The successful continuation takes11.863867324s; the first invocation510.723123153s includes fixture/schema lifecycle, neither is a benchmark.

Exact application commands, with the recorded toolchain activated:

```sh
python3 launch-durable.py --name sqlite-current-runtime-tax-and-http --driver sqlite tests/Feature/PaidTaxDownloadJourneyTest.php tests/Feature/PaidTaxDeliverySchemaRecoveryTest.php tests/Feature/PaidTaxDownloadNativeTest.php tests/Feature/PaidGrantHttpJourneyTest.php tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutHttpBoundaryTest.php tests/Feature/CustomerSessionHttpTest.php tests/Feature/StorefrontMetadataTest.php tests/Feature/PaidGrantRootRegistrationTest.php
python3 launch-durable-testing-key.py --name sqlite-unfinished-storefront-and-root-with-testing-key --driver sqlite tests/Feature/StorefrontMetadataTest.php tests/Feature/PaidGrantRootRegistrationTest.php
```

The three SQLite native skips are exactly the same declared authorization method and two redemption data sets as the original packet. No other case skipped. Current critical package bodies/source/dist/installed metadata and pristine Foundation120-file cache binding are retained. Six relevant database/container/event/process files match unchanged immutable old-lock Gitrefs. Missing old cached distributions and missing historical before-update vendor snapshots are disclosed; no whole vendor/runtime equality is invented.

Independent [plan](independent-dependency-plan-DECISION.md) and [actual source](independent-dependency-source-DECISION.md) approvals preserve their pending-outcome limits. Final sealed supplemental review and new exact-head preflight remain before merge. Original13-run archive and old-runtime native evidence stay immutable.
