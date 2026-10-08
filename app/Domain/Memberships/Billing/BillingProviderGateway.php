<?php

namespace App\Domain\Memberships\Billing;

/**
 * Retrieval-only provider boundary. Every method returns a bounded allowlisted projection or throws
 * BillingException; a collection that is not complete within its fixed bound is refused. There is no
 * create, update, pay, finalize, void or refund method, and an implementation must not add one.
 */
interface BillingProviderGateway
{
    /** synthetic_rehearsal (test mode) or verified_production; never supplied by a caller. */
    public function provenance(): string;

    /** GET /v1/account: the account that owns the credential. */
    public function account(): array;

    /** Invoice with its complete line list under `lines.data`. */
    public function retrieveInvoice(string $ref): array;

    /** @return list<array> the complete InvoicePayment list for one invoice */
    public function listInvoicePayments(string $invoiceRef): array;

    public function retrievePaymentIntent(string $ref): array;

    public function retrieveCharge(string $ref): array;

    public function retrieveBalanceTransaction(string $ref): array;

    /** Subscription with its complete item list under `items.data`. */
    public function retrieveSubscription(string $ref): array;
}
