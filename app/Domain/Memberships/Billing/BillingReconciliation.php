<?php

namespace App\Domain\Memberships\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Retrieves one invoice graph outside any database transaction, evaluates it, then appends exactly
 * one observation in a short transaction. A timeout or ambiguous provider response appends `unknown`
 * under the same invoice identity, so a retry continues the same chain. Nothing is reversed, awarded
 * or written to the provider.
 */
final class BillingReconciliation
{
    private const UNKNOWN = ['provider_unavailable', 'provider_inconsistent'];

    /** Verdict reasons meaning the provider's account, customer or subscription contradicts the binding. */
    private const BINDING_REFUSALS = ['account', 'customer', 'subscription'];

    public function __construct(private readonly BillingProviderGateway $gateway, private readonly BillingLedger $ledger = new BillingLedger) {}

    public function retrieve(string $bindingId, string $invoiceRef): array
    {
        BillingException::require(BillingValues::is('invoice', $invoiceRef), 'invalid_value');
        $policy = new BillingPolicy;
        $configuration = $policy->current();
        foreach (DB::getConnections() as $connection) {
            BillingException::require($connection->transactionLevel() === 0, 'transaction_open');
        }
        $provenance = $this->gateway->provenance();
        BillingException::require($provenance === $configuration['provenance'], 'provenance');
        $binding = $this->ledger->binding($bindingId, $configuration);
        // An identity this binding already owns is reused; a new one is claimed only after the verdict (see below).
        $invoice = $this->ledger->existingInvoice($binding, $invoiceRef);
        $attemptedAt = CarbonImmutable::now('UTC')->timestamp;
        try {
            $snapshots = $this->snapshots($invoiceRef, $provenance);
            $verdict = BillingSettlement::evaluate($snapshots, $binding['expectation']);
            $retrievedAt = $snapshots->retrievedAt;
        } catch (BillingException $error) {
            if ($error->reason === 'provider_incomplete') {
                $verdict = new BillingVerdict('refused', 'provider_incomplete', ['invoice_ref' => $invoiceRef]);
            } elseif (in_array($error->reason, self::UNKNOWN, true)) {
                $verdict = BillingVerdict::unknown($error->reason, ['invoice_ref' => $invoiceRef]);
            } else {
                throw $error;
            }
            $retrievedAt = $attemptedAt;
        }
        // Configuration withdrawn during provider I/O records nothing.
        $policy->proveConfiguration($configuration);
        // The invoice-identity row is immutable and its unique hash can never move to another binding. A retrieved account,
        // customer or parent subscription that contradicts this binding proves nothing about ownership, so it claims no
        // identity (and, with no identity, has no observation chain to hold a refusal): it fails closed instead (review R-3).
        // Unknown and provider-incomplete outcomes carry no retrieved graph to validate, so they still claim the identity.
        if ($invoice === null) {
            BillingException::require(! ($verdict->outcome === 'refused' && in_array($verdict->reason, self::BINDING_REFUSALS, true)),
                'binding_refused_'.$verdict->reason);
            $invoice = $this->ledger->invoice($binding, $invoiceRef);
        }

        return $this->ledger->append($invoice, $verdict, $retrievedAt, $provenance);
    }

    private function snapshots(string $invoiceRef, string $provenance): BillingSnapshots
    {
        $account = $this->gateway->account();
        $invoice = $this->gateway->retrieveInvoice($invoiceRef);
        $payments = $this->gateway->listInvoicePayments($invoiceRef);
        $intents = $charges = $transactions = [];
        foreach ($payments as $payment) {
            $intentRef = $payment['payment']['type'] === 'payment_intent' ? $payment['payment']['payment_intent'] : null;
            if (! BillingValues::is('payment_intent', $intentRef) || isset($intents[$intentRef])) {
                continue;
            }
            $intents[$intentRef] = $this->gateway->retrievePaymentIntent($intentRef);
            $chargeRef = $intents[$intentRef]['latest_charge'];
            if (BillingValues::is('charge', $chargeRef) && ! isset($charges[$chargeRef])) {
                $charges[$chargeRef] = $this->gateway->retrieveCharge($chargeRef);
                $transactionRef = $charges[$chargeRef]['balance_transaction'];
                if (BillingValues::is('balance_transaction', $transactionRef) && ! isset($transactions[$transactionRef])) {
                    $transactions[$transactionRef] = $this->gateway->retrieveBalanceTransaction($transactionRef);
                }
            }
        }
        $subscriptionRef = $invoice['parent']['subscription_details']['subscription'] ?? null;
        $subscription = BillingValues::is('subscription', $subscriptionRef) ? $this->gateway->retrieveSubscription($subscriptionRef) : null;

        return new BillingSnapshots($invoiceRef, $account, $invoice, $invoice['lines']['data'], $payments, $intents, $charges, $transactions,
            $subscription, CarbonImmutable::now('UTC')->timestamp, $provenance);
    }
}
