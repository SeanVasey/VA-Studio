# Review: lane "docs" (harness/launch-documents)

Range `f57e7256..58cc80340e73c67732aab041e0c359de72b2eb4d`, worktree `/home/user/wt-docs`. Read-only review; no worktree changes.

## Decision: REQUEST CHANGES

The reconciliation edits are accurate and in scope. However, one live-shaped data-handling statement in `docs/legal/PRIVACY.md` is false, and three default-off collection paths are missing. Accuracy of data-handling statements is this lane's sensitivity, so the draft must be corrected and re-checked before merge.

## Findings

**HIGH: PRIVACY.md:22 (also :20 and the :96 retention row).** The draft says "The application reads the visitor's IP address only for rate limiting". That is false. `config/session.php:21` defaults `SESSION_DRIVER` to `database`. The same driver is set in `.env.example:40` and `ops/staging/env.staging.example:58`, and `ops/staging/forge-deploy.sh:113` requires it. Laravel's `DatabaseSessionHandler::addRequestInformation` (`vendor/.../Session/DatabaseSessionHandler.php:250-255`) writes `ip_address` and `user_agent` into the `sessions` table (`database/migrations/0001_01_01_000000_create_users_table.php`) for every browser session. Those rows persist until lottery GC removes them (`config/session.php:117`). The retention row "Session cookie: 120 minutes" leaves out these server-side rows.

**MEDIUM: PRIVACY.md section 2 omits these default-off collection paths:**
- Support attachments: visitor and customer file uploads on inquiries and projects, with private originals, malware scanning, expiry and physical cleanup (`routes/support-attachments.php`, mounted at `routes/web.php:18`; `config/support-attachments.php`; `docs/verification/support-attachments-20261007.md`).
- Service-project briefs, which staging admits (`routes/services.php`; `app/Domain/Services/Projects/ServiceProjectPolicy.php:11`; `database/migrations/2026_10_07_244000_service_projects.php`).
- Free-grant declared name and assent (`docs/free-grants.md`; `database/migrations/2026_10_07_245000_free_grant_origins.php`). TERMS.md:25 describes this collection, but PRIVACY.md does not.

**LOW: `check-citations.py`.** I mutated a scratch copy. The script catches a removed or moved header, a mistyped path and a deleted cited file (4 of 4 killed). It does not catch a factual change ("120"→"999 minutes"), a path containing a space, or a `tests/` path (3 survived). That is acceptable, because the README labels it a documents check. It does mean prose claims rest on hand spot-checks. The finding above shows those checks missed some claims.

**LOW: verification README, "Commands and results".** The block shows the superseded 209-citation run. The final run is 214 (`green-check-citations-pass2.txt`), which I reproduced.

**INFO: PRIVACY.md:33.** Stripe Checkout is created without `customer_email` (`CheckoutEvidence.php:36-41`), so Stripe's page asks the buyer for an email address and billing details. The deferral to Stripe's notices is accurate, but this could be stated outright.

**INFO: commit trailers.** Four commits carry Fable 5.1 and one carries Opus 5.5. The implementer disclosed this.

**Verified correct:**
- Scope: only owned files changed. MONDAY-PLAN changed line 57 only, and `ops/staging/README.md` changed step 1 only. No forbidden paths changed.
- No secrets or real data.
- The #68 staging admission claims match `TestEnvironment`, `CheckoutPolicy`, `PaymentProcessingPolicy`, `ExecutionContextV1.php:60` and `AdminPanelProvider.php:78`.
- U-01 and the sizing figures match their sources.
- The render headers, password rule, delivery limits, consent columns and Stripe line items match the source.
- The invariants are not weakened, nothing is published, and every draft carries the header.

## Conditions (all in owned files)

1. Rewrite `docs/legal/PRIVACY.md` 2.1. State that every browser session is stored server-side in the `sessions` table with its IP address, user agent and session payload (`config/session.php`; `database/migrations/0001_01_01_000000_create_users_table.php`; `ops/staging/env.staging.example`), and remove "only for rate limiting". Add a section 4 retention row: rows expire after `SESSION_LIFETIME` and are deleted by probabilistic GC, with no fixed purge schedule.
2. Add default-off subsections to `docs/legal/PRIVACY.md` for support attachments, service-project briefs and free-grant identity and assent, each with citations. Add retention rows, including attachment expiry and cleanup from the cited verification record.
3. Update the "Commands and results" block in `docs/verification/launch-documents-20261010/README.md` to the final 214-citation run, then re-run `check-citations.py` and record the output.
4. Request re-review of the changed PRIVACY.md text.

## Verified by running

- `python3 -I docs/verification/launch-documents-20261010/check-citations.py` in the worktree: 95/64/55 cited paths, 214 in total, RESULT PASS, exit 0. This matches the claim.
- The same check on a `git archive` scratch copy with 7 mutations: 4 killed, 3 survived (above).
- The tracker grep over `resources/js resources/views app config`, widened to Sentry, Bugsnag, Datadog, New Relic and Cloudflare Insights: 0 matches.
- `git diff --check`: clean. Forbidden-path filter: empty. Secret-pattern scan of added lines: only `support@vasey.audio`.
- Source greps: `admitsTestCommerce` callers, `requiresStaffMfa`, the session driver, the `DatabaseSessionHandler` fields and `ExecutionContextV1:60`.
- I ran no PHPUnit. No PHP or TS changed and the lane cites no test counts, so there were no counts to reproduce.
