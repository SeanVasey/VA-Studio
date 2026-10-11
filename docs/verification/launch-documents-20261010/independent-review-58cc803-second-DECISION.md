# Independent review: lane "docs", f57e7256..58cc8034

**Decision: REQUEST CHANGES** for `58cc80340e73c67732aab041e0c359de72b2eb4d`. Nothing in the worktree was modified.

The branch has since moved (`d62f0e5`, now `7468661`). Later commits appear to address F1 to F3, but they were not reviewed here.

## Scope
All 17 changed files are in the owned list. MONDAY-PLAN changes only line 57. ops/staging/README changes only the step 1 sizing sentence. Nothing changed under resources/contracts, vendor, lockfiles, CHANGELOG, README, CLAUDE.md, app or routes. No secrets found. `git diff --check` is clean.

## Findings
- **F1 HIGH, PRIVACY.md:22 and :96.** "The application reads the visitor's IP address only for rate limiting" is false. The session driver is `database` (config/session.php:21; .env.example:40; ops/staging/forge-deploy.sh enforces `need SESSION_DRIVER database`). The `sessions` table stores `ip_address` and `user_agent` for every session (database/migrations/0001_01_01_000000_create_users_table.php:33-34), and Laravel writes both. Retention (:96) omits the server-side row.
- **F2 MEDIUM, PRIVACY.md section 2.** Three mounted, default-off features that collect personal data are missing: support attachments (routes/support-attachments.php, required from routes/web.php:18; FixtureAttachmentPolicy accepts files up to 5 MiB), service projects (routes/services.php) and free grants (routes/free-grants.php; TERMS.md:25 covers them, PRIVACY does not).
- **F3 MEDIUM, PRIVACY.md:86.** "ClamAV ... scans uploads before they are stored or served" overstates the order. Uploads are stored privately in quarantine first and scanned before promotion (docs/media-processing.md:16, :35, :89).
- **F4 LOW, PRIVACY.md:114.** "Public endpoints are rate limited" does not hold for `POST /webhooks/stripe` (routes/webhooks.php:7), which has no throttle.
- **F5 LOW, PRIVACY.md:117-125.** "Your choices today" lists account controls (consent withdrawal, library export, sign-out). These are test-only and not available to the public. Say so in the table.
- **F6 LOW, TERMS.md:22.** "these terms of service do not add, remove or interpret any license grant" is a drafted term with no citation. Move it to "Sean to decide" or mark it as proposed wording.
- **F7 LOW, verification README.md:18-27.** The "Commands and results" block shows the 209-citation run. The latest result is 214 (pass 2).
- **F8 INFO.** Mixed commit trailers (Fable 5.1 vs Opus 5.5), already disclosed.

## Claims verified
- **Staging admission:** TestEnvironment admits staging; the checkout, processing, account and service policies call `admitsTestCommerce`; ExecutionContextV1:60 still requires local/testing; `d8aba14` (PR #68) is an ancestor of the base.
- **U-01, host and sizing:** match MONDAY-PLAN rows 53-54, 72, 105, ops step 4 and the git remote.
- **Spot checks that held:** cookie flags, the password rule, the Stripe payload (no name or email, card only), quote and download limits, render-profile headers, `CURRENT_VERSION` v2, video nocookie and dnt, and 0 analytics matches.
- Red lines reproduce at the base.

## Checks (focused documents checks, not acceptance)
- `check-citations.py` reproduces the result: 214 citations, PASS, exit 0.
- I wrote a section-anchor checker: 80 quoted section references, 79 match headings, and the remaining one is a verbatim body quote.
- Mutations against `check-citations.py`:
  - **Killed:** a renamed cited path, a deleted header, and the header pushed below line 4.
  - **Survived:** a nonexistent section name (the script does not check sections), and a path containing `{` (skipped silently, so the count drops to 213).
- PHPUnit not run: no PHP or TypeScript changed.

## AGENTS.md invariants
Consent stays unknown and is never inferred. Payment is verified only by server retrieval, records are immutable, and downloads are entitled and short-lived. No prices or terms are invented. Nothing is published or routed, and every draft carries the header.

## Conditions (all in docs/legal/PRIVACY.md and the lane's verification folder)
1. Replace PRIVACY.md:22's "only for rate limiting" with the session-row storage of IP and user agent, citing the session config, migration and staging deploy. Add a retention row for server-side session rows, and state that throttle counters are hashed and keyed by IP.
2. Add PRIVACY section 2 entries for support attachments, service projects and free grants, each with its environment gate. Alternatively, list them explicitly as not covered.
3. Correct the malware-scanning row: uploads are stored privately and scanned before promotion or download.
4. Qualify "Public endpoints are rate limited" to exclude the signature-checked Stripe webhook. Mark "Your choices today" rows as test-only.
5. Cite or move TERMS.md:22's uncited term. Update the verification README's command block to the final run, then re-run `check-citations.py` and record the output.
