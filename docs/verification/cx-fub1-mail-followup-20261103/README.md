# CX FUB-1 mail follow-up — source preparation, 2026-11-03

**Prepared source only. PHP syntax, Pint, discovery, application tests, mutations and runtime verification are UNRUN.** Base is actual `b5d1467bbe00743d0d7298dc2a6d9687c9fec388`, on isolated sparse branch `codex/cx-mail-followup-prep-20261103`. This is remaining-C preparation; it does not reorder publication or bypass the required B security assessment.

Scope: `QueueTransactionalMail.php`, `TransactionalMailTest.php` and this packet only. No adapter, outcome policy, transport, configuration, queue job, schema, cursor, durable recovery, workflow, census, dependency, vendor or other product source changed.

## Behavior and existing boundaries

FUB-1 P3-2: the command still scans/continues within its original limit and four-times scan budget, counts refusals/errors, logs only unexpected exception classes, and prints the same ordered summary. A separate boolean records an unexpected per-intent Throwable. After finishing the batch and summary, that boolean produces FAILURE; healthy and ordinary-refusal-only batches remain SUCCESS. Invalid options and global mail-configuration refusal keep their existing exits. The failure signal does not request retry, resend or a different delivery state.

FUB-1 P3-4: `TransactionalMailAdapter::verifyAuthority()` already maps exactly CustomerAccessException, QuoteException and DeliveryException to authority_withdrawn. The adapter is unchanged. Added provider cases isolate QuoteException(ORDER_NOT_FOUND,404) and DeliveryException(not_found) at the real authority status-read boundary through the pre-existing DB::beforeExecuting fixture technique. A callback is limited to one original notice ID; other intents traverse their genuine existing graph. Hit counts prove the selected refusal was reached.

These are exception-classification regressions with injected exceptions, not evidence that a real purchase/activation was externally withdrawn or that an actual transport was reached. Fixtures remain synthetic; Mail::fake and the existing one-attempt afterCommit fake-queue runner are retained.

## Test contract and intentional assertion delta

All 11 original test methods, providers and assertions remain. The **one intentional observable-contract change** is in `test_a_customer_or_purchase_refusal_on_an_older_intent_is_skipped_and_never_stops_the_batch`: its existing mixed-error summary now assertsFailed rather than assertSuccessful. ROOT explicitly authorized that necessary exit expectation change. Its previous delivery-existence, warning-class and privacy assertions are unchanged. Additional assertions prove no outgoing message before the job runner, exactly one later valid wake-up/message, and no message to either refused/error recipient.

New methods:

- `test_a_batch_with_only_authority_refusals_succeeds_without_delivery_rows_or_mail`: real unverified-customer refusal only; exact summary/SUCCESS, zero delivery rows/mail audit, no mail job or outgoing message.
- `test_purchase_domain_refusals_before_queue_leave_no_delivery_and_allow_a_later_valid_intent`, provider quote/delivery: direct queue refuses with authority_withdrawn and leaves no delivery/audit/job/mail. Command skips that same intent, queues a later valid one with SUCCESS and exact summary; only the valid fake mail is sent by its ordinary job.
- `test_purchase_domain_refusals_after_claim_retain_failed_state_without_mail_and_allow_a_later_valid_intent`, provider quote/delivery: claim is genuine queued→submitting, followed by failed/authority_withdrawn, version2, start/finish timestamps, null message hash, exact queued/submitting/failed audit and no outgoing message or stuck row. A later valid intent queues and runs. Replaying the old failed wake-up leaves every retained failed-row byte unchanged and sends nothing for it.

Existing `test_the_command_queues_pending_intents_in_bounded_batches` supplies healthy-batch success and option validation; existing customer-after-claim case is untouched. Existing fake-job assertions still require tries=1 and afterCommit=true. No original assertion is deleted or weakened.

## Source review actually performed

Author source-read review traced the governing FUB-1 ledger/re-review, `docs/transactional-mail.md`, command, adapter, notification authority path, PurchaseAccess, ActivationPolicy/DeliveryAccessEvidence, fixtures and job. The new error flag is independent of human-readable refusal reason keys, so an ordinary NotificationException remains a refusal rather than becoming a command defect based on its reason string.

Host-only Python byte comparisons reconstructed the original test file by reversing exactly the two imports, additive cases/helper/assertions and the approved single exit expectation; it then matched b5d byte for byte. The command similarly matches b5d after reversing only flag initialization/assignment/final return/comment. `git diff --check` passed. No PHP interpreter, SQL, Docker, worker, application test, install or runtime action was used. This is an author source check, not independent approval or execution acceptance; sensitive-change independent review remains due before publication.

Prepared code SHA256:

- command: `0239a77eb34aded16454460d1179a6107ee54d868531ee4676a9e805506821fd`
- test: `855bbd1f7aa0a63fa52e9830cfe6e05dcf1617d2f79816b4f3ca4642e3388a04`

## Minimal later ROOT execution conditions — UNRUN

Use the final exact candidate in ROOT's serial admitted isolated application environment, with genuine contained dependencies, disposable database lifecycle, ordinary mail-preflight fixture, scanner/renderer fixture prerequisites and fake mail/queue intact. This sparse source checkout has no vendor installation or runtime qualification. Do not invoke the real queue command against a retained installation to verify this change.

1. PHP-lint the two changed PHP files; Pint-test only those paths. These are proposed checks, not completed results.
2. Actual list-only discovery must prove the selected existing/new methods and both data sets. Run the following six methods through the existing bounded PHPUnit harness on SQLite, then the admitted Oracle MySQL8.4.11 fixture if available. Provider expansion is not an accepted current test count until discovery/JUnit agree:

```text
Tests\Feature\CustomerComms\TransactionalMailTest::test_the_command_queues_pending_intents_in_bounded_batches
Tests\Feature\CustomerComms\TransactionalMailTest::test_a_customer_or_purchase_refusal_on_an_older_intent_is_skipped_and_never_stops_the_batch
Tests\Feature\CustomerComms\TransactionalMailTest::test_a_batch_with_only_authority_refusals_succeeds_without_delivery_rows_or_mail
Tests\Feature\CustomerComms\TransactionalMailTest::test_purchase_domain_refusals_before_queue_leave_no_delivery_and_allow_a_later_valid_intent
Tests\Feature\CustomerComms\TransactionalMailTest::test_purchase_domain_refusals_after_claim_retain_failed_state_without_mail_and_allow_a_later_valid_intent
Tests\Feature\CustomerComms\TransactionalMailTest::test_a_customer_refusal_after_the_claim_fails_the_delivery_instead_of_leaving_it_submitting
```

Target file: `tests/Feature/CustomerComms/TransactionalMailTest.php`. Anchor any method filter at the method-name boundary while admitting the actual PHPUnit data-set suffixes; preserve original bodies and full EOF/reap/source/JUnit/cleanup evidence. No full CX/REP or hosted matrix is requested.

3. Bounded disposable mutation checks: restoring unconditional SUCCESS must fail the existing mixed-error exit case; remove only QuoteException from the catch union, then only DeliveryException, and each corresponding pre-queue/after-claim provider case must fail. Removing continuation must fail the later-valid assertions. Restore byte-exact candidate before accepted green runs. No mutant ran during preparation.
4. Preserve any discovery, fixture, engine or product failure as original evidence; never skip, weaken or fabricate counts. ROOT owns any later measured selection/timing/count integration and exact-head review/preflight/publication.

## Explicitly deferred

P3-1's terminal failed classification for broader authority/configuration refusal is unchanged. P3-3's afterCommit dispatch failure can still leave queued without a job; no recovery path is invented. F3's oldest-first scan still lacks durable fairness beyond its bound; no cursor/schema design is slipped into this slice. Sender/provider/host acceptance, real SMTP, worker death, live payments, deployment/DNS, customer import and launch remain outside this change.
