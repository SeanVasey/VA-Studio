<?php

namespace Tests\Support;

use App\Domain\Memberships\Billing\BillingProviderGateway;
use Closure;

/**
 * Test-only provider seam that serves a rehearsal graph and runs one callback right after the retrieval's LAST provider read,
 * as a second job would run while this one is still between its provider reads and its ledger append. The callback runs outside
 * any database transaction, exactly where real provider I/O happens.
 */
final class InterleavingBillingGateway implements BillingProviderGateway
{
    private bool $fired = false;

    public function __construct(private readonly RehearsalBillingGateway $inner, private readonly Closure $between) {}

    public function provenance(): string
    {
        return $this->inner->provenance();
    }

    public function account(): array
    {
        return $this->inner->account();
    }

    public function retrieveInvoice(string $ref): array
    {
        return $this->inner->retrieveInvoice($ref);
    }

    public function listInvoicePayments(string $invoiceRef): array
    {
        return $this->inner->listInvoicePayments($invoiceRef);
    }

    public function retrievePaymentIntent(string $ref): array
    {
        return $this->inner->retrievePaymentIntent($ref);
    }

    public function retrieveCharge(string $ref): array
    {
        return $this->inner->retrieveCharge($ref);
    }

    public function retrieveBalanceTransaction(string $ref): array
    {
        return $this->inner->retrieveBalanceTransaction($ref);
    }

    public function retrieveSubscription(string $ref): array
    {
        $subscription = $this->inner->retrieveSubscription($ref);
        if (! $this->fired) {
            $this->fired = true;
            ($this->between)();
        }

        return $subscription;
    }
}
