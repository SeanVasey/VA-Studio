<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use SensitiveParameter;

/**
 * Provider boundary for Stripe Checkout `automatic_tax` sessions. No implementation in this lane performs I/O;
 * a real own-account adapter is separately reviewed work (A4) and needs Sean's authorization.
 */
interface TaxCheckoutTransport
{
    /** Pure and configuration-only. It is compared before any provider call and must never perform I/O. */
    public function boundTo(): string;

    /** POST /v1/checkout/sessions with the retained parameters and idempotency key; the result is only a locator. */
    public function create(TaxExecutionContext $context, #[SensitiveParameter] array $params, string $idempotencyKey): array;

    /** Authoritative GET with `line_items.data.price.product` expanded; the only source of buyer-reviewed amounts. */
    public function retrieve(TaxExecutionContext $context, string $sessionId): array;

    public function paymentIntent(TaxExecutionContext $context, string $paymentId): array;
}
