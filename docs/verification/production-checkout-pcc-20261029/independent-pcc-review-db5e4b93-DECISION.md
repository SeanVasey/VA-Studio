# Independent PCC payment/auth review — 2026-11-01

Reviewed commit: `db5e4b9342f08ede28d35c49524990d7777bfbcc`.
Baseline: `2ab0c41e946e30cc402afc8ec107a3670e399abd`.
Reviewer: separate non-author agent `/root/pcc_payment_auth_review`.
Reviewer worktree: `/workspace/VA-Studio-pcc-review` (detached at the reviewed SHA).

## Decision

**APPROVE the default-off development source, subject to completion and honest
classification of the requested execution evidence.** No P0, P1 or P2 defect was
found in the reviewed change. This record does not claim the ongoing full SQLite
suite has finished and does not itself satisfy that separate user-requested gate.
The final preflight and remote reviews also remain publication/merge gates.

No production activation, credential installation, live provider operation,
concurrency proof or release acceptance is approved by this review.

## Scope and findings

The review inspected the complete PCC application/configuration/ops diff and its
new tests, following the unchanged payment evidence and provider code where the
new operator path joins them. It also read AGENTS.md, the November 1 handoff and
the PCC packet. The developer did not self-approve this review.

1. **Production mount admission: named conflict accepted.** The existing mount
   pattern was local/testing only; PCC deliberately adds production to its
   allowlist. That is appropriate for this production composition mechanism:
   `production_checkout.mounted` is still a shipped code literal `false`, the
   production runtime validator separately insists it is `false`, and all
   checkout runtime switches remain held. Environment variables cannot flip
   this mount. Staging and unknown environments refuse. `ExecutionContextV1`
   and the existing domain policies remain independent admission checks.
2. **Private 404 ordering:** the gate is tested before the HTTP switch and before
   session, identity, SQL or controller work. Provider registration and route
   loading both use the same gate, while the global privacy middleware repeats
   it for stale route tables. The outer explicit proxy middleware only configures
   trusted proxy state and performs no identity/database work. The root exception
   branch emits fixed private responses and logs only the exception class.
3. **Operator reconciliation:** the command accepts a bounded UUID/session
   locator and requires the mount plus three literal switches before resolving
   the hosted service. Its new domain entry repeats the operator/reconciliation
   switches. Production provider I/O still requires the final concrete
   `OwnAccountStripeGateway`, own-account verification and the existing live
   configuration checks. The entry is retrieval-only; it never calls create.
   Retained intent metadata, line totals, payment identity, mode and capture
   evidence are revalidated by `HostedEvidence` and the same `record()` path as
   buyer reconciliation. A mismatched bound locator refuses before retrieval;
   a foreign unbound locator cannot bind without matching the retained intent.
   Retention deliberately does not depend on current buyer access; output exposes
   identifiers/states only. This is a trusted local operator command, not a new
   public or staff HTTP authority.
4. **Live-key admission and holds:** exact admission flags alter the preflight
   profile only. The runtime validator exempts only a correctly shaped live
   secret in `PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY` from its live-key scan;
   restricted/publishable keys and live values elsewhere remain refused.
   Production checkout switches, its literal mount and all four pre-launch
   capability holds remain enforced. Effective admission flags must agree with
   the candidate file. Real generic mail requires SMTPS on port 465 and rejects
   URL overrides; this does not claim successful provider authentication.
5. **Proxy handling:** no proxy is trusted by default, including Forge/Vapor
   domains. Only explicit valid IP/CIDR entries are used; malformed lists trust
   none. Only forwarded client address, scheme and port are honored, not host or
   path prefix. The replacement runs before every application privacy seam and
   resets Symfony's static proxy state for each request. This intentional change
   still requires actual topology verification before a CDN/proxy activation.
6. **Identity SMTP: named conflict accepted.** Replacing the older no-environment
   rule with code-literal activation plus environment-supplied credentials is
   appropriate for the requested host-secret configuration. With the shipped
   `$enabled = false`, the closure does not read SMTP values and settings remain
   null. No environment key selects the provider. When separately activated,
   the existing SMTP factory/settings validator still checks its closed capsule;
   the production validator additionally requires real-mail admission.
7. **Exclusivity detection:** any rights-scope link, active exclusive snapshot,
   exclusive-preparation purpose or exclusive template consults the authority
   ledger; an unavailable ledger refuses. Even store authority then refuses
   unsupported inventory. Nonexclusive interpretation and retained-source checks
   remain intact. This is a refusal guard, not proof that a future exclusive
   consumer can serialize against an authority transition; that future consumer
   needs its own locking/currentness review.

## Nonblocking follow-ups

- **P3-1, test coverage:** the new operator tests retain a valid buyer throughout.
  Add a regression that withdraws buyer access after the retained order/intent
  exists and proves operator reconciliation still retains verified provider state
  while the buyer projection remains refused. The inspected implementation has
  the intended separation; no failing behavior was observed.
- **P3-2, test coverage:** `tests/ops/test_production_runtime_validator.py:188`
  verifies the composed identity-SMTP hold by source-text assertion. A future
  isolated runtime test should compose settings and verify refusal with real-mail
  admission false. The shipped literal is closed and the inspected predicate is
  correct; this is additional behavioral coverage.
- **P3-3, documentation:** PCC packet assumption 4 (`README.md:68`) still says
  generic production mail accepts port 465 or 587. Actual implementation and its
  tests require SMTPS/465 only; 587/STARTTLS is a separate reviewed change. Align
  that assumption with the implementation before publication or record it in the
  documentation follow-up batch.

## Independently executed checks

In the separate reviewer worktree, with its own Composer autoload metadata and
`.env` copied from `.env.example`, no `public/build`, and the lead's local Docker
runtime (`va-pcc-runtime-20261101`, PHP 8.4.27 / PHPUnit 12.5.34):

```sh
env APP_ENV=testing APP_DEBUG=false \
  APP_KEY=base64:$(head -c32 /dev/zero | base64) \
  DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 \
  php -d memory_limit=512M vendor/bin/phpunit --colors=never \
  --fail-on-phpunit-warning tests/Unit/TrustedProxyListTest.php \
  tests/Feature/ExplicitTrustProxiesTest.php \
  tests/Feature/ProductionCheckoutComposition/ProductionCheckoutMountTest.php
```

Observed exit 0: **59 tests, 829 assertions, no failures/errors/skips** (1.470 s).
Original output: `/workspace/pcc-evidence/reviewer-proxy-mount.log`.
Reviewer worktree remained clean. These are lightweight mounted-boundary tests,
not the full suite and not native MySQL evidence.

## Evidence boundaries

The handoff's earlier native MySQL 8.4.11, focused checkout and mutation receipts
remain historical source-specific evidence. This reviewer has not repeated
those runs and makes no new locking, race or native latency claim. At record
creation the four-shard full SQLite run and equipped ops run at the reviewed SHA
are still in progress. No final all-suite pass is inferred from progress output.
No real Stripe/SMTP account, live key, host, cached production boot/route-cache
rebuild, browser, Foundation CI or GitLab run was exercised by this review.

The lead reported two Pint findings reproduced on exact main file bytes
(`bootstrap/app.php` unary/not spacing and `routes/web.php` ordered imports).
Those are preserved baseline findings, not repaired or declared passing here.
