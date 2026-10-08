<?php

namespace App\Jobs;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingValues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One bounded invoice retrieval. It resolves BillingReconciliation, whose gateway binding is not
 * registered by this lane, so the job fails closed until root binds a reviewed gateway. It never
 * awards: it appends one observation (settled, not_settled, unknown, refused or reversed).
 *
 * Up to three attempts. A first retrieval that ends unknown throws (it writes nothing), so the queue retries it with the backoff.
 * Under an identity the binding already owns, an unknown or incomplete outcome is appended first, so every attempt is visible in
 * the ledger, and the job is then released for another attempt while attempts remain. A retrieval overtaken by a newer one of the
 * same invoice (`superseded_retrieval`) is a normal end: the newer retrieval recorded the later state, so there is nothing to retry.
 *
 * The provider invoice reference is carried only as an application-key-encrypted value (the same sealing the ledger uses for its
 * payloads), never plaintext, so neither the queued payload nor `failed_jobs` holds it. The binding id is an internal row id.
 */
final class RetrieveMembershipInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    private readonly string $sealedInvoiceRef;

    public function __construct(public readonly string $bindingId, string $invoiceRef)
    {
        BillingException::require(BillingValues::is('invoice', $invoiceRef), 'invalid_value');
        $this->sealedInvoiceRef = BillingValues::encrypt(['schema_version' => 1, 'purpose' => 'production_membership_billing_retrieval_job', 'invoice_ref' => $invoiceRef]);
    }

    /** Seconds before the second and the third attempt. */
    public function backoff(): array
    {
        return [60, 600];
    }

    /** The provider invoice reference, unsealed only inside the worker. */
    public function invoiceRef(): string
    {
        $payload = BillingValues::decrypt($this->sealedInvoiceRef);
        BillingException::require(($payload['purpose'] ?? null) === 'production_membership_billing_retrieval_job' && is_string($payload['invoice_ref'] ?? null), 'ciphertext');

        return $payload['invoice_ref'];
    }

    public function handle(BillingReconciliation $reconciliation): void
    {
        try {
            $observation = $reconciliation->retrieve($this->bindingId, $this->invoiceRef());
        } catch (BillingException $error) {
            if ($error->reason === 'superseded_retrieval') {
                return;
            }

            throw $error;
        }
        $attempt = $this->attempts();
        if (BillingReconciliation::isInconclusive($observation) && $attempt < $this->tries) {
            $backoff = $this->backoff();
            $this->release($backoff[$attempt - 1] ?? $backoff[array_key_last($backoff)]);
        }
    }
}
