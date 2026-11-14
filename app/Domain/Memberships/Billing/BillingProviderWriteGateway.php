<?php

namespace App\Domain\Memberships\Billing;

use SensitiveParameter;

/**
 * Write-side provider boundary for membership Billing (BL-69), kept apart from the retrieval-only BillingProviderGateway so
 * that interface never gains a write. Test mode only. The only writes are a Checkout Session in subscription mode and a
 * Customer Portal session, each under an idempotency key; there is no method that pays, finalizes, voids, refunds, cancels or
 * updates a subscription, and an implementation must not add one. `retrievePrice` is a pre-flight read the checkout needs before it
 * may write. Every method returns a bounded allowlisted projection or throws BillingException.
 */
interface BillingProviderWriteGateway
{
    /** synthetic_rehearsal (test mode); never supplied by a caller. */
    public function provenance(): string;

    /** A recurring price as Stripe holds it: id, livemode, active, currency, unit_amount, type, billing_scheme and recurring terms. */
    public function retrievePrice(string $priceRef): array;

    /**
     * @param  array  $params  the exact Checkout Session parameters (BillingCheckoutParameters::subscription output)
     * @return array{id: string, livemode: bool, mode: string, status: string, url: string, client_reference_id: ?string, customer: ?string, currency: ?string, amount_total: ?int, expires_at: ?int, metadata: array<string, string>}
     */
    public function createSubscriptionCheckoutSession(#[SensitiveParameter] array $params, #[SensitiveParameter] string $idempotencyKey): array;

    /**
     * @param  array  $params  customer, return_url and optional configuration
     * @return array{id: string, livemode: bool, customer: string, url: string, return_url: ?string, configuration: ?string}
     */
    public function createPortalSession(#[SensitiveParameter] array $params, #[SensitiveParameter] string $idempotencyKey): array;
}
