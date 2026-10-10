# Privacy notice (draft)

**DRAFT FOR OWNER REVIEW. Not published. Not legal advice.**

Prepared 2026-10-10 from the source at `f57e7256891d0d3c117e151a36f7fb967c724ab7`. This draft describes what the VASEY.AUDIO application collects, why, and what happens to it, as the code stands today. Every factual statement cites the repository file that proves it. Where the code does not decide something, the matter is listed under [Sean to decide](#sean-to-decide) rather than written as policy. No sentence here is a commitment until Sean and a qualified reviewer have accepted it and the text is published through a reviewed mechanism (see `docs/legal/README.md`).

Two status markers appear throughout:

- **Live-shaped**: the code runs this way in every environment.
- **Default-off** or **test-only**: the feature exists in the source but is disabled by default, or admitted only in `local`, `testing` or the hosted `staging` rehearsal (`app/Support/Environment/TestEnvironment.php`). None of the commerce, account, consent or inquiry features below is enabled for the public today (`docs/private-server-readiness.md`, "First sale and cutover remain separate gates").

## 1. Who operates the store

VASEY.AUDIO is a first-party store: one seller publishes and sells his own catalog; there are no third-party sellers or tenants (`docs/architecture/decision-register.md`, D-07). The brand contact address used by this repository for conduct, security and legal matters is `support@vasey.audio` (`CLAUDE.md`, Project Notes). The seller's legal name and address, and the identity the published notice should name, are not set anywhere in the code: the only seller name the application holds is the one an operator supplies in a test order policy, and the example shipped with the documentation is explicitly synthetic (`docs/order-preparation.md`, "Boundary and configuration"). See [Sean to decide](#sean-to-decide), S-8.

## 2. What is collected, by activity

### 2.1 Visiting the storefront (live-shaped)

- **Session cookie and CSRF cookie.** The application keeps a server session for every browser. The session cookie lives 120 minutes by default, is HTTP-only and uses `SameSite=Lax`; the secure flag follows host configuration (`config/session.php`, keys `lifetime`, `http_only`, `same_site`, `secure`). The session is what ties a selection, quote, order or inquiry to the browser that created it (`docs/test-order-history.md`, "Ownership remains `QuoteOwner`'s server-derived HMAC of session secret and authentication context").
- **Browser storage.** The storefront keeps a few locators in the browser's `sessionStorage` so that a reload can recover a cart, quote or order status: `vaseyaudio-cart-v1` (`resources/js/lib/useCart.ts`), `vaseyaudio-quote-attempt-v1` (`resources/js/components/QuoteReview.tsx`) and `vaseyaudio-order-recovery-v1` (`resources/js/components/OrderPreparation.tsx`). The order locator stores at most ten opaque order IDs and no buyer details, request body or review (`docs/order-preparation.md`, "Review and browser contract"). Staff upload pages use `sessionStorage` to resume interrupted uploads (`resources/js/admin/resumable-media-upload.ts`, `resources/js/admin/resumable-kit-upload.ts`). The customer consent page uses no browser storage (`docs/verification/customer-consent-preferences-20261007/README.md`, "Root registration contract and customer behavior").
- **IP address.** The application reads the visitor's IP address only for rate limiting. Public embed routes are budgeted per IP (`app/Providers/AppServiceProvider.php`, `public-track-embed` and `public-track-embed-audio`); the contact form budgets by a keyed hash of the IP, and no IP is stored in the inquiry (`app/Providers/AppServiceProvider.php`, `customer-inquiries`; `docs/contact-inquiries.md`, "Submission and retry contract"). The reverse proxy and web server on the host keep their own access logs; those are host configuration, not application code (`ops/staging/README.md`).
- **No analytics or advertising trackers.** A search of the client and server source finds no analytics, tag-manager, session-replay or advertising script. The only third-party content the storefront can load is a YouTube or Vimeo player on editorial video pages, and it loads only after the visitor presses **Load … video**; the page says beforehand that the provider will receive the visitor's IP address and may set cookies, uses the `youtube-nocookie.com` and Vimeo `dnt=1` endpoints, and offers a **Remove video player** control (`resources/js/components/EditorialVideo.tsx`). Those providers' own notices govern what they then collect.
- **Public previews, artwork and share metadata.** Tagged preview audio and verified artwork are served publicly for published tracks (`routes/web.php`, `/media/{asset}`; `docs/media-processing.md`). Track pages publish Open Graph metadata (title, artist, description, artwork) so that links unfurl on other platforms; those platforms may cache the preview image after a track is withdrawn (`docs/track-sharing.md`, "Result"). A public embed route exists for track previews, outside the session and cookie middleware (`routes/embeds.php`; `bootstrap/app.php`).
- **Application log.** The application writes to the host log channel configured in `config/logging.php`. Customer-facing boundaries log exception classes, not request bodies, private values or secrets (`docs/verification/customer-account-test-journey.md`, "Enabled boundary and interface"; `docs/contact-inquiries.md`, "Private operator handling"; `docs/stripe-webhook-inbox.md`, "Durable evidence and retry behavior").

### 2.2 Buying a license (test-only today)

The whole purchase chain runs only in Stripe **test mode** and only in `local`, `testing` or the hosted `staging` rehearsal (`docs/hosted-test-checkout.md`, "Explicit test boundary"; `app/Support/Environment/TestEnvironment.php`). No live payment path is registered (`docs/ops/production-activation-packet.md`, section 1). When the chain is used, the data flow is:

1. **Selection and quote.** Choosing tracks and offers creates a server-side selection review owned by the browser session; it records the exact offer, license and file revisions, subtotal and expiry. It records no name, email or payment detail (`docs/provisional-quotes.md`; `docs/quote-pricing.md`).
2. **Order preparation.** To prepare an order the buyer supplies a legal name and an email address and ticks an initially unchecked assent box. The server validates length and syntax only; it does not verify the person, the mailbox or account ownership and does not infer marketing consent (`docs/order-preparation.md`, "Review and browser contract"). The name, email, the exact displayed terms, the seller name and assent text shown, the pricing and the line evidence are encrypted into an immutable `orders` row; nothing in that row can be updated or deleted by the application, and the hash stored beside it is of the ciphertext so the record cannot be used to guess identities (`docs/order-preparation.md`, "Immutable private records").
3. **Payment at Stripe.** Card details are entered on Stripe's hosted Checkout page, never on this site. The application sends Stripe the line amounts, generic numbered product names (`VASEY.AUDIO test license 1`, …) and opaque order, attempt and intent identifiers. It does not send the buyer's name, email or the license terms (`app/Domain/Commerce/Checkout/CheckoutEvidence.php`; `docs/hosted-test-checkout.md`, "Frozen amount mapping"). Stripe may create a customer object on its side (`customer_creation: if_required`, same file). What Stripe collects on its own pages is governed by Stripe's notices.
4. **Payment evidence.** Signed Stripe test events are stored as immutable receipts with an encrypted copy of the first verified body (`docs/stripe-webhook-inbox.md`, "Durable evidence and retry behavior"). Payment is treated as verified only after the application itself retrieves the session and PaymentIntent from Stripe; a redirect back to the site proves nothing (`docs/test-payment-processing.md`; `docs/hosted-test-checkout.md`, "Immutable binding and observations"). Refund and dispute observations retrieved from Stripe are kept with the order (`docs/test-payment-financial-observation.md`).
5. **Contract document.** After verified payment, one private PDF per purchased line is rendered from the frozen order input: it contains every original input field, including the buyer's supplied name and email, the seller name, the assent text, the selection, the pricing and the full license terms (`app/Domain/Contracts/ContractText.php`; `docs/test-contract-issuance.md`, "Pinned renderer and profile provenance"). Originals are preserved and never regenerated (`docs/test-contract-issuance.md`, "Retained request, work and original").
6. **Downloads.** Each download is authorized by a short-lived token (60 seconds, at most three new authorizations per order per 60 seconds) and recorded as one immutable authorization and redemption row; the token is stored only as a hash (`docs/test-owner-delivery.md`, "Authorization, retries and consumption", "Durable schema and rollback"). The owner-facing history shows the authorization times and outcomes, not tokens or paths (`docs/test-owner-delivery-http.md`, "Owner projection").
7. **Order history.** The session that prepared an order can list its own orders; the list exposes status and amounts, never buyer contact details, hashes or provider identifiers (`docs/test-order-history.md`, "Server contract"). A matching email, knowledge of an order ID or a later sign-in does not claim a guest order (same file).

Audit events for these steps record the acting user or staff ID, the action, the subject record and a bounded context; they contain safe identifiers and the test marker, not private payloads (`app/Support/Audit/AuditEvent.php`; `docs/order-preparation.md`, "Immutable private records").

### 2.3 Customer accounts (test-only today; production identity default-off)

Customer accounts exist in the source but are admitted only as synthetic test accounts in `local`/`testing`, with the production identity feature disabled by default (`docs/verification/customer-account-test-journey.md`, "Enabled boundary and interface"; `config/production-customer-identity.php`). When enabled:

- **Account record.** The `users` table holds a name, an email address, a password hash and the verification time (`database/migrations/0001_01_01_000000_create_users_table.php`). The customer account row adds an opaque public ID, a random owner key and a versioned access control; identity fields cannot be changed or deleted (`docs/verification/customer-account-test-journey.md`, "Ownership and withdrawal").
- **Sign-in and recovery.** Sign-in accepts only `email` and `password`; failures return one generic response (`docs/verification/customer-account-test-journey.md`). Enrollment and password recovery create a challenge whose recipient address is encrypted and whose proof is kept only as a hash; challenges expire after ten minutes (`docs/customer-test-self-service.md`, "Evidence and concurrency"). In the test transport no message is sent: the proof is written to a private local file for the tester (`docs/customer-test-self-service.md`, "Activation and transport").
- **Listening library.** A signed-in customer can save favorites, named playlists and private lyric notes. They are stored as one encrypted payload per account, versioned, and can be exported by the customer and cleared through a versioned command; the row cannot be deleted by the application (`database/migrations/2026_10_07_242000_customer_saved_tracks.php`; `app/Domain/Customers/Listening/SavedListeningLibrary.php`; `docs/verification/customer-listening-notes-export-20261007/README.md`; `routes/customer.php`, `/account/listening-library`). Notes are never shared (same README).
- **Purchase claims.** An authenticated account can claim a guest test purchase only by proving the original owning session; a matching email transfers nothing (`docs/customer-test-self-service.md`, "Activation and transport"; `routes/customer.php`, `/account/purchase-claim/*`).
- **Membership credit history.** The account area has membership-credit pages (`routes/customer.php`, `/account/membership-credits`). Memberships are not sold today and their development uses synthetic fixtures only (`docs/ops/production-activation-packet.md`, section 1, "Memberships C2–C5 and Billing259"; `docs/live-payment-and-production-preparation-queue.md`).

Withdrawing an account's access (a staff or feature action, not a self-service control) invalidates sessions and future access but retains orders, contracts and history (`docs/verification/customer-account-test-journey.md`, "Ownership and withdrawal"). There is no self-service account deletion route (`routes/customer.php` lists every account route).

### 2.4 Email marketing consent (default-off)

- **Unknown until stated.** The application never infers marketing consent from a purchase, an account, listening activity or an imported address. Consent for the single purpose `email_marketing` is unknown until the customer makes an explicit, affirmative choice against the exact notice shown; every grant and withdrawal is appended as an immutable event with the notice version and hash it answered (`docs/verification/customer-consent-preferences-20261007/README.md`, "Exact policy and durable graph"; `database/migrations/2026_10_07_250000_customer_consent.php`).
- **What is stored.** Each event stores the account, purpose, revision, granted/withdrawn status, source `first_party_customer`, the time, a keyed HMAC of the recipient address and an encrypted snapshot of it (`database/migrations/2026_10_07_250000_customer_consent.php`, `customer_consent_events`). The customer-facing response contains the status, version and notice text only (`docs/verification/customer-consent-preferences-20261007/README.md`).
- **Withdrawal and suppression.** A withdrawal also appends a suppression intent so that a future email provider can be told to stop; the provider adapter is unbound and no provider is configured, so nothing is sent today (`docs/verification/customer-suppression-20261007/README.md`; `config/customer-suppression.php`; `database/migrations/2026_10_07_251000_customer_suppression.php`; `database/migrations/2026_10_07_254000_production_suppression.php`). Exporting or clearing the listening library does not delete consent or suppression evidence (`docs/verification/customer-suppression-20261007/README.md`, "Configuration and limits").
- **No notice text exists.** The production notice for `email_marketing` is `null` by default and must be owner-reviewed copy (`config/customer-preferences.php`).

### 2.5 Contact inquiries and conversations (default-off)

- **What is collected.** The contact form accepts a name, an email address, a subject and a message, plus a honeypot field that must stay empty (`docs/contact-inquiries.md`, "Submission and retry contract"). The five input fields and the exact privacy notice shown at submission are encrypted in the database; the inquiry also records the notice hash, the operator responsible and the site release that was live (`docs/contact-inquiries.md`, "Private operator handling").
- **What is shown first.** Intake can be enabled only when an approved plain-text privacy notice (`CONTACT_INQUIRIES_PRIVACY_NOTICE`), a private retention-policy reference and a verified operator with MFA are configured, and the published site has a contact page; the notice is rendered beside the form and bound into the submission token (`docs/contact-inquiries.md`, "Setup remains disabled"; `config/inquiries.php`). No notice text exists in the repository.
- **Replies.** Staff reply inside the application; replies are not emailed. Reading a conversation requires the original browser session binding; a receipt alone, a matching email or a later sign-in does not recover it (`docs/inquiry-conversations.md`). Replies are encrypted plain text, at most 100 per inquiry (same file).
- **Retention.** Inquiries and replies are immutable; the schema refuses rollback while rows exist, which is protection against accidental loss, "not an invented permanent retention policy"; no deletion or export workflow exists yet (`docs/contact-inquiries.md`, "Private operator handling"; `docs/inquiry-conversations.md`, "Schema and operational boundary").

### 2.6 Email the application sends

None today. The default mailer is `log` (`config/mail.php`, `MAIL_MAILER`). Production customer identity notifications are disabled and have no transport bound (`config/production-customer-identity.php`, `notifications_enabled`, `transport_capability`); the SMTP sender configuration is a code change that has not been supplied (`docs/ops/production-activation-packet.md`, section 2, "Sender domain"). Test enrollment uses a private file capture instead of mail (`docs/customer-test-self-service.md`). Contact replies are in-app only (`docs/inquiry-conversations.md`).

### 2.7 Staff and operators

Staff sign in to the administration panel with TOTP multi-factor authentication where the panel requires it (`CLAUDE.md`, Project Notes, "§5 auth"; `docs/operator-setup-and-verification.md`). Staff actions on customer records (opening the inquiry inbox, replying, archiving, issuing deliveries, publishing) are audited with the staff actor and safe references (`docs/contact-inquiries.md`, "Private operator handling"; `app/Support/Audit/AuditEvent.php`).

## 3. Who else processes the data

| Party | Role today | Evidence |
| --- | --- | --- |
| Stripe (Vasey Multimedia's own account) | Hosted Checkout page and payment processing, **test mode only**; receives amounts, generic product names and opaque IDs, not buyer identity | `docs/architecture/decision-register.md`, "Payment account observation"; `app/Domain/Commerce/Checkout/CheckoutEvidence.php`; `docs/stripe-webhook-inbox.md` |
| YouTube / Vimeo | Video player on editorial pages, loaded only on the visitor's click | `resources/js/components/EditorialVideo.tsx` |
| Hosting | Staging host: Laravel Forge managing a VPS (DigitalOcean selected), private, basic-auth protected; the production host is undecided (U-02) | `docs/handoff/2026-10-08/MONDAY-PLAN.md`, section 2; `ops/staging/README.md`; `docs/architecture/decision-register.md`, U-02 |
| Backups | Host-local database and private-file backups; the encrypted off-host destination is not supplied (S-10) | `ops/staging/README.md`; `docs/handoff/2026-10-08/MONDAY-PLAN.md`, S-10; `docs/ops/backup-restore-proof.md` |
| Email provider | None configured | `config/mail.php`; `config/production-customer-identity.php` |
| Marketing / suppression provider | None configured; adapter unbound | `config/customer-suppression.php`; `config/production-suppression.php` |
| Analytics provider | None in the source | search of `resources/js`, `app`, `config` (see section 2.1) |
| Malware scanning | ClamAV on the host scans uploads before they are stored or served | `ops/staging/README.md`, "Packages and versions"; `docs/media-processing.md` |

The processor list for production (analytics, email, support tooling) is U-13 and belongs to [Sean to decide](#sean-to-decide).

## 4. How long data is kept

The code fixes some lifetimes and leaves the schedule open:

| Data | What the code does | Evidence |
| --- | --- | --- |
| Session cookie | 120 minutes by default | `config/session.php` |
| Browser locators | `sessionStorage`, cleared when the tab closes or on success | `docs/order-preparation.md`, "Review and browser contract" |
| Identity challenges | Expire after ten minutes; the record is retained | `docs/customer-test-self-service.md`, "Evidence and concurrency" |
| Stripe hosted session | 60-minute provider expiry; the intent record is retained | `docs/hosted-test-checkout.md`, "Explicit test boundary" |
| Download authorization | 60 seconds; the row is retained | `docs/test-owner-delivery.md` |
| Orders, payment receipts, contracts, grants, deliveries | Immutable; the application cannot update or delete them, and schema rollback refuses while rows exist | `docs/order-preparation.md`; `docs/stripe-webhook-inbox.md`; `docs/test-contract-issuance.md`; `docs/test-owner-delivery.md` |
| Consent and suppression events | Append-only; never deleted | `database/migrations/2026_10_07_250000_customer_consent.php`; `docs/verification/customer-suppression-20261007/README.md` |
| Inquiries and replies | Immutable; no deletion workflow yet | `docs/contact-inquiries.md`; `docs/inquiry-conversations.md` |
| Listening library | Customer-exportable and clearable; the row itself is retained | `docs/verification/customer-listening-notes-export-20261007/README.md`; `app/Domain/Customers/Listening/SavedListeningLibrary.php` |
| Account | Access can be withdrawn; identity and history are retained | `docs/verification/customer-account-test-journey.md` |

A retention schedule, a deletion procedure that respects the immutable commercial records, and the account-deletion exemptions are U-13 (`docs/architecture/decision-register.md`). The inquiry documentation says explicitly that a later approved deletion/export workflow must implement the chosen policy (`docs/contact-inquiries.md`).

## 5. How data is protected

- Private payloads (orders, receipts, consent recipients, inquiries, listening library, delivery evidence) are encrypted at rest with Laravel's authenticated encryption under the application key; the key must be backed up with the database (`docs/order-preparation.md`, "Immutable private records"; `docs/stripe-webhook-inbox.md`, "Configure a development receiver").
- Masters, stems, contracts and purchased files live in private, unserved storage and are streamed only after a fresh entitlement and authorization check (`docs/test-contract-issuance.md`, "Isolation and private storage boundaries"; `docs/test-owner-delivery.md`; `AGENTS.md`, Invariants).
- Private pages and responses carry `private, no-store`, `noindex` and no-referrer headers (`docs/hosted-test-checkout.md`, "Owner API and recovery"; `docs/contact-inquiries.md`).
- Public endpoints are rate limited and bodies are bounded (`app/Providers/AppServiceProvider.php`; `docs/contact-inquiries.md`).
- Staff access requires MFA and is audited; the operating contract is in `docs/architecture/security-operations.md`.

## 6. Your choices today

| Choice | How | Evidence |
| --- | --- | --- |
| Not load third-party video | Do not press **Load … video**; use the external watch link instead | `resources/js/components/EditorialVideo.tsx` |
| Withdraw marketing consent | **Communication preferences** in the account area; withdrawal works even when no current notice exists | `docs/verification/customer-consent-preferences-20261007/README.md`; `routes/customer.php` |
| Export or clear the listening library | **Listening library** export and versioned clear | `routes/customer.php`; `docs/verification/customer-listening-notes-export-20261007/README.md` |
| Sign out | `/account/sign-out`, available even after access withdrawal | `docs/verification/customer-account-test-journey.md` |
| Ask about your data | `support@vasey.audio` (the handling procedure is not defined in code) | `CLAUDE.md`, Project Notes |

There is no self-service deletion of an account, an order, a contract or an inquiry; the records are immutable by design (section 4). Any deletion or access-request procedure is a decision for Sean and a reviewer.

## Sean to decide

Nothing in this section is decided by the code. Each item names the register key or input that tracks it.

1. **Retention schedule, deletion procedure and account-deletion exemptions** (U-13): how long each record class above is kept, how a deletion request is handled against immutable commercial records, and who executes it (`docs/architecture/decision-register.md`, U-13; `docs/contact-inquiries.md`).
2. **Analytics and email processors** (U-13): whether any analytics, email, support or marketing provider is used in production, and its name for this notice (`docs/architecture/decision-register.md`, U-13).
3. **Consent purposes and the marketing notice text** (U-13): the exact `email_marketing` notice and review reference (`config/customer-preferences.php`).
4. **Seller legal name, address and the controller identity this notice should name** (S-8): `docs/handoff/2026-10-08/MONDAY-PLAN.md`, section 4.
5. **Contact-form privacy notice and retention reference** (`CONTACT_INQUIRIES_PRIVACY_NOTICE`, `CONTACT_INQUIRIES_RETENTION_REFERENCE`): `docs/contact-inquiries.md`.
6. **Governing law and the applicable privacy regime(s)**, including whether the notice must address international transfers (hosting region is part of U-02) and age limits: not decided anywhere in the code.
7. **Production host, region and backup custody** (U-02, U-03, S-10): `docs/architecture/decision-register.md`; `docs/handoff/2026-10-08/MONDAY-PLAN.md`.
8. **Customer identity transport for production** (U-07): the enrollment and recovery messages a real customer would receive, and the provider that sends them (`config/production-customer-identity.php`).
9. **How a data-subject request is received and answered**: the support mailbox exists; the procedure and timing do not.
10. **Whether and how customers are told about BeatStars-era data** (U-09): no customer or entitlement import code exists, and consent is never inferred from an import (`AGENTS.md`, Invariants; `docs/handoff/2026-10-08/MONDAY-PLAN.md`, section 1).

## Change log

- 2026-10-10: first draft from source `f57e7256891d0d3c117e151a36f7fb967c724ab7` (lane `harness/launch-documents`).
