<?php

namespace App\Domain\Customers;

final class CustomerPurchaseClaimPolicy
{
    public const VERSION = 'guest-test-purchase-v1';

    public const TTL_SECONDS = 600;

    public function enabled(): bool
    {
        return app(CustomerAccessPolicy::class)->enabled() && config('customer.test_purchase_claims_enabled') === true;
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new CustomerAccessException;
        }
    }
}
