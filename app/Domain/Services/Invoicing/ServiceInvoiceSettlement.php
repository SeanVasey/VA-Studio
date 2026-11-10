<?php

namespace App\Domain\Services\Invoicing;

use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Payments\PaymentVerificationException;
use App\Domain\Commerce\Payments\PaymentWork;
use App\Domain\Services\Invoicing\Models\ServiceInvoice;
use App\Domain\Services\Invoicing\Models\ServiceInvoicePayment;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Applies a verified Stripe receipt that names an invoice to the service invoice it belongs to (BL-70 phase 1).
 *
 * The event is a locator only: its type, status and amounts are never read. The invoice is re-read from Stripe with the pinned API
 * version through the own-account test-mode gateway, and the re-read object alone decides. A payment is recorded only when that
 * object is `paid` for exactly the authored amount in USD with nothing paid off Stripe, overpaid or credited (ServiceInvoiceEvidence).
 * Redelivery is harmless: the receipt is unique per event, the payment row is unique per invoice, and a second event for an already
 * paid invoice only re-checks it. A contradiction is quarantined through the ordinary payment work, never recorded.
 */
final class ServiceInvoiceSettlement
{
    public const EVENTS = ['invoice.paid', 'invoice.payment_failed', 'invoice.voided', 'invoice.marked_uncollectible'];

    /** @param array{event_type: string, object_type: string, session_id: ?string} $locator */
    public function process(array $locator, StripeReceiptWork $claim): string
    {
        if (! app(ServiceInvoicePolicy::class)->enabled()) {
            return app(PaymentWork::class)->outcome($claim, 'unsupported', 'service_invoicing_off');
        }
        $invoiceId = $locator['session_id'];
        if ($locator['object_type'] !== 'invoice' || ! StripeSdkInvoiceGateway::invoiceId($invoiceId)) {
            throw new PaymentVerificationException('receipt_changed');
        }
        $local = ServiceInvoice::where('stripe_invoice_id', $invoiceId)->first();
        try {
            // Outside any transaction: the gateway refuses otherwise. A provider outage is a retry, not a verdict.
            $provider = app(StripeInvoiceGateway::class)->retrieve($invoiceId);
        } catch (RuntimeException) {
            throw new PaymentVerificationException('retry');
        }
        if (! $local) {
            // A crash between Stripe's finalize and the local record leaves a `creating` row with no provider id: adopt it by the
            // metadata this server set, but only when the provider facts then match that row. Anything else is not ours.
            $ref = is_array($provider['metadata'] ?? null) ? ($provider['metadata']['va_service_invoice'] ?? null) : null;
            $local = is_string($ref) ? ServiceInvoice::where('public_id', $ref)->where('state', 'creating')->whereNull('stripe_invoice_id')->first() : null;
            if (! $local) {
                return app(PaymentWork::class)->outcome($claim, 'unsupported', 'not_a_service_invoice');
            }
        }

        return DB::transaction(function () use ($claim, $local, $provider, $locator): string {
            $work = app(PaymentWork::class)->owns($claim);
            if (! $work) {
                return 'stale';
            }
            $invoice = ServiceInvoice::whereKey($local->id)->lockForUpdate()->firstOrFail();
            $adopting = $invoice->stripe_invoice_id === null;
            // Bind the re-read object to the locked row; for an adoption the provider id is the one thing not yet recorded.
            $probe = $invoice->replicate();
            $probe->stripe_invoice_id = $adopting ? $provider['id'] : $invoice->stripe_invoice_id;
            if (ServiceInvoiceEvidence::mismatches($provider, $probe) !== [] || ($adopting && $invoice->state !== 'creating')) {
                throw new PaymentVerificationException('payment_changed');
            }
            if ($adopting) {
                $invoice->fill(['state' => 'open', 'stripe_customer_id' => $provider['customer'], 'stripe_invoice_id' => $provider['id'],
                    'hosted_invoice_url' => ServiceInvoiceEvidence::hostedUrl($provider), 'updated_at' => now()])->save();
            }
            $outcome = match ($provider['status'] ?? null) {
                'paid' => $this->paid($invoice, $provider, $claim),
                'void' => $this->terminal($invoice, 'void'),
                'uncollectible' => $this->terminal($invoice, 'uncollectible'),
                'open' => $this->open($invoice, $provider, $locator['event_type']),
                default => throw new PaymentVerificationException('payment_changed'),
            };
            app(PaymentWork::class)->finish($work, 'processed', $outcome);

            return $outcome;
        }, 5);
    }

    private function paid(ServiceInvoice $invoice, array $provider, StripeReceiptWork $claim): string
    {
        $bad = ServiceInvoiceEvidence::settlementMismatches($provider, $invoice);
        if ($bad !== []) {
            // Paid on Stripe but not for what this server authored (or contradicting the local row): no payment, a quarantined receipt.
            throw new PaymentVerificationException('payment_changed');
        }
        $payment = ServiceInvoicePayment::where('service_invoice_id', $invoice->id)->first();
        if ($payment) {
            // A repeated or later event for an invoice already settled: it must agree with the evidence that was recorded.
            if ($payment->evidence_hash !== ServiceInvoiceEvidence::hash($provider, $invoice) || $invoice->state !== 'paid') {
                throw new PaymentVerificationException('payment_changed');
            }

            return 'already_paid';
        }
        if (! in_array($invoice->state, ['open', 'uncollectible'], true)) {
            throw new PaymentVerificationException('payment_changed');
        }
        $payment = ServiceInvoicePayment::create(['public_id' => (string) Str::uuid(), 'service_invoice_id' => $invoice->id, 'project_id' => $invoice->project_id,
            'quote_id' => $invoice->quote_id, 'stripe_invoice_id' => $invoice->stripe_invoice_id, 'stripe_webhook_receipt_id' => $claim->stripe_webhook_receipt_id,
            'amount_minor' => $invoice->amount_minor, 'currency' => $invoice->currency, 'evidence_hash' => ServiceInvoiceEvidence::hash($provider, $invoice),
            'verified_at' => now()]);
        $invoice->fill(['state' => 'paid', 'updated_at' => now()])->save();
        AuditEvent::recordAttributed('service_invoice.payment_verified', $invoice, ['payment' => $payment->public_id, 'amount_minor' => $invoice->amount_minor,
            'kind' => $invoice->kind, 'test_only' => true], null);

        return 'payment_verified';
    }

    private function terminal(ServiceInvoice $invoice, string $state): string
    {
        if ($invoice->state === $state) {
            return 'already_'.$state;
        }
        // Paid is final: a provider that now calls a settled invoice void or uncollectible contradicts the evidence on file.
        if (in_array($invoice->state, ['paid', 'void'], true)) {
            throw new PaymentVerificationException('payment_changed');
        }
        $invoice->fill(['state' => $state, 'updated_at' => now()])->save();
        AuditEvent::recordAttributed('service_invoice.'.$state, $invoice, ['kind' => $invoice->kind, 'revision' => $invoice->revision, 'test_only' => true], null);

        return $state;
    }

    private function open(ServiceInvoice $invoice, array $provider, string $eventType): string
    {
        if ($invoice->state !== 'open') {
            // Open at Stripe but void, paid or uncollectible here (a stale or out-of-order event): leave the stronger local fact alone.
            return 'stale_open';
        }
        if ($eventType === 'invoice.payment_failed') {
            $invoice->fill(['payment_failures' => $invoice->payment_failures + 1, 'last_payment_failed_at' => now(), 'updated_at' => now()])->save();

            return 'payment_failure_recorded';
        }

        return 'still_open';
    }
}
