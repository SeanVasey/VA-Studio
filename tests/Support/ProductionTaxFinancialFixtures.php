<?php

namespace Tests\Support;

use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use Illuminate\Support\Facades\DB;

/**
 * Programmable synthetic Stripe read facts for a production tax order's PaymentIntent (BL-42). Nothing here reaches Stripe, holds a
 * credential or writes a refund: it implements only the read-only inspection interface, and each test sets the complete refund and
 * dispute lists the "provider" reports. Items are [id, amount, status] with an optional currency.
 */
final class ProductionTaxFinancialFixtures
{
    public const CHARGE = 'ch_SYNTHETICPRODUCTIONCHARGE';

    public static function gateway(string $account, string $paymentId, int $gross): object
    {
        return new class($account, $paymentId, $gross) implements StripeFinancialInspectionGateway
        {
            public array $calls = [];

            public array $refunds = [];

            public array $disputes = [];

            public array $chargeOverrides = [];

            /** @var ?callable(array): array */
            public $onInspect = null;

            public function __construct(public string $account, public string $paymentId, public int $gross) {}

            public function financialState(string $paymentIntentId): array
            {
                $this->calls[] = ['id' => $paymentIntentId, 'transaction_level' => DB::transactionLevel()];

                return ProductionTaxFinancialFixtures::source($this->account, $paymentIntentId, $this->gross, $this->refunds, $this->disputes,
                    $this->chargeOverrides, $this->onInspect);
            }
        };
    }

    public static function source(string $account, string $paymentId, int $gross, array $refunds, array $disputes, array $chargeOverrides = [], ?callable $onInspect = null): array
    {
        $payment = ['id' => $paymentId, 'object' => 'payment_intent', 'livemode' => false, 'currency' => 'usd', 'amount' => $gross,
            'amount_received' => $gross, 'amount_capturable' => 0, 'status' => 'succeeded', 'latest_charge' => self::CHARGE];
        $item = fn (string $kind, array $spec): array => ['id' => $spec[0], 'object' => $kind, 'payment_intent' => $paymentId, 'charge' => self::CHARGE,
            'currency' => $spec[3] ?? 'usd', 'amount' => $spec[1], 'status' => $spec[2], 'metadata' => ['private' => 'private-financial@example.test']]
            + ($kind === 'dispute' ? ['livemode' => false, 'evidence' => ['customer_email_address' => 'private-financial@example.test']] : []);
        $refunded = min($gross, array_sum(array_map(fn (array $spec): int => in_array($spec[2], ['pending', 'requires_action', 'succeeded'], true) ? $spec[1] : 0, $refunds)));
        $charge = array_replace(['id' => self::CHARGE, 'object' => 'charge', 'payment_intent' => $paymentId, 'livemode' => false, 'currency' => 'usd',
            'amount' => $gross, 'amount_captured' => $gross, 'amount_refunded' => $refunds === [] ? 0 : $refunded, 'refunded' => $refunds !== [] && $refunded === $gross,
            'paid' => true, 'captured' => true, 'status' => 'succeeded', 'disputed' => $disputes !== [], 'billing_details' => ['email' => 'private-financial@example.test']],
            $chargeOverrides);
        $source = ['account_id' => $account, 'payment' => $payment, 'charge_before' => $charge, 'charge_after' => $charge,
            'refunds' => ['object' => 'list', 'has_more' => false, 'data' => array_map(fn (array $spec): array => $item('refund', $spec), $refunds)],
            'disputes' => ['object' => 'list', 'has_more' => false, 'data' => array_map(fn (array $spec): array => $item('dispute', $spec), $disputes)]];

        return $onInspect === null ? $source : $onInspect($source);
    }
}
