<?php

namespace App\Jobs;

use App\Domain\Memberships\Billing\BillingReconciliation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One bounded invoice retrieval. It resolves BillingReconciliation, whose gateway binding is not
 * registered by this lane, so the job fails closed until root binds a reviewed gateway. It never
 * awards: it appends one observation (settled, not_settled, unknown, refused or reversed).
 */
final class RetrieveMembershipInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $bindingId, public readonly string $invoiceRef) {}

    public function handle(BillingReconciliation $reconciliation): void
    {
        $reconciliation->retrieve($this->bindingId, $this->invoiceRef);
    }
}
