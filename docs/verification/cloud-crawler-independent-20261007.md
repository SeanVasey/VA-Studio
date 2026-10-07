# Independent crawler correction review

Approved exact composed source `6b11c9ff916d9be1ee0a470e384bb05a9ef8435e`,
tree `edf646d7fc15b0ef3da055243b25ce63e28aeabe`, on merged PR #26 main
`5b58e2e4805ed5a30cf3071457cbc8e526c1425a`. No blocking finding.
The review used a separate detached worktree and owns only this new receipt.

The product change is one production robots prefix: `/inquiries` becomes the
actual registered `/contact/inquiries`. Both changed files are byte-exact to
author executable `99478464f9b8dd89c5595ab15b75466f90ea6555`; author evidence
is retained from `5ba37d2`. The two source SHA256 values are:

- Controller: `9b2b694e412e6fa0555de102ac32782e3c535748e6deee74e31b5483ae017814`.
- Feature test: `cd936bde6e22bb1defd2598107d025bcd062dd617acb665e203ed8a2467a0f8e`.

The reviewer inspected the actual controller/test diff, public and inquiry route
registration, inquiry privacy/response middleware, existing public contact/page
sitemap and ownership/CSRF/privacy cases, and raw author receipts. The emitted
prefix covers all eight registered route names and their actual method/path pairs:

| Route | Method | Path |
| --- | --- | --- |
| `contact.inquiries` | POST | `/contact/inquiries` |
| `inquiries.order.setup` | GET/HEAD | `/contact/inquiries/for-order/{order}` |
| `inquiries.order.store` | POST | `/contact/inquiries/for-order/{order}` |
| `inquiries.order.context` | GET/HEAD | `/contact/inquiries/{receipt}/order-context` |
| `inquiries.history` | GET/HEAD | `/contact/inquiries/history` |
| `inquiries.history.before` | GET/HEAD | `/contact/inquiries/history/before/{before}` |
| `inquiries.conversation` | GET/HEAD | `/contact/inquiries/{receipt}/conversation` |
| `inquiries.conversation.reply` | POST | `/contact/inquiries/{receipt}/conversation` |

The regression enumerates the actual router collection and checks all eight
against emitted prefixes; it fails against the old controller. `/contact` is
outside the new disallow prefix and remains in the exact public page-sitemap
fixture. The sitemap index exposes only the canonical public child sitemap.
Robots is crawler advice; private routes retain their existing server ownership,
session rotation, CSRF/transport checks and `private, no-store`,
`noindex, nofollow`, `no-referrer`, `nosniff` and Cookie-vary protection.
No route, middleware, authorization, inquiry body, public contact behavior,
canonical-origin rule, session or response-header code changes.

Independent local reconciliation used `git diff`, exact Git blob comparisons,
Python `hashlib.sha256` and JUnit XML parsing:

- All 12 author source/dependency/evidence hashes in `source-runtime.json`
  match the composed files; no mismatch. All six selected feature files are
  byte-identical to the tested author commit, and dependency manifests/locks
  are unchanged.
- Author focused JUnit records 185 cases / 4,218 assertions, zero
  errors/failures/skips: discovery index 23/103, page sitemap 18/50,
  customer inquiry 99/3,193, conversation 14/250, history 15/371 and
  order inquiry 16/251. These are carried author results, not new independent
  execution. The preserved pre-fix regression records one failure and no error;
  Pint and whitespace receipts remain intact.
- The actual registered-route artifact has exactly the eight names above;
  all stored paths match the current route declarations and corrected prefix.
  The existing HTTP cases preserve owner success, foreign/lost/rotated-session
  refusal, real CSRF, public-contact privacy and private response headers.
- The only non-document additions between the author executable and composed
  source are the four independently approved PR #26 amount classes and their
  four tests. They add no route/provider/middleware dependency. All other
  application, route, bootstrap, schema and focused test source is unchanged.
  The 158-package locked graph was independently reconciled in the PR #26
  review carried on this baseline.

No unresolved behavior required a new independent canary. The valid unchanged
185-case focused selection was carried without duplication; no hosted CI,
MySQL, browser or full Foundation matrix was run. This approves the bounded
correction for development integration, with production crawl/deployment,
broader SEO acceptance, production commerce and cutover still open. A moved
executable or relevant baseline needs fresh source/evidence reconciliation.
