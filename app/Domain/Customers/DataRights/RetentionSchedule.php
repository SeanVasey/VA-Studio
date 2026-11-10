<?php

namespace App\Domain\Customers\DataRights;

/**
 * The retention schedule (U-13, FP-076): every table that holds a record family the deletion workflow keeps
 * (`CustomerDataRights::RETAINED`), that the data-rights export and deletion read, or whose family Sean's decisions of
 * 2026-11-07 and 2026-11-09 classify, with its retention class and the timestamp columns that start its clock. It drives the
 * read-only `vasey:retention-report`; nothing here deletes.
 *
 * Classes with a period take it from `customer-data-rights.retention` through `DataRightsPolicy::retention()`, which refuses
 * an unset or invalid value: periods are never inferred. The key's suffix is the unit (`_years` or `_days`). `indefinite`
 * records are immutable, evidence of a purchase, or held with the order they belong to (AGENTS.md invariants: immutable
 * published licenses, purchased asset revisions, order snapshots, original historical contracts, durable verified payment
 * state; PRIVACY section 7 keeps download records with the order) and are never purged by this mechanism. `not_applicable`
 * tables follow the account's own lifecycle (the deletion workflow pseudonymizes or erases them), are expiring stores, are
 * test-commerce records that must stay empty in production, or hold no customer data.
 *
 * A clock is one of:
 * - `columns`: the row is past its period when every listed column is set and at or before the cutoff. A null column (an
 *   open request, an unfinished delivery, an unpurged export) is never past. The last column dates the row. `format` names
 *   how the columns store time: `datetime` (the default), `iso8601` (`YYYY-MM-DDTHH:MM:SSZ` text) or `epoch` (Unix seconds).
 * - `columns` with `unless_later` [table, foreign key, column]: also no child row newer than the cutoff (a conversation's
 *   last message), and `unless_referenced` [[table, column]] or [[table, column, own column]]: no row there whose column
 *   equals this row's `id` (or own column), so the row is kept with that record (a quote that became an order).
 * - `via` [foreign key, parent table] or [foreign key, parent table, parent key]: the row follows its parent, whose clock is
 *   `columns` or itself `via`; the parent key defaults to `id`. The "parent" is whichever row carries the clock: a production
 *   checkout intent follows the terminal observation that names it (`['id', observations, 'intent_id']`).
 * - `when` [column, value], on a `columns` or a `via` clock, limits it to rows whose column holds that value (one source kind,
 *   one terminal observation kind); any other row is never past.
 * - `latest_in` [group column, order column], on a `columns` clock: the row is past only while it is the last row of its group
 *   in its own table (no row with the same group value and a higher order value). An observation that anything followed, a
 *   failed or contradicting read included, is never past.
 * - `closure` ['account' => column]: the row's account (that column of its own table, a `customer_accounts.id`) had a deletion request
 *   approved at or before the cutoff and no retained order still ties it to its address (`RetainedOrders`, DD-48). Rows without a
 *   completed deletion are never past. Dated by the approval; the report prints only the count.
 * - null: no clock can be modeled from the schema (no primary key or timestamp to scan, or no signal for when the clock
 *   starts), so the table is reported as held and never counted. The rationale says why.
 *
 * Tables in neither ENTRIES nor NOT_CUSTOMER_DATA are listed by the report as unclassified: names only, never a failure.
 */
final class RetentionSchedule
{
    /** Retention class => the `customer-data-rights.retention` key that sets its period, or null for no period. */
    public const CLASSES = [
        'financial' => 'financial_records_years',
        'consent' => 'consent_records_years',
        'correspondence' => 'correspondence_records_years',
        'security' => 'security_records_years',
        'evidence' => 'evidence_records_years',
        'abandoned_checkout' => 'abandoned_checkout_days',
        'operational' => 'operational_records_days',
        'indefinite' => null,
        'not_applicable' => null,
    ];

    /**
     * `CustomerDataRights::RETAINED` labels that are not a single period key, with the classes their tables may take: a record
     * kept indefinitely; a production checkout review kept with its order or, with no order, for the abandoned-checkout period;
     * and a production order snapshot kept as a financial record or, when it was never paid and its provider session was observed
     * expired, for the abandoned-checkout period.
     */
    public const RETAINED_LABELS = [
        'indefinite' => ['indefinite'],
        'with_order_or_abandoned_checkout_days' => ['indefinite', 'abandoned_checkout'],
        'financial_records_years_or_abandoned_checkout_days' => ['indefinite', 'abandoned_checkout'],
    ];

    /**
     * The proven terminal state of an unpaid production (V1) order snapshot (FP-076, decision register "Unpaid order snapshots
     * (retention)"): its intent has a `production_checkout_observations` row of kind `session_expired`, and no
     * `production_checkout_payments` row. `HostedCheckout::record()` is the only writer of a payment row; it refuses any provider
     * read whose session status differs once a retained observation is `expired` (`terminal_regression`), and a confirmed payment
     * needs a `complete` session, so no later reconcile can attach payment. `HostedEvidence::intent()` repeats the check on every
     * read. That terminality rests on this store refusing contrary reads, not on the provider: a refused read leaves only an
     * `uncertain` observation, which cannot be told from an outage. So the expired observation counts only while it is the
     * intent's LATEST observation (`latest_in`); any later observation holds the order until a successful expired read is
     * recorded again, and the clock then starts at that latest observation's `created_at`. Proven by
     * ProductionUnpaidSnapshotRetentionTest. A purge must still re-read the provider session first.
     */
    public const PRODUCTION_ABANDONED_KIND = 'session_expired';

    private const ABANDONED_ORDER = 'PRIVACY 30-day row (decided 2026-11-09, proven 2026-11-10): an unpaid production order whose provider session was observed expired did not become an order; ';

    /** Shared rationales of the families the 2026-11-09 decisions hold with the order (PRIVACY section 7 orders row). */
    private const WITH_ORDER = 'Held with the order (PRIVACY section 7 orders row, decided 2026-11-09): ';

    private const TEST_COMMERCE = 'Test-commerce record (default-off, local/testing/staging rehearsal only); not_applicable in production, where it must stay empty.';

    /** One entry per table; `family` is the `CustomerDataRights::RETAINED` record it belongs to, or null. */
    public const ENTRIES = [
        // Order snapshots and purchase evidence (RETAINED financial).
        ['table' => 'orders', 'family' => 'orders', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Order snapshot: immutable proof of license and tax record; PRIVACY section 7 keeps it while the license can be used.'],
        ['table' => 'order_lines', 'family' => 'orders', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Part of the immutable order snapshot (purchased asset revision and offer).'],
        ['table' => 'order_finalizations', 'family' => 'orders', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Binds the order to its verified payment; purchase evidence.'],
        ['table' => 'license_grants', 'family' => 'license_grants', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Immutable published license grant; many licenses are perpetual.'],
        ['table' => 'grant_contracts', 'family' => 'grant_contracts', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Original historical contract (AGENTS.md: preserved).'],
        ['table' => 'verified_payments', 'family' => 'verified_payments', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Durable verified payment state and reconciliation evidence behind a grant.'],
        ['table' => 'customer_purchase_claims', 'family' => 'customer_purchase_claims', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Links an account to the order it owns; evidence of the purchase for as long as the order is kept.'],
        // Physical-goods fulfillment (BL-63, default-off): the hand-off of a paid order to Printful. Held with the order. Phase 1 gap:
        // the data-rights export and deletion workflow do not read these tables yet (the encrypted shipping address is customer data),
        // so `family` stays null here and the export/deletion coverage is a gate for lifting the merch flag, not a claim.
        ['table' => 'merch_fulfillments', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'fulfillment of a paid order (encrypted shipping address, Printful order id, tracking); the retention of a shipping address after delivery is a U-13 decision.'],
        ['table' => 'merch_fulfillment_events', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'Printful webhook receipts of a fulfillment (identifiers, type and a body hash only; no address); kept with it.'],
        // Download records (RETAINED delivery_records): they follow the order, never their own authorization timestamps.
        ['table' => 'test_delivery_authorizations', 'family' => 'delivery_records', 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'download record of a perpetual license, kept while the order is kept.'],
        ['table' => 'test_delivery_redemptions', 'family' => 'delivery_records', 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'redeemed download, kept with its authorization and order.'],
        ['table' => 'test_delivery_admissions', 'family' => 'delivery_records', 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'each admitted v2 response (FP-077), kept with its authorization and order.'],
        ['table' => 'test_delivery_coverage', 'family' => 'delivery_records', 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'bytes served per v2 response and the one counted completion (FP-077), kept with its authorization and order.'],
        // Production checkout records with frozen buyer declarations (RETAINED financial).
        ['table' => 'production_checkout_reviews', 'family' => 'production_checkout_reviews', 'class' => 'abandoned_checkout',
            'clock' => ['columns' => ['created_at'], 'format' => 'iso8601', 'unless_referenced' => [['production_checkout_orders', 'review_id']]],
            'rationale' => 'PRIVACY: a checkout that did not become an order, 30 days after it; a review an order was built from (D-30) is kept with that order.'],
        ['table' => 'production_checkout_orders', 'family' => 'production_checkout_orders', 'class' => 'abandoned_checkout',
            'clock' => ['via' => ['id', 'production_checkout_provider_intents', 'order_id']],
            'rationale' => 'Production order snapshot (V1). '.self::ABANDONED_ORDER.'a paid order, an order still in flight and an order with no observed expiry are never counted.'],
        ['table' => 'production_tax_checkout_orders', 'family' => 'production_tax_checkout_orders', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Production tax order snapshot (V2).'],
        ['table' => 'production_checkout_exemption_bases', 'family' => 'production_checkout_exemption_bases', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Exemption attestation behind the zero tax an order approved; tax evidence of that order.'],
        ['table' => 'production_identity_origins', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Read by the export; the buyer binding sealed into every production checkout and paid-grant record.'],
        // Paid-grant chains (RETAINED financial).
        ['table' => 'paid_order_origins', 'family' => 'paid_order_origins', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Paid order origin with the sealed buyer binding; purchase evidence.'],
        ['table' => 'paid_grant_origins', 'family' => 'paid_grant_origins', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Paid line origin with copied buyer declarations; the license grant record.'],
        ['table' => 'paid_originals', 'family' => 'paid_originals', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Original contract manifest (AGENTS.md: original historical contracts are preserved).'],
        ['table' => 'paid_tax_order_origins', 'family' => 'paid_tax_order_origins', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Paid-tax order origin; purchase evidence.'],
        ['table' => 'paid_tax_grant_origins', 'family' => 'paid_tax_grant_origins', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Paid-tax line origin; the license grant record.'],
        ['table' => 'paid_tax_originals', 'family' => 'paid_tax_originals', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Original contract manifest of the paid-tax chain.'],
        ['table' => 'paid_authorizations', 'family' => 'paid_authorizations', 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'download record of the paid chain, kept while the order is kept.'],
        ['table' => 'paid_redemptions', 'family' => 'paid_redemptions', 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'redeemed download of the paid chain.'],
        ['table' => 'paid_tax_authorizations', 'family' => 'paid_tax_authorizations', 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'download record of the paid-tax chain, kept while the order is kept.'],
        ['table' => 'paid_tax_redemptions', 'family' => 'paid_tax_redemptions', 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'redeemed download of the paid-tax chain.'],
        // Consent and suppression ledgers (RETAINED consent): "while we hold the email address, plus 3 years". No column records
        // when an address stops being held: account closure has no timestamp, and an erased account's email stays inside its
        // retained order snapshots and contracts, so erasure does not end the holding either. Held, never counted.
        ['table' => 'customer_consent_events', 'family' => 'customer_consent_events', 'class' => 'consent', 'clock' => null,
            'rationale' => 'Clock starts when the email address is no longer held; no column records that (erasure keeps it in orders), so held.'],
        ['table' => 'customer_consent_states', 'family' => 'customer_consent_events', 'class' => 'consent', 'clock' => null,
            'rationale' => 'Current state derived from the consent ledger; held with it.'],
        ['table' => 'customer_suppression_targets', 'family' => 'customer_suppression_records', 'class' => 'consent', 'clock' => null,
            'rationale' => 'An opt-out must keep being honored; no signal that the address is no longer held, so held.'],
        ['table' => 'customer_suppression_intents', 'family' => 'customer_suppression_records', 'class' => 'consent', 'clock' => null,
            'rationale' => 'Suppression ledger; held with its target.'],
        ['table' => 'customer_suppression_attempts', 'family' => 'customer_suppression_records', 'class' => 'consent', 'clock' => null,
            'rationale' => 'Suppression ledger; held with its target.'],
        ['table' => 'customer_suppression_confirmations', 'family' => 'customer_suppression_records', 'class' => 'consent', 'clock' => null,
            'rationale' => 'Suppression ledger; held with its target.'],
        // Production consent and suppression ledgers (DD-48, 2026-11-10): the clock starts at the staff approval of the account's
        // deletion (`customer_data_requests` kind deletion, state completed, `resolved_at`), and only for an account that no retained
        // order, order snapshot or contract still ties to the address (`RetainedOrders::HOLDERS`). While one does, the records stay
        // held with it. Every other ledger row follows its binding or target. Test accounts' ledgers above keep no such signal.
        ['table' => 'production_account_feature_bindings', 'family' => 'production_account_feature_bindings', 'class' => 'consent',
            'clock' => ['closure' => ['account' => 'account_id']],
            'rationale' => 'Key of the production consent ledger (DD-47, corrected by BL-44): the database refuses every update and delete of a binding and the retained consent rows reference it, so it is held with them. It holds ids, a hash and a sealed account binding, no listening content.'],
        ['table' => 'production_consent_events', 'family' => 'production_consent_events', 'class' => 'consent',
            'clock' => ['via' => ['binding_id', 'production_account_feature_bindings']],
            'rationale' => 'Production consent ledger; past its period only once the deletion-approval clock of its binding has run (DD-48).'],
        ['table' => 'production_consent_states', 'family' => 'production_consent_events', 'class' => 'consent',
            'clock' => ['via' => ['binding_id', 'production_account_feature_bindings']],
            'rationale' => 'Current state derived from the production consent ledger; follows its binding (DD-48).'],
        ['table' => 'production_suppression_targets', 'family' => 'production_suppression_records', 'class' => 'consent',
            'clock' => ['via' => ['binding_id', 'production_account_feature_bindings']],
            'rationale' => 'Production opt-out that must keep being honored; past its period only once the deletion-approval clock of its binding has run (DD-48).'],
        ['table' => 'production_suppression_intents', 'family' => 'production_suppression_records', 'class' => 'consent',
            'clock' => ['via' => ['target_id', 'production_suppression_targets']],
            'rationale' => 'Production suppression ledger; follows its target.'],
        ['table' => 'production_suppression_attempts', 'family' => 'production_suppression_records', 'class' => 'consent',
            'clock' => ['via' => ['target_id', 'production_suppression_targets']],
            'rationale' => 'Production suppression ledger; follows its target.'],
        ['table' => 'production_suppression_confirmations', 'family' => 'production_suppression_records', 'class' => 'consent',
            'clock' => ['via' => ['attempt_id', 'production_suppression_attempts']],
            'rationale' => 'Production suppression ledger; follows its attempt.'],
        ['table' => 'customer_consent_policies', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Read by the export: the notice versions consent was given to, not customer data.'],
        // Conversations (RETAINED inquiries): 2 years after the last message unless the conversation concerns an order.
        ['table' => 'customer_inquiries', 'family' => 'inquiries', 'class' => 'correspondence',
            'clock' => ['columns' => ['created_at'], 'unless_later' => ['inquiry_messages', 'inquiry_id', 'created_at'],
                'unless_referenced' => [['inquiry_order_contexts', 'inquiry_id']]],
            'rationale' => 'PRIVACY: 2 years after the last message; a conversation linked to an order is kept as long as the order records.'],
        ['table' => 'inquiry_messages', 'family' => 'inquiries', 'class' => 'correspondence', 'clock' => ['via' => ['inquiry_id', 'customer_inquiries']],
            'rationale' => 'Replies follow their conversation.'],
        ['table' => 'inquiry_notification_intents', 'family' => 'inquiries', 'class' => 'correspondence', 'clock' => ['via' => ['customer_inquiry_id', 'customer_inquiries']],
            'rationale' => 'Staff alert for a conversation; follows it.'],
        ['table' => 'inquiry_order_contexts', 'family' => 'inquiries', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Marks a conversation as about an order, which PRIVACY keeps as long as the order records.'],
        ['table' => 'support_attachments', 'family' => 'inquiries', 'class' => 'correspondence',
            'clock' => ['via' => ['source_id', 'customer_inquiries', 'public_id'], 'when' => ['source_kind', 'inquiry']],
            'rationale' => 'An inquiry attachment follows its conversation (PRIVACY contact row); a service project attachment is never counted here.'],
        // Outgoing order notices (RETAINED correspondence).
        ['table' => 'transactional_notices', 'family' => 'transactional_notices', 'class' => 'correspondence', 'clock' => ['columns' => ['created_at']],
            'rationale' => 'Order-ready intent with the encrypted recipient email.'],
        ['table' => 'transactional_notice_attempts', 'family' => 'transactional_notices', 'class' => 'correspondence',
            'clock' => ['via' => ['notice_id', 'transactional_notices']],
            'rationale' => 'Delivery attempt of an order-ready notice; follows its notice.'],
        ['table' => 'transactional_mail_deliveries', 'family' => 'transactional_mail_deliveries', 'class' => 'correspondence',
            'clock' => ['columns' => ['queued_at', 'finished_at']],
            'rationale' => 'Delivery of a notice; never past while unfinished.'],
        // Security records (RETAINED security): PRIVACY section 7 keeps verification and recovery code records 12 months.
        ['table' => 'customer_identity_challenges', 'family' => 'identity_challenges', 'class' => 'security', 'clock' => ['columns' => ['created_at', 'expires_at']],
            'rationale' => 'PRIVACY: verification and recovery code records are kept 12 months for security.'],
        ['table' => 'customer_purchase_challenges', 'family' => 'identity_challenges', 'class' => 'security',
            'clock' => ['columns' => ['created_at', 'expires_at'], 'unless_referenced' => [['customer_purchase_claims', 'challenge_id']]],
            'rationale' => 'Purchase claim challenge; one that proved a claim is kept with the claim.'],
        ['table' => 'customer_identity_addresses', 'family' => 'identity_challenges', 'class' => 'security', 'clock' => null,
            'rationale' => 'Address key that serializes identity challenges; no primary key or timestamp to scan, so held.'],
        ['table' => 'password_reset_tokens', 'family' => null, 'class' => 'security', 'clock' => null,
            'rationale' => 'PRIVACY verification row (12 months); keyed by email with no primary key to scan in ranges, so held. Tokens expire within the hour.'],
        ['table' => 'production_identity_challenges', 'family' => null, 'class' => 'security',
            'clock' => ['columns' => ['created_at', 'expires_at'], 'unless_referenced' => [['production_identity_origins', 'initial_challenge_id'],
                ['production_identity_verifications', 'challenge_id']]],
            'rationale' => 'PRIVACY verification row, 12 months; a challenge an identity origin or a verification references is part of the verified chain and is kept with it.'],
        ['table' => 'production_identity_notices', 'family' => null, 'class' => 'security', 'clock' => ['columns' => ['created_at']],
            'rationale' => 'Verification code notice of a production identity challenge; PRIVACY verification row, 12 months.'],
        ['table' => 'production_identity_attempts', 'family' => null, 'class' => 'security', 'clock' => ['columns' => ['started_at', 'lease_expires_at']],
            'rationale' => 'Send attempt of a verification notice; PRIVACY verification row, 12 months.'],
        ['table' => 'production_identity_outcomes', 'family' => null, 'class' => 'security', 'clock' => ['columns' => ['created_at']],
            'rationale' => 'Result of a notice send attempt; bound to the attempt, not to an order, so PRIVACY verification row, 12 months.'],
        ['table' => 'production_identity_verifications', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'verification chain of an identity origin; every production read re-verifies it from sequence 1, and sealed order, grant, invoice and checkout bindings name it.'],
        ['table' => 'production_identity_addresses', 'family' => null, 'class' => 'security', 'clock' => null,
            'rationale' => 'Address hash with no timestamp; one an identity origin binds is kept with the orders that origin is sealed into. Held.'],
        // Audit events evidence license approvals, exclusive activations, payments, finalizations and refunds of records kept
        // indefinitely, so they are kept with those records.
        ['table' => 'audit_events', 'family' => 'audit_events', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Evidence of license approvals, exclusive activations, payments, finalizations, refunds, role changes and deletions; kept with the records it proves.'],
        // Privacy request records (RETAINED evidence): 7 years after the request closes.
        ['table' => 'customer_data_requests', 'family' => 'customer_data_requests', 'class' => 'evidence', 'clock' => ['columns' => ['resolved_at']],
            'rationale' => 'PRIVACY: 7 years after the request closes; an open request has no resolved_at and is never past.'],
        ['table' => 'customer_data_request_events', 'family' => 'customer_data_requests', 'class' => 'evidence',
            'clock' => ['via' => ['request_id', 'customer_data_requests']],
            'rationale' => 'Append-only history of a data request; follows the request.'],
        ['table' => 'customer_data_exports', 'family' => 'customer_data_exports', 'class' => 'evidence',
            'clock' => ['via' => ['request_id', 'customer_data_requests']],
            'rationale' => 'Export metadata (contents purged on download, expiry or deletion approval); follows its request. File contents are out of scope.'],
        // Abandoned checkouts and quotes: 30 days after the quote ends, unless it became an order (orders.quote_id).
        ['table' => 'quotes', 'family' => null, 'class' => 'abandoned_checkout',
            'clock' => ['columns' => ['created_at', 'expires_at'], 'unless_referenced' => [['orders', 'quote_id']]],
            'rationale' => 'PRIVACY: a quote that did not become an order, 30 days after it expires; one an order was built from is kept with it.'],
        ['table' => 'quote_lines', 'family' => null, 'class' => 'abandoned_checkout', 'clock' => ['via' => ['quote_id', 'quotes']],
            'rationale' => 'Lines of a quote; follow the quote.'],
        ['table' => 'quote_pricings', 'family' => null, 'class' => 'abandoned_checkout', 'clock' => ['via' => ['quote_id', 'quotes']],
            'rationale' => 'Price snapshot of a quote; follows the quote.'],
        ['table' => 'inventory_reservations', 'family' => null, 'class' => 'abandoned_checkout', 'clock' => ['via' => ['quote_id', 'quotes']],
            'rationale' => 'Inventory reservation of a quote; follows the quote.'],
        ['table' => 'inventory_claims', 'family' => null, 'class' => 'abandoned_checkout', 'clock' => ['via' => ['inventory_reservation_id', 'inventory_reservations']],
            'rationale' => 'Exclusive inventory claim of a reservation; follows the reservation and its quote.'],
        ['table' => 'promotion_uses', 'family' => null, 'class' => 'abandoned_checkout', 'clock' => ['via' => ['quote_pricing_id', 'quote_pricings']],
            'rationale' => 'Promotion applied to a quote price; follows the quote.'],
        ['table' => 'quote_owners', 'family' => null, 'class' => 'abandoned_checkout', 'clock' => null,
            'rationale' => 'Buyer owner key shared by quotes and orders; no primary key or timestamp to scan, so held.'],
        // Checkout state bound to an order snapshot: every intent, attempt and session row has a non-null order (or a parent that
        // has one), so it follows the order. The Stripe-test chain (`orders` and the four tables below) stays with the order even
        // when unpaid: it has no terminal unpaid state, because a staff release keeps accepting a late confirmed payment
        // (`UnpaidReleasePolicy::CONTRACT` late_confirmation, VerifyTestPayment::commit). The production V1 chain follows its order
        // into the abandoned-checkout class only through the proven terminal state (PRODUCTION_ABANDONED_KIND).
        ['table' => 'order_attempts', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'checkout attempt bound to its order snapshot (order_id is required and unique).'],
        ['table' => 'checkout_intents', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'payment intent created for an existing order snapshot (order_id is required).'],
        ['table' => 'checkout_sessions', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'provider session of an intent that always has an order.'],
        ['table' => 'checkout_observations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'provider session status observations of an order checkout.'],
        ['table' => 'production_checkout_order_lines', 'family' => null, 'class' => 'abandoned_checkout', 'clock' => ['via' => ['order_id', 'production_checkout_orders']],
            'rationale' => 'Lines of the production order snapshot; follow their order ('.self::ABANDONED_ORDER.'otherwise kept with the order).'],
        ['table' => 'production_checkout_attempts', 'family' => null, 'class' => 'abandoned_checkout', 'clock' => ['via' => ['order_id', 'production_checkout_orders']],
            'rationale' => 'Checkout attempt of a production order (order_id is required); follows its order, so it is kept with a paid or in-flight order.'],
        ['table' => 'production_checkout_provider_intents', 'family' => null, 'class' => 'abandoned_checkout',
            'clock' => ['via' => ['id', 'production_checkout_observations', 'intent_id']],
            'rationale' => 'Payment intent of a production order; follows the expired-session observation that ends it, and is kept with the order while a payment exists or no expiry was observed.'],
        ['table' => 'production_checkout_sessions', 'family' => null, 'class' => 'abandoned_checkout', 'clock' => ['via' => ['intent_id', 'production_checkout_provider_intents']],
            'rationale' => 'Provider session of a production order intent; follows its intent.'],
        // The clock row of an abandoned production order: only a `session_expired` observation that is the intent's latest
        // observation, 30 days after it was recorded, and never while the intent has a payment. Other observations of the same
        // intent (`session_open`, `uncertain`, a `canceled` payment attempt, an earlier expiry) are never counted on their own; a
        // purge design must take them with the intent.
        ['table' => 'production_checkout_observations', 'family' => null, 'class' => 'abandoned_checkout',
            'clock' => ['columns' => ['created_at'], 'format' => 'iso8601', 'when' => ['kind', self::PRODUCTION_ABANDONED_KIND],
                'latest_in' => ['intent_id', 'sequence'], 'unless_referenced' => [['production_checkout_payments', 'intent_id', 'intent_id']]],
            'rationale' => 'Provider observations of a production order intent. '.self::ABANDONED_ORDER.'the expired-session observation starts the 30 days while it is the intent\'s latest observation; one that a later read followed (a failed or contradicting read), or whose intent has a payment, is kept with the order.'],
        ['table' => 'production_checkout_exemption_authorities', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'qualification authority behind an exemption basis; tax evidence of the orders it approved.'],
        ['table' => 'production_tax_checkout_requests', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'tax checkout request of a production tax order (order_id is required).'],
        ['table' => 'production_tax_checkout_bindings', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'provider session binding of a tax checkout request.'],
        // Payment records.
        ['table' => 'payment_observations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'payment provider observation of an order checkout; payment evidence.'],
        ['table' => 'stripe_webhook_receipts', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'deduplicated payment provider events and reconciliation evidence (AGENTS.md).'],
        ['table' => 'stripe_receipt_work', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'processing state of a payment provider event.'],
        ['table' => 'production_checkout_payments', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'observed payment of a production order.'],
        ['table' => 'production_tax_checkout_reviewed_sessions', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'reviewed paid session of a production tax order, with its amounts.'],
        // Refunds and disputes.
        ['table' => 'refund_dispute_objects', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'refund or dispute of an order.'],
        ['table' => 'refund_dispute_observations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'observed refund or dispute state of an order.'],
        ['table' => 'refund_dispute_statuses', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'status history of a refund or dispute.'],
        ['table' => 'refund_dispute_effects', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'effects a refund or dispute applied to an order or grant.'],
        ['table' => 'refund_dispute_holds', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'hold a refund or dispute placed on an order or grant.'],
        ['table' => 'refund_dispute_work', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'processing state of an order refund or dispute.'],
        ['table' => 'refund_line_allocations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'refund amount allocated to an order line.'],
        // Fulfillment.
        ['table' => 'fulfillment_outbox', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'fulfillment effects of a finalized order.'],
        ['table' => 'pending_entitlements', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'deliverable assets a license grant entitles.'],
        ['table' => 'contract_render_requests', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'contract render request of a license grant.'],
        ['table' => 'contract_render_work', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'processing state of a contract render request.'],
        ['table' => 'paid_fulfillments', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'fulfillment batch of the paid chain.'],
        ['table' => 'paid_document_work', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'contract document work of a paid grant origin.'],
        ['table' => 'paid_tax_fulfillments', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'fulfillment batch of the paid-tax chain.'],
        ['table' => 'paid_tax_document_work', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'contract document work of a paid-tax grant origin.'],
        ['table' => 'service_projects', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'service project a customer bought, with its brief; fulfillment record.'],
        ['table' => 'service_project_events', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'history of a purchased service project.'],
        ['table' => 'service_invoices', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'Stripe test-mode invoice for a purchased service project (BL-70); a settled invoice is purchase evidence and is never deleted. '
                .'Test-commerce record, default-off; in production the lane is held off.'],
        ['table' => 'service_invoice_payments', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'verified service payment (BL-70): durable verified payment state behind a service project, immutable (AGENTS.md invariant).'],
        // Grants, including free licenses (PRIVACY orders row covers free licenses and their download records).
        ['table' => 'exclusive_sales', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'exclusive sale of a rights scope; must never be silently revoked (AGENTS.md).'],
        ['table' => 'free_origins', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'free license grant of an account.'],
        ['table' => 'free_originals', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'original contract of a free license (AGENTS.md: preserved).'],
        ['table' => 'free_document_work', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'contract document work of a free license.'],
        ['table' => 'free_authorizations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'download record of a free license.'],
        ['table' => 'free_redemptions', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'redeemed download of a free license.'],
        ['table' => 'production_free_origins', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'production free license grant of an account.'],
        ['table' => 'production_free_originals', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'original contract of a production free license.'],
        ['table' => 'production_free_document_work', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'contract document work of a production free license.'],
        ['table' => 'production_free_authorizations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'download record of a production free license.'],
        ['table' => 'production_free_redemptions', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'redeemed download of a production free license.'],
        ['table' => 'production_free_revocations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'staff revocation of a production free license; grant evidence.'],
        ['table' => 'production_member_origins', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'member license grant of an account, from a membership redemption.'],
        ['table' => 'production_member_activations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'activation of a member license grant.'],
        ['table' => 'production_member_artifacts', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'deliverable artifacts of a member license grant.'],
        ['table' => 'production_member_definitions', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'member grant definition and original terms sealed into every member grant made from it.'],
        ['table' => 'production_member_profiles', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'member grant renderer profile sealed into every member grant made from it.'],
        // Membership billing and credits.
        ['table' => 'membership_credit_buckets', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'membership credit allowance of an account from a billing event.'],
        ['table' => 'membership_credit_events', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'membership credit ledger of an account.'],
        ['table' => 'production_membership_billing_subscriptions', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'membership billing subscription of an account.'],
        ['table' => 'production_membership_billing_invoices', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'membership billing invoice; payment evidence.'],
        ['table' => 'production_membership_billing_observations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'observed membership invoice state and amounts; payment evidence.'],
        ['table' => 'production_membership_billing_events', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'membership billing provider events; payment evidence.'],
        ['table' => 'production_membership_billing_attempts', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'membership invoice retrieval attempts; reconciliation evidence.'],
        ['table' => 'production_membership_billing_positions', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'ordering positions of membership billing retrievals; reconciliation evidence.'],
        ['table' => 'production_membership_billing_award_fence', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'serialization fence of membership credit awards; part of the billing ledger.'],
        ['table' => 'production_membership_paid_periods', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'paid membership period of an account.'],
        ['table' => 'production_membership_credit_events', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'membership credit ledger of a paid period.'],
        ['table' => 'production_membership_redemptions', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'membership credit redemption that a member grant is made from.'],
        // Imported BeatStars order history.
        ['table' => 'legacy_history_batches', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'import batch of legacy order history (source hashes, dry-run evidence).'],
        ['table' => 'legacy_history_customers', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'buyer of imported legacy orders; part of the legacy order record.'],
        ['table' => 'legacy_history_orders', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'imported legacy order.'],
        ['table' => 'legacy_history_lines', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'imported legacy order line.'],
        ['table' => 'legacy_history_contracts', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'imported original contract (AGENTS.md: original historical contracts are preserved).'],
        ['table' => 'legacy_history_contract_declarations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'declared legacy contract of an imported order line.'],
        ['table' => 'legacy_history_grant_reviews', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'staff review of an imported legacy line.'],
        ['table' => 'legacy_history_track_links', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'link from an imported legacy order line to the store track it names; part of the legacy order record.'],
        ['table' => 'legacy_exclusivity_authority_events', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => self::WITH_ORDER.'exclusivity authority over legacy sales; protects prior exclusive grants.'],
        // Framework job stores: PRIVACY application error log row, 14 days.
        ['table' => 'jobs', 'family' => null, 'class' => 'operational', 'clock' => ['columns' => ['created_at'], 'format' => 'epoch'],
            'rationale' => 'Queued job payloads can hold customer data; PRIVACY application error log row, 14 days after queueing.'],
        ['table' => 'failed_jobs', 'family' => null, 'class' => 'operational', 'clock' => ['columns' => ['failed_at']],
            'rationale' => 'Failed job payloads and exceptions can hold customer data; PRIVACY application error log row, 14 days after failing.'],
        ['table' => 'job_batches', 'family' => null, 'class' => 'operational', 'clock' => null,
            'rationale' => 'Job batch counters; its primary key is a string, which the primary-key range scan cannot read, so held.'],
        // Expiring stores and test commerce.
        ['table' => 'cache', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Expiring cache store (PRIVACY rate-limit row): each entry carries its own expiration and is removed by routine cleanup.'],
        ['table' => 'cache_locks', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Expiring lock store (PRIVACY rate-limit row): each lock carries its own expiration.'],
        ['table' => 'test_delivery_controls', 'family' => null, 'class' => 'not_applicable', 'clock' => null, 'rationale' => self::TEST_COMMERCE],
        ['table' => 'test_fulfillment_activations', 'family' => null, 'class' => 'not_applicable', 'clock' => null, 'rationale' => self::TEST_COMMERCE],
        ['table' => 'test_payment_exception_events', 'family' => null, 'class' => 'not_applicable', 'clock' => null, 'rationale' => self::TEST_COMMERCE],
        ['table' => 'test_payment_exception_work', 'family' => null, 'class' => 'not_applicable', 'clock' => null, 'rationale' => self::TEST_COMMERCE],
        ['table' => 'test_payment_financial_observations', 'family' => null, 'class' => 'not_applicable', 'clock' => null, 'rationale' => self::TEST_COMMERCE],
        ['table' => 'test_refund_resolution_requests', 'family' => null, 'class' => 'not_applicable', 'clock' => null, 'rationale' => self::TEST_COMMERCE],
        ['table' => 'test_refund_resolutions', 'family' => null, 'class' => 'not_applicable', 'clock' => null, 'rationale' => self::TEST_COMMERCE],
        ['table' => 'test_unpaid_releases', 'family' => null, 'class' => 'not_applicable', 'clock' => null, 'rationale' => self::TEST_COMMERCE],
        ['table' => 'test_unpaid_release_events', 'family' => null, 'class' => 'not_applicable', 'clock' => null, 'rationale' => self::TEST_COMMERCE],
        ['table' => 'test_unpaid_release_work', 'family' => null, 'class' => 'not_applicable', 'clock' => null, 'rationale' => self::TEST_COMMERCE],
        // Review evidence held indefinitely (DD-47): staff-reported buyer assent observations. They are not keyed to an account.
        ['table' => 'production_buyer_assent_observations', 'family' => null, 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Staff-reported production buyer assent, sealed and held indefinitely as review evidence (DD-47); no account key, so closure leaves it as it is.'],
        // Account lifecycle, handled by the deletion workflow rather than a retention purge.
        ['table' => 'users', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Sign-in profile: pseudonymized when a deletion request is approved.'],
        ['table' => 'customer_accounts', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Account row: access withdrawn on deletion; immutable records reference it.'],
        ['table' => 'customer_saved_tracks', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Listening library: erased on deletion approval.'],
        ['table' => 'production_listening_libraries', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Production listening library (DD-47): its encrypted contents are overwritten with the empty library when a deletion request is approved; the row stays because the database refuses every delete of it.'],
        ['table' => 'sessions', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Expire after inactivity and are ended on deletion approval.'],
    ];

    /**
     * Tables reviewed (by reading their migration or schema class) as holding no customer data: framework, catalog, storefront,
     * license-template and staff-authored definition tables. Staff actor ids may appear. Any other table that is neither here nor
     * in ENTRIES is reported as unclassified and needs Sean's retention decision; when unsure, a table stays unclassified.
     */
    public const NOT_CUSTOMER_DATA = [
        'migrations' => 'Framework migration log: migration names and batch numbers.',
        'tracks' => 'Catalog: published track metadata.',
        'track_publication_schedules' => 'Catalog: scheduled track publications (staff actors, track state, alert evidence).',
        'track_metadata_presets' => 'Catalog: staff metadata presets.',
        'collaborators' => 'Catalog: staff-entered collaborator credits (display name, role, source reference); no customer, payment, tax or contact data.',
        'album_releases' => 'Catalog: staff-entered releases of Sean\'s own music (title, artist, date, cover art reference, listening links); no customer, payment or contact data.',
        'album_release_tracks' => 'Catalog: staff-entered tracklist rows (title, duration) of a release.',
        'track_collections' => 'Catalog: staff-entered browse collections of tracks (title, slug, description, publication state); no price, customer, payment or contact data.',
        'track_collection_items' => 'Catalog: ordered references from a collection to existing tracks; no customer data.',
        'track_collaborator_splits' => 'Catalog: staff-entered per-track collaborator shares in basis points; metadata only.',
        'media_assets' => 'Catalog media files (masters, previews, artwork) uploaded by staff.',
        'media_processing_runs' => 'Processing runs of catalog media.',
        'media_upload_sessions' => 'Resumable staff uploads of catalog media.',
        'stems_recordings' => 'Catalog: verified stems and master links of a track.',
        'rights_declarations' => 'Catalog: rights and sample declarations of a track.',
        'rights_scopes' => 'Catalog: rights scopes that offers sell.',
        'rights_scope_offers' => 'Catalog: links between rights scopes and offer revisions.',
        'exclusive_activations' => 'Catalog: staff activation of an exclusive offer; no buyer reference.',
        'license_templates' => 'License template names.',
        'license_versions' => 'Published license text and terms, authored and approved by staff.',
        'license_review_evidence' => 'Staff review evidence of a license version.',
        'vasey_license_contract_texts' => 'Contract template and renderer profile shared by every buyer of a version, by hash; no buyer data.',
        'offers' => 'Catalog: offers and prices.',
        'offer_revisions' => 'Catalog: immutable published offer revisions.',
        'catalog_import_batches' => 'Catalog import evidence (track source hashes and review); no customer records.',
        'catalog_import_mappings' => 'Catalog import mapping of source records to tracks.',
        'catalog_discovery_epoch' => 'Catalog discovery invalidation counter.',
        'discovery_sitemap_current' => 'Public sitemap pointer.',
        'discovery_sitemap_generations' => 'Public sitemap generations.',
        'discovery_sitemap_windows' => 'Public sitemap pages.',
        'site_images' => 'Storefront images uploaded by staff, with their credits.',
        'site_image_variants' => 'Resized storefront image variants.',
        'site_releases' => 'Storefront content releases.',
        'site_release_images' => 'Images of a storefront release.',
        'site_publications' => 'Active storefront release pointer.',
        'site_publication_revisions' => 'Storefront publication history (staff actors).',
        'site_publication_schedules' => 'Scheduled storefront publications.',
        'sound_kit_drafts' => 'Catalog: sound kit drafts.',
        'sound_kit_revisions' => 'Catalog: sound kit archives and manifests.',
        'sound_kit_upload_sessions' => 'Resumable staff uploads of sound kits.',
        'product_drafts' => 'Catalog: product drafts.',
        'product_draft_versions' => 'Catalog: product draft versions.',
        'product_draft_members' => 'Catalog: tracks of a product draft version.',
        'merch_drafts' => 'Catalog: merch drafts.',
        'merch_draft_versions' => 'Catalog: merch draft versions.',
        'service_drafts' => 'Catalog: service drafts.',
        'service_draft_versions' => 'Catalog: service draft versions.',
        'service_publications' => 'Catalog: staff approvals of service draft versions for the public service pages; staff-authored, no customer data.',
        'membership_plans' => 'Membership plan definitions.',
        'membership_plan_versions' => 'Membership plan versions.',
        'production_membership_plan_versions' => 'Production membership plan policies and terms.',
        'promotion_campaigns' => 'Promotion definitions and codes.',
        'promotion_availabilities' => 'Current on/off state of a promotion.',
        'promotion_availability_revisions' => 'Staff history of promotion availability.',
        'free_definitions' => 'Free-download definitions authored by staff.',
        'free_reviews' => 'Staff review of a free-download definition.',
        'free_availability' => 'Staff availability changes of a free-download definition.',
        'production_free_definitions' => 'Production free-grant definitions authored by staff.',
        'production_free_reviews' => 'Staff review of a production free-grant definition.',
        'production_free_availability' => 'Staff availability changes of a production free-grant definition.',
        'production_consent_policies' => 'Production consent notice versions, as customer_consent_policies.',
        'production_track_policy_drafts' => 'Staff production track policy drafts.',
        'production_track_policy_versions' => 'Staff production track policy versions.',
        'production_track_policy_source_reviews' => 'Staff review of a production track policy version.',
        'production_track_capability_candidates' => 'Production track capability candidates (catalog policy).',
        'production_track_capability_approvals' => 'Staff approval of a capability candidate.',
        'production_track_capability_closures' => 'Staff closure of a capability candidate.',
        'production_track_preparation_packets' => 'Staff production preparation packets of catalog offers.',
        'production_track_preparation_packet_lines' => 'Offer lines of a preparation packet.',
        'transactional_mail_scan_progress' => 'Mail scanner cursor positions; no recipient data.',
        'scheduler_heartbeats' => 'Operational health: scheduled command names and their last success and failure times.',
        'backup_evidence' => 'Operational health: backup and restore-drill kind, status, size and dump hash; no path or customer data.',
    ];

    /** @return array<string, array{table: string, family: ?string, class: string, clock: ?array, rationale: string}> keyed by table */
    public static function byTable(): array
    {
        return array_column(self::ENTRIES, null, 'table');
    }

    /** @return list<array{table: string, family: ?string, class: string, clock: ?array, rationale: string}> */
    public static function forClass(string $class): array
    {
        return array_values(array_filter(self::ENTRIES, fn (array $entry): bool => $entry['class'] === $class));
    }
}
