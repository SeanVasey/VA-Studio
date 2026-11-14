<?php

namespace App\Domain\Services\Invoicing;

use App\Domain\Services\Invoicing\Models\ServiceInvoice;
use App\Support\CanonicalJson;

/**
 * Pure comparison of a provider invoice with the local row, whose amounts the server authored from the retained quote. A provider
 * event, status or number is never trusted on its own: every fact here is compared with what this server expects.
 */
final class ServiceInvoiceEvidence
{
    /** @return list<string> reasons the provider invoice does not belong to, or does not match, the local row; empty means bound */
    public static function mismatches(array $invoice, ServiceInvoice $row): array
    {
        $bad = [];
        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $check = function (string $name, bool $ok) use (&$bad): void {
            if (! $ok) {
                $bad[] = $name;
            }
        };
        $check('object', ($invoice['object'] ?? null) === 'invoice');
        $check('livemode', ($invoice['livemode'] ?? null) === false);
        $check('account', ($invoice['account'] ?? null) === null && ($invoice['context'] ?? null) === null && ($invoice['application'] ?? null) === null);
        $check('invoice_id', $row->stripe_invoice_id === null || ($invoice['id'] ?? null) === $row->stripe_invoice_id);
        $check('metadata', ($metadata['va_service_invoice'] ?? null) === $row->public_id);
        $check('collection_method', ($invoice['collection_method'] ?? null) === 'send_invoice');
        $check('currency', ($invoice['currency'] ?? null) === 'usd' && $row->currency === 'USD');
        $check('customer', is_string($invoice['customer'] ?? null) && ($row->stripe_customer_id === null || $invoice['customer'] === $row->stripe_customer_id));
        $check('total', ($invoice['total'] ?? null) === $row->amount_minor && ($invoice['amount_due'] ?? null) === $row->amount_minor);

        return $bad;
    }

    /** Extra facts for a newly issued invoice: finalized, unpaid, with a hosted page. */
    public static function issuedMismatches(array $invoice, ServiceInvoice $row, string $customerId): array
    {
        $bad = self::mismatches($invoice, $row);
        if (($invoice['customer'] ?? null) !== $customerId) {
            $bad[] = 'customer';
        }
        if (($invoice['status'] ?? null) !== 'open' || ($invoice['amount_paid'] ?? null) !== 0) {
            $bad[] = 'status';
        }
        // The response carries the due date, not the requested day count: a send_invoice invoice must have one.
        if (! is_int($invoice['due_date'] ?? null)) {
            $bad[] = 'due_date';
        }
        if (self::hostedUrl($invoice) === null) {
            $bad[] = 'hosted_invoice_url';
        }

        return array_values(array_unique($bad));
    }

    /**
     * Proof of settlement. The invoice must be paid on Stripe for exactly the authored amount, in USD, with nothing paid off Stripe,
     * overpaid or credited, and a recorded paid time. Anything else is a contradiction to quarantine, never a payment.
     *
     * @return list<string>
     */
    public static function settlementMismatches(array $invoice, ServiceInvoice $row): array
    {
        $bad = self::mismatches($invoice, $row);
        $zero = fn (string $field): bool => ($invoice[$field] ?? 0) === 0;
        if (($invoice['status'] ?? null) !== 'paid') {
            $bad[] = 'status';
        }
        if (($invoice['amount_paid'] ?? null) !== $row->amount_minor || ($invoice['amount_remaining'] ?? null) !== 0) {
            $bad[] = 'amount_paid';
        }
        foreach (['amount_overpaid', 'amount_paid_off_stripe', 'pre_payment_credit_notes_amount', 'post_payment_credit_notes_amount'] as $field) {
            if (! $zero($field)) {
                $bad[] = $field;
            }
        }
        if (! is_int($invoice['status_transitions']['paid_at'] ?? null)) {
            $bad[] = 'paid_at';
        }

        return array_values(array_unique($bad));
    }

    /** Hash of the canonical provider facts that were compared; stored with the payment, never the private invoice. */
    public static function hash(array $invoice, ServiceInvoice $row): string
    {
        return CanonicalJson::hash(['schema' => 'service-invoice-settlement-v1', 'invoice' => $invoice['id'], 'ref' => $row->public_id,
            'status' => $invoice['status'], 'amount_paid' => $invoice['amount_paid'], 'total' => $invoice['total'], 'currency' => $invoice['currency'],
            'customer' => $invoice['customer'], 'paid_at' => $invoice['status_transitions']['paid_at']]);
    }

    public static function hostedUrl(array $invoice): ?string
    {
        $url = $invoice['hosted_invoice_url'] ?? null;

        return is_string($url) && strlen($url) <= 1024 && preg_match('#\Ahttps://invoice\.stripe\.com/[A-Za-z0-9_\-./?=&%]+\z#D', $url) === 1 ? $url : null;
    }
}
