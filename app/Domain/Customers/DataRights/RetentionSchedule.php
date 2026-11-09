<?php

namespace App\Domain\Customers\DataRights;

/**
 * The retention schedule (U-13, FP-076): every table that holds a record family the deletion workflow keeps
 * (`CustomerDataRights::RETAINED`) or that the data-rights export and deletion read, with its retention class and the
 * timestamp columns that start its clock. It drives the read-only `vasey:retention-report`; nothing here deletes.
 *
 * Classes with a period take it from `customer-data-rights.retention` through `DataRightsPolicy::retention()`, which refuses
 * an unset or invalid value: periods are never inferred. `indefinite` records are immutable or evidence of a purchase
 * (AGENTS.md invariants: immutable published licenses, purchased asset revisions, order snapshots, original historical
 * contracts, durable verified payment state) and are never purged by this mechanism. `not_applicable` tables follow the
 * account's own lifecycle (the deletion workflow pseudonymizes or erases them) or hold no customer data.
 *
 * A clock is one of:
 * - `columns`: the row is past its period when every listed column is set and at or before the cutoff. A null column (an
 *   open request, an unfinished delivery, an unpurged export) is never past. The last column dates the row.
 * - `columns` with `unless_later` [table, foreign key, column]: also no child row newer than the cutoff (a conversation's
 *   last message), and `unless_referenced` [[table, column]]: no row there references this one (kept with that record).
 * - `via` [foreign key, parent table]: the row follows its parent, whose clock must be `columns`.
 * - null: the clock the approved policy names is not modeled yet, or the approved policy names no period for the record
 *   (audit and data-request evidence, until Sean decides), so the table is reported as held and never counted.
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
        'indefinite' => null,
        'not_applicable' => null,
    ];

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
        // Download records (RETAINED delivery_records): a minimum period only.
        ['table' => 'test_delivery_authorizations', 'family' => 'delivery_records', 'class' => 'financial', 'clock' => ['columns' => ['issued_at', 'expires_at']],
            'rationale' => 'Download record. PRIVACY: at least the financial period and while the license can be used, so past-period rows are review candidates only.'],
        ['table' => 'test_delivery_redemptions', 'family' => 'delivery_records', 'class' => 'financial', 'clock' => ['columns' => ['redeemed_at']],
            'rationale' => 'Download record (redeemed). Same minimum period as its authorization.'],
        // Production checkout records with frozen buyer declarations (RETAINED financial).
        ['table' => 'production_checkout_reviews', 'family' => 'production_checkout_reviews', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Frozen buyer declarations an order snapshot is built from (D-30).'],
        ['table' => 'production_checkout_orders', 'family' => 'production_checkout_orders', 'class' => 'indefinite', 'clock' => null,
            'rationale' => 'Production order snapshot (V1).'],
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
        ['table' => 'paid_authorizations', 'family' => 'paid_authorizations', 'class' => 'financial', 'clock' => ['columns' => ['created_at', 'expires_at']],
            'rationale' => 'Download record of the paid chain; minimum period only, as for test delivery records.'],
        ['table' => 'paid_redemptions', 'family' => 'paid_redemptions', 'class' => 'financial', 'clock' => ['columns' => ['created_at']],
            'rationale' => 'Redeemed download of the paid chain; minimum period only.'],
        ['table' => 'paid_tax_authorizations', 'family' => 'paid_tax_authorizations', 'class' => 'financial', 'clock' => ['columns' => ['created_at', 'expires_at']],
            'rationale' => 'Download record of the paid-tax chain; minimum period only.'],
        ['table' => 'paid_tax_redemptions', 'family' => 'paid_tax_redemptions', 'class' => 'financial', 'clock' => ['columns' => ['created_at']],
            'rationale' => 'Redeemed download of the paid-tax chain; minimum period only.'],
        // Consent and suppression ledgers (RETAINED consent): the clock is "while we hold the email address, plus 3 years".
        ['table' => 'customer_consent_events', 'family' => 'customer_consent_events', 'class' => 'consent', 'clock' => null,
            'rationale' => 'Clock starts when the email address is no longer held, which is not modeled; held so consent stays provable.'],
        ['table' => 'customer_consent_states', 'family' => 'customer_consent_events', 'class' => 'consent', 'clock' => null,
            'rationale' => 'Current state derived from the consent ledger; held with it.'],
        ['table' => 'customer_suppression_targets', 'family' => 'customer_suppression_records', 'class' => 'consent', 'clock' => null,
            'rationale' => 'An opt-out must keep being honored; clock not modeled, so held.'],
        ['table' => 'customer_suppression_intents', 'family' => 'customer_suppression_records', 'class' => 'consent', 'clock' => null,
            'rationale' => 'Suppression ledger; held with its target.'],
        ['table' => 'customer_suppression_attempts', 'family' => 'customer_suppression_records', 'class' => 'consent', 'clock' => null,
            'rationale' => 'Suppression ledger; held with its target.'],
        ['table' => 'customer_suppression_confirmations', 'family' => 'customer_suppression_records', 'class' => 'consent', 'clock' => null,
            'rationale' => 'Suppression ledger; held with its target.'],
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
        ['table' => 'support_attachments', 'family' => 'inquiries', 'class' => 'correspondence', 'clock' => null,
            'rationale' => 'Polymorphic source and private files on disk; no per-source clock is modeled, so held.'],
        // Outgoing order notices (RETAINED correspondence).
        ['table' => 'transactional_notices', 'family' => 'transactional_notices', 'class' => 'correspondence', 'clock' => ['columns' => ['created_at']],
            'rationale' => 'Order-ready intent with the encrypted recipient email.'],
        ['table' => 'transactional_mail_deliveries', 'family' => 'transactional_mail_deliveries', 'class' => 'correspondence',
            'clock' => ['columns' => ['queued_at', 'finished_at']],
            'rationale' => 'Delivery of a notice; never past while unfinished.'],
        // Security records (RETAINED security). PRIVACY section 7 sets 12 months only for verification and recovery code records.
        ['table' => 'customer_identity_challenges', 'family' => 'identity_challenges', 'class' => 'security', 'clock' => ['columns' => ['created_at', 'expires_at']],
            'rationale' => 'PRIVACY: verification and recovery code records are kept 12 months for security.'],
        ['table' => 'customer_purchase_challenges', 'family' => 'identity_challenges', 'class' => 'security',
            'clock' => ['columns' => ['created_at', 'expires_at'], 'unless_referenced' => [['customer_purchase_claims', 'challenge_id']]],
            'rationale' => 'Purchase claim challenge; one that proved a claim is kept with the claim.'],
        // Audit and data-request evidence: PRIVACY section 7 gives these records no period, so they are held (never counted)
        // until Sean decides their retention. They are the evidence that a deletion was honored, of role changes and of held orders.
        ['table' => 'audit_events', 'family' => 'audit_events', 'class' => 'security', 'clock' => null,
            'rationale' => 'Held until Sean decides: PRIVACY gives audit evidence no period; it also proves deletions, role changes and held orders.'],
        ['table' => 'customer_data_requests', 'family' => 'customer_data_requests', 'class' => 'security', 'clock' => null,
            'rationale' => 'Held until Sean decides: PRIVACY gives data request records no period; this is the evidence a deletion was honored.'],
        ['table' => 'customer_data_request_events', 'family' => 'customer_data_requests', 'class' => 'security', 'clock' => null,
            'rationale' => 'Held with its request until Sean decides: append-only history of the data request.'],
        ['table' => 'customer_data_exports', 'family' => 'customer_data_exports', 'class' => 'security', 'clock' => null,
            'rationale' => 'Held until Sean decides: export rows (contents purged) are kept as data request evidence, which PRIVACY gives no period.'],
        // Account lifecycle, handled by the deletion workflow rather than a retention purge.
        ['table' => 'users', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Sign-in profile: pseudonymized when a deletion request is approved.'],
        ['table' => 'customer_accounts', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Account row: access withdrawn on deletion; immutable records reference it.'],
        ['table' => 'customer_saved_tracks', 'family' => null, 'class' => 'not_applicable', 'clock' => null,
            'rationale' => 'Listening library: erased on deletion approval.'],
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
        'track_metadata_presets' => 'Catalog: staff metadata presets.',
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
