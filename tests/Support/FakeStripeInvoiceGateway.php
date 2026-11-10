<?php

namespace Tests\Support;

use App\Domain\Services\Invoicing\StripeInvoiceGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * In-memory Stripe Invoicing. Idempotent on the issue key exactly as the provider's idempotency keys make the real calls, so a repeated
 * issue after a simulated crash returns the same invoice. No network, no real account, customer or card data.
 */
final class FakeStripeInvoiceGateway implements StripeInvoiceGateway
{
    /** @var array<string, array> */
    public array $invoices = [];

    /** @var array<string, string> idempotency key => invoice id */
    public array $byKey = [];

    public int $issueCalls = 0;

    public int $retrieveCalls = 0;

    public array $specs = [];

    /** 'before' fails before anything exists; 'after' creates the invoice then fails, like a lost response. */
    public ?string $failIssue = null;

    public bool $retrieveUnavailable = false;

    public function issue(array $spec): array
    {
        $this->issueCalls++;
        $this->specs[] = $spec;
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('STRIPE_INVOICE_UNAVAILABLE');
        }
        if ($this->failIssue === 'before') {
            $this->failIssue = null;
            throw new RuntimeException('STRIPE_INVOICE_UNAVAILABLE');
        }
        $id = $this->byKey[$spec['key']] ??= 'in_Fake'.substr($spec['key'], 0, 20);
        $customer = $spec['customer_id'] ?? 'cus_Fake'.substr($spec['key'], 0, 12);
        $this->invoices[$id] ??= [
            'id' => $id, 'object' => 'invoice', 'livemode' => false, 'account' => null, 'context' => null, 'application' => null,
            'status' => 'open', 'collection_method' => 'send_invoice', 'currency' => 'usd', 'customer' => $customer,
            'total' => $spec['amount_minor'], 'amount_due' => $spec['amount_minor'], 'amount_paid' => 0, 'amount_remaining' => $spec['amount_minor'],
            'amount_overpaid' => 0, 'amount_paid_off_stripe' => null, 'pre_payment_credit_notes_amount' => 0, 'post_payment_credit_notes_amount' => 0,
            'due_date' => time() + 86400 * $spec['days_until_due'], 'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_FixtureStore/test_'.substr($spec['key'], 0, 24),
            'metadata' => ['va_service_invoice' => $spec['invoice_ref'], 'va_project' => $spec['project_ref']],
            'status_transitions' => ['paid_at' => null, 'finalized_at' => time(), 'voided_at' => null, 'marked_uncollectible_at' => null],
        ];
        if ($this->failIssue === 'after') {
            $this->failIssue = null;
            throw new RuntimeException('STRIPE_INVOICE_UNAVAILABLE');
        }

        return ['customer_id' => $customer, 'invoice' => $this->invoices[$id]];
    }

    public function retrieve(string $invoiceId): array
    {
        $this->retrieveCalls++;
        if ($this->retrieveUnavailable || ! isset($this->invoices[$invoiceId])) {
            throw new RuntimeException('STRIPE_INVOICE_UNAVAILABLE');
        }

        return $this->invoices[$invoiceId];
    }

    /** Provider-side change, as if Stripe settled, voided or altered the invoice. */
    public function set(string $invoiceId, array $changes): void
    {
        $this->invoices[$invoiceId] = array_replace_recursive($this->invoices[$invoiceId], $changes);
    }

    public function pay(string $invoiceId, array $changes = []): void
    {
        $total = $this->invoices[$invoiceId]['total'];
        $this->set($invoiceId, array_replace_recursive(['status' => 'paid', 'amount_paid' => $total, 'amount_remaining' => 0,
            'status_transitions' => ['paid_at' => time()]], $changes));
    }

    public function onlyInvoiceId(): string
    {
        return array_key_first($this->invoices) ?? throw new RuntimeException('No fake invoice.');
    }
}
