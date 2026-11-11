<?php

namespace App\Domain\SoundKits\Purchases;

use App\Support\Environment\TestEnvironment;

/**
 * Admission of the sound-kit Stripe TEST purchase lane (BL-13, FP-007). True only where the track test chain is admitted
 * ({@see TestEnvironment::admitsTestCommerce()}: local, testing, staging) AND `sound-kits.test_checkout_enabled` is exactly
 * `true` (env `VASEY_SOUND_KIT_TEST_CHECKOUT_ENABLED`, default false). Production can never admit it, whatever the flag says.
 * Checkout and payment verification also keep the track chain's provider gates (CheckoutPolicy for the Stripe test session,
 * PaymentProcessingPolicy for GET-only reads). Contract issuance and delivery are gated only by this policy plus, for delivery, the
 * per-order operator control (`vasey:control-test-kit-delivery`, absent means not enabled); they do not read the track chain's
 * `contracts.test_issuance_enabled` or `VASEY_TEST_DELIVERY_ACCESS_ENABLED`. There is no live path.
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
