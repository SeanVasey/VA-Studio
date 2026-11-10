<?php

namespace App\Domain\Merch\Fulfillment;

use App\Support\Environment\TestEnvironment;

/**
 * Merchandise fulfillment is default-off and admitted only where the Stripe-test commerce chain is (local, testing, staging).
 * Production has no admission: validate-runtime.php holds MERCH_PRINTFUL_ENABLED false and this gate refuses by environment
 * even if the flag were set. Lifting it is a reviewed change with Sean's authorization (U-12).
 */
final class MerchFulfillmentPolicy
{
    public function enabled(): bool
    {
        return TestEnvironment::admitsTestCommerce() && config('merch.printful_enabled') === true;
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new MerchFulfillmentException('MERCH_FULFILLMENT_DISABLED', 404);
        }
    }

    /** Confirming charges the Printful account, so it has its own literal-true switch on top of the main flag. */
    public function confirmsOrders(): bool
    {
        return $this->enabled() && config('merch.confirm_orders') === true;
    }
}
