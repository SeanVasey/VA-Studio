# FP-077 — Resumable private delivery: decided design and phases

Status: **Phase 1 (shared infrastructure and the test-delivery chain) implemented on branch `claude/download-resume-v2-20261109`, 2026-11-09, pending review.** Paid, paid-tax, free and production-free downloads are unchanged: they still refuse `Range` and `If-Range` (Phases 2–4 below). Sean decided the design on 2026-11-09; this page records it, what Phase 1 built and what remains.

## Sean's decisions (2026-11-09)

1. The test-delivery policy may move to a v2 that allows resume.
2. Transport (the lead's recommendation, which Sean asked for):
   - The authorization is still created by the existing CSRF-protected issuance POST, unchanged.
   - The token POST answers **303** to a GET URL that holds only the opaque authorization ID, never the secret.
   - The GET is valid only with the same server-side session (and owner) that created the authorization, inside the start window or, once started, the resume window, and after every existing entitlement, policy, control, refund/dispute and evidence check, as strictly as before.
   - Responses carry `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, `Vary: Cookie`, `Content-Disposition: attachment`.
   - GET supports one `Range` and a strong-ETag `If-Range`. No token-bearing links and no JavaScript blob downloader.
   - D-20's rationale is amended: the threat it guarded against was a secret in a URL; here no secret is in the URL and access needs the session.
3. Counting: a partial or interrupted transfer does not count toward `max_downloads`. A download counts once, when distinct bytes served under one authorization cover the whole file. Coverage is stored as merged byte intervals per authorization, bounded. Re-served bytes never double-count. Abuse bound: started-but-incomplete authorizations are capped per grant per rolling 24 h (config, default 10); beyond it new authorizations are refused with a clear reason. This is an operational limit, not a license term.
4. Large archives: the 60-second start window stays. Once started, resumed ranges are accepted for a resume window sized to the file: 1 h below 512 MiB, 6 h from 512 MiB, 24 h from 4 GiB (config values, these defaults). Resume never extends past the window. Whole-file SHA-256 verification runs before any byte of every response.

## Phase 1 as built (test-delivery chain)

### Policy

`TestAccessPolicy::CONTRACT_V2` (`test-owner-delivery-v2`) is the second approved literal, beside the retained v1 `CONTRACT`. It keeps v1's scope, storage, verification, 32-byte token, 60-second authorization and three-per-order-per-minute budget, and replaces `"stream_attempts": 1, "ranges": "disabled"` with `transport`, `ranges`, `counting`, `resume_window` and `incomplete_authorizations` mechanism fields. `VASEY_TEST_DELIVERY_ACCESS_POLICY` must equal one literal exactly; `scripts/ops/validate-test-commerce-profile.php` (`delivery.access`) checks it and, for v2, the numbers below. The staging profile example now uses v2.

Each authorization freezes the literal it was issued under in its encrypted evidence and `policy_version`. Evidence is verified against the authorization's own literal, so v1 authorizations and history keep verifying under v1. An authorization is served only while the current policy is its own literal: after a switch, in-flight authorizations of the other version answer 503 and the buyer requests a new one.

| Config key | Environment variable | Default | Bounds |
| --- | --- | --- | --- |
| `delivery.test_resume_window_seconds.below_512_mib` | `VASEY_TEST_DELIVERY_RESUME_SECONDS_BELOW_512_MIB` | 3600 | 60..604800 |
| `delivery.test_resume_window_seconds.from_512_mib` | `VASEY_TEST_DELIVERY_RESUME_SECONDS_FROM_512_MIB` | 21600 | 60..604800, not below the previous tier |
| `delivery.test_resume_window_seconds.from_4_gib` | `VASEY_TEST_DELIVERY_RESUME_SECONDS_FROM_4_GIB` | 86400 | 60..604800, not below the previous tier |
| `delivery.test_incomplete_authorizations_per_grant_24h` | `VASEY_TEST_DELIVERY_INCOMPLETE_PER_GRANT_24H` | 10 | 1..1000 |

An invalid value (including a blank one) makes v2 unavailable rather than defaulting silently. The resume window for an authorization is chosen at issuance from the file size (`TestAccessPolicy::resumeWindowSeconds`) and frozen in its evidence with the session binding hash, so a later configuration change never moves an issued window. Test files are at most 1 GiB, so the 4 GiB tier is reachable only by the paid chains; it is unit-tested with fake sizes.

### Transport

| Step | Route | Effect |
| --- | --- | --- |
| Issue | `POST /orders/{order}/delivery/authorizations` (unchanged) | Under v2, also binds the new authorization to the session: `sha256("vasey-test-delivery-session-v1\0" + authorization ID + "\0" + secret)`, where the 32-byte secret lives only in the server-side session (`_test_delivery_session`). |
| Present token | `POST /orders/{order}/delivery/download` | v1 authorization: streams once, as before (ranges refused with 416). v2 authorization: verifies owner, token and session binding, records the ID as presented in this session, and answers **303** to the GET. Nothing is consumed and no private byte is read. |
| Transfer | `GET /orders/{order}/delivery/downloads/{authorization}` | Requires the session's binding secret and the presented marker, the same owner, and then `TransferTestDelivery`. HEAD is refused (405) because it would start a transfer without sending bytes; query strings are refused (422). |

A leaked URL is useless: without the session secret the GET answers 404 with no bytes and no consumption. Logging out invalidates the session, so the secret is gone; another user's session has another owner and secret. The deployed session driver is `database`. A cookie session driver would let a copied pre-logout cookie replay its session, so it is not supported for this path.

The existing UI already submits the token in a native form POST; the browser follows the 303 and its download manager resumes the GET with `Range` and `If-Range`. No front-end change was needed.

### Admission (`TransferTestDelivery`)

Every response passes the v1 redemption checks (owner, current v2 policy and account, control version, refund/dispute holds, authorization and redemption evidence, session binding) before the snapshot and again under the order → control → authorization locks:

- **Not started:** admitted only while `now < expires_at` (60 s). The first served response inserts the authorization's single `test_delivery_redemptions` row, which marks the start (`commerce.delivery.redeemed`). Its commit is still bound by the start window.
- **Started:** any satisfiable selection, full or partial, is admitted while `now < redeemed_at + resume_window_seconds` and coverage is incomplete (`commerce.delivery.resumed`). After completion everything answers 409 `DELIVERY_ATTEMPTED`. After 64 recorded responses, 429 `DELIVERY_RESUME_LIMIT`.
- **Unsatisfiable:** same checks, no private file access, no start and no coverage; 416 `DELIVERY_RANGE_NOT_SATISFIABLE` with `Content-Range: bytes */size`. Expired, withdrawn or foreign authorizations get their usual refusal, never 416.
- **Integrity:** the whole file is copied to an unlinked spool snapshot and its SHA-256 read back before the first byte of every response; a partial response seeks inside that verified snapshot. A byte changed outside the requested range is refused (503) before any byte.

Range grammar (`DeliveryByteRange`, from the prototype): one `bytes` range (`a-b`, `a-`, `-n`), `b` clipped to the last byte. No `Range`, an unknown unit, malformed syntax, `b < a`, any multi-range list, a header over 128 bytes, an `If-Range` that is not a strong match of the ETag (weak tags and dates never match) and a range covering every byte are all full selections. `a ≥ size` and `-0` are unsatisfiable. 206 carries `Content-Range`; 200 and 206 carry `Accept-Ranges: bytes` and a strong ETag derived from the content hash (`sha256("vasey-test-delivery-etag-v1:" + sha256)`), not the hash itself.

### Counting and coverage

`test_delivery_coverage` is an append-only log: one row per served response with the bytes actually handed to the transport (`first_byte`, `last_byte`), the merged, sorted, non-adjacent intervals of all distinct bytes served so far (`intervals`, canonical JSON), `covered_bytes`, a per-authorization `sequence`, and `completed_authorization_id`, set on exactly the first row whose coverage reaches the file size. That row is the one counted download (`commerce.delivery.completed`). Re-served bytes add nothing.

Coverage is recorded after streaming, from a counter of bytes the consumer accepted, so an interrupted response records only its served prefix and a consumer that fails before accepting a byte records nothing. "Served" means handed to PHP's output, not proof of receipt. Writers (`TestDeliveryCoverageLedger::record`) lock the authorization row first and read the latest row with a locking read, so concurrent ranges on one authorization serialize: none loses coverage and only one completes. A recording failure is logged and loses only that response's coverage; it can never count a download that was not served.

Bounds: admission stops at 64 responses per authorization, at most three verified streams exist at once (spool slots), and each response adds at most one interval, so intervals stay below the schema's hard cap of 128 sequence rows.

### Abuse cap

At issuance (preflight and again under the order lock), v2 authorizations of the same grant whose transfer started within the rolling 24 hours and never completed are counted. At the cap, issuance answers 429 `DELIVERY_INCOMPLETE_LIMIT`. Issued-but-never-started authorizations do not count; completing a transfer frees its slot.

### Schema (`2026_11_09_270300_test_delivery_resume_v2`)

- Replaces the 000023 authorization insert guard with `test_delivery_authorizations_valid_insert_v2`, identical except that `policy_version` may be exactly `test-owner-delivery-v1` or `test-owner-delivery-v2` (byte-exact on MySQL). The migration rebuilds the 000023 definition from that migration's own helpers and refuses before any DDL unless the installed guard matches it. It creates the new guard before dropping the old, so no instant admits an unguarded row. The 000029 hash and 000030 UUID byte guards are separate triggers and are untouched.
- Creates `test_delivery_coverage` with `_valid_insert` (bound to a started v2 authorization, contiguous `sequence` from 1 to 128, monotonic coverage, last byte inside the file, completion exactly on the first full row), `_immutable_update` and `_immutable_delete` guards, plus unique `(authorization, sequence)` and unique `completed_authorization_id`.
- Rollback refuses while any v2 authorization or coverage row exists, and otherwise restores the exact 000023 guard.

The retention schedule classifies `test_delivery_coverage` as a `financial` download record (`recorded_at`). The customer data export does not include it yet.

### Verification

The Phase 1 commit message records the exact commands and results: focused SQLite suites, native MySQL migration and race tests, the shard proof and the mutations caught.

## Remaining phases

Each later chain needs its own resume branch, native MySQL races and independent review, reusing `DeliveryByteRange`, `PreparedDeliveryStream::writeRangeTo` and the coverage approach:

- **Phase 2 — paid (`paid_authorizations`/`paid_redemptions`):** v2 contract, 303 + session-bound GET, coverage table with append-only trigger, `max_downloads` counted by completion instead of redemption, incomplete cap, size-tiered resume window measured from the redemption commit (replacing the transfer deadline), within the paid frame and closed-projection reads.
- **Phase 3 — paid-tax:** the same on `paid_tax_*`, under its own frame and per-buyer spool slots.
- **Phase 4 — free and production free:** the same for `free_redemptions` (`max_downloads`) and `production_free_redemptions` (`already_redeemed` becomes completion-based).

Open for these phases: whether the 4 GiB tier needs a larger spool budget than three 1 GiB slots, whether resume should survive a policy switch, and adding coverage to the customer data export.

## Signed-link retry

No signed or reusable download link exists, and none is added: the v2 URL is useless without the session. A transfer that cannot resume (window closed, policy switched, session lost) is recovered by requesting a new authorization, subject to the rolling budget and the incomplete cap.
