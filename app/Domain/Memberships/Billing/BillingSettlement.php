<?php

namespace App\Domain\Memberships\Billing;

/**
 * Pure evaluator from one bounded retrieval to a named outcome. No I/O, clock, config or container.
 *
 * Settled requires: own account and mode on every object; the approved customer and subscription;
 * invoice status paid with exactly one non-proration subscription line of the approved price, whose
 * line period is the service period; invoice total, amount due and amount paid equal the approved
 * amount in the approved currency with no discount, tax, pre-tax credit, credit note, credit balance
 * or off-Stripe payment; exactly one InvoicePayment, paid, of type payment_intent; a succeeded
 * PaymentIntent; its latest Charge succeeded, paid, captured, unrefunded and undisputed; and that
 * Charge's balance transaction available in the same currency without FX.
 *
 * Outcomes: `refused` (facts conflict with the binding or use an unapproved shape), `reversed`
 * (a once-paid charge is refunded or disputed; recorded, never auto-reversed), `not_settled`
 * (a factual open/pending state). Unknown is produced by the caller when retrieval fails.
 */
final class BillingSettlement
{
    public static function evaluate(BillingSnapshots $s, BillingExpectation $e): BillingVerdict
    {
        $live = $e->mode === 'live';
        $currency = strtolower($e->currency);
        $invoice = $s->invoice;
        $facts = ['invoice_ref' => $s->requestedInvoiceRef, 'subscription_ref' => $e->subscriptionRef, 'customer_ref' => $e->customerRef,
            'price_ref' => $e->priceRef, 'provenance' => $s->provenance];
        $refuse = fn (string $reason): BillingVerdict => new BillingVerdict('refused', $reason, $facts);
        $pending = fn (string $reason): BillingVerdict => new BillingVerdict('not_settled', $reason, $facts);

        if (($s->account['object'] ?? null) !== 'account' || ($s->account['id'] ?? null) !== $e->accountRef) {
            return $refuse('account');
        }
        if (($invoice['object'] ?? null) !== 'invoice' || ($invoice['id'] ?? null) !== $s->requestedInvoiceRef) {
            return $refuse('invoice_identity');
        }
        foreach ([$invoice, ...$s->lines, ...$s->payments, ...array_values($s->paymentIntents), ...array_values($s->charges), ...($s->subscription === null ? [] : [$s->subscription])] as $object) {
            if (($object['livemode'] ?? null) !== $live) {
                return $refuse('mode');
            }
        }
        if (BillingValues::ref($invoice['customer'] ?? null) !== $e->customerRef) {
            return $refuse('customer');
        }
        if (($invoice['parent']['type'] ?? null) !== 'subscription_details'
            || BillingValues::ref($invoice['parent']['subscription_details']['subscription'] ?? null) !== $e->subscriptionRef) {
            return $refuse('subscription');
        }
        if (($invoice['currency'] ?? null) !== $currency) {
            return $refuse('currency');
        }
        if (($invoice['on_behalf_of'] ?? null) !== null || ($invoice['application'] ?? null) !== null) {
            return $refuse('connect');
        }
        $status = $invoice['status'] ?? null;
        if (in_array($status, ['draft', 'open', 'void', 'uncollectible'], true)) {
            return $pending('invoice_'.$status);
        }
        if ($status !== 'paid') {
            return $refuse('invoice_status');
        }

        // Exactly one subscription line of the approved price; prorations and extra lines are unapproved.
        if (count($s->lines) !== 1) {
            return $refuse('line_count');
        }
        $line = $s->lines[0];
        if (($line['object'] ?? null) !== 'line_item' || ($line['parent']['type'] ?? null) !== 'subscription_item_details'
            || BillingValues::ref($line['parent']['subscription_item_details']['subscription'] ?? null) !== $e->subscriptionRef) {
            return $refuse('line_parent');
        }
        if (($line['parent']['subscription_item_details']['proration'] ?? null) !== false) {
            return $refuse('proration');
        }
        if (($line['pricing']['type'] ?? null) !== 'price_details' || BillingValues::ref($line['pricing']['price_details']['price'] ?? null) !== $e->priceRef) {
            return $refuse('price');
        }
        if (($line['quantity'] ?? null) !== 1) {
            return $refuse('quantity');
        }
        if (($line['currency'] ?? null) !== $currency) {
            return $refuse('currency');
        }
        if (! self::none($line['discounts'] ?? null) || ! self::zeroAmounts($line['discount_amounts'] ?? null)) {
            return $refuse('discount');
        }
        if (! self::none($line['pretax_credit_amounts'] ?? null)) {
            return $refuse('credit');
        }
        if (! self::none($line['taxes'] ?? null)) {
            return $refuse('tax_unapproved');
        }
        $start = $line['period']['start'] ?? null;
        $end = $line['period']['end'] ?? null;
        if (! is_int($start) || ! is_int($end) || $start <= 0 || $start >= $end || $end >= 253402300800) {
            return $refuse('period');
        }

        $amount = $e->amountMinor;
        if (($invoice['total'] ?? null) === 0 || ($line['amount'] ?? null) === 0) {
            return $refuse('zero_amount');
        }
        if (($line['amount'] ?? null) !== $amount || ($line['subtotal'] ?? null) !== $amount) {
            return $refuse('amount');
        }
        if (! self::none($invoice['discounts'] ?? null) || ! self::zeroAmounts($invoice['total_discount_amounts'] ?? null)) {
            return $refuse('discount');
        }
        if (! self::none($invoice['total_pretax_credit_amounts'] ?? null)) {
            return $refuse('credit');
        }
        if (! self::none($invoice['total_taxes'] ?? null)) {
            return $refuse('tax_unapproved');
        }
        if (($invoice['starting_balance'] ?? null) !== 0 || ! in_array($invoice['ending_balance'] ?? null, [0, null], true)) {
            return $refuse('credit_balance');
        }
        if (($invoice['pre_payment_credit_notes_amount'] ?? null) !== 0 || ($invoice['post_payment_credit_notes_amount'] ?? null) !== 0) {
            return $refuse('credit_note');
        }
        if (! in_array($invoice['amount_paid_off_stripe'] ?? null, [0, null], true)) {
            return $refuse('out_of_band');
        }
        foreach (['total', 'subtotal', 'amount_due', 'amount_paid'] as $field) {
            if (($invoice[$field] ?? null) !== $amount) {
                return $refuse('amount');
            }
        }
        if (($invoice['amount_remaining'] ?? null) !== 0 || ($invoice['amount_overpaid'] ?? null) !== 0) {
            return $refuse('amount');
        }

        // One InvoicePayment, of type payment_intent, for exactly this invoice.
        if (count($s->payments) !== 1) {
            return $refuse(count($s->payments) === 0 ? 'payment_absent' : 'payment_count');
        }
        $payment = $s->payments[0];
        if (($payment['object'] ?? null) !== 'invoice_payment' || BillingValues::ref($payment['invoice'] ?? null) !== $s->requestedInvoiceRef
            || ! BillingValues::is('invoice_payment', $payment['id'] ?? null)) {
            return $refuse('payment_identity');
        }
        if (($payment['payment']['type'] ?? null) !== 'payment_intent') {
            return $refuse('payment_type');
        }
        if (($payment['currency'] ?? null) !== $currency) {
            return $refuse('currency');
        }
        if (($payment['status'] ?? null) !== 'paid') {
            return in_array($payment['status'] ?? null, ['open', 'canceled'], true) ? $pending('payment_'.$payment['status']) : $refuse('payment_status');
        }
        if (($payment['amount_paid'] ?? null) !== $amount) {
            return $refuse('amount');
        }
        $intentRef = BillingValues::ref($payment['payment']['payment_intent'] ?? null);
        $intent = $intentRef === null ? null : ($s->paymentIntents[$intentRef] ?? null);
        if ($intent === null || ($intent['object'] ?? null) !== 'payment_intent' || ($intent['id'] ?? null) !== $intentRef) {
            return $refuse('incomplete_graph');
        }
        foreach (['on_behalf_of', 'transfer_data', 'application', 'application_fee_amount'] as $field) {
            if (($intent[$field] ?? null) !== null) {
                return $refuse('connect');
            }
        }
        if (($intent['currency'] ?? null) !== $currency || (($intent['customer'] ?? null) !== null && BillingValues::ref($intent['customer']) !== $e->customerRef)) {
            return $refuse(($intent['currency'] ?? null) !== $currency ? 'currency' : 'customer');
        }
        if (($intent['status'] ?? null) !== 'succeeded') {
            return $pending('payment_intent_'.(is_string($intent['status'] ?? null) && preg_match('/\A[a-z_]{1,40}\z/D', $intent['status']) === 1 ? $intent['status'] : 'state'));
        }
        if (($intent['amount'] ?? null) !== $amount || ($intent['amount_received'] ?? null) !== $amount) {
            return $refuse('amount');
        }

        $chargeRef = BillingValues::ref($intent['latest_charge'] ?? null);
        $charge = $chargeRef === null ? null : ($s->charges[$chargeRef] ?? null);
        if ($charge === null || ($charge['object'] ?? null) !== 'charge' || ($charge['id'] ?? null) !== $chargeRef
            || BillingValues::ref($charge['payment_intent'] ?? null) !== $intentRef) {
            return $refuse('incomplete_graph');
        }
        foreach (['on_behalf_of', 'transfer_data', 'application', 'application_fee_amount'] as $field) {
            if (($charge[$field] ?? null) !== null) {
                return $refuse('connect');
            }
        }
        if (($charge['currency'] ?? null) !== $currency) {
            return $refuse('currency');
        }
        $facts += ['invoice_payment_ref' => $payment['id'], 'payment_intent_ref' => $intentRef, 'charge_ref' => $chargeRef];
        if (($charge['refunded'] ?? null) !== false || ($charge['amount_refunded'] ?? null) !== 0) {
            return new BillingVerdict('reversed', 'refunded', $facts);
        }
        if (($charge['disputed'] ?? null) !== false) {
            return new BillingVerdict('reversed', 'disputed', $facts);
        }
        if (($charge['status'] ?? null) !== 'succeeded' || ($charge['paid'] ?? null) !== true || ($charge['captured'] ?? null) !== true) {
            return $pending('charge_not_captured');
        }
        if (($charge['amount'] ?? null) !== $amount || ($charge['amount_captured'] ?? null) !== $amount) {
            return $refuse('amount');
        }

        $transactionRef = BillingValues::ref($charge['balance_transaction'] ?? null);
        $transaction = $transactionRef === null ? null : ($s->balanceTransactions[$transactionRef] ?? null);
        if ($transaction === null || ($transaction['object'] ?? null) !== 'balance_transaction' || ($transaction['id'] ?? null) !== $transactionRef
            || ($transaction['type'] ?? null) !== 'charge' || BillingValues::ref($transaction['source'] ?? null) !== $chargeRef) {
            return $refuse('incomplete_graph');
        }
        if (($transaction['currency'] ?? null) !== $currency || ($transaction['exchange_rate'] ?? null) !== null) {
            return $refuse('fx');
        }
        if (($transaction['amount'] ?? null) !== $amount) {
            return $refuse('amount');
        }
        $facts['balance_transaction_ref'] = $transactionRef;
        if (($transaction['status'] ?? null) !== 'available') {
            return $pending('balance_pending');
        }

        $subscription = $s->subscription;
        if ($subscription === null || ($subscription['object'] ?? null) !== 'subscription' || ($subscription['id'] ?? null) !== $e->subscriptionRef
            || BillingValues::ref($subscription['customer'] ?? null) !== $e->customerRef) {
            return $refuse('subscription');
        }
        $items = $subscription['items']['data'] ?? null;
        $prices = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $prices[] = is_array($item) ? BillingValues::ref($item['price'] ?? null) : null;
        }
        if (! is_array($items) || ($subscription['items']['has_more'] ?? null) !== false || ! in_array($e->priceRef, $prices, true)) {
            return $refuse('price');
        }

        return new BillingVerdict('settled', null, $facts + ['line_period_start' => BillingValues::utc($start), 'line_period_end' => BillingValues::utc($end),
            'amount_minor' => $amount, 'currency' => $e->currency]);
    }

    /**
     * Whether the retrieved account, invoice, customer and parent subscription all match the binding. `evaluate()` checks these in
     * the order account, invoice identity, mode, customer, subscription, so a refusal for invoice identity or mode can arrive
     * before the binding was ever compared. Pure, like evaluate().
     */
    public static function bindingValidated(BillingSnapshots $s, BillingExpectation $e): bool
    {
        $invoice = $s->invoice;

        return ($s->account['object'] ?? null) === 'account' && ($s->account['id'] ?? null) === $e->accountRef
            && ($invoice['object'] ?? null) === 'invoice' && ($invoice['id'] ?? null) === $s->requestedInvoiceRef
            && BillingValues::ref($invoice['customer'] ?? null) === $e->customerRef
            && ($invoice['parent']['type'] ?? null) === 'subscription_details'
            && BillingValues::ref($invoice['parent']['subscription_details']['subscription'] ?? null) === $e->subscriptionRef;
    }

    private static function none(mixed $value): bool
    {
        return $value === null || $value === [];
    }

    private static function zeroAmounts(mixed $value): bool
    {
        if ($value === null || $value === []) {
            return true;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $entry) {
            if (! is_array($entry) || ($entry['amount'] ?? null) !== 0) {
                return false;
            }
        }

        return true;
    }
}
