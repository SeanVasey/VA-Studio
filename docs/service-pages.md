# Public service pages (BL-64 phase 1)

Public pages for services, default off. When the switch is off every `/services` and `/services/{slug}` request answers
the ordinary 404, and no navigation, sitemap or structured-data entry exists. When it is on, a visitor sees only
services that staff approved on the **Service pages** screen. The contact inquiry stays the live path: a service
page's "Request this service" button opens `/contact?service={slug}` with the subject "Service request" and a first line
naming the service. That is an ordinary inquiry (`SubmitInquiry`); it books nothing, creates no project, order or payment
and sends no notification beyond the existing inquiry intake.

## The switch

`config('services-projects.catalog_enabled')` is a **code literal** (`false`), read only through
`ServiceProjectPolicy::catalogEnabled()` (strict `=== true`). It is separate from the test project journey's
`VASEY_TEST_SERVICE_PROJECTS_ENABLED` / `ServiceProjectPolicy::enabled()`, which keeps its test-commerce admission and
staging boundary unchanged. There is no client flag and no environment variable, so no staging or production capability
census changes. Turning it on is a reviewed one-line change, taken as the per-family launch decision (SQ-04 amendment,
Sean). BL-50's `FamilyEntryPoints` should read `catalogEnabled()` for the public `/services` path (the private
`/services/projects` path keeps `enabled()`).

## What is published

The existing service draft (`ServiceDrafts`, private evidence only) has no approval state and no price. Phase 1 adds
`service_publications` (one row per draft): an approval pins the draft's **latest immutable version** (id and content
hash), a unique slug, and an **optional staff-entered price** (integer minor units in USD only, per Sean's SQ-06 decision; any other currency is refused server-side;
both or neither). Approval needs `administer-catalog` and admin MFA, writes one audit event
(`services.publication.approved` / `.withdrawn`, subject = the publication row) and is idempotent. Saving a newer draft
version changes nothing public until staff approve again. Rows are never deleted; Withdraw hides the page at once and
re-approving restores it.

The public projection re-verifies the pinned version on every read (decrypts it, checks the service format and that its
hash matches both the version and the approval) and skips the page if anything fails. It shows the title, description and
the **authored** scope, revisions, deposit and cancellation text, labelled indicative ("the written quote you accept sets
the binding scope, price and terms"); unresolved declarations are left out. The price is the staff-entered one, else
"Quoted on request". Nothing is computed or defaulted.

## Files

`app/Domain/Services/Catalog/{ServiceCatalog,ServicePublications}.php`, `Models/ServicePublication.php`, migration
`2026_11_10_286400_service_publications`, `app/Http/Controllers/ServiceCatalogController.php`, routes in
`routes/services.php`, staff screen `app/Filament/Resources/ServicePublicationResource.php`, page
`resources/js/Pages/ServiceCatalog.tsx` (renders server text; no logic), contact prefill in `EditorialController` and
`ContactInquiryForm`. The table is classified `NOT_CUSTOMER_DATA` in `RetentionSchedule`.

## FP-087 audit: deposits, milestones, "no completion before required payment"

What exists in `app/Domain/Services/Projects` (test-chain journey, default off, synthetic accounts):

- A staff-authored quote with integer `totalMinor`, `depositMinor` (zero or within the total), currency, revision
  allowance, cancellation text and ordered milestones; acceptance freezes the quote hash.
- Milestone states `pending -> active -> ready_for_review -> approved`, included revisions against the accepted
  allowance, cancellation request/record, withdrawal. The only transition table is `ServiceProjects::advance`.
- The buyer and staff command sets are closed lists in `ServiceProjectInput::command`. No command completes a project,
  records or collects a payment or deposit, issues an invoice or authorizes delivery. The last status reachable is
  `scope_reviewed`, which the docs define as scope review only. Every projection reports `paymentState=not_collected`
  and `deliveryAuthorized=false` as constants.
- Attachments are authorized by `ServiceProjectAttachmentAuthority` for the brief only; there is no deliverable path.

So "no completion before required payment" holds today **by absence**: there is no completion and no payment. Phase 1
adds `tests/Feature/ServiceProjectPaymentGateTest.php` to pin that absence (closed command sets, no reachable
completed/paid/delivered state, no order/payment/entitlement rows). It is not the real gate and says so.

Missing for FP-087 (phase 2, needs Sean's decisions where marked):

1. A payment request for the accepted quote's deposit and milestone amounts through the existing verified-payment chain
   (durable verified state before any effect, event deduplication, reconciliation evidence); today the quote amounts are
   authored text, not authoritative totals, and no tax is computed (**Sean/accountant:** service tax treatment).
2. A `paymentState` that is derived from verified payments instead of a constant, per quote and milestone.
3. A completion command, refused until the required payments are verified, with a failing-without test.
4. Deliverable intake (staff upload, scanning, private storage) and a short-lived download authorization that requires
   both payment and the buyer's accepted milestone; reuse the existing delivery entitlement model.
5. Refund and cancellation policy mapped to payments; the cancellation text is authored only and no refund is inferred
   (**Sean/counsel:** refund terms for TERMS).
6. Booking dates and invoice documents, if wanted; none are inferred.
7. A customer notice path for quote, milestone and payment events (the journey sends none).
8. Production policy for the customer account path the journey needs (U-07).
