# Launch documents lane: evidence (2026-10-10)

Branch `harness/launch-documents` from `f57e7256891d0d3c117e151a36f7fb967c724ab7`. Documents only. See `PLAN.md` for the files owned, assumptions and acceptance criteria. These are focused documents checks, not a test-suite run or any form of acceptance.

| File | What it records |
| --- | --- |
| `PLAN.md` | Files owned, assumptions, acceptance criteria, checks, what cannot be proven here (committed before any edit). |
| `red-contradictions.txt` | The contradicting lines in the five reconciliation targets as they stood at the base SHA, and the absence of `docs/legal/`, before any edit. |
| `red-check-citations.txt` | First run of `check-citations.py`: failed on three header lines (the check compared whole lines while the header carries Markdown emphasis) and on one glob cited as a path in the index. Both fixed; neither was a factual error in the drafts. |
| `check-citations.py` | The check: extracts every backticked repository path from the three legal documents, fails on a missing path or a missing owner-review header. Run from the repository root with `python3 docs/verification/launch-documents-20261010/check-citations.py`. |
| `green-check-citations.txt` | Second run: 209 citations across 3 documents, `RESULT PASS`, exit 0. |
| `green-check-citations-pass2.txt` | Third run, after the second-pass corrections below: 214 citations across 3 documents, `RESULT PASS`, exit 0. |
| `pass2-source-checks.txt` | Source greps behind the second pass: zero analytics/tracker matches in `resources/js`, `resources/views`, `app`, `config`; the customer-account, identity and service-project gates call `TestEnvironment::admitsTestCommerce()` (`local`, `testing`, `staging`). |
| `green-contradictions.txt` | The same greps after the edits, showing each corrected line with its citation, plus the three new `docs/legal/` files. |
| `independent-review-58cc803-DECISION.md` | The independent review of `58cc803`: REQUEST CHANGES, with four conditions (copied verbatim). |
| `pass3-source-checks.txt` | Third pass, for the review conditions: the false IP claim and missing sections at `58cc803` (red), the session, attachment, service-project and free-grant sources, and the corrected headings and retention rows (green). |
| `green-check-citations-pass3.txt` | Run after the third pass: 236 citations across 3 documents, `RESULT PASS`, exit 0; `git diff --check` clean. |
| `green-check-citations-pass4.txt` | Final run, after the delta review's conditions: 239 citations across 3 documents, `RESULT PASS`, exit 0; `git diff --check` clean. |
| `independent-review-8838dd7-DECISION.md` | The delta review of the third pass. |

## Commands and results

```
$ python3 -I docs/verification/launch-documents-20261010/check-citations.py
docs/legal/README.md: 95 distinct cited paths
docs/legal/PRIVACY.md: 89 distinct cited paths
docs/legal/TERMS.md: 55 distinct cited paths
checked 239 citations across 3 documents
RESULT PASS
exit 0
$ git diff --check
(exit 0)
```

This is the final run (`green-check-citations-pass4.txt`), after the delta review's conditions. The earlier 209-, 214- and 236-citation runs are kept in `green-check-citations.txt`, `green-check-citations-pass2.txt` and `green-check-citations-pass3.txt`. The check proves only that each cited path exists and that each draft carries the header; it does not check what a sentence says about the cited file. The independent review showed that a hand spot-check missed a false claim, so every prose claim still needs a reviewer to read it against its source.

No PHP or TypeScript file changed, so Pint, PHPUnit, the frontend tests and the build were not run; running them would not exercise this change.

## Claims corrected, with citations

| File | Was | Now | Citation |
| --- | --- | --- | --- |
| `docs/ops/production-activation-packet.md`, section 1 gate row | "the test checkout/processing policies require `local`/`testing`" blocks hosted test-mode payments on a staging host | `CheckoutPolicy` and `PaymentProcessingPolicy` admit `staging` since PR #68 at `d8aba146`; only `ExecutionContextV1` test funds still require `local`/`testing` | `app/Support/Environment/TestEnvironment.php` (`TEST_COMMERCE`); `app/Domain/Commerce/Checkout/CheckoutPolicy.php` and `app/Domain/Commerce/Payments/PaymentProcessingPolicy.php` (`TestEnvironment::admitsTestCommerce`); `app/Domain/Commerce/ProductionCheckout/ExecutionContextV1.php` (`environment(['local', 'testing'])`); `docs/private-server-readiness.md`, "First sale and cutover remain separate gates" |
| same file, section 6 finding and S2b bullet | "the current source refuses S2 on a hosted staging environment"; S2b "needs a reviewed code change" | Finding dated to base `3716324a` and marked superseded; S2b records the merged admission and the remaining production-checkout gates | as above |
| same file, section 8 unknowns | whether S2 runs through "a reviewed staging admission change" is unknown | the admission is no longer unknown; the S2a/S2b choice remains Sean's | as above |
| `docs/live-payment-and-production-preparation-queue.md`, line 20 | "hosted staging test mode is refused" | past tense at base `3716324a`, superseded by PR #68 at `d8aba146` | as above |
| `docs/handoff/2026-10-08/MONDAY-PLAN.md`, section 2 interim-environment row | "Every test-commerce policy admits only `local`/`testing` today" | "admitted only … when this was decided; M-06 (PR #68 at `d8aba146`) has since admitted `staging`" | same file, row M-06; `docs/private-server-readiness.md` |
| `docs/architecture/decision-register.md`, U-01 | open: namespace, creation and access unresolved | resolved: `SeanVasey/VA-Studio`; D-02's `VASEYDEV/VASEYAUDIO` kept as history | `ops/staging/README.md`, provisioning step 4; `docs/handoff/2026-10-08/MONDAY-PLAN.md`, status log |
| same file, new dated entry | no record of the staging-host choice | Forge + VPS (DigitalOcean) recorded as the staging-host decision, explicitly not U-02 | `docs/handoff/2026-10-08/MONDAY-PLAN.md`, section 2; `docs/ops/production-activation-packet.md`, sections 2 and 8; `docs/site-content-releases.md` ("not chosen yet (U-02)") |
| same entry and `ops/staging/README.md`, step 1 | plan says "at least 4 GB RAM", kit says 4 vCPU / 8 GB / 160 GB, neither names the other | both figures stated; the ops README named as the sizing specification and 4 GB as its floor | `ops/staging/README.md`, step 1; `docs/handoff/2026-10-08/MONDAY-PLAN.md`, S-2 |

## Second pass (continuation after the first implementer stopped)

A review of the drafts against the source found three statements that repeated pre-#68 documentation instead of the code. Corrected in the drafts:

| File | Was | Now | Citation |
| --- | --- | --- | --- |
| `docs/legal/PRIVACY.md`, section 2.3 | test customer accounts admitted only in `local`/`testing` | admitted in `local`, `testing` or the hosted `staging` rehearsal, only with `VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED=true` (default false) | `app/Domain/Customers/CustomerAccessPolicy.php`; `config/customer.php`; `app/Support/Environment/TestEnvironment.php` |
| `docs/legal/TERMS.md`, section 6 | enrollment and recovery only in `local`/`testing`; passwords "12–72 bytes" | admitted wherever test accounts are; passwords at least 12 characters with letters and numbers and at most 72 UTF-8 bytes | `app/Domain/Customers/CustomerIdentityPolicy.php`; `docs/customer-test-self-service.md`, "Customer journey" |
| `docs/legal/TERMS.md`, section 9 | service briefs and quotes only in `local`/`testing` | `local`, `testing` or `staging`, behind a default-off flag | `app/Domain/Services/Projects/ServiceProjectPolicy.php`; `config/services-projects.php` |

## Third pass (independent review of `58cc803`)

The review (`independent-review-58cc803-DECISION.md`) returned REQUEST CHANGES. Applied in `docs/legal/PRIVACY.md`:

| Condition | Was | Now | Citation |
| --- | --- | --- | --- |
| 1 (HIGH) | Section 2.1: "The application reads the visitor's IP address only for rate limiting"; the retention table listed only the 120-minute cookie | Every browser session is stored server-side in the `sessions` table with the user ID, IP address, user agent and payload; the payload is encrypted when `SESSION_ENCRYPT` is true, but the IP and user-agent columns are not. "Only for rate limiting" is removed. A new section 4 row says the rows expire after `SESSION_LIFETIME` and are deleted by probabilistic garbage collection (2 in 100 requests), with no fixed purge schedule | `config/session.php` (`driver`, `lifetime`, `encrypt`, `lottery`); `database/migrations/0001_01_01_000000_create_users_table.php`; `ops/staging/env.staging.example`; `ops/staging/forge-deploy.sh`; Laravel `DatabaseSessionHandler::addRequestInformation` and `gc` (vendor, quoted in `pass3-source-checks.txt`) |
| 2 (MEDIUM) | No section for support attachments, service-project briefs or free-license name and assent | New sections 2.6, 2.7 and 2.8, each marked default-off and cited, with matching section 4 rows. Attachments are admitted only in `local`/`testing`, not `staging`; their row records the 24-hour access expiry, no automatic deletion, and explicit tombstone and physical cleanup. Email and staff sections are renumbered 2.9 and 2.10 | `routes/support-attachments.php`; `config/support-attachments.php`; `app/Domain/SupportAttachments/FixtureAttachmentPolicy.php`; `docs/verification/support-attachments-20261007.md`; `routes/services.php`; `app/Domain/Services/Projects/ServiceProjectPolicy.php`; `database/migrations/2026_10_07_244000_service_projects.php`; `docs/service-projects.md`; `docs/free-grants.md`; `config/free-grants.php`; `database/migrations/2026_10_07_245000_free_grant_origins.php` |
| INFO | Section 2.2 step 3 deferred to Stripe's notices | It also says that no `customer_email` is passed, so Stripe's page asks the buyer for an email address and payment details | `app/Domain/Commerce/Checkout/CheckoutEvidence.php` |
| 3 (LOW) | "Commands and results" showed the 209-citation run | It shows the final 236-citation run | `green-check-citations-pass3.txt` |

Condition 4, re-review of the changed `PRIVACY.md` text, was met by an independent delta review of `8838dd7` (`independent-review-8838dd7-DECISION.md`, APPROVE WITH CONDITIONS).

## Fourth pass (delta review of `8838dd7`)

| Condition | Change |
| --- | --- |
| Section 3 malware row said uploads are scanned "before they are stored or served" | Uploads are scanned before they are promoted, served or downloaded; staff media stays private until a clean scan; support-attachment originals are stored first and scanned before any download. Cites `docs/media-processing.md` and `docs/verification/support-attachments-20261007.md`, "Effects, retry and retention" |
| Section 2.1 named only the embed and contact limiters | Names the other per-IP (guest) and per-user throttles in `routes/web.php`, `routes/customer.php`, `routes/free-grants.php`, `routes/inquiry-conversations.php` and `routes/discovery-track-sitemaps.php`, and says the counters are stored under hashed keys in the database cache; section 4 adds a row saying expired counters have no scheduled purge (`routes/console.php` registers no cache prune), listed under U-13 |
| Re-run `check-citations.py` | 239 citations across 3 documents, `RESULT PASS`, exit 0 (`green-check-citations-pass4.txt`) |

The review's INFO items are not conditions and stay open for the owner's privacy review: no trusted proxies are configured, so a CDN or proxy in front would make the stored session IP the proxy's; Stripe's email collection is inferred from the absent `customer_email`; the free-grant `payload_hash` is a hash of the plaintext. The contradiction it found in `docs/service-projects.md` and `config/services-projects.php` (no attachment authority, against the registered `test_service_project_v1` attachment family) is added below.

The `check-citations.py` script is unchanged. The review's LOW finding on its scope (it checks paths and headers, not facts) is recorded above under "Commands and results", not fixed.

## Contradictions reported, not edited

Integration update: the `CLAUDE.md` Project Notes line and the four guides that said `local`/`testing` only (`docs/service-projects.md`, `docs/free-grants.md`, `docs/verification/customer-account-test-journey.md`, `docs/customer-test-self-service.md`) were corrected at integration, after checking that each gate calls `TestEnvironment::admitsTestCommerce()`. The other items below remain as reported.

- `CLAUDE.md`, Project Notes, "§5 auth": "customer accounts don't exist yet (U-07)". Test customer accounts exist and are documented (`docs/verification/customer-account-test-journey.md`; `docs/customer-test-self-service.md`; `routes/customer.php`); production identity remains default-off (`config/production-customer-identity.php`). U-07 (production enrollment, recovery and claim policy) is still open, so the accurate statement is that production customer accounts do not exist yet.
- `resources/contracts/test-v2/PROVENANCE.md`: "current issuance profile and policy still select v1". The registry selects v2 (`app/Domain/Contracts/ContractRenderProfileRegistry.php`, `CURRENT_VERSION = 'test-buyer-pdf-v2'`; `app/Domain/Contracts/ContractIssuancePolicy.php`, `profile => 'test-buyer-pdf-v2'`; `docs/test-contract-issuance.md`, "The current development runtime selects v2"). The file is frozen and was not changed.
- `docs/architecture/decision-register.md`, D-02: "Target GitHub repository name is VASEYAUDIO". Left as history; the 2026-10-10 entry records the current repository beside it.
- `docs/service-projects.md` ("activates it only in `local` or `testing`") and `docs/free-grants.md` ("mint only `test_only: true` definitions in `local` or `testing`") also predate PR #68: `ServiceProjectPolicy`, `FreeGrantPolicy` and `FreeGrantDefinitions` call `TestEnvironment::admitsTestCommerce()`. `PRIVACY.md` sections 2.7 and 2.8 follow the code. Outside this lane's owned files.
- `docs/verification/customer-account-test-journey.md` ("enables the feature only in `local` or `testing`") and `docs/customer-test-self-service.md` ("The environment must be `local` or `testing`") predate PR #68: the code admits `staging` through `TestEnvironment::admitsTestCommerce()`. Outside this lane's owned files; reported for the integrator.
- `docs/service-projects.md` ("No private file intake/scanning") and the comment in `config/services-projects.php` (no attachment authority) contradict the registered `test_service_project_v1` attachment family (`app/Providers/SupportAttachmentServiceProvider.php`). `PRIVACY.md` section 2.6 follows the code. Outside this lane's owned files.
