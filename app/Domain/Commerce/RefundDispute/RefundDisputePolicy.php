<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;

/**
 * Configured refund/dispute policy for the Stripe-test chain. There are no defaults: Sean has not approved a
 * refund or dispute policy, so every write refuses while any value is unset, empty or unknown. The supported
 * values are exactly MachinePolicyV1's `refunds_and_disputes` choices; nothing here encodes the unapproved
 * proposal in docs/legal/TERMS.md.
 */
final class RefundDisputePolicy
{
    public const VERSION = 'test-refund-dispute-lifecycle-v1';

    public const SUPPORTED = [
        'refund_authority' => ['authoritative_provider_operator_review'],
        'partial_refunds' => ['exact_line_allocation'],
        'dispute_access' => ['operator_review', 'temporarily_block_while_reconciling'],
        'exclusive_reopening' => ['remain_sold', 'qualified_operator_review'],
    ];

    /** Fixed boundary recorded with every policy snapshot; none of it is configurable. */
    public const BOUNDARY = ['provider' => 'stripe', 'mode' => 'test', 'provider_writes' => 'forbidden',
        'license_revocation' => 'never', 'originals' => 'preserved', 'inventory_restock' => 'never',
        'catalog_reopening' => 'not_performed_u08'];

    /** The complete admitted policy snapshot, or a bounded refusal. */
    public function current(): array
    {
        $this->account();
        RefundDisputeException::require(config('refund-dispute.enabled') === true, 'disabled');
        $configured = config('refund-dispute.policy');
        RefundDisputeException::require(is_array($configured), 'policy_unset');
        $values = [];
        foreach (self::SUPPORTED as $key => $allowed) {
            $value = $configured[$key] ?? null;
            RefundDisputeException::require(is_string($value) && $value !== '', 'policy_unset');
            RefundDisputeException::require(in_array($value, $allowed, true), 'policy_unknown');
            $values[$key] = $value;
        }
        RefundDisputeException::require(array_diff(array_keys($configured), array_keys(self::SUPPORTED)) === [], 'policy_unknown');

        return ['schema_version' => 1, 'purpose' => 'test_refund_dispute_lifecycle', 'version' => self::VERSION,
            'values' => $values, 'boundary' => self::BOUNDARY];
    }

    public static function hash(array $policy): string
    {
        return CanonicalJson::hash($policy);
    }

    /** Environment and own-account admission, shared by reads and writes. */
    public function account(): string
    {
        $account = config('payments.stripe.account_id');
        RefundDisputeException::require(TestEnvironment::admitsTestCommerce() && config('payments.stripe.mode') === 'test'
            && is_string($account) && preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) === 1, 'disabled');

        return $account;
    }
}
