# C4e phase A: default-off member HTTP over the C4d member domain

Lane C4e, phase A, October 24, 2026, branch `harness/member-http-c4e-20261024`, from `origin/main`
`a08e79ca` (PR #165, D1). Phase A is the code and SQLite evidence. Native MySQL evidence (phase B) runs only after
lane G1 (schema ownership once per frame) merges, because the existing writers overrun their fixed 60-second budget on
this host without it.

This is a local development candidate. Nothing is pushed, no PR is open and no workflow was dispatched.

## Work package (stated before editing)

### Authority and limits

Sean approved C4 on 2026-10-18 with `explicit_owner_confirmation_v1` as the member-license activation mode only: a
retained technical confirmation that the owner requested activation, neither legal assent nor write authority. Display
wording, consumption and retention terms, and every policy, definition, profile and terms hash remain unapproved
inputs. This lane sets none of them. `production-memberships.enabled`, `member-grants.enabled` and every approved hash
stay as shipped (`false`/`null`). The decision register also says member HTTP activation needs its own decision; this
lane does not mount activation.

### Files owned

- New: `app/Http/Membership/*` (mount gate, privacy root seam, controller), `routes/production-memberships.php`,
  `app/Domain/Memberships/Production/MembershipHttpBinding.php` (request-bound identity capsule composed from the
  reviewed family 256 capsule), new tests under `tests/Feature/ProductionMembershipHttp/` and
  `tests/Feature/ProductionMembershipBilling/`, this packet, `docs/ops/production-membership-http-runtime.md`.
- Edited: `MembershipCustomerIndex.php` (F3 fence removal, F4 clock seam), `MemberOriginalSourceCapture.php`
  (F6 activation commit classification, one method), `MembershipNativeCustomerIndexTest.php` (F3 lock evidence),
  `config/production-memberships.php` (`http_mounted => false`), `routes/web.php` (gated include),
  `bootstrap/app.php` (privacy root seam), `tests/Feature/StagingEnvironmentAdmissionTest.php` (gate census),
  `scripts/ci/database-sqlite-skips.json` (append only), `scripts/ci/database-mysql-selection.json` if regenerated,
  the two workflow count comments, `CHANGELOG.md`.
- Not touched: G1's files (`IdentityMigrationOwnership`, `IdentityRows`, `SchemaProofFrame`, `MembershipSchema`,
  `MembershipAwardTransaction`, the member and membership observers), `resources/contracts/**`, D1's
  `app/Http/ProductionFree/*`, `AppServiceProvider`, `composer.lock`, CLAUDE.md.

### Assumptions

1. Phase A mounts the membership read and redemption family only: the customer index, and period-addressed reserve,
   release and expire. The activation family (review, confirm, activate, readActivated) stays unmounted: it needs the
   member render profile r2 for PHP-FPM, the review and readActivated pre-bytes hooks, and Sean's separate decision on
   member HTTP activation.
2. The real membership capability bindings (`SealedMembershipPaidInvoice`, `SealedMembershipPolicyFacts`,
   `CurrentEligibleLicenses`, member grant authorities) are not registered in `AppServiceProvider`: the config file says
   "No invoice, license or grant producer is installed", and installing one is not part of this approval. Tests bind
   them as `MemberActivationFixtures` does. Without them every mounted route refuses as not found.
3. No frontend page ships. Customer display wording is an unapproved owner input, and the D1 precedent mounts JSON
   only. The JSON contract is documented in the runtime doc for a later page.
4. F3 option (a): every writer of a member's period, event, redemption and activation rows takes that owner's
   T23 lock (`ProductionCustomerAccess::lock`, `FOR UPDATE` on the owner's `users`, `customer_accounts`, origin,
   verification and challenge rows) before its first membership read and holds it to commit. The index already takes
   the same lock first, so the global fence adds no consistency for the index and is dropped (analysis below).

### Acceptance criteria

- The mount is off unless the literal `production-memberships.http_mounted === true` in `local`/`testing`; a stale
  route table is refused before any session, identity or SQL work; production and staging never mount.
- Every domain refusal on a mounted route maps to one identical not-found response (F8), except: a writer's
  `committed_receipt_refused` is a fixed "submitted, refresh" response, and a writer's `operation_refused` is a fixed
  "outcome unknown, retry the same request" response (F7). Neither says nothing happened.
- The index body is sent only after `MembershipCustomerRead::proveBeforeBytes()` and a fresh capsule proof (C2/F3).
- Per-tier rate limits (reads, writes) with separate limiter prefixes.
- F3: the index no longer touches `BillingAwardFence` (SQLite query census, native NOWAIT plan); F4: an expired
  original deadline refuses pre-bytes with `operation_deadline` (test with a captured clock); F6: activation's commit
  classification uses `MembershipCommitWitness`.
- Census: the new native methods are appended to the SQLite census (first 150 unchanged), the native selection is
  regenerated, both count comments match, and the four safeguards pass.

### Tests

- New HTTP feature tests (default-off matrix, stale table, journey with test-bound capabilities, F8 one-not-found for
  unknown/foreign/malformed ids, F7 outcome-unknown and submitted mappings, privacy headers, body bounds, rate limits).
- New domain tests for F3 (no fence query), F4 (deadline), F6 (pre-commit idle refusal stays `source_refused`).
- Re-run: `MembershipCustomerIndexTest`, `MembershipPeriodAddressedOperationsTest`, `MembershipCommitWitnessTest`,
  the activation and authorization files affected by F6, the staging admission and validator tests, the four
  safeguards, `composer validate --strict`, `npm test` and the typecheck.
- Mutations on the not-found mapping, the outcome-unknown mapping, the mount gate, the rate limiter, the fence removal
  and F6.
