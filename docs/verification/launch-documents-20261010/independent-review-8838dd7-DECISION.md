# Re-review: lane "docs", 58cc803..8838dd7 (harness/launch-documents)

Reviewed head `8838dd739712434b24c94d1b128757941ff8801c` in the lane worktree (read-only; worktree clean, nothing modified).

## Decision: APPROVE WITH CONDITIONS

Prior conditions 1-3 hold up against the source. This review satisfies condition 4. Two small accuracy gaps remain in `docs/legal/PRIVACY.md`.

## Verified correct
- **Scope.** Only 5 owned files changed: `docs/legal/PRIVACY.md` and `docs/verification/launch-documents-20261010/*`. No forbidden paths across `f57e7256..HEAD`. `git diff --check` is clean. Added lines contain no secrets or real customer, artist or payment data.
- **2.1 sessions.** The new text matches the source:
  - `config/session.php:21,35,50,117,172,185,202`
  - `.env.example:40-42` and `ops/staging/env.staging.example:58-66`
  - `forge-deploy.sh:108-114`, where `need` dies on mismatch
  - the migration's `sessions` columns
  - vendor `DatabaseSessionHandler:250-256` (IP and UA written on each save) and `:299-302` (gc)
  - `StartSession:169-189` (lottery)
  - `routes/console.php`, which has no session purge
  "Only for rate limiting" is gone.
- **2.6 attachments.** `FixtureAttachmentPolicy.php:14-46` has local/testing only, the flag check, 5 MiB / 10 files / 86400 s, the four types and ClamAV. `SupportAttachmentServiceProvider.php:17` registers only the fixture. `SupportAttachments.php:83` sets `expires_at` at reservation. The verification doc (lines 94-148) covers tombstones, pending cleanup and the absence of an automatic purge.
- **2.7 and 2.8.** `ServiceProjectPolicy.php:11`, `FreeGrantPolicy.php:11,32` and `FreeGrantDefinitions.php:19` use `admitsTestCommerce`, which includes staging. Config flags default to false. `production-free-grants.php` has `enabled => false`. Both migrations' `down()` throw. Encryption uses `Crypt::encryptString` (`SupportAttachments.php:373`, `ServiceProjects.php:461`, `FreeGrantRecords.php:15`).
- **2.2 step 3.** `CheckoutEvidence.php` has no `customer_email`.
- **Drafts.** Nothing is published. The header and the "not legal advice" line remain. No AGENTS.md invariant is weakened, and consent is still never inferred.

## Findings
- **LOW: PRIVACY.md:102, section 3, "Malware scanning".** The row says ClamAV "scans uploads before they are stored or served". New section 2.6 (and support-attachments doc:110-130) shows attachment originals are stored first and scanned before download. Media uploads are quarantined, then scanned before promotion (`docs/media-processing.md:35`). The row now contradicts 2.6.
- **LOW: PRIVACY.md:24 and section 4, rate-limit data.** The text names only the embed and contact limiters. Many guest routes also use a generic `throttle:N,1` keyed by IP: `routes/web.php:21-37`, `routes/services.php`, `routes/free-grants.php`, `routes/discovery-track-sitemaps.php`. Laravel keys them as `sha1(domain|ip)` or `md5(name.key)` (`ThrottleRequests.php:134,342`). Those rows sit in the database `cache` table with no scheduled prune. Section 4 has no row for them.
- **INFO.** `Request::ip()` with no trusted proxies configured stores the connecting address. That address would be a CDN or proxy if one is ever placed in front.
- **INFO.** The Stripe email-collection sentence is an inference from an absent parameter, with no Stripe source cited. The implementer disclosed this.
- **INFO.** `FreeGrantRecords.php:15` stores `sha256(plaintext)`, unlike the orders ciphertext hash. PRIVACY makes no claim about it.
- **INFO, outside the lane.** `docs/service-projects.md:109` ("no private file intake/scanning") and the comment in `config/services-projects.php` ("no ... attachment authority") contradict `test_service_project_v1` attachments. Report them to the integrator with the existing contradictions.
- **INFO.** `check-citations.py` mutations: 4 of 7 were killed (two mistyped new paths, a deleted cited file, a removed header). 3 survived: 24h→48h, reinserting "only for rate limiting", and admitting staging for attachments. This matches the limitation recorded in the README.

## Conditions (owned file `docs/legal/PRIVACY.md`)
1. In section 3, change the Malware-scanning row so it says uploads are scanned before they are promoted, served or downloaded, not before they are stored. Cite `docs/verification/support-attachments-20261007.md`, "Effects, retry and retention", and `docs/media-processing.md`.
2. In the 2.1 IP bullet, state that other public routes are also rate-limited per IP (or per signed-in user), with counters stored under hashed keys in the database cache (`routes/web.php`; `config/cache.php`). Add a section 4 row saying these entries have no scheduled purge, or list them under U-13.
3. Re-run `check-citations.py` and record the result.

## Verified by running
- `python3 -I docs/verification/launch-documents-20261010/check-citations.py`: 95/86/55 = 236, RESULT PASS, exit 0. Matches the claim.
- `git diff --check 58cc803..8838dd7`: exit 0. The forbidden-path filter over `f57e7256..8838dd7` is empty.
- 7 checker mutations on a `git archive` scratch copy (results above).
- Source greps for every new claim (listed above).
- No PHPUnit: no PHP or TS changed and the lane cites no test counts.
