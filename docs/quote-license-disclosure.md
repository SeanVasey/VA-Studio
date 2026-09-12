# Full license disclosure for frozen selection reviews

This WP-06 increment connects the full license source to an owned, immutable selection review. The cart's **Review selection** result now offers **Read full terms** for each reviewed line. Its URL identifies that quote and commercial revision, rather than a mutable catalog draft or a later quote.

## HTTP contract

`POST /quotes` and `GET /quotes/{quote}` add `licenseUrl` to each item. These bounded responses retain their compact summaries and never include full policy bodies or private evidence. `GET /quotes/{quote}/offers/{revision}/license` then returns:

| Fields | Meaning |
| --- | --- |
| `disclosureSchema: 1`, `quoteId`, `expiresAt` | Explicit disclosure format and owning review identity/lifetime |
| `offerId`, `offerRevisionId`, `licenseVersionId` | Exact selected immutable offer/license identities, serialized as strings |
| `name`, `version`, `type`, `features`, `deliverableRoles`, `termsText` | Allowlisted buyer-facing values from the retained quote line's license snapshot |
| `disclosureHash` | SHA-256 of the canonical JSON of all preceding response fields; it excludes itself and every private snapshot field |

`ReadQuote` checks the session/authentication-bound owner before checking expiry, current selection availability and immutable snapshot/reference hashes. Only a revision selected in that verified quote can be disclosed. Unknown quotes, another owner and unselected revisions return 404; expiry returns 410; changed publication, license/offer evidence or missing media returns 409. All errors preserve the existing private/no-store, Cookie-varying, nosniff boundary, including debug and middleware errors. Disclosure shares the quote-read rate limit and session lock, so adding subroutes cannot multiply the read budget.

The common rights projection preserves the public track endpoint's v1–v4 behavior. Typed policies use their retained schema renderer; literal v1 sources remain literal. Full v4 policy text is rendered from the captured local bundle. Internal approvals, reviewer evidence, private asset identity and storage locations are excluded. A draft edit cannot change retained terms; a published successor requires a new review without rewriting the old record.

## Client behavior and boundaries

Full text loads on demand through the same cancellation/retry/inert-text component used on track pages. In quote context the response must additionally match the quote ID and disclosure schema/hash shape. Changing the cart, restarting the review or reaching expiry removes the old disclosure and cancels pending requests. A failed quote disclosure never falls back to public catalog terms. New frontend quote results require a same-origin disclosure URL.

The hash identifies this safe deterministic disclosure. It does not record buyer assent or make the review payable, and it is not the private quote snapshot hash. No existing quote is changed; the response derives from its already immutable evidence. Future assent/order code must store the exact accepted disclosure/version and verify it with the quote in the purchase transaction. It must not treat merely opening this panel as acceptance.

## Verification and handoff

`QuoteDisclosureTest` exercises exact legacy/economic source, hash reproducibility, safe projections, replay, session/authentication isolation, unselected revisions, draft edits, successors, expiry, missing media, withdrawal, shared throttling and private debug errors. Existing quote races, snapshot invariants and public disclosure tests remain enabled. Frontend tests cover quote-bound fetches, obsolete replies and mismatched quote/schema/hash envelopes. Local verification passed 55 frontend tests and TypeScript/production build; the PR records final PHP/MySQL/SQLite and browser results. PHP/Composer are unavailable locally.

The shared browser disclosure scenario also verifies focus and overflow before pressing End, then waits for native scrolling to reach the final clause. An immediate scroll-position read raced the browser in CI; the corrected assertion observes the actual end position without setting it or adding retries.

This completes the bounded disclosure prerequisite within WP-06. Continue its authoritative tax-policy envelope and pricing/promotion rules, shared exclusive inventory and reservation lifecycle before WP-07 hosted checkout/finalization. Keep U-04/U-05/U-08 real policy choices explicit. The increment adds no migration, live provider action, payable total, reservation, assent, order or rights grant. Revert the code/UI while preserving all quote evidence if rollback is needed.
