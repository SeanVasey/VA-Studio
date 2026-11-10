<?php

namespace App\Domain\Services\Invoicing;

/** Provider boundary for service invoices. Tests bind a fake; the real implementation talks only to Stripe test mode. */
interface StripeInvoiceGateway
{
    /**
     * Creates (or resumes, through deterministic idempotency keys) a customer when none is given, a draft send_invoice invoice, its
     * one line and finalizes it. Returns ['customer_id' => string, 'invoice' => array]. Throws RuntimeException on any failure or
     * unverifiable provider object; an exception means the outcome is uncertain, never that nothing was created.
     *
     * @param  array{customer_id: ?string, email: string, amount_minor: int, currency: string, days_until_due: int, description: string, invoice_ref: string, project_ref: string, key: string}  $spec
     */
    public function issue(array $spec): array;

    /** Reads one invoice from the provider with the same account, mode and shape proof as issue(). */
    public function retrieve(string $invoiceId): array;
}
