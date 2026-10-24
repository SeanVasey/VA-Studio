# Production memberships: member HTTP mount

The customer member HTTP mount (lane C4e, phase A) is built and ships off. `production-memberships.http_mounted` is
the literal `false` in `config/production-memberships.php`, next to `enabled => false`, `provenance => null` and
`approved_policy_hash => null`. Mounting approves nothing: no policy, definition, profile or terms hash, no wording,
no capability binding and no activation. Live use needs Sean's explicit authorization and the inputs listed at the end.

## What is mounted when the flag is true

`routes/web.php` requires `routes/production-memberships.php` only when `App\Http\Membership\MembershipHttpMount`
admits: an exact boolean `true` read from the loaded configuration storage (no repository method, no provider
resolution) and a cached environment of `local` or `testing`. A string `"true"`, `1`, `null`, an array, `staging` or
`production` registers nothing.

| Method | Path | Body | Domain call |
| --- | --- | --- | --- |
| GET | `/production-memberships/periods` | none | `MembershipCustomerIndex::index`, then `MembershipCustomerRead::proveBeforeBytes` |
| POST | `/production-memberships/periods/{period}/reservations` | JSON `{offerId, expectedRevision, requestKey}` | `MembershipReservationOperations::reserveInPeriod` |
| POST | `/production-memberships/periods/{period}/reservations/{redemption}/release` | JSON `{}` | `releaseInPeriod` |
| POST | `/production-memberships/periods/{period}/expire` | JSON `{}` | `expireInPeriod` |

`offerId` is a decimal string, `expectedRevision` a positive integer and `requestKey` 16 to 128 characters of
`[A-Za-z0-9_-]` chosen by the client; the same key replays the same reservation. All routes use the web session, real
CSRF, session blocking (`block(20, 5)`) and per-tier throttles without Inertia: reads 10 a minute
(`production-memberships-reads`), writes 10 a minute (`production-memberships-writes`). The index is the expensive
call (up to 101 audited periods, read twice for the pre-bytes proof). Like the family 256 mount, the throttle key is
the request signature without a default-guard user (the client address); a per-customer limiter is a follow-up.

The activation family (review, confirm, activate and the activated read) is **not** mounted. It needs the member render
profile r2 (the frozen r1 renderer cannot launch under PHP-FPM), pre-bytes hooks for `review` and `readActivated`, and
Sean's separate decision on member HTTP activation.

## The root seam

`App\Http\Membership\MembershipPrivacy` is prepended in `bootstrap/app.php` and runs before the web group on every
request under `production-memberships/*`:

- Unmounted, including a stale cached route table after the flag is withdrawn: the not-found response before any
  session, identity or SQL work.
- GET or POST only; no method override; no query string; no `Range` or `If-Range`; same origin; identity content
  encoding. GET has no body. POST bodies are `application/json`, at most 1 KiB, and duplicate object keys are refused.
- Every response is private: `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`,
  `X-Robots-Tag: noindex, nofollow`, `X-Content-Type-Options: nosniff`, `Vary: Cookie`.

## Responses

| Situation | Status | Body |
| --- | --- | --- |
| Index | 200 | `{"index": {schemaVersion, periodLimit, hasMore, provenance, periods: [...]}}` (the C4d projection) |
| Reserve, release or expire committed | 200 | `{"result": {periodId, status, balances, redemptionId?, creditAmount?, honorDeadline?}}` |
| Any domain refusal (unknown, malformed or foreign period or redemption, honor or credit not yet due, owner or gate refusal, deadline), an unknown route, the unmounted family, or an index body withheld at pre-bytes | 404 | `{"code": "MEMBERSHIP_NOT_FOUND"}`, byte-identical in every case |
| A writer's write is durable but its receipt was refused (a throwing after-commit callback, or the capsule or mount failed after the commit) | 202 | `{"code": "MEMBERSHIP_SUBMITTED", "refresh": true}` |
| A writer could not classify its outcome (`operation_refused`, for example a commit whose result is unknown) | 503, `Retry-After: 1` | `{"code": "MEMBERSHIP_OUTCOME_UNKNOWN", "refresh": true, "retry": "same_request"}` |
| No or changed customer session, or a withdrawn account, before any domain call | 403 | `{"code": "MEMBERSHIP_UNAVAILABLE"}` |
| CSRF, body, media type, method, origin or rate limit | 419, 422, 413, 415, 405, 403, 429 | `{"code": "MEMBERSHIP_UNAVAILABLE"}` |

A client must treat 202 and the outcome-unknown 503 as "something may have happened": refresh the index, or repeat
the exact same request. The writers are idempotent (reserve by request key; release and expire by their retained
terminal events), so a repeat converges.

## Identity

`App\Domain\Memberships\Production\MembershipHttpBinding` is a copy of the reviewed family 256 request capsule on the
membership parents (`production-memberships`, `member-grants`). It captures the T23 session marker, the customer guard
and actor, both user resolvers and the plain configuration parents once, and `proveCurrent()` re-reads only that
state. The controller proves it before a domain call, after it and before any byte. The capsule is never membership
authority: each domain call re-runs its own owner lock and proofs.

## Capabilities

No production capability is registered: `config/production-memberships.php` still says "No invoice, license or grant
producer is installed". Without `MembershipPaidInvoiceAuthority`, `MembershipPolicyFactsAuthority`,
`MembershipEligibleLicenseAuthority` and `MemberGrantAuthority` bindings every mounted route is the not-found
response. Tests bind the real producers as `tests/Support/MemberActivationFixtures.php` does.

## Before anything is enabled

- Native MySQL evidence on an exact SHA with no deadline raised (review C2): index locks without the global fence, a
  wait at a writer-held owner lock, completion while another customer holds the fence, pre-bytes after a reserve, the
  reservation and release witness, the period-addressed positive path and index latency at 1, 10 and 100 periods.
  This runs after lane G1 (schema ownership once per frame) merges.
- Owner inputs: display wording, consumption and retention terms, and every policy, definition, profile and terms
  hash. None is approved by the 2026-10-18 decision.
- A decision to install the capability producers, and for activation the r2 render profile, its pre-bytes hooks and
  Sean's decision on member HTTP activation.
- A customer page. None ships: its wording is an owner input.
