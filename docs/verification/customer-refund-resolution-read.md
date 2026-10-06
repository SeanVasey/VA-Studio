# Customer read of retained test exception resolution

This child adds a database-only historical read of the existing verified full-refund pending-resource resolution. It is based on `a831d67aa787bd5850c113d2abbc27b4e0251aa5`. It creates no refund, resolution, audit, grant, contract, entitlement or schema change. Existing payment/finalization/fulfillment states and order, history, checkout, original-item and delivery DTOs remain unchanged. Production refund/access policy, provider interoperability, partial-refund/dispute effects and live activation remain separate work.

## Public contract

`GET /orders/{order}/exception-resolution` accepts one canonical order locator, an empty body and no query string. The response is exactly:

```json
{
  "resolution": {
    "exceptionResolutionSchema": 1,
    "orderId": "00000000-0000-4000-8000-000000000001",
    "testOnly": true,
    "record": {
      "kind": "full_refund_verified_resources_released",
      "observedAt": "2026-10-06T10:00:00Z",
      "releasedAt": "2026-10-06T10:00:01Z"
    }
  }
}
```

`record: null` means this read found no retained supported resolution. It does not establish whether a refund occurred or whether operator work remains. A populated record describes the original observation and technical resource release, not refund execution/arrival, settlement finality or current provider state. Dates use canonical UTC seconds; observation cannot follow release, and release cannot be in the future. Historical records do not expire with the original release-admission deadlines, later operation history, or disabled new-write policy.

`PurchaseAccess::read` authorizes the exact order and rechecks account authority after projection. The read verifies the original order, selects a resolution, explicitly validates that exact resolution and the complete finalization graph, then exposes only the two dates and constant kind. This handles a resolution committing after the initial original-graph verification without treating row existence as proof. Missing required proof or corrupt present proof is an error, never a successful null record. The operator review service is not used because it creates work/audit records.

Original guest owners and directly owning accounts can read their exceptions. The existing purchase-claim boundary remains paid orders with issued contracts; a legitimate claimed paid order returns no resolution record and retains its existing delivery access. No claimed paid-exception fixture or new claim eligibility is introduced. Ordinary HTTP reads use autocommit; this does not promise locking-current results inside an arbitrary preexisting repeatable-read transaction.

## Privacy and transport

The route retains the existing commerce identity middleware, read throttle and session lock. Endpoint-specific middleware runs before session work. It rejects unsupported methods (405 with `Allow: GET`), nonempty query/body and method overrides (422), ranges (416), unsupported encoding (415), and cross-site/origin requests (403). Input inspection reads at most one body byte. Every response is private/no-store, cookie-vary, no-referrer, noindex and nosniff, with cache validators removed.

All errors use only `TEST_RESOLUTION_UNAVAILABLE` and `This test order resolution could not be loaded.` Unknown/foreign orders are 404, withdrawn account access is 403, and corrupt/infrastructure/session-lock failures are 503. Framework debug/routing/throttle failures receive the same boundary. Logging retains only safe exception-class metadata; original provider objects, identifiers, owner keys, buyer data, hashes, request IDs and ciphertext never enter the DTO.

## Verification

The focused suites are `CustomerRefundResolutionTest.php` (14 cases) and `CustomerRefundResolutionHttpTest.php` (17 cases). They cover both resource shapes, unchanged original DTOs/evidence, retained history after time/policy/sequence changes, financial observation without resolution, valid/corrupt proof appearing after initial verification, missing/corrupt/future proof, exact guest/account/claim access, authority withdrawal during projection, zero SQL/provider writes, and private transport/framework failures. The new tests do not claim a new MySQL concurrency proof; the deterministic late-appearance seam executes the real original verification and real guarded resolution before selecting the candidate.

Final checks used recovered PHP 8.4.26 and a physical vendor copy. SQLite and native MySQL results remain separate; neither substitutes for hosted native browser acceptance of the integrating UI.

| Check | Actual result | Session receipt |
| --- | --- | --- |
| New suites plus existing order history, original items and purchase claims on SQLite | 90 passed, 1,746 assertions, 185.033 seconds; zero errors/failures/skips | `customer-refund-resolution-combined-sqlite.xml` / `.log` |
| Both new suites on native MySQL 8.4.11 | 31 passed, 519 assertions, 103.393 seconds; zero errors/failures/skips | `customer-refund-resolution-native.xml` / `.log` |
| New PHP files and tests | Pint passed | Local command output |
| Route/bootstrap syntax and whitespace | Both `php -l` checks and `git diff --check` passed | Local command output |

The SQLite command used a synthetic `APP_KEY` in the environment:

```sh
php vendor/bin/phpunit tests/Feature/CustomerRefundResolutionTest.php \
  tests/Feature/CustomerRefundResolutionHttpTest.php tests/Feature/OwnedTestOrderHistoryTest.php \
  tests/Feature/CustomerOrderItemsTest.php tests/Feature/CustomerPurchaseClaimTest.php \
  --log-junit ../customer-refund-resolution-combined-sqlite.xml
python3 ../mysql-runtime/run-tests.py -- php vendor/bin/phpunit \
  tests/Feature/CustomerRefundResolutionTest.php tests/Feature/CustomerRefundResolutionHttpTest.php \
  --log-junit ../customer-refund-resolution-native.xml
```

The isolated native wrapper retained normal durability: `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite enabled and binary logging enabled. No native concurrency scenario was substituted with SQLite. Final source remained unchanged throughout these runs; their application storage used the existing per-test UUID isolation.

Initial verification preserved these failures before repair:

- The first 11-case run passed three cases. Laravel `getJson` supplied a nonempty JSON GET body, correctly refused by the new transport. Tests now send empty GETs. The corruption test initially expected the wrong existing decryptor exception type, and the no-write queue check initially included media jobs created by fixture setup; both test setup errors were corrected.
- A 28-case run then passed with 497 assertions. Adding real financial-observation and ordinary paid-claim coverage exposed missing contract-issuance fixture configuration, then the same nonempty-GET issue on the preexisting delivery route. Ordinary fixture configuration and empty delivery GETs fixed those setup errors.
- The first expanded 90-case regression also found the unchanged history suite needed `APP_KEY` in the local command environment. The final command supplies a synthetic test key. No application guard, assertion, timeout, policy or database durability setting was weakened.

The frontend/browser child remains separately owned and reviewed. It preserves existing DTOs, adds an explicit bounded historical-read panel, and extends the existing two-engine refund journey without a new test identity. Its actual receipts and final integration belong to the composed candidate.
