# Retained test-payment exception inspection

T19-DIAG-01 adds **Inspect retained evidence** to the existing staff test-payment exception list. It checks the complete historical graph and appends a minimized inspection audit. It does not resolve an exception, refund money, release inventory/promotion capacity, create rights or authorize delivery. T19/T20 and WP-07 remain open.

The application service `InspectRetainedTestPaymentException` accepts one canonical finalization UUID and a staff actor. In one transaction it locks and reloads the persisted actor, checks `administer-catalog` and the admin panel's current MFA enrollment rule, scopes the exception to the configured test account, then invokes the existing complete order/finalization verifiers. No provider, storage, renderer or job is called. A caller-owned transaction is refused so a returned inspection cannot depend on an audit the caller later rolls back.

Configured-account history remains inspectable after fresh checkout, processing or finalization policy flags are withdrawn. Only retained `mode=test`, `outcome=paid_exception` records qualify. Missing/invalid account configuration produces no broader fallback. The UUID and account are compared exactly after lookup. Unknown, malformed, non-exception and foreign-account records have the same not-found boundary; guests and withdrawn/unverified staff are denied before decryption.

Verified output contains only test/inspection status, public finalization/order locators, an approved historical reason, confirmation/original cutoff/finalization timestamps, retained inventory/promotion classifications, verified graph counts and `currentProviderState=not_inspected`. Unverified graphs return **Evidence needs attention**, with diagnosis, timestamps, order locator and counts withheld. No buyer details, asset paths, payment IDs, ciphertext, hashes, request keys or recovery tokens enter the modal or audit.

The audit action is `commerce.payment_exception.inspected`. Its context has exactly the public finalization locator, `verified`/`attention` result and test-only marker; actor and subject are retained by the normal audit schema. An audit failure rolls back that event and returns no successful inspection. Each explicit modal rendering rechecks evidence and may append another inspection audit; financial/resource records remain unchanged. Subsequent reactive requests reauthorize, and the inspection service independently checks freshly persisted authority/MFA.

**Stored graph verified** means the retained order, authoritative historical payment evidence, immutable exception, pending resources, absence of grants/exclusive sales and single pending exception event agree. It is not an inspection of current provider, refund/dispute or physical file state. Later scope unblocking or asset repair does not change the original exception or establish permission to fulfill it. No new policy or schema is installed.

## Candidate evidence

Prepared separately from the experience candidate, based on local `582492e3469104a69f54d441094401edba0ea9b2`, tree `b9b24792f9dfe477f3a75155e7ba640eb51114b8`. The inspection source and tests require their own independent review and integration acceptance.

PHP 8.4.26 with physical checkout-local dependencies, isolated SQLite and synthetic private media:

```sh
php vendor/bin/phpunit tests/Feature/TestPaymentExceptionInspectionTest.php tests/Feature/TestCommerceOperationsTest.php --colors=never
php vendor/bin/pint --test app/Domain/Commerce/Operations/InspectRetainedTestPaymentException.php app/Filament/Resources/TestPaymentExceptionResource.php tests/Feature/TestPaymentExceptionInspectionTest.php
git diff --check
```

- Final focused run: **54 tests / 831 assertions, all passed**, 87.128 seconds. Coverage includes five reasons, promoted/non-promoted, non-exclusive/mixed-cart inspection and actual Livewire modals, stale role/verification/MFA, scope/privacy, twelve corrupt graphs, poisoned external/fulfillment services, audit rollback, default/second-connection transaction refusal, close/reopen and snapshot privacy.
- Targeted Pint and whitespace checks passed. The pre-existing operations test retains its original formatting except for the three added assertions; no broad formatting rewrite was made.
- Earlier 51-case run found one synthetic unavailable-asset fixture reaching the existing retry boundary before fresh digest inspection. The fixture now preserves a proved private path, matching the accepted finalization regression; the corrected reason matrix passed 5 tests / 164 assertions before the final complete run.

The reason matrix uses real retained test-payment/finalization fixtures for late confirmation, scope block, unavailable purchased bytes and withdrawn rights. The `inventory_unavailable` case constructs a guarded synthetic immutable observation to test historical interpretation; it does not claim an actual competing-sale race. Corruption cases alter only disposable testing databases after removing the relevant guard.

- Native browser/operator acceptance and independent-process MySQL concurrency remain pending. SQLite checks do not prove MySQL locking.
- Proposed native coverage: ordinary operator login, opening the actual inspection modal, historical labels/privacy, attention state, close/reopen and persisted authority/MFA withdrawal. No network interception or artificial payment-success guarantee is required.

## Follow-on boundaries

An actual resolution needs its own explicit test contract and the applicable T10 late-payment/refund/exclusive decisions. Preserve the original exception and append any reviewed resolution/effect evidence; do not rewrite it to `paid` or substitute current terms/assets. T20 additionally needs an immutable unpaid-release proof and historical-reader extension. Current `canceled`/`expired` observations, elapsed TTL or an uncertain provider result are not existing authority to release pending capacity. Late successful payment after a future release must retain payment evidence without issuing rights or silently reacquiring a sold scope.
