<?php

namespace App\Domain\Memberships\Billing;

/**
 * The approved binding a retrieval is compared against: own account, mode, customer, subscription,
 * price, currency and amount. It comes only from a sealed subscription binding row, never from HTTP,
 * an event payload or an invoice. No typed exception (discount, proration, tax, credit balance, FX,
 * out-of-band, zero amount) is approved, so each of those shapes is refused.
 */
final readonly class BillingExpectation
{
    public function __construct(
        public string $accountRef,
        public string $mode,
        public string $customerRef,
        public string $subscriptionRef,
        public string $priceRef,
        public string $currency,
        public int $amountMinor,
    ) {
        BillingException::require(BillingValues::is('account', $accountRef) && $mode === 'test'
            && BillingValues::is('customer', $customerRef) && BillingValues::is('subscription', $subscriptionRef)
            && BillingValues::is('price', $priceRef) && preg_match('/\A[A-Z]{3}\z/D', $currency) === 1
            && $amountMinor > 0 && $amountMinor <= 100000000, 'invalid_expectation');
    }
}
