<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1;
use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use Throwable;

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

    public const PRODUCTION_VERSION = 'production-tax-refund-dispute-lifecycle-v1';

    /**
     * The only values the production tax chain accepts: DD-16 (docs/architecture/decision-register.md, 2026-11-10), as TERMS
     * appendix A.4 reads the approved section 10. The chain reads the values from the order's retained, validated machine
     * policy and refuses unless each equals the confirmed one, so a host cannot enter an unconfirmed refund term. They are
     * a subset of SUPPORTED (the machine policy also admits `operator_review` and `remain_sold`, which stay test-chain only).
     */
    public const PRODUCTION_CONFIRMED = ['refund_authority' => 'authoritative_provider_operator_review', 'partial_refunds' => 'exact_line_allocation',
        'dispute_access' => 'temporarily_block_while_reconciling', 'exclusive_reopening' => 'qualified_operator_review'];

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

    /**
     * The production tax chain's policy snapshot, from the order's retained machine policy. Admission is the existing default-off
     * flag plus the literal `local`/`testing` environment (never staging or production) and the own Stripe test account; the
     * machine policy must still validate and its `refunds_and_disputes` must equal DD-16 exactly. Nothing here reads
     * `refund-dispute.policy`, which belongs to the test chain.
     *
     * @param  array  $machine  the machine policy retained in the paid order (never a request value)
     */
    public function productionTax(array $machine): array
    {
        $this->productionAccount();
        $configured = $machine['choices']['refunds_and_disputes'] ?? null;
        RefundDisputeException::require(is_array($configured), 'policy_unset');
        $values = [];
        foreach (self::PRODUCTION_CONFIRMED as $key => $confirmed) {
            $value = $configured[$key] ?? null;
            RefundDisputeException::require(is_string($value) && $value !== '', 'policy_unset');
            RefundDisputeException::require($value === $confirmed, 'policy_unknown');
            $values[$key] = $value;
        }
        RefundDisputeException::require(array_diff(array_keys($configured), array_keys(self::PRODUCTION_CONFIRMED)) === [], 'policy_unknown');
        try {
            MachinePolicyV1::validate($machine);
        } catch (Throwable) {
            throw new RefundDisputeException('unavailable');
        }

        return ['schema_version' => 1, 'purpose' => 'production_tax_refund_dispute_lifecycle', 'version' => self::PRODUCTION_VERSION,
            'values' => $values, 'boundary' => self::BOUNDARY + ['chain' => RefundDisputeSource::PRODUCTION_TAX, 'machine_hash' => CanonicalJson::hash($machine)]];
    }

    /** Own-account admission for the production tax chain: the test admission, narrowed to literal `local` and `testing`. */
    public function productionAccount(): string
    {
        $account = $this->account();
        RefundDisputeException::require(config('refund-dispute.enabled') === true && in_array(app()->environment(), ['local', 'testing'], true), 'disabled');

        return $account;
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
