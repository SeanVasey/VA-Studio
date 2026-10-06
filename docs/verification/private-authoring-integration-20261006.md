# Private authoring development integration — October 6, 2026

This batch builds on verified remote main `13d7474f2f843084f7c7ab94b4e5e8105d08bbce`, including merged PR #14's `4abee91825adc23a0fb9e572531c4d65cbcfa998`. It preserves the reviewed product ancestry incorporated through PR #13. The integrating PR records the composed commit/tree, ordered local-to-GitHub source mapping, independent reviews, cheap preflight and expected-head merge disposition. Development acceptance is distinct from final release acceptance.

## Completed component functionality

| Component | Actual source | Focused evidence and independent review |
| --- | --- | --- |
| Selected license-draft source replacement | `d79a07e02693724eb2b409ee56293781a5792a8e` | 105 MySQL cases / 2,531 assertions, including 20 actual independent-process PRIMARY record waits; 211 SQLite compatibility cases / 955 assertions. Root independently reran 20 adverse cases / 79 assertions and reviewed the exact retained source |
| Mounted bulk review/save/recovery | `2f980cd80a6b8c6e7daadd9dd2d7ae9fea5c1d6b` | Same 61-case / 1,079-assertion selection passed on both SQLite and genuine MySQL; independent exact-source UI/native review |
| Native journey and database evidence helper | `6fe2c34f47106312ef6767a1eec1b589197594f2` | Actual 4-case / 119-assertion private subprocess selection passed, including whole-row timestamps and lost-response/no-op evidence; independent review reran it. Type checking and browser discovery passed; rendered engine execution remains deferred |
| Durable empty private onboarding | `15862fa4f035dd1c8459e94fa96de06d69a7b314` | Actual 18 Node/PHP/SQLite/HTTP cases passed, including real admin creation, signed draft editing, three restarts, stable sessions/key/files and fail-closed ownership/path/lease checks; reviewer independently passed all 18 and verified source/class resolution |
| Redacted production track-readiness report | `e236f0a0db3679dbf8891d15ff312763bfbfe013` | Actual 66 SQLite cases / 334 assertions; independent reviewer reran the complete affected selection. Every existing production refusal stays enforced |

The bulk action replaces only explicitly selected current editable license drafts, with a strict 1–25 bound and exact before/after review. Actor, template and draft fences protect the entire batch. Stale evidence rejects every write; identical source preserves timestamps, authorship and audit rows. Lost responses retain a copyable source and require a fresh review. Published licenses, purchased terms and existing single-draft behavior remain protected. [Domain](bulk-license-draft-source.md), [editor](bulk-license-draft-source-ui.md) and [native evidence](bulk-license-source-browser-evidence.md) documents state the detailed contract.

[Persistent onboarding](persistent-content-onboarding.md) retains real private track/license/CMS authoring across stop/restart without fabricated catalog, prices or default credentials. It isolates external effects and refuses changed installation identity. Pending uploads are not processed media. A separately owned explicit stopped copy-upgrade child is in progress; this component does not silently migrate, reset or rekey existing data.

`php artisan vasey:commerce-readiness --json` reports separate implemented-code barriers, bounded configuration shapes and unverified acceptance facts. It performs no provider, database, storage, mail or queue effects and never certifies deployment. [Its contract](production-commerce-readiness.md) explains why configured settings cannot unlock the current test-only production commerce boundaries.

## Composed focused validation

The actual composed application selection passed **216 cases / 1,963 assertions**, with zero failures, errors or skips, using PHP 8.4.26, SQLite and an isolated random test key:

```sh
php vendor/bin/phpunit \
  tests/Feature/BulkReplaceLicenseDraftSourceTest.php \
  tests/Feature/BulkLicenseDraftSourceAuthoringActionTest.php \
  tests/Feature/LicenseDraftAuthoringActionTest.php \
  tests/Feature/LicensingAdminTest.php \
  tests/Unit/BulkLicenseDraftSourceBrowserEvidenceTest.php \
  tests/Feature/ProductionCommerceReadinessTest.php
PERSISTENT_CONTENT_REQUIRE_PHP=1 node --test scripts/dev/persistent-content.test.mjs
python3 scripts/ci/test-focused-tests.py
python3 scripts/ci/test-workflow-cadence.py
```

The composed onboarding run passed all 18 cases without skips. Scoped Pint passed all 14 affected PHP paths. Focused-selection safeguards passed 44 cases and workflow-cadence safeguards passed 12; prior scope, partition, database-receipt and GitLab writer-routing safeguards passed 27, 36, 34 and 7 respectively. The affected GitLab database-receipt safeguards also passed all 24 cases. Runtime-required onboarding checks receive a genuine locked npm install/build in their fresh quality checkout; missing PHP/build prerequisites fail instead of supplying skipped proof.

Actual PHPUnit discovery on both drivers reports the same **4,307 cases across 261 files**, preserving all 4,093 predecessor identities and adding 214 cases. This is discovery, not full execution. SQLite's exact native-only policy is 135 methods expanding to 453 cases. Only the two new bulk concurrency methods add exclusions; all 20 of their cases have actual MySQL execution. Original 227 timing rows and original method bodies remain intact; four appended bulk timing rows identify their real raw XML source. Readiness cases use the existing untimed-file fallback.

Browser discovery defines 132 cases across 39 files, 66 per engine, preserving the prior 130 identities. The added journey and screenshot definitions are not rendered browser or captured screenshot proof.

## Policy and unresolved acceptance

Current AGENTS.md governs every component and integration. Tracked `ci.yml` and `focused.yml` remain absent. Selected feedback and final verification remain manual; the latter requires the exact reviewed 40-character SHA and rejects a moved ref. GitLab full acceptance is also manual-only: its workflow admits explicit web/API requests only with a lowercase 40-character `EXPECTED_SHA` equal to the pipeline SHA; a checkout guard independently verifies the actual HEAD before provenance or dependent jobs. The inherited merge-request/push rules were caught before publication, removed and independently re-reviewed. No GitLab pipeline was launched. Cheap PR preflight retains frontend tests/builds/audit, bundle secret scans, Composer audit, required native setup checks and provenance safeguards. No full or manual hosted workflow was dispatched to produce these component results.

The external source/evidence packet binds each reviewed commit/tree to every tracked blob and SHA-256, actual commands, terminal XML/logs, review reports and failed predecessors. Earlier test setup/assertion failures and the onboarding predecessor's incorrect class-resolution provenance remain preserved. A temporary inventory report with incorrect policy-pair expansion is retained as unaccepted preparation, not runtime evidence.

Complete MySQL/SQLite matrices, rendered Chromium/WebKit recovery journeys, genuine scanner/media acceptance, actual Mac/server setup, real provider/mail/storage interoperability and measured backup restore remain open. Cancelled run `37521810874` and earlier native browser failures remain incomplete/failed acceptance records; they must not be restarted or reused as proof of this source. The final consolidated manual verification belongs to the exact completed integration candidate.

Membership-credit, transactional notification, production-policy and copy-upgrade successors run on separately owned branches. Their preparation does not close production commerce or the broader 40 outcome groups. The [parallel plan](../parallel-execution-20261006.md) and [source-backed remaining assessment](../remaining-code-assessment-20261006.md) retain the complete scope, dependencies and separate engineering/configuration/acceptance estimates.
