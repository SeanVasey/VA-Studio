<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;

/**
 * Tax255 refuses everything unless `production-tax-checkout.enabled` is literally true AND the application
 * environment is local or testing. There is no production activation path in this lane.
 */
final class TaxCheckoutPolicy
{
    public const STRIPE_SDK_VERSION = '21.3.2';

    public const STRIPE_API_VERSION = '2026-08-26.dahlia';

    public const PRODUCER = 'production_tax_checkout_v2';

    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('production-tax-checkout.enabled') === true;
    }

    public static function requireEnabled(): void
    {
        CheckoutException::require(self::enabled(), 'disabled', 503);
        self::requirePins();
    }

    /** The configured pins must equal the locked SDK contract the V1 checkout already pins. */
    public static function requirePins(): void
    {
        CheckoutException::require(config('production-tax-checkout.stripe_sdk_version') === self::STRIPE_SDK_VERSION
            && config('production-tax-checkout.stripe_api_version') === self::STRIPE_API_VERSION
            && ExecutionContextV1::API_VERSION === self::STRIPE_API_VERSION, 'unsupported', 503);
    }

    /**
     * Fail closed before any provider I/O: the configured provider identifier must be a non-empty string equal
     * to the bound transport's pure, configuration-only identity, and the refusing default never qualifies.
     */
    public static function transport(?TaxCheckoutTransport $transport): TaxCheckoutTransport
    {
        self::requireEnabled();
        $bound = config('production-tax-checkout.provider');
        CheckoutException::require(is_string($bound) && $bound !== '' && $transport !== null
            && ! $transport instanceof UnboundTaxCheckoutTransport && hash_equals($bound, $transport->boundTo()), 'provider_unbound', 503);

        return $transport;
    }
}
