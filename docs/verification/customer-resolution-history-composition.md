# Customer historical test-resolution read and display

October 6, 2026 UTC. This separate follow-up lets the original customer explicitly read a retained, verified full-test-refund resolution. It builds on the reviewed license-template and inquiry-history source `5bc7cbd591be2777d02b81a235faf6f19b0545fb`; that preceding batch and PR #8 retain their own acceptance boundaries. This document records local composition and coverage proof, not full hosted acceptance or a merged change.

## Customer behavior and limits

An original guest or directly owning account can request `GET /orders/{order}/exception-resolution`. The database-only reader verifies exact ownership, original commerce evidence and any present resolution proof, then returns only a versioned envelope containing the order locator, `testOnly`, and either no supported retained record or its constant kind and original observation/release dates. Present invalid proof fails privately. The read rechecks current account authority after projection and does not infer claimed paid-exception eligibility. Existing order, history, checkout, item and delivery DTOs remain unchanged. The [backend record](customer-refund-resolution-read.md) defines exact privacy, transport and historical-proof behavior.

Existing `paid_exception` details offer **View recorded test-order resolution**. Only explicit activation fetches the record. Absence does not establish whether a refund occurred; a retained record describes a verified test observation and released reservations, not refund arrival, settlement or current provider state. Contracts and downloads stay blocked. A strict streamed 4 KiB response reader, 20-second deadline, request ownership and departure/order-replacement cleanup bound and clear private state. The [UI record](customer-resolution-ui.md) preserves the exact contract, component evidence, reviewed validation correction and existing-theme treatment.

The existing refund browser identity now covers an original guest's null/read-after-release/partial-history reads and foreign denial. Its genuine guest session is passed only through bounded helper stdin; no owner/session adoption is fabricated. The preceding durable-release-before-drop correction remains in place, including complete post-drop proof equality, exact retry, provider-call count and unchanged business/guard checks. No browser case, timeout or fixture guard is removed or relaxed. No refund, financial observation, resolution, audit, grant, contract, entitlement or schema is created by the new customer read. Production refund/access policy and live commerce remain outside this slice.

## Exact source composition

| Source | Local commit | Tree |
| --- | --- | --- |
| Reviewed preceding batch | `5bc7cbd591be2777d02b81a235faf6f19b0545fb` | `80f979aa56a0645fac174981138f9a9f13a8bca8` |
| Independently reviewed backend, 9 paths | `f62a4a59021a94990fbb2ea00843e58b389202e2` | `26432d0a8379a187825c63e692600dbb68fb47ae` |
| Independently reviewed original UI child, 10 paths | `afca207d7518b4de69783dcf5bb09058671791f8` | `d8e66b946f1b817dda8a7d4292faafa3cc169c0e` |
| UI-only cherry-pick onto preceding batch | `8752f08039d8addd4dabac00a03918af6274e5bc` | `fc3589777d32096e8c14e415a8f9e5dd18c9b68d` |
| Exact two-feature composition | `23170e60108d88cba9bd40d8b0f2fab6256dbc00` | `3846e1b4286fb73762cb4b5e8fa2b2af4803473c` |
| Bounded shared registration and census | `593b2c49106f103516df8f168d3ab1b4a0365d06` | `9453f1d5a53a66eda78f2c9de7e30d685829eda6` |

The original UI child has parent `8b8eb7b2de5b8252f6bceaf89fc906719ce5fda4`, its local cherry-pick of the already incorporated `2380da8` refund-response correction. Integration therefore cherry-picked only `afca207` with original-source attribution. All ten resulting UI blobs exactly equal the reviewed original UI commit. The backend, based on `a831d67`, was merged after its final exact-source approval; all nine backend blobs exactly equal `f62a4a5`. The feature composition is precisely their disjoint 19-path union over `5bc7cbd`, without importing duplicate correction ancestry or replacing intervening work. These local IDs are not represented as published GitHub IDs.

The only shared registration edit adds the two new PHP suites to `customer` and the new frontend suite to `FRONTEND_TARGETS`. Customer grows from 17 to 19 files and frontend from 30 to 31. Browser remains 32, operator remains 32 and all other selections retain their exact prior files and order. The fixed 32-file cap, workflow enums, selection/execution logic, database exclusion policy, timing weights and acceptance gates are unchanged. No mirror test was added for tuple data; the existing safeguards and actual discovery verify this registration.

## Executed discovery and partition proof

Actual PHPUnit discovery at `593b2c4` found **3,863 cases in 243 files** on each engine configuration. Every expanded case, complete file and group appears exactly once across eight MySQL and two SQLite partitions. All 3,832 preceding case identities and file mappings remain. The only additions are 14 domain-read cases and 17 HTTP/privacy cases, with no native-only addition.

SQLite retains exactly **130 native methods / 396 excluded expanded identities**, byte-for-byte policy and identity equality with the preceding batch. A full SQLite run must execute 3,467 cases; this expected count is not a passing execution receipt. The two partitions contain 1,853 / 2,010 cases, 121 / 122 files and 193 / 203 exact exclusions. Existing timing inputs remain unchanged; ordinary fallback warnings identify 20 untimed files. Selector safeguards passed **40 cases** and partition safeguards passed **36**.

Expanded Vitest collection found **715 cases in 33 files**, preserving all preceding 668 expanded identities and adding exactly the 47-case resolution suite. The default static listing first reported 354 declarations; that output is retained separately because it does not expand parameterized tests. `--no-staticParse` collected the 715 actual cases without executing their bodies.

Direct Playwright listing found the same **124 identities in 36 files**, 62 per browser, with exact identity equality to the preceding batch. It used an import-only empty scratch fixture manifest. No test function, server or fixture preparation ran. UI/frontend/browser discovery was collected at `8752f08`; all relevant source paths are byte-identical at `593b2c4`. Backend additions and registration do not change them.

The scratch proof is `ci-customer-resolution-census-593b2c4.json`, SHA-256 `1d6cf48c2947520665f7488e775c6e2f5f6ff51399b7e54a9aa5ea3dab6da3a3`. It includes exact feature blob identities, per-shard inventories and evidence hashes, all selected files, unchanged exclusion identities and frontend/browser preservation checks. All 1,338 tracked files remained unchanged during the proof. The later addition of this composition document changes no executable source.

Commands used the unchanged repository tools:

```sh
DB_CONNECTION=mysql python3 scripts/ci/phpunit-shards.py --shards=8 \
  --prefix=phpunit-ci-customer-resolution-mysql --timings=scripts/ci/phpunit-timings-mysql.json
DB_CONNECTION=sqlite python3 scripts/ci/phpunit-shards.py --shards=2 \
  --prefix=phpunit-ci-customer-resolution-sqlite --timings=scripts/ci/phpunit-timings-sqlite.json
node node_modules/vitest/vitest.mjs list --json --no-staticParse
VASEY_BROWSER_DIRECTORY=/tmp/va-authoring-discovery \
  node node_modules/@playwright/test/cli.js test --list --reporter=json
python3 scripts/ci/test-focused-tests.py
python3 scripts/ci/test-phpunit-shards.py
```

## Source-bound component evidence and remaining gates

The final reviewed backend passed **90 SQLite cases / 1,746 assertions**, covering both new suites plus existing order history, original items and purchase claims; JUnit records 185.014870 seconds. Its two new suites separately passed **31 native MySQL cases / 519 assertions**, JUnit 103.385771 seconds. Both receipts have zero errors, failures and skips. Native MySQL 8.4.11 retained normal durability. The independent reviewer parsed those receipts and approved exact `f62a4a5`; the feature source is unchanged in this composition. The author record preserves prior fixture/command failures and their corrections. This integration did not repeat the native run or claim new concurrency evidence.

The reviewed UI child passed **688 frontend cases in 32 files**, including 47 new cases, before integration with the preceding batch's 27 inquiry-history cases. Its independent reviewer also passed the 47 new cases. TypeScript and an external-directory production build passed on that child; the existing bundle advisory and earlier copy-expectation failure remain recorded. Those child results and the actual 715-case composed discovery are distinct: this record does not claim that the composed 715-case suite executed successfully.

Exact disjoint feature preservation, bounded registration and the unchanged contracts exposed no concrete additional composition defect requiring a duplicate behavior run. Full composed execution remains required. Local pinned browsers and genuine scanner/signature runtime are unavailable, so the extended original-session helper, both rendered browser engines, the complete composed suite and duration bounds still need fresh hosted evidence. Require all full gates, ten independently verified database receipts and exact aggregation at the final published source, independent final review, expected-head merge and fresh main verification. This work does not close its broader customer/financial parent tasks or establish production, migration or cutover readiness.
