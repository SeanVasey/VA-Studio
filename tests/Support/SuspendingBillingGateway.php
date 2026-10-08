<?php

namespace Tests\Support;

use App\Domain\Memberships\Billing\BillingProviderGateway;
use Fiber;
use LogicException;

/**
 * Test-only provider seam that serves a rehearsal graph and suspends the running Fiber once, either just BEFORE the retrieval's
 * first provider read (`before_first_read`: the retrieval has its start position and is stalled before `snapshots()`) or just
 * AFTER its last provider read (`after_last_read`: the reads are done and the end position is not yet allocated). Two retrievals
 * driven as Fibers can then interleave in any order in one process, which nested callbacks cannot do (Codex P1 on PR #54,
 * `BillingReconciliation.php:42`). It suspends outside any database transaction, exactly where real provider I/O happens.
 */
final class SuspendingBillingGateway implements BillingProviderGateway
{
    private bool $suspended = false;

    public function __construct(private readonly RehearsalBillingGateway $inner, private readonly string $stage)
    {
        if (! in_array($stage, ['before_first_read', 'after_last_read'], true)) {
            throw new LogicException('Unknown suspension stage.');
        }
    }

    public function provenance(): string
    {
        return $this->inner->provenance();
    }

    public function account(): array
    {
        if ($this->stage === 'before_first_read') {
            $this->suspend();
        }

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
        if ($this->stage === 'after_last_read') {
            $this->suspend();
        }

        return $subscription;
    }

    private function suspend(): void
    {
        if (! $this->suspended) {
            $this->suspended = true;
            if (Fiber::getCurrent() === null) {
                throw new LogicException('Run the retrieval inside a Fiber.');
            }
            Fiber::suspend($this->stage);
        }
    }
}
