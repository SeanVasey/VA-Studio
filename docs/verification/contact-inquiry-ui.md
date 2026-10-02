# T15a private contact inquiry UI verification

This bounded frontend adds `ContactInquiryForm`, its scoped stylesheet, focused React tests, and native-browser transport fixtures. It requires the separately reviewed inquiry backend and root-owned Editorial integration. It sends no email or marketing messages. A successful receipt means the backend confirmed private storage; it makes no delivery or response-time promise.

## Integration and contract

Only a public published contact page may render the form. The server projects optional `contactInquiryEnabled` (default false) and `contactInquiryPrivacyNotice` (default null), after checking the explicit disabled-by-default feature flag, approved privacy notice, internal retention-policy reference, eligible operator destination and active contact content. Private previews must never enable it. These display props are not server authorization.

The root-owned `resources/js/Pages/Editorial.tsx` imports the component and renders it before the existing email fallback:

```tsx
{!sitePreview && editorial.section === 'contact' && contactInquiryEnabled && contactInquiryPrivacyNotice &&
  <ContactInquiryForm privacyNotice={contactInquiryPrivacyNotice} />}
```

The component also renders nothing for an empty privacy notice. Approved notice text is escaped by React; no policy or retention duration is invented. Existing mailto remains available.

POST `/contact/inquiries` uses same-origin JSON `{name,email,subject,message,website,requestKey}`, current CSRF protection, no-store transport and no redirects. Bounds are 120/254/160/8,000 Unicode characters, an empty honeypot, canonical lowercase UUIDv4 and at most 16,384 UTF-8 body bytes. Draft strings are sent exactly, without trimming or case folding. Status 201 or 200 is accepted only with `{state:"saved",receipt:<canonical UUIDv4>}`. Server response text is never inserted into error messages.

A submitted serialized body and key stay in memory unchanged across timeout, lost/malformed response, 404, 409, 419, 429 or 503. The original inputs become read-only but remain copyable. There is no automatic retry. First definitive 422 field validation or 413 size rejection permits correction with a new key; a rejection after an uncertain send cannot replace that original attempt. The server must enforce **global request-key uniqueness and owner-bound receipt replay**. In particular, opening contact in a separate tab can renew CSRF after 419 but cannot promise recovery of an expired inquiry owner; retry may then return a generic 409 without a second save or disclosing another owner's receipt. The form keeps that unconfirmed original and offers the existing email fallback.

The application never persists drafts, request keys or receipts in localStorage/sessionStorage, URLs, analytics or logs. Memory is cleared after a valid receipt. Leaving/reloading the page loses the in-memory draft and retry identity; the UI says the draft stays only on this page. This is not cross-session recovery.

## Design and state coverage

The form uses the existing live-theme `--va-*` color, type, border and radius tokens, following `AGENTS.md` and `docs/brand/README.md`; it adds no imagery, font, motif or external asset. Native labels, required text, linked error summaries, aria-invalid/describedby, fixed success/error messages and keyboard focus accompany every state. Error and success summaries receive focus; starting a confirmed new inquiry focuses Name. Controls are at least 48 px tall, error links at least 44 px, text inputs use 16 px type, and the grid changes from one column to two at 640 px. Text and receipts wrap. No motion is introduced.

## Actual local checks

Node 24.19.0, npm 11.9.0, locked cached dependencies:

- `npm test -- tests/frontend/contact-inquiry.test.tsx`: 21 tests passed. This uses mocked HTTP responses and proves frontend behavior, not database persistence.
- `npm run typecheck`: passed.
- In an isolated test checkout with the exact Editorial snippet above, `npm test -- tests/frontend/contact-inquiry.test.tsx tests/frontend/editorial-content.test.tsx`: 28 tests passed. The existing editorial test emits jsdom's navigation-not-implemented diagnostic; there are no failing tests.
- Integrated `npm run build`: passed. Combined application output was 446.46 kB JavaScript / 135.25 kB gzip and 38.34 kB CSS / 8.10 kB gzip. These sizes are build evidence, not a measured performance-budget result.
- `python3 scripts/ci/scan-client-bundle.py`: passed, three built files checked with no configured secret-name/key-prefix hits.
- `VASEY_BROWSER_DIRECTORY=<isolated-directory> npx playwright test tests/browser/contact-inquiry.spec.ts --list`: discovers eight cases across Chromium desktop and WebKit mobile. **Discovery is not execution.** Native browser binaries are unavailable locally; CI must execute these cases on the final combined candidate.

The native fixture uses the real built React/CSS and synthetic contact HTTP transport. It covers disabled/public/private-page gating, keyboard validation, focus, minimum input heights, horizontal overflow, uncertain retry with exact original body/key, private receipt, PII storage-write checks and the email fallback. It does not prove persistence, session ownership, CSRF middleware or staff inbox authorization; those require the backend suites. Screenshots are produced only when native cases actually execute.

Unverified locally: native visual/device behavior, 320 px reflow, 200% zoom, tablet/landscape variants, forced-colors rendering, screen readers, runtime performance, MySQL persistence/concurrency and real operator setup. Feature remains disabled until the owner supplies approved setup values. Rollback is disabling the feature and removing the public projection/component; it must not delete saved inquiries.
