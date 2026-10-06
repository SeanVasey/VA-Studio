<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1;

/** Every value is synthetic test input, never a selected merchant policy or external approval. */
final class ProductionTrackCapabilitiesFixtures
{
    public static function machine(?array $source = null): array
    {
        $source ??= ProductionTrackPolicyFixtures::authored();

        return [
            'schema_version' => 1, 'purpose' => MachinePolicyV1::PURPOSE, 'version' => 'synthetic-machine-v1',
            'source_commitments' => array_map(fn (array $declaration): mixed => $declaration['source_sha256'], $source['declarations']),
            'choices' => [
                'seller_identity' => ['legal_name' => 'NONBINDING SYNTHETIC SELLER'],
                'provider_account' => ['provider' => 'stripe', 'account_id' => 'acct_SYNTHETIC', 'mode' => 'live',
                    'api_version' => '2026-08-26.dahlia', 'capture_method' => 'automatic', 'return_origin' => 'https://review.invalid'],
                'currency' => ['code' => 'USD', 'minor_unit_exponent' => 2],
                'tax_calculation' => ['strategy' => 'provider_calculated', 'behavior' => 'exclusive', 'maximum_rate_bps' => 2500, 'rounding' => 'provider_exact'],
                'assent' => ['version' => 'synthetic-assent-v1', 'text' => "NONBINDING SYNTHETIC ASSENT\nExplicit review fixture only."],
                'license_terms' => ['authority' => 'immutable_published_license_version', 'disclosure' => 'exact_purchased_revision',
                    'publication_required' => true, 'prior_grants' => 'retain'],
                'buyer_identity' => ['mode' => 'verified_account', 'order_binding' => 'explicit_verified_purchase_binding'],
                'recovery' => ['mode' => 'verified_account_recovery', 'channel' => 'transactional_mail', 'purchase_implies_marketing' => false],
                'reservation_and_exclusives' => ['reservation_seconds' => 1800, 'provider_lifetime_seconds' => 1800, 'retry_seconds' => 300,
                    'pending_cutoff' => 'block_new_nonexclusive_on_pending_exclusive', 'late_time_basis' => 'authoritative_provider_payment_time',
                    'late_action' => 'paid_exception_operator_reconciliation'],
                'refunds_and_disputes' => ['refund_authority' => 'authoritative_provider_operator_review', 'partial_refunds' => 'exact_line_allocation',
                    'dispute_access' => 'operator_review', 'exclusive_reopening' => 'remain_sold'],
                'original_documents' => ['renderer_profile' => 'synthetic-renderer-v1', 'purpose' => 'production_original_contract',
                    'preservation' => 'retain_original_never_substitute_regeneration'],
                'storage' => ['adapter' => 'private_posix', 'adapter_version' => 'synthetic-posix-v1', 'boundary_id' => 'synthetic-boundary-v1'],
                'delivery' => ['transfer' => 'private_posix_single_attempt', 'authorization_seconds' => 60, 'budget_count' => 3,
                    'budget_window_seconds' => 3600, 'maximum_bytes' => 1048576],
                'privacy' => ['retention_policy_id' => 'synthetic-retention-v1', 'deletion_policy_id' => 'synthetic-deletion-v1',
                    'marketing_consent' => 'explicit_purpose_only', 'unknown_consent' => 'remain_unknown'],
            ],
        ];
    }

    public static function reference(): array
    {
        return ['reference' => 'synthetic:software-choice-review', 'source_sha256' => hash('sha256', 'NONBINDING SOFTWARE CHOICE REVIEW')];
    }
}
