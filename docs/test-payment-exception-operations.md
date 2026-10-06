# Retained test-payment operational history

T19-OPS-01 is a bounded continuation of [retained exception inspection](test-payment-exception-inspection.md). It adds operator acknowledgments and leased, own-account test-payment observations. **It does not resolve a financial exception. T19 and WP-07 remain open.** The original `paid_exception` record remains terminal, its inventory/promotion resources remain pending, and fulfillment remains blocked.

Current extension: [T19-FINANCIAL-OBS-01](test-payment-financial-observation.md) adds bounded Charge/refund/dispute GET observations to newly confirmed checks. Its additional response contract, immutable sidecar and explicit incomplete/unavailable states supersede this original increment's `not_inspected` limit. Old history remains unchanged, and financial resolution and resource effects remain blocked.

The protected test-payment exception list now exposes four named actions: the existing historical graph inspection, operational history, recording `acknowledged` or `needs_review`, and checking the original payment's current test status. It still denies ordinary model create/update/delete/detail abilities and every bulk action. An acknowledgment is not an approval to fulfill, refund or release resources.

## Review and append contract

`TestPaymentExceptionOperations` requires an independent transaction, a freshly locked persisted administrator, verified email and the existing MFA rule. It only operates in local/testing against the exact configured Stripe test account. It verifies the original order/finalization graph before returning operational history or appending anything. Fresh checkout, processing and finalization flags may be withdrawn without removing the ability to acknowledge retained history; actual provider checks additionally require the existing processing policy.

The order lock serializes coordination creation. Current locking reads of the work row and events protect the sequence from an earlier repeatable-read snapshot. Each operation records an immutable event and minimized actor audit in the same transaction. A case-scoped request UUID, operation kind, disposition and actor define a retry; an identical retry returns the retained result without another event or provider request. Conflicting request reuse and stale review sequence fail closed. Database guards retain events, constrain canonical UUID bytes, bind adjacent event/coordination sequences and refuse populated rollback.

The Filament modal captures its request UUID and expected sequence when opened. Submission does not recapture current state. On an unconfirmed operation it retains that request for retry; closing and reopening obtains a new review. Operational history returns at most the latest 20 events and is itself audited. Projections and audits omit buyer details, account/provider IDs, ciphertext, hashes, asset paths and request/claim tokens.

## Leased current observation

The explicit check commits a `reconciliation_requested` event and a 120-second UUID claim before calling the existing `VerifyTestPayment::inspect` path. Account, Checkout Session and PaymentIntent retrieval happen outside all database transactions. The original increment uses the existing GET interface; the linked financial-observation extension adds a separate GET-only interface under the same claim. Neither calls `VerifyTestPayment::commit`, payment creation/capture/refund, finalization or fulfillment dispatch.

The result transaction rechecks current operator authority, configured account/processing policy, immutable original intent/payment identity and exact claim token/expiry. A completed request replays its existing result without I/O. An active claim reports busy. An expired request may be reclaimed only while its request event remains the current sequence; a later disposition prevents it from returning to the front of the queue. A replaced or expired worker appends nothing.

Only a minimized payment observation (`confirmed`, `pending`, `authorized`, `expired`, `canceled`, `attention` or `unavailable`) is appended. Even `confirmed` leaves the original exception and blocked fulfillment unchanged. In this original increment, refund and dispute state remains `not_inspected`; new checks follow the linked financial-observation contract. The PaymentIntent's successful status is not proof that no refund or dispute exists. Corrupt or conflicting provider evidence cannot establish a financial resolution. Provider fixtures used in automated tests do not establish actual account interoperability.

## Verification and limits

Source was prepared on isolated branch `codex/payment-exception-operations-20261002` from `d953b93711f4093f5c033e3047972bff983888a5`. Exact tested commit and integrated acceptance belong in the integrating merge request.

Focused verification commands:

```sh
php vendor/bin/phpunit tests/Feature/TestPaymentExceptionOperationsTest.php tests/Feature/TestPaymentExceptionOperationsConcurrencyTest.php tests/Feature/TestPaymentExceptionInspectionTest.php tests/Feature/TestCommerceOperationsTest.php
bash scripts/dev/with-mysql-test-server.sh php vendor/bin/phpunit tests/Feature/TestPaymentExceptionOperationsTest.php tests/Feature/TestPaymentExceptionOperationsConcurrencyTest.php
```

The MySQL launcher requires the documented portable runtime environment variables and creates its own disposable localhost database. Independent workers prove exact order-row contention for simultaneous dispositions, a second request finishing during the first provider call without database locks spanning I/O, and stale-token rejection after an expired claim is reclaimed. The workers independently report their database connection/process identity, provider transaction levels and empty job queues. SQLite cannot prove those races.

Final frozen-source checks passed on PHP 8.4.26: SQLite **82 reported /79 executed /3 exact MySQL-only skips /976 assertions**, and MySQL 8.4.11 with repeatable read and performance schema enabled **28/28 tests /431 assertions /zero skips**. The MySQL suite includes the three independent-process races. Targeted formatting and whitespace checks passed. Earlier feedback retained an 18-case SQLite domain pass; the first full MySQL attempt failed three assertions because the parent queue fake still contained synthetic fixture media jobs, after all three race assertions succeeded. The fixture baseline was corrected and child-process queue assertions added. The first extended browser attempt retained the original strict journey verifier and failed after a successful acknowledgment because it reused an earlier inspection phase; explicit operational phases now verify the exact one-event/audit history and unchanged financial graph. The broader SQLite run identified the previous one-action whitelist, which now explicitly lists all four bounded actions while preserving every model mutation denial. Independent review also required canonical UUID byte predicates, now covered by hostile direct inserts/updates. These earlier runs are not final acceptance evidence.

The expanded native WebKit mobile journey passed acknowledgment/history, policy-disabled provider-check handling, unchanged financial evidence and guard definitions, privacy and authority withdrawal. The final formatted-fixture run passed **1/1 case in 17.7 seconds** with no skip or retry. Composed hosted acceptance, Chromium desktop execution and merge remain required. The existing original exception graph, actual provider refund/dispute reconciliation, policy-approved financial resolution, resource release, recovery/library and production gates remain separate next work. No live credentials or business-policy choices are installed by this increment.

The integrating owner registered the exact three-dataset MySQL-only method for SQLite skips and both new classes in bounded focused commerce feedback. The 26 selector safeguards and 24 receipt safeguards passed; this registration preserves the full native acceptance requirement.
