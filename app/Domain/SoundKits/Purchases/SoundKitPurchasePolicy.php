<?php

namespace App\Domain\SoundKits\Purchases;

use App\Support\Environment\TestEnvironment;

/**
 * Admission of the sound-kit Stripe TEST purchase lane (BL-13, FP-007). True only where the track test chain is admitted
 * ({@see TestEnvironment::admitsTestCommerce()}: local, testing, staging) AND `sound-kits.test_checkout_enabled` is exactly
 * `true` (env `VASEY_SOUND_KIT_TEST_CHECKOUT_ENABLED`, default false). Production can never admit it, whatever the flag says.
 * Each step also keeps the track chain's own gates: Stripe test checkout (CheckoutPolicy), GET-only payment processing
 * (PaymentProcessingPolicy) and the test delivery account (operator control). There is no live path.
 */
final class SoundKitPurchasePolicy
{
    /** Lifetime of a kit order before its checkout must start (as CreateQuote's provisional review). */
    public const ORDER_MINUTES = 15;

    /** The Stripe session lifetime, from the order's creation (CheckoutPolicy's 3600 s). */
    public const PROVIDER_SECONDS = 3600;

    /** A download authorization is single-use and lives this long (the track delivery's 60 s). */
    public const AUTHORIZATION_SECONDS = 60;

    public function admitted(): bool
    {
        return TestEnvironment::admitsTestCommerce() && config('sound-kits.test_checkout_enabled') === true;
    }

    /** Every customer, webhook and console entry point calls this first; a closed lane is indistinguishable from absence. */
    public function require(): void
    {
        if (! $this->admitted()) {
            throw new SoundKitPurchaseException('NOT_FOUND', 404);
        }
    }
}
