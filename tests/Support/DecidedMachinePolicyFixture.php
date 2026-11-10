<?php

namespace Tests\Support;

/**
 * The machine-policy choices that decisions DD-11, DD-16, DD-17 and DD-42 and the approved TERMS
 * appendix A.8 already settle, assembled into a MachinePolicyV1 candidate.
 *
 * Decided values are fixed here and nowhere in application code. Everything the decisions leave to
 * the host stays synthetic: the provider account id, the return origin, the prices (none), the
 * hashes and the software-only choices. This is test input, not a production policy or an approval.
 */
final class DecidedMachinePolicyFixture
{
    /** Register field => value for the rows the register states as `field = value`. */
    public const REGISTER_VALUES = [
        'DD-16' => [
            'refund_authority' => 'authoritative_provider_operator_review',
            'partial_refunds' => 'exact_line_allocation',
            'dispute_access' => 'temporarily_block_while_reconciling',
            'exclusive_reopening' => 'qualified_operator_review',
        ],
        'DD-17' => [
            'reservation_seconds' => 1800,
            'provider_lifetime_seconds' => 1800,
            'pending_cutoff' => 'block_new_nonexclusive_on_pending_exclusive',
            'late_action' => 'paid_exception_operator_reconciliation',
            'late_time_basis' => 'authoritative_provider_payment_time',
        ],
        'DD-42' => [
            'buyer_identity.mode' => 'verified_account',
        ],
    ];

    /** Approved by Sean on 2026-11-07, copied from docs/legal/TERMS.md appendix A.8. */
    public const ASSENT_TEXT = "I am buying the license(s) listed above from Vasey Multimedia dba VASEY.AUDIO, licensed by Sean Vasey. I have read and agree to each license's full terms and to the Terms of Sale and Use. I understand delivery starts immediately after payment, and that once I download a file I lose any right to cancel for a change of mind.";

    public const SELLER_LEGAL_NAME = 'Vasey Multimedia dba VASEY.AUDIO';

    public const CURRENCY = ['code' => 'USD', 'minor_unit_exponent' => 2];

    /**
     * @param  array|null  $source  authored policy draft whose declaration hashes the candidate commits to
     */
    public static function policy(?array $source = null): array
    {
        $policy = ProductionTrackCapabilitiesFixtures::machine($source);
        $d17 = self::REGISTER_VALUES['DD-17'];

        $policy['version'] = 'synthetic-decided-v1';
        $policy['choices']['seller_identity']['legal_name'] = self::SELLER_LEGAL_NAME;
        $policy['choices']['assent'] = ['version' => 'synthetic-assent-v1', 'text' => self::ASSENT_TEXT];
        $policy['choices']['currency'] = self::CURRENCY;
        $policy['choices']['buyer_identity']['mode'] = self::REGISTER_VALUES['DD-42']['buyer_identity.mode'];
        $policy['choices']['recovery']['mode'] = 'verified_account_recovery';
        $policy['choices']['refunds_and_disputes'] = self::REGISTER_VALUES['DD-16'];
        foreach (['reservation_seconds', 'provider_lifetime_seconds', 'pending_cutoff', 'late_action', 'late_time_basis'] as $field) {
            $policy['choices']['reservation_and_exclusives'][$field] = $d17[$field];
        }

        return $policy;
    }
}
