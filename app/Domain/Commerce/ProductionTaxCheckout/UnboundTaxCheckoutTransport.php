<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use SensitiveParameter;

/** The shipped default: every provider operation refuses. */
final class UnboundTaxCheckoutTransport implements TaxCheckoutTransport
{
    public function boundTo(): string
    {
        return 'unbound';
    }

    public function create(TaxExecutionContext $context, #[SensitiveParameter] array $params, string $idempotencyKey): array
    {
        throw new CheckoutException('provider_unbound', 503);
    }

    public function retrieve(TaxExecutionContext $context, string $sessionId): array
    {
        throw new CheckoutException('provider_unbound', 503);
    }

    public function paymentIntent(TaxExecutionContext $context, string $paymentId): array
    {
        throw new CheckoutException('provider_unbound', 503);
    }
}
