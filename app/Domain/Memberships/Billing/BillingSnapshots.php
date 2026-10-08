<?php

namespace App\Domain\Memberships\Billing;

/**
 * One bounded retrieval of a provider invoice graph: own account, invoice with its complete line list,
 * its complete InvoicePayment list, and the PaymentIntent, Charge, BalanceTransaction and Subscription
 * reached from them. Each entry is an allowlisted projection; secrets and customer details are dropped.
 */
final readonly class BillingSnapshots
{
    /**
     * @param  list<array>  $lines
     * @param  list<array>  $payments
     * @param  array<string, array>  $paymentIntents
     * @param  array<string, array>  $charges
     * @param  array<string, array>  $balanceTransactions
     */
    public function __construct(
        public string $requestedInvoiceRef,
        public array $account,
        public array $invoice,
        public array $lines,
        public array $payments,
        public array $paymentIntents,
        public array $charges,
        public array $balanceTransactions,
        public ?array $subscription,
        public int $retrievedAt,
        public string $provenance,
    ) {
        BillingException::require(BillingValues::is('invoice', $requestedInvoiceRef) && array_is_list($lines) && array_is_list($payments)
            && count($lines) <= StripeSdkBillingGateway::MAX_LINES && count($payments) <= StripeSdkBillingGateway::MAX_PAYMENTS
            && $retrievedAt > 0 && in_array($provenance, ['synthetic_rehearsal', 'verified_production'], true), 'invalid_snapshot');
    }
}
