<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProjection;
use App\Domain\Memberships\Billing\BillingProviderGateway;
use LogicException;

/**
 * Test-only provider seam serving a BillingStripeFixtures graph through the same projections as the
 * SDK gateway. synthetic_rehearsal provenance only; refuses outside the testing environment. `fail`
 * names a retrieval stage that raises the gateway's provider_unavailable refusal (a timeout).
 */
final class RehearsalBillingGateway implements BillingProviderGateway
{
    public array $calls = [];

    public function __construct(public array $graph, public ?string $fail = null)
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Rehearsal billing gateway requires the testing environment.');
        }
    }

    public function provenance(): string
    {
        return IdentityPolicy::REHEARSAL;
    }

    public function account(): array
    {
        return $this->serve('account', fn () => BillingProjection::project('account', $this->graph['account']));
    }

    public function retrieveInvoice(string $ref): array
    {
        return $this->serve('invoice', function () use ($ref) {
            BillingException::require(($this->graph['invoice']['id'] ?? null) === $ref, 'provider_inconsistent');

            return BillingProjection::project('invoice', $this->graph['invoice']);
        });
    }

    public function listInvoicePayments(string $invoiceRef): array
    {
        return $this->serve('payments', fn () => array_map(fn (array $payment) => BillingProjection::project('invoice_payment', $payment), $this->graph['payments']));
    }

    public function retrievePaymentIntent(string $ref): array
    {
        return $this->serve('intent', fn () => BillingProjection::project('payment_intent', $this->graph['intents'][$ref] ?? throw new BillingException('provider_inconsistent')));
    }

    public function retrieveCharge(string $ref): array
    {
        return $this->serve('charge', fn () => BillingProjection::project('charge', $this->graph['charges'][$ref] ?? throw new BillingException('provider_inconsistent')));
    }

    public function retrieveBalanceTransaction(string $ref): array
    {
        return $this->serve('transaction', fn () => BillingProjection::project('balance_transaction', $this->graph['transactions'][$ref] ?? throw new BillingException('provider_inconsistent')));
    }

    public function retrieveSubscription(string $ref): array
    {
        return $this->serve('subscription', fn () => BillingProjection::project('subscription', $this->graph['subscription']));
    }

    private function serve(string $stage, \Closure $result): array
    {
        $this->calls[] = $stage;
        if ($this->fail === $stage) {
            throw new BillingException('provider_unavailable');
        }

        return $result();
    }
}
