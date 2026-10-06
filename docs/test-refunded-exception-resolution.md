# Refunded test-payment exception resource resolution

This bounded T19/T21 workflow releases the original still-pending inventory and promotion resources of an unfulfilled test `paid_exception` after conservative, fresh evidence of complete successful refunds. The payment, exception, purchased snapshots and original legal evidence remain unchanged. It does not send a refund, change grants or contracts, reverse consumed resources, relist a completed exclusive sale, enable live commerce or complete the wider payment and fulfillment programme.

## Configuration and operator flow

The feature defaults off. `VASEY_TEST_REFUND_RESOLUTION_ENABLED=true` and the exact JSON object declared by `RefundResolutionPolicy::CONTRACT` in `VASEY_TEST_REFUND_RESOLUTION_POLICY` are required for a write. The environment must be `local` or `testing`, using the configured own Stripe account in test mode. The existing payment-processing policy must also permit the reused verification operation. Production is refused even when the flags are supplied. No existing checkout, processing, finalization or webhook flag is enabled by this package.

Authorized staff use **Test commerce → Test payment exceptions**. **Refund resolution history** shows only the retained resolution identifier/time and a bounded event projection. **Verify full refund and release** reviews the current sequence and fixes one request UUID when the dialog opens. Submission and uncertain retries preserve that exact pair. Closing and reopening starts a new review; the action does not silently substitute a newer sequence or a new request key after an unknown result.

| Result | Meaning |
| --- | --- |
| `released` | The retained pending-resource release is confirmed, including an exact replay. |
| `not_refunded` | Available observations did not establish the complete successful-refund contract. |
| `attention` | Original-payment or financial evidence is conflicting, incomplete or unsupported. |
| `unavailable` | Verification could not establish a release; inspect history before starting a new review. |
| `busy` | A verification claim is already active. |
| `stale` | The original review, request deadline or observation is no longer current. |

Only `released` with `testOnly: true` displays success. Raw exceptions, provider identifiers, buyer identity, ciphertext and private evidence are not shown. Both review and write freshly enforce staff and required MFA authority; a loaded page or a previously checked user object is insufficient.

## Conservative financial evidence

The service reuses the existing [leased exception verification](test-payment-exception-operations.md) and [retained financial observation](test-payment-financial-observation.md). Original account, Checkout Session and PaymentIntent bindings are verified first. The GET-only financial adapter then verifies the original payment and Charge, complete explicit refund/dispute lists and a repeated Charge observation. Provider requests run outside all application database transactions. This package adds no provider mutation capability or API-version change.

The resolution contract requires a complete supported observation, the original Charge fully refunded by exactly its original integer amount, no disputed Charge and an empty complete dispute list. Every listed refund must be `succeeded`, bound to the original payment/charge, and its amount must contribute to an exact positive total. Partial refunds, pagination, unsupported or inconsistent data, pending or failed refunds, canceled historical attempts and even closed disputes are refused. Existing generic financial observations can retain those facts without authorizing this narrow resolution.

Stripe primary documentation checked on 2026-10-06 defines [refund amounts, relationships and statuses](https://docs.stripe.com/api/refunds/object), [explicit refund-list filtering and pagination](https://docs.stripe.com/api/refunds/list), [dispute-list filtering and pagination](https://docs.stripe.com/api/disputes/list), and [Charge refund/dispute fields](https://docs.stripe.com/api/charges/object). The embedded Charge refund list is not completeness evidence. The application's release rule is a conservative policy applied to these observations. Separate provider GETs are not an atomic snapshot and do not establish settlement finality or prevent a later dispute.

## Freshness, replay and immutable evidence

A resolution request is insert-only and binds its actor, original reviewed sequence, UUID, policy version and creation time. A new unresolved request expires at exactly 120 seconds from that original creation time. Reusing an old request cannot reset its clock or begin fresh provider verification. Its successful financial observation must be less than 60 seconds old at the resource commit. Clock checks follow all required locks and reject future observations. The retained proof binds both the exact original request timestamp and reconciliation-request timestamp, as well as the original order/finalization/payment hashes, observation and resources.

An already successful exact request can confirm the retained result without another provider read or age-based reauthorization of the historical release. Current staff, MFA, account and enabled policy still govern that command. Historical order reading verifies the retained policy/proof independently of a currently disabled write flag. Changed request arguments or another actor cannot take over the original request.

The commit locks fresh User → Order → exception work → optional promotion campaign/use → sorted rights scopes → reservation/claims. It inserts encrypted canonical proof and releases only the original pending resource pair in one transaction. The service rechecks current authority, account, policy, deadlines and original graph after recording the audit. An audit callback cannot withdraw authority or delay a new release beyond either deadline and leave a committed release; a reversed clock also refuses the new commit. Audit failure rolls back the release. Exact replay after an uncertain commit retains the first result.

`FinalizationEvidence` validates the additional disposition through an acyclic proof verifier. `OrderEvidence` reconstructs the original pending resource state only after that proof is verified; purchased ciphertext and original payload hashes are never rewritten. Missing or corrupted resolution, request timing, financial sidecar or observation bindings fail closed. Paid/granted finalizations, consumed reservations and exceptions already produced by the unpaid-release path cannot qualify.

Migration `2026_10_06_000047_test_refund_resolutions.php` adds immutable request/resolution tables. It verifies exact owned schema and replaces only the two existing inventory/promotion update guards to add the proof-bound pending-to-released branch, retaining every prior transition and identity check. Empty rollback restores the exact prior definitions; populated rollback, ambiguous ownership, mutation, deletion and replacement are refused. These guards do not defend against privileged schema destruction. Existing manual migration roundtrips remove this new dependent migration first and restore it last.

## Focused verification

Run the four suites against the selected disposable database:

```sh
php vendor/bin/phpunit tests/Feature/TestRefundResolutionTest.php tests/Feature/TestRefundResolutionMigrationTest.php tests/Feature/TestRefundResolutionResourceTest.php tests/Feature/TestRefundResolutionConcurrencyTest.php
```

The suites cover full and partial refunds, multiple successful refunds, malformed and incomplete observations, exact replay, original evidence reconstruction, request/observation expiry, role/MFA/account/policy withdrawal during provider I/O and after audit, SQL immutability, rollback ownership, capacity and operator messaging. `TestRefundResolutionResourceTest` isolates the UI contract with a service double; the domain suite exercises real services with GET-only synthetic provider observations.

The MySQL suite has five cases in four method identities:

- `test_exact_authority_and_order_fences_keep_one_resolution_for_identical_or_competing_commands`: identical same-actor requests prove the User-row wait; competing requests use different staff and prove the Order-row wait.
- `test_committed_actor_withdrawal_fences_the_resolution_request_before_provider_io`: committed role withdrawal wins the User fence and prevents provider I/O.
- `test_new_scope_capacity_waits_for_the_refund_release_commit`: a new scope-capacity user waits for the resource commit.
- `test_last_promotion_capacity_waits_for_the_same_atomic_refund_release`: a last-use promotion claimant waits for the same atomic release.

Each native case uses independent processes/connections and checks the exact requesting/blocking InnoDB record wait, target table and primary-key ID. SQLite skips these five cases and cannot establish concurrency.

The browser journey `tests/browser/test-refunded-exception-resolution.spec.ts` selects one case for Chromium desktop and WebKit mobile:

```sh
npm run test:browser -- test-refunded-exception-resolution.spec.ts
```

It signs in as real staff, executes a real successful Livewire release and deliberately discards that response, then retries the original request. Private fixture verification checks unchanged original business rows and payload hashes, unchanged SQL guards, exactly four transaction-free gateway interface reads on release, no additional reads on replay and refusal of a partial refund. The production financial adapter performs multiple GETs. This browser fixture supplies synthetic observations and counts four gateway-interface calls, not provider network requests. Synthetic transport overrides exist only in the capability-protected isolated browser router. Ordinary fixture/server configuration keeps the feature disabled. Synthetic paid exceptions are prepared in the past, and real HTTP requests use the wall clock and unchanged application freshness checks.

Local receipts and remaining hosted gates are recorded below and in the integrating PR. Provider interoperability, a real test-account refund/dispute rehearsal, production refund/access policy and live financial effects remain separate acceptance work.

| Local check, 2026-10-06 | Actual result |
| --- | --- |
| Domain, migration and operator suites on SQLite | 65 passed; 488 assertions; 127.355 seconds, before the final clock changes |
| Complete four-suite selection on isolated MySQL 8.4.11 | 70 passed; 614 assertions; 552.982 seconds, after removal of the redundant post-clock query and before the final post-audit check |
| Final post-audit delta on MySQL 8.4.11 | 8 passed; 83 assertions; 56.047 seconds: both successful resource shapes with replay at +121 seconds, three audit-clock cases, audit rollback/exact retry, original-request expiry and observation expiry |
| New migration plus eight existing migration suites on SQLite | 64 passed; 800 assertions |
| Independent operator-resource verification | 15 passed; 173 assertions |
| Frontend/source checks | TypeScript, targeted new/operator PHP formatting and diff checks passed |
| Browser journey | Two engine cases discovered; rendered execution remains a hosted gate |

The final bounded native command used `TestRefundResolutionTest.php --filter 'test_final_audit_clock_change|test_full_refund_releases_only_pending_resources|test_resolution_audit_failure|test_original_resolution_request_expiry|test_old_observed_result'`. All native receipts used ordinary durability: `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite enabled and binary logging enabled. The final source adds three audit-clock cases to the earlier full selection; its complete composed acceptance remains a hosted CI gate. The existing `FinalizationEvidence.php` and `OrderEvidence.php` whole-file formatting failures were reproduced against the unchanged accepted base; this package keeps their edits narrow.

The initial multi-case native migration attempt hit transient tablespace collisions while using a scratch-backed disposable datadir. Isolated schema cases passed, and the normal-durability wrapper subsequently moved disposable data to `/tmp`; the underlying storage cause was not established. No application migration, durability setting or assertion was weakened. Local rendered browser execution is unavailable because the pinned browser binaries are absent; genuine bootstrap also requires the unavailable ClamAV executable. Those gates were not bypassed.
