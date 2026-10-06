<?php

namespace Tests\Support;

use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use Illuminate\Support\Facades\DB;

/** Synthetic read-only provider facts. Never reaches Stripe or configures real credentials. */
final class PaymentFinancialFixtures
{
    public static function source(array $payment, string $account = CheckoutFixtures::ACCOUNT): array
    {
        $charge = ['id' => $payment['latest_charge'], 'object' => 'charge', 'payment_intent' => $payment['id'],
            'livemode' => false, 'currency' => 'usd', 'amount' => $payment['amount'], 'amount_captured' => $payment['amount'],
            'amount_refunded' => 0, 'refunded' => false, 'paid' => true, 'captured' => true, 'status' => 'succeeded',
            'disputed' => false, 'billing_details' => ['email' => 'private-financial@example.test']];

        return ['account_id' => $account, 'payment' => $payment, 'charge_before' => $charge, 'charge_after' => $charge,
            'refunds' => ['object' => 'list', 'has_more' => false, 'data' => []],
            'disputes' => ['object' => 'list', 'has_more' => false, 'data' => []]];
    }

    public static function item(string $kind, string $status, int $amount): array
    {
        return ['id' => $kind === 'refund' ? 're_SYNTHETIC' : 'du_SYNTHETIC', 'object' => $kind,
            'payment_intent' => PaymentFixtures::PAYMENT, 'charge' => 'ch_SYNTHETICCHARGE', 'currency' => 'usd',
            'amount' => $amount, 'status' => $status, 'metadata' => ['private' => 'private-financial@example.test']]
            + ($kind === 'dispute' ? ['livemode' => false, 'evidence' => ['customer_email_address' => 'private-financial@example.test']] : []);
    }

    public static function gateway(object $payments): StripeFinancialInspectionGateway
    {
        return new class($payments) implements StripeFinancialInspectionGateway
        {
            public array $calls = [];

            public mixed $onInspect = null;

            public function __construct(private object $payments) {}

            public function financialState(string $paymentIntentId): array
            {
                $this->calls[] = ['operation' => 'financial_state', 'id' => $paymentIntentId, 'transaction_level' => DB::transactionLevel()];
                $source = PaymentFinancialFixtures::source($this->payments->payment);

                return $this->onInspect === null ? $source : ($this->onInspect)($source);
            }
        };
    }
}
