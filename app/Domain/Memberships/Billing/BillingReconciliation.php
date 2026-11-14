<?php

namespace App\Domain\Memberships\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Retrieves one invoice graph outside any database transaction, evaluates it, then appends exactly
 * one observation in a short transaction. Once the binding owns the invoice identity, a timeout or
 * ambiguous provider response appends `unknown` under it, so a retry continues the same chain. A FIRST
 * retrieval that ends unknown, incomplete or unbound retains a separate non-identity attempt before throwing:
 * the immutable identity is claimed only after a provider graph validated the binding. Attempt diagnostics
 * never pin an invoice or cover a hint. Nothing is reversed, awarded or written to the provider.
 */
final class BillingReconciliation
{
    private const UNKNOWN = ['provider_unavailable', 'provider_inconsistent'];

    /** Verdict reasons meaning the provider's account, customer or subscription contradicts the binding. */
    private const BINDING_REFUSALS = ['account', 'customer', 'subscription'];

    public function __construct(private readonly BillingProviderGateway $gateway, private readonly BillingLedger $ledger = new BillingLedger) {}

    public function retrieve(string $bindingId, string $invoiceRef, ?string $hintEventId = null): array
    {
        BillingException::require(BillingValues::is('invoice', $invoiceRef), 'invalid_value');
        $policy = new BillingPolicy;
        $configuration = $policy->current();
        foreach (DB::getConnections() as $connection) {
            BillingException::require($connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction(), 'transaction_open');
        }
        $provenance = $this->gateway->provenance();
        BillingException::require($provenance === $configuration['provenance'], 'provenance');
        $binding = $this->ledger->binding($bindingId, $configuration);
        // An identity this binding already owns is reused; a new one is claimed only after the verdict (see below).
        $invoice = $this->ledger->existingInvoice($binding, $invoiceRef);
        // Anonymous committed endpoints bracket all provider I/O. Only their native end
        // supplies retrieval time; the provider's paid_at remains an independent fact.
        $start = $this->ledger->startRetrieval();
        $validated = false;
        $graph = null;
        try {
            $graph = $this->graph($invoiceRef);
        } catch (BillingException $error) {
            if ($error->reason === 'provider_incomplete') {
                $verdict = new BillingVerdict('refused', 'provider_incomplete', ['invoice_ref' => $invoiceRef]);
            } elseif (in_array($error->reason, self::UNKNOWN, true)) {
                $verdict = BillingVerdict::unknown($error->reason, ['invoice_ref' => $invoiceRef]);
            } else {
                throw $error;
            }
        }
        $interval = $this->ledger->endRetrieval($start);
        if ($graph !== null) {
            $snapshots = new BillingSnapshots($invoiceRef, ...$graph,
                retrievedAt: BillingDatabaseInstant::fromNativeValue($interval->databaseAt, 0)->seconds(), provenance: $provenance);
            $verdict = BillingSettlement::evaluate($snapshots, $binding['expectation']);
            $validated = BillingSettlement::bindingValidated($snapshots, $binding['expectation']);
        }
        // Configuration withdrawn during provider I/O records nothing.
        $policy->proveConfiguration($configuration);
        // The invoice-identity row is immutable and its unique hash can never move to another binding, so it is claimed only
        // after a retrieved provider graph has validated the binding (reviews R-3 and Codex unknown-outcomes). On a first
        // retrieval, which has no identity yet, only a non-identity attempt may be retained without such a graph:
        //  - a retrieved account, customer or parent subscription that contradicts the binding proves nothing about ownership;
        //  - an unknown (timeout, ambiguous response) or provider-incomplete outcome retrieved no validated graph at all.
        // Those retain a diagnostic attempt then throw, leaving no identity or observation row. The attempt does not cover a
        // hint, so redelivery or a sweep still dispatches the retrieval. Once the binding owns the identity, the same outcomes
        // are appended as observations exactly as before.
        if ($invoice === null) {
            // Settlement refuses account, invoice identity and mode before it looks at the customer and subscription, so those refusals
            // can precede validation. Whatever refusal comes first, a first retrieval claims the identity only once the retrieved
            // account, invoice, customer and parent subscription all match the binding (Addendum 1, A1-1). A refusal for a
            // reason after that point (currency, amount, shape, ...) is about an invoice this binding owns and is recorded.
            $refusal = match (true) {
                $verdict->outcome === 'unknown' => (string) $verdict->reason,
                $verdict->outcome === 'refused' && $verdict->reason === 'provider_incomplete' => 'provider_incomplete',
                $verdict->outcome === 'refused' && (in_array($verdict->reason, self::BINDING_REFUSALS, true) || ! $validated) => 'binding_refused_'.$verdict->reason,
                default => null,
            };
            if ($refusal !== null) {
                (new BillingRetrievalAttempts)->record($binding, $invoiceRef, $refusal, $interval, $hintEventId, $configuration);
                throw new BillingException($refusal);
            }
            $invoice = $this->ledger->invoiceForRetrieval($binding, $invoiceRef, $interval);
        }

        return $this->ledger->append($invoice, $verdict, $provenance, $interval);
    }

    /**
     * Whether a stored observation read a usable provider state. `unknown` (a timeout or ambiguous response) and a `provider_incomplete`
     * refusal (an unbounded list, so nothing was read) did not, and a job retries them.
     */
    public static function isInconclusive(array $observation): bool
    {
        if ($observation['outcome'] === 'unknown') {
            return true;
        }

        return $observation['outcome'] === 'refused'
            && (BillingValues::decrypt($observation['payload_ciphertext'])['reason'] ?? null) === 'provider_incomplete';
    }

    private function provider(\Closure $read): array
    {
        foreach (DB::getConnections() as $connection) {
            BillingException::require($connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction(), 'transaction_open');
        }

        return $read();
    }

    private function graph(string $invoiceRef): array
    {
        $account = $this->provider(fn () => $this->gateway->account());
        $invoice = $this->provider(fn () => $this->gateway->retrieveInvoice($invoiceRef));
        $payments = $this->provider(fn () => $this->gateway->listInvoicePayments($invoiceRef));
        $intents = $charges = $transactions = [];
        foreach ($payments as $payment) {
            $intentRef = $payment['payment']['type'] === 'payment_intent' ? $payment['payment']['payment_intent'] : null;
            if (! BillingValues::is('payment_intent', $intentRef) || isset($intents[$intentRef])) {
                continue;
            }
            $intents[$intentRef] = $this->provider(fn () => $this->gateway->retrievePaymentIntent($intentRef));
            $chargeRef = $intents[$intentRef]['latest_charge'];
            if (BillingValues::is('charge', $chargeRef) && ! isset($charges[$chargeRef])) {
                $charges[$chargeRef] = $this->provider(fn () => $this->gateway->retrieveCharge($chargeRef));
                $transactionRef = $charges[$chargeRef]['balance_transaction'];
                if (BillingValues::is('balance_transaction', $transactionRef) && ! isset($transactions[$transactionRef])) {
                    $transactions[$transactionRef] = $this->provider(fn () => $this->gateway->retrieveBalanceTransaction($transactionRef));
                }
            }
        }
        $subscriptionRef = $invoice['parent']['subscription_details']['subscription'] ?? null;
        $subscription = BillingValues::is('subscription', $subscriptionRef) ? $this->provider(fn () => $this->gateway->retrieveSubscription($subscriptionRef)) : null;

        return [$account, $invoice, $invoice['lines']['data'], $payments, $intents, $charges, $transactions, $subscription];
    }
}
