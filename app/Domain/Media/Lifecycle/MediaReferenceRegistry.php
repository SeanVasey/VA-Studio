<?php

namespace App\Domain\Media\Lifecycle;

use App\Domain\Catalog\OfferSnapshot;
use App\Domain\Catalog\ProductDraftManifest;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\SiteBuilder\SiteRelatedTracks;
use App\Jobs\BuildDiscoverySitemapWindow;
use App\Jobs\DeliverProductionIdentityNotice;
use App\Jobs\DeliverTransactionalMail;
use App\Jobs\FinalizeTestPaymentJob;
use App\Jobs\IssueTestContractJob;
use App\Jobs\NotifyInquiryOperatorJob;
use App\Jobs\ProcessMedia;
use App\Jobs\ProcessSiteImage;
use App\Jobs\ProcessSoundKit;
use App\Jobs\ProcessStripeReceiptJob;
use App\Jobs\RetrieveMembershipInvoice;

/**
 * The schema-reviewed list of every column that can reference a media asset, a processing run, an upload session, a track
 * or a media manifest digest (FP-034 delete prerequisite P2-4). {@see MediaReferenceProof} reads references only through
 * this list; it no longer guesses from column names.
 *
 * Every "candidate" column of the live schema must appear in exactly one list below: a column foreign-keyed to tracks or
 * to a media table, a column whose name looks like media (see {@see self::NAME_PATTERN}), a `<prefix>_type`/`<prefix>_id`
 * pair, and every long-text or JSON column. A candidate that is in no list makes coverage incomplete at run time
 * (`unregistered:<table>.<column>`), and `MediaReferenceRegistryTest` fails on it, so a migration that adds such a column
 * must classify it here in the same change. When in doubt use {@see self::OPAQUE}: it is the fail-closed choice.
 *
 * Adding a column to {@see self::REVIEWED} says "this can never name a media family or its track"; give the reason.
 */
final class MediaReferenceRegistry
{
    /** Column names that look like media. Long-text columns and foreign keys are candidates whatever their name. */
    public const NAME_PATTERN = '/asset|track|media|manifest|recording|deliverable|preview|artwork|waveform|(?:\A|_)(?:stems?|master)(?:_|\z)/';

    public const LONG_TEXT_TYPES = ['text', 'tinytext', 'mediumtext', 'longtext', 'json', 'jsonb', 'clob'];

    public const TARGET_TABLES = ['media_assets', 'media_processing_runs', 'media_upload_sessions'];

    /** Integer columns holding the id of a media row: column => table it points into. Checks `fk:<table>.<column>`. */
    public const MEDIA_IDS = [
        'media_assets.parent_asset_id' => 'media_assets',
        'media_assets.processing_run_id' => 'media_processing_runs',
        'media_processing_runs.source_asset_id' => 'media_assets',
        'media_upload_sessions.asset_id' => 'media_assets',
        'pending_entitlements.media_asset_id' => 'media_assets',
        'stems_recordings.stems_asset_id' => 'media_assets',
        'stems_recordings.master_asset_id' => 'media_assets',
        'stems_recordings.preview_asset_id' => 'media_assets',
        'stems_recordings.recording_source_id' => 'media_assets',
    ];

    /**
     * Integer columns holding a track id on rows that record a sold or committed item (P2-2). Every family of that track
     * counts as attached. Checks `track:<table>.<column>`. Test-chain order lines (`order_lines`, `quote_lines`) name
     * their track only through `offer_revision_id`, so the immutable `offer_revisions.track_id` row stands for them.
     */
    public const TRACK_IDS = [
        'catalog_import_mappings.track_id',
        'free_definitions.track_id',
        'offer_revisions.track_id',
        'product_draft_members.track_id',
        'production_checkout_order_lines.track_id',
        'production_track_preparation_packet_lines.track_id',
    ];

    /**
     * Text columns holding a track identifier from another system. No reviewed link from these to store tracks exists yet
     * (FP-098/FP-099), so none is resolvable: any value makes coverage incomplete
     * (`unresolved_track_source:<table>.<column>`).
     */
    public const TRACK_SOURCE_IDS = ['legacy_history_lines.track_source_id'];

    /** Polymorphic `<prefix>_type`/`<prefix>_id` pairs that can name a media row: `<table>.<prefix>`. Checks `poly:...`. */
    public const POLYMORPHIC = [];

    /**
     * Plaintext JSON read by the cheap structural pass (small, indexed-by-design tables): column => parser.
     * `asset_list` is a bare list of asset ids; `ids` is the key vocabulary of {@see MediaReferenceJson}.
     */
    public const JSON_STRUCTURAL = [
        'media_processing_runs.output_asset_ids' => 'asset_list',
        'offer_revisions.snapshot' => 'assets',
        'offers.deliverable_asset_ids' => 'asset_list',
    ];

    /**
     * Plaintext JSON read only by the full proof (P2-3): column => parser. `ids` reads asset, run and track ids; `assets`
     * reads asset and run ids only; `outbox` and `job` accept one documented shape and make anything else unparsed. A row
     * that cannot be parsed, or more candidate rows than the bound, makes coverage incomplete.
     *
     *  - audit_events.context: asset and run ids count unless the event's subject is a row of the same family. Track ids
     *    are not read: an event about a track is not a sold or committed item, and the rows such events describe are
     *    checked where they live.
     *  - quotes, quote pricings, reservations and exclusive activations embed offer snapshots
     *    ({@see OfferSnapshot}): `product.id` is the track, `assets` and `preview` are assets.
     *  - site_releases.content: `related_track_ids` ({@see SiteRelatedTracks}).
     *  - product_draft_versions.manifest: members with `track_id` ({@see ProductDraftManifest}).
     *  - fulfillment_outbox.payload: schema 1 holds only public ids and an evidence hash
     *    ({@see FinalizeTestPayment}).
     *  - jobs and failed_jobs: Laravel's queued-command payload; see {@see self::JOBS}.
     */
    public const JSON_DEEP = [
        'audit_events.context' => 'assets',
        'exclusive_activations.snapshot' => 'ids',
        'failed_jobs.payload' => 'job',
        'fulfillment_outbox.payload' => 'outbox',
        'inventory_reservations.snapshot' => 'ids',
        'jobs.payload' => 'job',
        'media_processing_runs.evidence' => 'assets',
        'product_draft_versions.manifest' => 'ids',
        'quote_pricings.snapshot' => 'ids',
        'quotes.request' => 'ids',
        'quotes.snapshot' => 'ids',
        'site_releases.content' => 'ids',
        'stems_recordings.evidence' => 'assets',
    ];

    /**
     * Queued commands whose constructor arguments were reviewed: class => null (holds no media or track id) or
     * [property, media table]. Any other job class, or an encrypted or unreadable command, is unparsed.
     */
    public const JOBS = [
        BuildDiscoverySitemapWindow::class => null,
        DeliverProductionIdentityNotice::class => null,
        DeliverTransactionalMail::class => null,
        FinalizeTestPaymentJob::class => null,
        IssueTestContractJob::class => null,
        NotifyInquiryOperatorJob::class => null,
        ProcessMedia::class => ['runId', 'media_processing_runs'],
        ProcessSiteImage::class => null,
        ProcessSoundKit::class => null,
        ProcessStripeReceiptJob::class => null,
        RetrieveMembershipInvoice::class => null,
    ];

    /**
     * Digests of a manifest that may cover media, with no readable list beside them. Any non-null value makes coverage
     * incomplete (`hash_only_manifest:<table>.<column>`).
     */
    public const MANIFEST_DIGESTS = [
        'membership_plan_versions.manifest_hash',
        'production_free_definitions.asset_manifest_hash',
        'production_free_definitions.source_manifest_hash',
        'production_free_origins.asset_manifest_hash',
        'production_member_activations.artifact_manifest_hash',
        'production_member_definitions.asset_manifest_hash',
        'production_member_origins.artifact_manifest_hash',
        'production_member_origins.asset_manifest_hash',
        'production_membership_redemptions.asset_manifest_hash',
        'support_attachments.manifest_hash',
    ];

    /**
     * Payloads the proof cannot read: encrypted columns (P2-1, still open) and plaintext whose shape has not been reviewed.
     * Any non-empty value makes coverage incomplete (`opaque:<table>.<column>`).
     */
    public const OPAQUE = [
        // Encrypted payloads and evidence.
        'catalog_import_batches.review_ciphertext', 'catalog_import_mappings.evidence_ciphertext',
        'checkout_intents.request_ciphertext', 'checkout_observations.evidence_ciphertext',
        'checkout_sessions.evidence_ciphertext', 'customer_consent_events.recipient_ciphertext',
        'customer_data_exports.ciphertext', 'customer_purchase_claims.evidence_ciphertext',
        'customer_suppression_attempts.binding_ciphertext', 'customer_suppression_confirmations.receipt_ciphertext',
        'customer_suppression_targets.recipient_ciphertext', 'legacy_history_contract_declarations.record_ciphertext',
        'legacy_history_contracts.record_ciphertext', 'legacy_history_customers.record_ciphertext',
        'legacy_history_lines.record_ciphertext', 'legacy_history_orders.record_ciphertext',
        'license_grants.render_input_ciphertext', 'order_finalizations.evidence_ciphertext', 'orders.payload_ciphertext',
        'payment_observations.evidence_ciphertext', 'production_account_feature_bindings.binding_ciphertext',
        'production_buyer_assent_observations.payload_ciphertext', 'production_checkout_attempts.payload_ciphertext',
        'production_checkout_exemption_authorities.payload_ciphertext',
        'production_checkout_exemption_bases.payload_ciphertext', 'production_checkout_observations.payload_ciphertext',
        'production_checkout_order_lines.payload_ciphertext', 'production_checkout_orders.payload_ciphertext',
        'production_checkout_payments.payload_ciphertext', 'production_checkout_provider_intents.payload_ciphertext',
        'production_checkout_reviews.payload_ciphertext', 'production_checkout_sessions.payload_ciphertext',
        'production_consent_events.recipient_ciphertext', 'production_free_authorizations.payload_ciphertext',
        'production_free_availability.payload_ciphertext', 'production_free_definitions.payload_ciphertext',
        'production_free_document_work.payload_ciphertext', 'production_free_originals.payload_ciphertext',
        'production_free_origins.payload_ciphertext', 'production_free_redemptions.payload_ciphertext',
        'production_free_reviews.payload_ciphertext', 'production_free_revocations.payload_ciphertext',
        'production_identity_challenges.payload_ciphertext', 'production_member_activations.payload_ciphertext',
        'production_member_artifacts.payload_ciphertext', 'production_member_definitions.payload_ciphertext',
        'production_member_origins.payload_ciphertext', 'production_member_profiles.payload_ciphertext',
        'production_membership_billing_attempts.payload_ciphertext',
        'production_membership_billing_events.payload_ciphertext',
        'production_membership_billing_invoices.payload_ciphertext',
        'production_membership_billing_observations.payload_ciphertext',
        'production_membership_billing_subscriptions.payload_ciphertext',
        'production_membership_paid_periods.payload_ciphertext', 'production_membership_plan_versions.payload_ciphertext',
        'production_membership_redemptions.payload_ciphertext', 'production_suppression_attempts.provider_ciphertext',
        'production_suppression_confirmations.receipt_ciphertext', 'production_suppression_targets.recipient_ciphertext',
        'production_tax_checkout_bindings.payload_ciphertext', 'production_tax_checkout_orders.payload_ciphertext',
        'production_tax_checkout_requests.payload_ciphertext', 'production_tax_checkout_reviewed_sessions.payload_ciphertext',
        'production_track_capability_approvals.approval_ciphertext',
        'production_track_capability_candidates.payload_ciphertext',
        'production_track_capability_closures.closure_ciphertext', 'production_track_policy_source_reviews.review_ciphertext',
        'production_track_policy_versions.payload_ciphertext', 'production_track_preparation_packets.payload_ciphertext',
        'refund_dispute_observations.evidence_ciphertext', 'stripe_webhook_receipts.payload_ciphertext',
        'test_delivery_authorizations.evidence_ciphertext', 'test_delivery_redemptions.evidence_ciphertext',
        'test_fulfillment_activations.evidence_ciphertext', 'test_payment_financial_observations.evidence_ciphertext',
        'test_refund_resolutions.evidence_ciphertext', 'test_unpaid_releases.evidence_ciphertext',
        'transactional_notices.capture_ciphertext', 'verified_payments.evidence_ciphertext',
        // Plaintext or mixed payloads without a reviewed shape.
        'contract_render_requests.profile', 'customer_data_request_events.detail', 'customer_inquiries.payload',
        'customer_saved_tracks.payload', 'free_authorizations.payload', 'free_availability.payload', 'free_definitions.payload',
        'free_originals.payload', 'free_origins.payload', 'free_reviews.payload', 'inquiry_messages.body',
        'job_batches.options', 'legacy_history_batches.members', 'membership_plan_versions.policy',
        'merch_draft_versions.manifest', 'order_attempts.binding', 'paid_authorizations.payload', 'paid_fulfillments.payload',
        'paid_grant_origins.payload', 'paid_order_origins.payload', 'paid_originals.payload', 'paid_tax_authorizations.payload',
        'paid_tax_fulfillments.payload', 'paid_tax_grant_origins.payload', 'paid_tax_order_origins.payload',
        'paid_tax_originals.payload', 'production_listening_libraries.payload', 'promotion_campaigns.snapshot',
        'refund_dispute_effects.context', 'service_draft_versions.manifest', 'service_project_events.payload',
        'service_projects.brief', 'service_projects.service_manifest', 'support_attachments.manifest',
        'support_attachments.policy_binding', 'support_attachments.scan_evidence', 'support_attachments.source_binding',
    ];

    private const OWN = 'The media row\'s own lineage or metadata, not a reference to it.';

    private const TRACK_SETUP = 'Mutable track set-up, not a sold or committed item; its asset references are checked by id.';

    private const POLICY_CHAIN = 'A production track-policy record id; "track" is part of the table name.';

    private const CONSENT = 'A consent or suppression identifier, digest, label or notice text.';

    private const OWN_FILES = 'Kits, site images and drafts keep their own files and never name media_assets by id; shared bytes are found by the content check.';

    private const PROSE = 'Staff-authored text or settings with no ids.';

    /** Reviewed candidates that are not a reference: column => why. */
    public const REVIEWED = [
        'audit_events.subject' => 'An event about a track or about a family row is not a reference; ids in its context are read by json:audit_events.context.',
        'cache.value' => 'Framework cache: never authoritative.',
        'catalog_import_mappings.track_sha256' => 'Digest of the imported track attributes; the row counts through track_id.',
        'customer_consent_events.public_id' => self::CONSENT, 'customer_consent_events.purpose' => self::CONSENT,
        'customer_consent_events.recipient_hmac' => self::CONSENT, 'customer_consent_events.source' => self::CONSENT,
        'customer_consent_events.status' => self::CONSENT, 'customer_consent_policies.notice' => self::CONSENT,
        'customer_consent_policies.notice_hash' => self::CONSENT, 'customer_consent_policies.policy_hash' => self::CONSENT,
        'customer_consent_policies.purpose' => self::CONSENT, 'customer_consent_policies.review_reference' => self::CONSENT,
        'customer_consent_policies.version' => self::CONSENT, 'customer_consent_states.purpose' => self::CONSENT,
        'customer_identity_challenges.email' => 'An email address.',
        'customer_inquiries.privacy_notice' => self::CONSENT,
        'customer_suppression_attempts.binding_hash' => self::CONSENT, 'customer_suppression_attempts.public_id' => self::CONSENT,
        'customer_suppression_attempts.request_hash' => self::CONSENT, 'customer_suppression_confirmations.receipt_hash' => self::CONSENT,
        'customer_suppression_confirmations.request_hash' => self::CONSENT, 'customer_suppression_intents.public_id' => self::CONSENT,
        'customer_suppression_targets.public_id' => self::CONSENT, 'customer_suppression_targets.purpose' => self::CONSENT,
        'customer_suppression_targets.recipient_hmac' => self::CONSENT,
        'discovery_sitemap_current.certificate' => 'Derived from published tracks, which publication:track already keeps attached.',
        'discovery_sitemap_generations.header' => 'Derived from published tracks, which publication:track already keeps attached.',
        'discovery_sitemap_windows.body' => 'Derived from published tracks, which publication:track already keeps attached.',
        'failed_jobs.exception' => 'Exception text; paths and hashes in it are found by the content check.',
        'job_batches.failed_job_ids' => 'Queue job uuids.',
        'legacy_history_contracts.media_type' => 'A MIME type.',
        'license_versions.authored_source' => 'License text.', 'license_versions.content_author_ids' => 'Staff user ids.',
        'license_versions.structured_terms' => 'Typed license terms.',
        'license_versions.submission_payload' => 'The license version\'s own review payload (App\Domain\Rights\LicenseReviewPayload).',
        'media_assets.technical_metadata' => self::OWN, 'media_assets.track_id' => self::OWN,
        'media_processing_runs.profile' => self::OWN, 'media_upload_sessions.parts' => self::OWN,
        'media_upload_sessions.track_id' => self::OWN,
        'membership_credit_events.after_balance' => 'A credit balance.', 'membership_credit_events.before_balance' => 'A credit balance.',
        'merch_draft_versions.manifest_sha256' => 'Digest of the manifest column of the same row, which is classified itself.',
        'offers.track_id' => self::TRACK_SETUP,
        'pending_entitlements.asset_hash' => 'The asset\'s SHA-256, found by the content check; the row counts through media_asset_id.',
        'product_draft_versions.manifest_sha256' => 'Digest of the manifest column of the same row, which is classified itself.',
        'production_account_feature_bindings.binding_hash' => self::CONSENT, 'production_account_feature_bindings.feature' => self::CONSENT,
        'production_account_feature_bindings.feature_policy_version' => self::CONSENT,
        'production_account_feature_bindings.origin_public_id' => self::CONSENT, 'production_account_feature_bindings.public_id' => self::CONSENT,
        'production_buyer_assent_observations.production_track_preparation_packet_id' => self::POLICY_CHAIN,
        'production_consent_events.public_id' => self::CONSENT, 'production_consent_events.purpose' => self::CONSENT,
        'production_consent_events.recipient_hmac' => self::CONSENT, 'production_consent_events.source' => self::CONSENT,
        'production_consent_events.status' => self::CONSENT, 'production_consent_policies.notice' => self::CONSENT,
        'production_consent_policies.notice_hash' => self::CONSENT, 'production_consent_policies.policy_hash' => self::CONSENT,
        'production_consent_policies.purpose' => self::CONSENT, 'production_consent_policies.review_reference' => self::CONSENT,
        'production_consent_policies.version' => self::CONSENT, 'production_consent_states.purpose' => self::CONSENT,
        'production_free_definitions.asset_count' => 'A count of assets, not an id.',
        'production_member_definitions.license_manifest_hash' => 'Digest of license versions, not media.',
        'production_member_origins.license_manifest_hash' => 'Digest of license versions, not media.',
        'production_member_profiles.font_manifest_hash' => 'Digest of renderer fonts.',
        'production_membership_redemptions.license_manifest_hash' => 'Digest of license versions, not media.',
        'production_suppression_attempts.provider_hash' => self::CONSENT, 'production_suppression_attempts.public_id' => self::CONSENT,
        'production_suppression_attempts.request_hash' => self::CONSENT, 'production_suppression_confirmations.receipt_hash' => self::CONSENT,
        'production_suppression_confirmations.request_hash' => self::CONSENT, 'production_suppression_intents.public_id' => self::CONSENT,
        'production_suppression_targets.public_id' => self::CONSENT, 'production_suppression_targets.purpose' => self::CONSENT,
        'production_suppression_targets.recipient_hmac' => self::CONSENT,
        'production_track_capability_approvals.production_track_capability_candidate_id' => self::POLICY_CHAIN,
        'production_track_capability_candidates.production_track_policy_draft_id' => self::POLICY_CHAIN,
        'production_track_capability_candidates.production_track_policy_source_review_id' => self::POLICY_CHAIN,
        'production_track_capability_candidates.production_track_policy_version_id' => self::POLICY_CHAIN,
        'production_track_capability_closures.production_track_capability_candidate_id' => self::POLICY_CHAIN,
        'production_track_policy_source_reviews.production_track_policy_version_id' => self::POLICY_CHAIN,
        'production_track_policy_versions.production_track_policy_draft_id' => self::POLICY_CHAIN,
        'production_track_preparation_packet_lines.production_track_preparation_packet_id' => self::POLICY_CHAIN,
        'production_track_preparation_packets.production_track_capability_approval_id' => self::POLICY_CHAIN,
        'production_track_preparation_packets.production_track_capability_candidate_id' => self::POLICY_CHAIN,
        'production_track_preparation_packets.production_track_policy_draft_id' => self::POLICY_CHAIN,
        'rights_declarations.provenance_reference' => self::PROSE, 'rights_declarations.sample_disclosure' => self::PROSE,
        'rights_declarations.track_id' => self::TRACK_SETUP,
        'service_draft_versions.manifest_sha256' => 'Digest of the manifest column of the same row, which is classified itself.',
        'sessions.payload' => 'Encrypted browser session: never authoritative.', 'sessions.user_agent' => 'A user agent string.',
        'site_images.evidence' => self::OWN_FILES, 'site_images.manifest_sha256' => self::OWN_FILES,
        'sound_kit_drafts.description' => self::PROSE, 'sound_kit_revisions.description_snapshot' => self::PROSE,
        'sound_kit_revisions.evidence' => self::OWN_FILES, 'sound_kit_revisions.manifest' => self::OWN_FILES,
        'sound_kit_revisions.manifest_sha256' => self::OWN_FILES, 'sound_kit_revisions.profile' => self::OWN_FILES,
        'sound_kit_upload_sessions.parts' => self::OWN_FILES,
        'stems_recordings.track_id' => 'The binding row counts through its asset columns.',
        'stripe_webhook_receipts.event' => 'Stripe event id and type strings, not a model reference.',
        'stripe_webhook_receipts.object' => 'Stripe object id and type strings, not a model reference.',
        'track_metadata_presets.metadata' => self::PROSE,
        'tracks.description' => self::PROSE, 'tracks.tags' => self::PROSE, 'tracks.waveform' => 'Display peaks of the track.',
        'users.app_authentication_recovery_codes' => 'Encrypted TOTP recovery codes.',
        'users.app_authentication_secret' => 'Encrypted TOTP secret.',
        'vasey_license_contract_texts.payload' => 'Contract template and renderer profile text, addressed by its own SHA-256.',
    ];

    /**
     * @param  array<string, array<string, mixed>>  $extra  list name => entries merged over the constants; tests only
     */
    public function __construct(private readonly array $extra = []) {}

    /** @return array<string, string|null|array{0:string,1:string}> */
    public function entries(string $list): array
    {
        $base = match ($list) {
            'media_ids' => self::MEDIA_IDS,
            'track_ids' => array_fill_keys(self::TRACK_IDS, 'tracks'),
            'track_source_ids' => array_fill_keys(self::TRACK_SOURCE_IDS, 'tracks'),
            'polymorphic' => array_fill_keys(self::POLYMORPHIC, 'poly'),
            'json_structural' => self::JSON_STRUCTURAL,
            'json_deep' => self::JSON_DEEP,
            'manifest_digests' => array_fill_keys(self::MANIFEST_DIGESTS, 'digest'),
            'opaque' => array_fill_keys(self::OPAQUE, 'opaque'),
            'reviewed' => self::REVIEWED,
            'jobs' => self::JOBS,
        };

        return ($this->extra[$list] ?? []) + $base;
    }

    /** Every classified `<table>.<column>` (a polymorphic or reviewed pair is listed as `<table>.<prefix>`). */
    public function classified(): array
    {
        $all = [];
        foreach (['media_ids', 'track_ids', 'track_source_ids', 'polymorphic', 'json_structural', 'json_deep', 'manifest_digests', 'opaque', 'reviewed'] as $list) {
            foreach (array_keys($this->entries($list)) as $column) {
                $all[$column][] = $list;
            }
        }

        return $all;
    }

    /**
     * Candidate columns of a schema that are in no list.
     *
     * @param  array<string, array<string, string>>  $tables  table => column => lower-case type name
     * @param  array<string, list<string>>  $foreign  table => columns foreign-keyed to tracks or a media table
     * @return list<string>
     */
    public function unregistered(array $tables, array $foreign): array
    {
        $classified = $this->classified();
        $missing = [];
        foreach ($tables as $table => $columns) {
            foreach ($columns as $column => $type) {
                $qualified = $table.'.'.$column;
                $pair = null;
                foreach (['_type', '_id'] as $suffix) {
                    if (str_ends_with($column, $suffix)) {
                        $prefix = substr($column, 0, -strlen($suffix));
                        if (isset($columns[$prefix.'_type'], $columns[$prefix.'_id'])) {
                            $pair = $table.'.'.$prefix;
                        }
                    }
                }
                if ($pair !== null) {
                    if (! isset($classified[$pair])) {
                        $missing[$pair] = true;
                    }

                    continue;
                }
                $candidate = in_array($column, $foreign[$table] ?? [], true) || in_array($type, self::LONG_TEXT_TYPES, true)
                    || preg_match(self::NAME_PATTERN, $column) === 1;
                if ($candidate && ! isset($classified[$qualified])) {
                    $missing[$qualified] = true;
                }
            }
        }
        $missing = array_keys($missing);
        sort($missing);

        return $missing;
    }
}
